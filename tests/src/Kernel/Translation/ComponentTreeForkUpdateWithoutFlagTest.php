<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

use Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork;
use Drupal\canvas\Entity\Page;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the translation fork updates do nothing without the flag module.
 *
 * @legacy-covers \canvas_update_11201
 * @legacy-covers \canvas_post_update_0031_mark_divergent_component_tree_translations_forked
 */
#[Group('canvas')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class ComponentTreeForkUpdateWithoutFlagTest extends ContentComponentTreeSymmetricalTranslationTestBase {

  public function test(): void {
    $module_handler = \Drupal::moduleHandler();
    $module_handler->loadInclude('canvas', 'install');
    $module_handler->loadInclude('canvas', 'php', 'canvas.post_update');

    canvas_update_11201();
    self::assertNull(\Drupal::entityDefinitionUpdateManager()->getFieldStorageDefinition(ComponentTreeTranslationFork::FIELD_NAME, Page::ENTITY_TYPE_ID));

    // Finished on the first pass.
    $sandbox = [];
    canvas_post_update_0031_mark_divergent_component_tree_translations_forked($sandbox);
    self::assertSame([], $sandbox);
  }

}
