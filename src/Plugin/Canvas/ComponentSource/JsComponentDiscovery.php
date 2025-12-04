<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentMetadataRequirementsChecker;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent
 * @phpstan-import-type ComponentSourceSpecificId from \Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface
 * @internal
 */
final class JsComponentDiscovery implements ComponentCandidatesDiscoveryInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ConfigFactoryInterface::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<ComponentSourceSpecificId, JavaScriptComponent>
   */
  public function discover(): array {
    $js_components = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)->loadMultiple();
    // All Canvas Component config entities have a config name that start with
    // this prefix.
    $entity_type = $this->entityTypeManager->getDefinition(Component::ENTITY_TYPE_ID);
    \assert($entity_type instanceof ConfigEntityTypeInterface);
    $config_prefix = sprintf('%s.%s.', $entity_type->getConfigPrefix(), JsComponent::SOURCE_PLUGIN_ID);
    $already_exposed_js_components = $this->configFactory->listAll($config_prefix);
    return array_filter(
      $js_components,
      fn (JavaScriptComponent $js_component): bool =>
        // Any code component that has once been exposed, must continue to be
        // discovered, even if its `status` changes to FALSE.
        in_array($config_prefix . $js_component->id(), $already_exposed_js_components, TRUE)
        // Before exposing a JavaScriptComponent as a Canvas Component for the
        // first time it must be flagged as being added to Canvas's component
        // library.
        || $js_component->status() === TRUE
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);

    $js_component = $this->discover()[$source_specific_id];

    try {
      $ephemeral_sdc_component = self::buildEphemeralSdcPluginInstance($js_component);
    }
    catch (InvalidComponentException $e) {
      throw new ComponentDoesNotMeetRequirementsException([$e->getMessage()]);
    }

    ComponentMetadataRequirementsChecker::check(
      $source_specific_id,
      $ephemeral_sdc_component->metadata,
      $js_component->getRequiredProps(),
      // @see \Drupal\Core\Config\ConfigBase::validateKeys()
      forbidden_key_characters: ['.' => '_'],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $js_component = $this->discover()[$source_specific_id];
    $ephemeral_sdc_component = self::buildEphemeralSdcPluginInstance($js_component);
    // @see `type: canvas.component_source_settings.sdc`
    return [
      'prop_field_definitions' => SingleDirectoryComponent::getPropsForComponentPlugin($ephemeral_sdc_component),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool {
    // @see ::discover()
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $js_component = $this->discover()[$source_specific_id];
    return [
      'label' => (string) $js_component->label(),
      // @todo Update in https://www.drupal.org/project/canvas/issues/3541364.
      'category' => NULL,
      'status' => $js_component->status(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return \sprintf('%s.%s', JsComponent::SOURCE_PLUGIN_ID, $source_specific_component_id);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    $prefix = JsComponent::SOURCE_PLUGIN_ID . '.';
    \assert(str_starts_with($prefix, $component_id));
    return substr($component_id, strlen($prefix));
  }

  /**
   * Any valid JavaScript Component config entity can be mapped to SDC metadata.
   *
   * @throws \Drupal\Core\Render\Component\Exception\InvalidComponentException
   *   Thrown if invalid.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\JsComponentHasValidAndSupportedSdcMetadataConstraintValidator
   */
  public static function buildEphemeralSdcPluginInstance(JavaScriptComponent $component): ComponentPlugin {
    $definition = $component->toSdcDefinition();
    return new ComponentPlugin(
      [
        'app_root' => '',
        'enforce_schemas' => TRUE,
      ],
      $definition['id'],
      $definition,
    );
  }

}
