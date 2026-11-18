<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\layout_builder\Plugin\Block\InlineBlock;

/**
 * Replaces the block plugin class for Layout Builder's inline block.
 *
 * @see \Drupal\canvas\Block\BuildsContentBlockImmediatelyTrait
 * @see \Drupal\canvas\Hook\ContentTemplateHooks::blockAlter()
 *
 * @todo Remove when core builds content blocks in its block plugins: https://www.drupal.org/project/drupal/issues/3638853
 *
 * @drupalOptionalDependency layout_builder
 *
 * @internal
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class ContentTemplateAwareInlineBlock extends InlineBlock {

  use BuildsContentBlockImmediatelyTrait;

}
