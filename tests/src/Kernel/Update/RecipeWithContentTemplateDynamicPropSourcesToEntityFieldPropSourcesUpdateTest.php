<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Update;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Functional\Update\ContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest;

/**
 * Proves that no update path is necessary for exported content templates.
 *
 * @group canvas
 * @covers \canvas_post_update_0013_update_dynamic_prop_sources_to_entity_field_prop_sources
 * @see \Drupal\Tests\canvas\Functional\Update\ContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest()
 *
 * Note this cannot use CanvasKernelTestBase because that would pre-install the
 * Canvas module: this test is installing Canvas via a recipe.
 */
final class RecipeWithContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest extends KernelTestBase {

  use RecipeTestTrait;

  private const string FIXTURES_DIR = __DIR__ . '/../../../fixtures/recipes';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up the basic stuff needed for Canvas to work.
    $recipe = Recipe::createFromDirectory(self::FIXTURES_DIR . '/base');
    RecipeRunner::processRecipe($recipe);
  }

  /**
   * @group legacy
   */
  public function test(): void {
    $this->expectDeprecation(ContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest::EXPECT_DEPRECATION_3566701);

    // The recipe should apply without errors, because the components used by
    // the content should be available by the time the content is imported.
    $recipe = Recipe::createFromDirectory(self::FIXTURES_DIR . '/canvas_post_update_0013_update_dynamic_prop_sources_to_entity_field_prop_sources');
    RecipeRunner::processRecipe($recipe);

    $raw_inputs_for_first_component_instance = ContentTemplate::load('node.page.full')
      ?->getComponentTree()
      // phpcs:ignore Drupal.WhiteSpace.ObjectOperatorIndent.Indent
      ->get(0)
      ?->getValue()['inputs'];

    // Note that the content template was imported with the "dynamic" prop
    // source, but that it was updated to use the "entity-field" prop source,
    // while updates were not executed.
    // This is because the creation a new ContentTemplate config entity ends up
    // triggering the just-in-time update path in PropSource::parse().
    // @see \Drupal\canvas\PropSource\PropSource::parse()
    self::assertStringNotContainsString('"sourceType":"dynamic"', $raw_inputs_for_first_component_instance);
    self::assertStringContainsString('"sourceType":"entity-field"', $raw_inputs_for_first_component_instance);
  }

}
