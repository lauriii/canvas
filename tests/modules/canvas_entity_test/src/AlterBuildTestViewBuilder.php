<?php

declare(strict_types=1);

namespace Drupal\canvas_entity_test;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;

/**
 * A view builder that marks every render array it alters.
 *
 * @see \Drupal\canvas_entity_test\Hook\EntityTestHooks::entityTypeAlter()
 */
final class AlterBuildTestViewBuilder extends EntityViewBuilder {

  /**
   * {@inheritdoc}
   */
  protected function alterBuild(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode): void {
    $build['#canvas_entity_test_altered'] = TRUE;
  }

}
