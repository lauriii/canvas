<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentMetadataRequirementsChecker;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent
 * @internal
 */
final class SingleDirectoryComponentDiscovery implements ComponentCandidatesDiscoveryInterface {

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ComponentPluginManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function discover(): array {
    $definitions = $this->componentPluginManager->getDefinitions();
    return array_filter(
      $definitions,
      // Automatically ignore `noUi` SDCs.
      fn (array $definition): bool => ($definition['noUi'] ?? FALSE) === FALSE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);

    // Special case exception for 'all-props' SDC.
    // (This is used to develop support for more prop shapes.)
    if ($source_specific_id === 'sdc_test_all_props:all-props') {
      return;
    }

    $definition = $this->componentPluginManager->getDefinition($source_specific_id);

    if (isset($definition['status']) && $definition['status'] === 'obsolete') {
      throw new ComponentDoesNotMeetRequirementsException(['Component has "obsolete" status']);
    }

    $component_plugin = $this->componentPluginManager->createInstance($source_specific_id);

    if (property_exists($component_plugin->metadata, 'noUi') && $component_plugin->metadata->noUi === TRUE) {
      throw new ComponentDoesNotMeetRequirementsException(['Component flagged "noUi".']);
    }
    // The above only works on Drupal core >=11.3.
    // @todo Remove in https://www.drupal.org/i/3537695
    // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
    if ($component_plugin->getPluginDefinition()['noUi'] ?? FALSE) {
      throw new ComponentDoesNotMeetRequirementsException(['Component flagged "noUi".']);
    }

    ComponentMetadataRequirementsChecker::check(
      $definition['id'],
      $component_plugin->metadata,
      $definition['props']['required'] ?? [],
      forbidden_key_characters: [],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $component_plugin = $this->componentPluginManager->createInstance($source_specific_id);
    // @see `type: canvas.component_source_settings.sdc`
    return [
      'prop_field_definitions' => SingleDirectoryComponent::getPropsForComponentPlugin($component_plugin),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): ?string {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $definition = $this->componentPluginManager->getDefinition($source_specific_id);
    return $definition['provider'];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool {
    \assert(array_key_exists($source_specific_id, $this->discover()), $source_specific_id);
    $component_plugin = $this->componentPluginManager->createInstance($source_specific_id);
    // Disabled if obsolete or flagged with noUi.
    $initial_status = !(
      (property_exists($component_plugin->metadata, 'noUi') && $component_plugin->metadata->noUi === TRUE)
      // The above only works on Drupal core >=11.3.
      // @todo Remove in https://www.drupal.org/i/3537695
      // @phpstan-ignore offsetAccess.nonOffsetAccessible
      || ($component_plugin->getPluginDefinition()['noUi'] ?? FALSE)
      || (isset($component_plugin->metadata->status) && $component_plugin->metadata->status === 'obsolete')
    );
    return $initial_status;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    $definition = $this->componentPluginManager->getDefinition($source_specific_id);
    return [
      'label' => (string) ($definition['name'] ?? $definition['machineName']),
    ];
  }

  /**
   * {@inheritdoc}
   *
   *  The naming convention for SDC plugin components is
   *  [module/theme]:[component machine name]. Colon is invalid config entity
   *  name, so we replace it with '.'.
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   * @see https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/api-for-single-directory-components
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    \assert(str_contains($source_specific_component_id, ':'));
    return \sprintf('%s.%s',
      SingleDirectoryComponent::SOURCE_PLUGIN_ID,
      str_replace(':', '.', $source_specific_component_id),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    $prefix = SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.';
    \assert(str_starts_with($component_id, $prefix));
    return str_replace('.', ':', substr($component_id, strlen($prefix)));
  }

}
