<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\block_content\Plugin\Block\BlockContentBlock;

/**
 * Replaces the block plugin class for a content block placed as a block.
 *
 * @see \Drupal\canvas\Block\BuildsContentBlockImmediatelyTrait
 * @see \Drupal\canvas\Hook\ContentTemplateHooks::blockAlter()
 *
 * @todo Remove when core builds content blocks in its block plugins: https://www.drupal.org/project/drupal/issues/3638853
 *
 * @drupalOptionalDependency block_content
 *
 * @internal
 */
final class ContentTemplateAwareBlockContentBlock extends BlockContentBlock {

  use BuildsContentBlockImmediatelyTrait;

}
