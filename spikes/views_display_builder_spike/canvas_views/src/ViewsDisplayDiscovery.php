<?php

declare(strict_types=1);

namespace Drupal\canvas_views;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\canvas_views\Entity\CanvasViewsDisplay;
use Drupal\canvas_views\Plugin\Canvas\ComponentSource\ViewsDisplayComponent;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers one Canvas component per Canvas views display entity.
 */
final class ViewsDisplayDiscovery implements ComponentCandidatesDiscoveryInterface, ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(EntityTypeManagerInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function discover(): array {
    $candidates = [];
    $displays = $this->entityTypeManager
      ->getStorage(CanvasViewsDisplay::ENTITY_TYPE_ID)
      ->loadByProperties(['status' => TRUE]);
    foreach ($displays as $id => $display) {
      $candidates[(string) $id] = ['label' => (string) $display->label()];
    }
    return $candidates;
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    if (CanvasViewsDisplay::load($source_specific_id) === NULL) {
      throw new ComponentDoesNotMeetRequirementsException([
        \sprintf('Canvas views display "%s" does not exist.', $source_specific_id),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    return ['display_id' => $source_specific_id];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): string {
    return 'canvas_views';
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
    $display = CanvasViewsDisplay::load($source_specific_id);
    return [
      'label' => $display === NULL ? $source_specific_id : (string) $display->label(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return ViewsDisplayComponent::SOURCE_PLUGIN_ID . '.' . $source_specific_component_id;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    return \substr($component_id, \strlen(ViewsDisplayComponent::SOURCE_PLUGIN_ID) + 1);
  }

}
