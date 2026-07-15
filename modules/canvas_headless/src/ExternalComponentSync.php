<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Synchronizes external Code Components from the headless application.
 */
final class ExternalComponentSync {

  private const string LOCK_KEY = 'canvas_headless_external_component_sync';

  /**
   * The component metadata endpoint path exposed by headless applications.
   *
   * Every Canvas Headless SDK adapter mounts this route as part of the
   * integration contract, just as preview activation uses a fixed route.
   */
  public const string COMPONENT_METADATA_PATH = '/api/canvas/components';

  /**
   * The component metadata payload version this reader understands.
   *
   * The cross-repo contract with the Drupal Canvas Headless SDK's component
   * metadata endpoint (see the SDK's components-endpoint entry): the reader
   * hard-fails on an unknown version instead of mis-parsing.
   */
  private const int SUPPORTED_PAYLOAD_VERSION = 1;

  private readonly JsComponentDiscovery $jsComponentDiscovery;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    PropShapeRepositoryInterface $propShapeRepository,
  ) {
    $this->jsComponentDiscovery = new JsComponentDiscovery(
      $propShapeRepository,
      $this->configFactory,
      $this->entityTypeManager,
    );
  }

  /**
   * Validates and synchronizes a component metadata payload.
   *
   * The browser fetches the payload because it can reach the same frontend
   * URL it embeds. Drupal remains responsible for validation and persistence.
   * Components omitted from the payload are retained.
   *
   * @param array<string, mixed> $payload
   *   The component metadata payload.
   *
   * @return array{created: int, updated: int, unchanged: int, warnings: list<string>, errors: list<string>}
   *   The synchronization result.
   */
  public function synchronize(array $payload): array {
    if (!isset($payload['components']) || !\is_array($payload['components'])) {
      throw new \UnexpectedValueException('The component metadata payload must contain a components array.');
    }
    if (($payload['version'] ?? NULL) !== self::SUPPORTED_PAYLOAD_VERSION) {
      throw new \UnexpectedValueException(\sprintf(
        'Unsupported component metadata payload version %s; this site understands version %d.',
        json_encode($payload['version'] ?? NULL),
        self::SUPPORTED_PAYLOAD_VERSION,
      ));
    }
    if (!$this->lock->acquire(self::LOCK_KEY)) {
      throw new \RuntimeException('Another external component synchronization is already in progress.');
    }

    $result = [
      'created' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'warnings' => [],
      'errors' => [],
    ];

    try {
      // Surface the payload's own diagnostics in both the result and the
      // Drupal log.
      $warnings = $payload['warnings'] ?? [];
      foreach (\is_array($warnings) ? $warnings : [] as $warning) {
        if (!\is_array($warning) || !\is_string($warning['message'] ?? NULL)) {
          continue;
        }
        $message = $warning['message'] . (\is_string($warning['path'] ?? NULL) ? ' [' . $warning['path'] . ']' : '');
        $result['warnings'][] = $message;
        $this->loggerFactory->get('canvas_headless')->warning('The component metadata payload reported a warning (@code): @message', [
          '@code' => \is_string($warning['code'] ?? NULL) ? $warning['code'] : 'unknown',
          '@message' => $message,
        ]);
      }

      $seen_machine_names = [];
      foreach ($payload['components'] as $definition) {
        try {
          $outcome = $this->synchronizeDefinition($definition, $seen_machine_names, $result['warnings']);
          if ($outcome !== NULL) {
            $result[$outcome]++;
          }
        }
        catch (\Throwable $e) {
          $result['errors'][] = $e->getMessage();
          $this->loggerFactory->get('canvas_headless')->error('Could not synchronize an external component: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    finally {
      $this->lock->release(self::LOCK_KEY);
    }

    return $result;
  }

  /**
   * Creates or updates one external component definition.
   *
   * @param mixed $definition
   *   A component definition from the metadata payload.
   * @param array<string, true> $seen_machine_names
   *   Machine names already synchronized in this run, keyed by name. Updated
   *   with this definition's machine name.
   * @param list<string> $warnings
   *   Synchronization warnings, updated for skipped duplicates.
   *
   * @return 'created'|'updated'|'unchanged'|null
   *   The synchronization outcome, or NULL when skipped.
   */
  private function synchronizeDefinition(mixed $definition, array &$seen_machine_names, array &$warnings): ?string {
    // The entry shape of the SDK's component metadata payload: machineName
    // and name as strings, props as a flat prop-name-to-definition map,
    // required as a top-level list of prop names.
    if (!\is_array($definition) || !isset($definition['machineName'], $definition['name'], $definition['props']) || !\is_string($definition['machineName']) || !\is_string($definition['name']) || !\is_array($definition['props'])) {
      throw new \UnexpectedValueException('Each component must define string machineName and name values and a props object.');
    }

    $machine_name = lcfirst($definition['machineName']);
    if (!preg_match('/^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$/', $machine_name)) {
      throw new \UnexpectedValueException("The component machine name '{$definition['machineName']}' is invalid.");
    }

    // The SDK ships every duplicate-machine-name definition and leaves the
    // conflict policy to the reader. Without a policy, duplicates would
    // overwrite each other on every run and churn config and component
    // versions endlessly, so the first definition in the payload wins.
    if (isset($seen_machine_names[$machine_name])) {
      $message = "Skipped a duplicate definition for the external component '$machine_name': the first definition in the payload wins.";
      $warnings[] = $message;
      $this->loggerFactory->get('canvas_headless')->warning($message);
      return NULL;
    }
    $seen_machine_names[$machine_name] = TRUE;

    $props = $definition['props'];
    $required = $definition['required'] ?? [];
    $slots = $definition['slots'] ?? [];
    if (!\is_array($required) || !\is_array($slots)) {
      throw new \UnexpectedValueException("The component '{$definition['machineName']}' has invalid required or slots metadata.");
    }
    $props = \array_map($this->filterSupportedPropKeys(...), $props);
    $slots = \array_map(
      fn(mixed $slot): mixed => \is_array($slot)
        ? $this->filterSupportedKeys($slot, 'canvas.slot_definition')
        : $slot,
      $slots,
    );

    $storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $component = $storage->load($machine_name);
    if ($component !== NULL && (!$component instanceof JavaScriptComponent || !$component->isExternal())) {
      throw new \UnexpectedValueException("The component '$machine_name' already exists and is not external.");
    }

    $values = [
      'machineName' => $machine_name,
      'name' => $definition['name'],
      'status' => \is_bool($definition['status'] ?? NULL) ? $definition['status'] : TRUE,
      'type' => 'external',
      'props' => $props,
      'required' => $required,
      'slots' => $slots,
      'dataDependencies' => [],
    ];
    $candidate = $storage->create($values);
    \assert($candidate instanceof JavaScriptComponent);
    $violations = $candidate->getTypedData()->validate();
    if ($violations->count() > 0) {
      throw new \UnexpectedValueException((string) $violations);
    }

    $canvas_component = Component::load(JsComponent::componentIdFromJavascriptComponentId($machine_name));
    if ($component !== NULL && $canvas_component !== NULL && $this->matchesStoredComponents($candidate, $component, $canvas_component)) {
      return 'unchanged';
    }

    $outcome = $component === NULL ? 'created' : 'updated';
    if ($component === NULL) {
      $component = $candidate;
    }
    else {
      foreach ($values as $property => $value) {
        $component->set($property, $value);
      }
    }
    $component->save();
    return $outcome;
  }

  /**
   * Filters a prop definition to keys supported by Canvas config schema.
   *
   * Values are preserved verbatim so config and entity validation can reject
   * invalid definitions.
   *
   * @param array<string, mixed> $prop
   *   The prop definition.
   * @param bool $include_metadata
   *   Whether keys from the top-level prop metadata schema are supported.
   *
   * @return array<string, mixed>
   *   The filtered prop definition.
   */
  private function filterSupportedPropKeys(array $prop, bool $include_metadata = TRUE): array {
    $type = \is_string($prop['type'] ?? NULL) ? $prop['type'] : '*';
    $supported_keys = $include_metadata
      ? $this->getSupportedKeys('canvas.json_schema.prop.*')
      : [];
    $supported_keys = [
      ...$supported_keys,
      ...$this->getSupportedKeys("canvas.json_schema.prop_shape.$type"),
    ];
    $filtered = \array_intersect_key($prop, \array_flip($supported_keys));
    if ($type === 'array' && \is_array($filtered['items'] ?? NULL)) {
      $filtered['items'] = $this->filterSupportedPropKeys($filtered['items'], FALSE);
    }
    return $filtered;
  }

  /**
   * Filters data to keys in a Canvas config schema mapping.
   *
   * @param array<string, mixed> $data
   *   The data to filter.
   * @param string $schema_type
   *   The config schema type whose mapping defines supported keys.
   *
   * @return array<string, mixed>
   *   The filtered data.
   */
  private function filterSupportedKeys(array $data, string $schema_type): array {
    return \array_intersect_key($data, \array_flip($this->getSupportedKeys($schema_type)));
  }

  /**
   * Gets supported mapping keys from a config schema type.
   *
   * @return string[]
   *   The supported keys.
   */
  private function getSupportedKeys(string $schema_type): array {
    $definition = $this->typedConfigManager->getDefinition($schema_type);
    return \array_values(\array_filter(
      \array_keys($definition['mapping'] ?? []),
      \is_string(...),
    ));
  }

  /**
   * Checks an unsaved candidate's version and live metadata against storage.
   */
  private function matchesStoredComponents(JavaScriptComponent $candidate, JavaScriptComponent $stored_code_component, Component $stored_canvas_component): bool {
    $settings = $this->jsComponentDiscovery->computeComponentSettingsForEntity($candidate);
    $source = $this->componentSourceManager->createInstance(JsComponent::SOURCE_PLUGIN_ID, [
      'local_source_id' => $candidate->id(),
      ...$settings,
    ]);
    \assert($source instanceof JsComponent);
    $candidate_version = $source
      ->setJavaScriptComponent($candidate)
      ->generateVersionHash();

    return $stored_canvas_component->getActiveVersion() === $candidate_version
      && $stored_code_component->label() === $candidate->label()
      && $stored_code_component->status() === $candidate->status()
      && $stored_canvas_component->label() === $candidate->label()
      && $stored_canvas_component->status() === $candidate->status();
  }

}
