<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\EventSubscriber;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\Page;
use Drupal\field\Entity\FieldConfig;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @group experience_builder
 * @covers \Drupal\experience_builder\EventSubscriber\RecipeSubscriber
 */
final class RecipeSubscriberTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use RecipeTestTrait;

  public function testComponentsAndDefaultContentAvailableOnRecipeApply(): void {
    $fixtures_dir = __DIR__ . '/../../../fixtures/recipes';

    // Set up the basic stuff needed for XB to work.
    $recipe = Recipe::createFromDirectory($fixtures_dir . '/base');
    RecipeRunner::processRecipe($recipe);

    // The recipe should apply without errors, because the components used by
    // the content should be available by the time the content is imported.
    $recipe = Recipe::createFromDirectory($fixtures_dir . '/test_site');
    RecipeRunner::processRecipe($recipe);

    // Components should have been created.
    $this->assertInstanceOf(Component::class, Component::load('sdc.xb_test_sdc.grid-container'));
    $this->assertInstanceOf(Component::class, Component::load('block.system_menu_block.admin'));

    // Demo XB field should have been created.
    $this->assertArrayHasKey('node.article.field_xb_demo', FieldConfig::loadMultiple());
    $this->assertSame([
      'type' => 'experience_builder_naive_render_sdc_tree',
      'label' => 'hidden',
      'settings' => [],
      'third_party_settings' => [],
      'weight' => -2,
      'region' => 'content',
    ], EntityViewDisplay::load('node.article.default')?->getComponent('field_xb_demo'));

    // Demo content should have been created.
    $this->assertSame([
      1 => ['Homepage', '/homepage'],
      2 => ['Empty Page', '/test-page'],
      3 => ['Page without a path', NULL],
    ], array_map(
      // @phpstan-ignore-next-line
      fn (Page $page) => [$page->label(), $page->get('path')->alias],
      Page::loadMultiple()
    ));
    $this->assertSame('/homepage', $this->config('system.site')->get('page.front'));
  }

}
