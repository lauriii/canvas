<?php

declare(strict_types=1);

namespace Drupal\canvas_views_poc;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\canvas_views_poc\Plugin\Canvas\ComponentSource\ViewsList;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Entity\View;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers one Canvas component per eligible Views display.
 *
 * Eligible: an enabled view over an entity base table. The display types are
 * not filtered; any display's rows can be listed. The source-specific ID is
 * "{view_id}.{display_id}".
 */
final class ViewsListDiscovery implements ComponentCandidatesDiscoveryInterface, ContainerInjectionInterface {

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
    $views = $this->entityTypeManager->getStorage('view')->loadByProperties(['status' => TRUE]);
    foreach ($views as $view) {
      \assert($view instanceof View);
      $executable = $view->getExecutable();
      if ($executable->getBaseEntityType() === FALSE) {
        continue;
      }
      foreach ($view->get('display') as $display_id => $display) {
        if ($display['display_plugin'] === 'default') {
          continue;
        }
        $candidates[$view->id() . '.' . $display_id] = [
          'label' => \sprintf('%s: %s', $view->label(), $display['display_title'] ?? $display_id),
        ];
      }
    }
    return $candidates;
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(string $source_specific_id): void {
    [$view_id] = \explode('.', $source_specific_id, 2);
    $view = View::load($view_id);
    if ($view === NULL || $view->getExecutable()->getBaseEntityType() === FALSE) {
      throw new ComponentDoesNotMeetRequirementsException([
        \sprintf('View "%s" is missing or not based on an entity type.', $view_id),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function computeComponentSettings(string $source_specific_id): array {
    [$view_id, $display_id] = \explode('.', $source_specific_id, 2);
    return [
      'view_id' => $view_id,
      'display_id' => $display_id,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInitialComponentProvider(string $source_specific_id): string {
    return 'views';
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
    return [
      'label' => $this->discover()[$source_specific_id]['label'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getComponentConfigEntityId(string $source_specific_component_id): string {
    return ViewsList::SOURCE_PLUGIN_ID . '.' . $source_specific_component_id;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSourceSpecificComponentId(string $component_id): string {
    return \substr($component_id, \strlen(ViewsList::SOURCE_PLUGIN_ID) + 1);
  }

}
