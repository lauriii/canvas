<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;

/**
 * Builds a content block immediately, also when a content template renders it.
 *
 * A block reads the attributes and contextual links of its content block when
 * the block is built. Core therefore builds every content block immediately,
 * in its view builder. Canvas' view builder builds lazily when a content
 * template renders the content block, so the block plugins that show a content
 * block build it here.
 *
 * @see \Drupal\block_content\BlockContentViewBuilder::viewMultiple()
 * @see \Drupal\block\BlockViewBuilder::preRender()
 * @see \Drupal\canvas\Hook\ContentTemplateHooks::blockAlter()
 *
 * @todo Remove this trait, the two block plugin classes that use it, ContentTemplateHooks::blockAlter() and ContentTemplateAwareViewBuilder::buildImmediately() when core builds content blocks in its block plugins: https://www.drupal.org/project/drupal/issues/3638853
 *
 * @internal
 */
trait BuildsContentBlockImmediatelyTrait {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = parent::build();
    $view_builder = $this->entityTypeManager->getViewBuilder('block_content');
    return $view_builder instanceof ContentTemplateAwareViewBuilder
      ? $view_builder->buildImmediately($build)
      : $build;
  }

}
