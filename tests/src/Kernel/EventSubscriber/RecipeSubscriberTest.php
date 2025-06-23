<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\EventSubscriber;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\experience_builder\Entity\Component;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * @group experience_builder
 * @covers \Drupal\experience_builder\EventSubscriber\RecipeSubscriber
 */
final class RecipeSubscriberTest extends KernelTestBase {

  use RecipeTestTrait;

  public function testComponentsAvailableOnRecipeApply(): void {
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
  }

}
