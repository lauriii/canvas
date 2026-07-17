<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers the single component provided by the `list` source.
 *
 * The List element is a fixed component, but discovery (rather than shipped
 * default config) keeps the Component config entity's version hash in sync
 * with the settings DSL automatically, on both new installs and updates.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent
 * @internal
 */
final class ListComponentDiscovery implements ComponentCandidatesDiscoveryInterface {

  public const string SOURCE_SPECIFIC_ID = 'list';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ModuleHandlerInterface::class),
      $container->get(EntityTypeBundleInfoInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function discover(): array {
    return [self::SOURCE_SPECIFIC_ID => 'List'];
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    \assert($source_specific_id === self::SOURCE_SPECIFIC_ID);
    if (!$this->moduleHandler->moduleExists('node')) {
      throw new ComponentDoesNotMeetRequirementsException(['The List element requires the Node module.']);
    }
    if ($this->bundleInfo->getBundleInfo('node') === []) {
      throw new ComponentDoesNotMeetRequirementsException(['The List element requires at least one content type.']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    \assert($source_specific_id === self::SOURCE_SPECIFIC_ID);
    // The List element has no per-component settings: all of its behavior is
    // per-instance input. Its Component version changes only when the settings
    // DSL changes.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent::getExplicitInputDefinitions()
    // @see `type: canvas.component_source_settings.list`
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): string {
    return 'canvas';
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentStatus(string $source_specific_id): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCurrentComponentMetadata(string $source_specific_id): array {
    return ['label' => 'List'];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return \sprintf('%s.%s', ListComponent::SOURCE_PLUGIN_ID, $source_specific_component_id);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    $prefix = ListComponent::SOURCE_PLUGIN_ID . '.';
    \assert(\str_starts_with($component_id, $prefix));
    return \substr($component_id, \strlen($prefix));
  }

}
