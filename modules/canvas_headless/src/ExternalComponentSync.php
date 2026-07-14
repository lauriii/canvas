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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Synchronizes external Code Components from the headless application.
 */
final class ExternalComponentSync {

  private const string LOCK_KEY = 'canvas_headless_external_component_sync';

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
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly PreviewUrlGeneratorInterface $previewUrlGenerator,
    PropShapeRepositoryInterface $propShapeRepository,
  ) {
    $this->jsComponentDiscovery = new JsComponentDiscovery(
      $propShapeRepository,
      $this->configFactory,
      $this->entityTypeManager,
    );
  }

  /**
   * Fetches the configured component metadata and synchronizes its definitions.
   */
  public function synchronize(): void {
    $config = $this->configFactory->get('canvas_headless.settings');
    $configured_endpoint = $config->get('component_metadata_url');
    if (!\is_string($configured_endpoint) || $configured_endpoint === '') {
      return;
    }

    if (!$this->lock->acquire(self::LOCK_KEY)) {
      return;
    }

    try {
      // The SDK's component metadata endpoint is protected by
      // proof-by-redemption: the caller presents a fresh, single-use preview
      // assertion as a Bearer token, and the app verifies it by redeeming it
      // at this site's own token endpoint. Minting is permission-checked;
      // without the preview permission (cron, drush) there is nothing to
      // authenticate with, so the sync waits for a permitted request. Minted
      // under the lock so a contended run does not waste single-use tokens.
      $assertion = $this->previewUrlGenerator->issueForPath('/');
      if ($assertion === NULL) {
        return;
      }

      $endpoint = str_starts_with($configured_endpoint, '/')
        ? $config->get('frontend_url') . $configured_endpoint
        : $configured_endpoint;
      $response = $this->httpClient->request('GET', $endpoint, [
        // Do not let an unavailable endpoint significantly delay Canvas boot.
        RequestOptions::CONNECT_TIMEOUT => 1,
        RequestOptions::TIMEOUT => 3,
        RequestOptions::HEADERS => [
          'Accept' => 'application/json',
          'Authorization' => 'Bearer ' . $assertion,
        ],
      ]);
      $body = (string) $response->getBody();
      $payload = json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
      if (!\is_array($payload) || !isset($payload['components']) || !\is_array($payload['components'])) {
        throw new \UnexpectedValueException('The component metadata payload must contain a components array.');
      }
      if (($payload['version'] ?? NULL) !== self::SUPPORTED_PAYLOAD_VERSION) {
        throw new \UnexpectedValueException(\sprintf(
          'Unsupported component metadata payload version %s; this site understands version %d.',
          json_encode($payload['version'] ?? NULL),
          self::SUPPORTED_PAYLOAD_VERSION,
        ));
      }

      // Surface the payload's own diagnostics (e.g. duplicate machine names
      // or components excluded during discovery) in the Drupal log.
      $warnings = $payload['warnings'] ?? [];
      foreach (\is_array($warnings) ? $warnings : [] as $warning) {
        if (!\is_array($warning) || !\is_string($warning['message'] ?? NULL)) {
          continue;
        }
        $this->loggerFactory->get('canvas_headless')->warning('The component metadata payload reported a warning (@code): @message', [
          '@code' => \is_string($warning['code'] ?? NULL) ? $warning['code'] : 'unknown',
          '@message' => $warning['message'] . (\is_string($warning['path'] ?? NULL) ? ' [' . $warning['path'] . ']' : ''),
        ]);
      }

      $seen_machine_names = [];
      foreach ($payload['components'] as $definition) {
        try {
          $this->synchronizeDefinition($definition, $seen_machine_names);
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('canvas_headless')->error('Could not synchronize an external component: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('canvas_headless')->error('Could not fetch the external component metadata: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $this->lock->release(self::LOCK_KEY);
    }
  }

  /**
   * Creates or updates one external component definition.
   *
   * @param mixed $definition
   *   A component definition from the metadata payload.
   * @param array<string, true> $seen_machine_names
   *   Machine names already synchronized in this run, keyed by name. Updated
   *   with this definition's machine name.
   */
  private function synchronizeDefinition(mixed $definition, array &$seen_machine_names): void {
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
      $this->loggerFactory->get('canvas_headless')->warning("Skipped a duplicate definition for the external component '@name': the first definition in the payload wins.", [
        '@name' => $machine_name,
      ]);
      return;
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
      return;
    }

    if ($component === NULL) {
      $component = $candidate;
    }
    else {
      foreach ($values as $property => $value) {
        $component->set($property, $value);
      }
    }
    $component->save();
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
