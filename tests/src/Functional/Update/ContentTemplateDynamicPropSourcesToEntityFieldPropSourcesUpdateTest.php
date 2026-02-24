<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\ContentTemplate;

/**
 * @covers \canvas_post_update_0013_update_dynamic_prop_sources_to_entity_field_prop_sources
 * @group canvas
 * @group legacy
 * @see \Drupal\Tests\canvas\Kernel\Update\RecipeWithContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest
 *
 * Note that only ContentTemplate config entities are allowed to use
 * DynamicPropSources.
 */
final class ContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  public const string EXPECT_DEPRECATION_3566701 = 'The "dynamic" prop source was renamed to "entity field" and is deprecated in canvas:1.2.0 and will be removed from canvas:2.0.0. Re-save (and re-export) all Canvas content templates. See https://www.drupal.org/node/3566701';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/dynamic_prop_sources/content-template-with-dynamic-prop-source.php';
  }

  public function test(): void {
    $this->expectDeprecation(self::EXPECT_DEPRECATION_3566701);

    $raw_inputs_for_first_component_instance = ContentTemplate::load('node.page.full')
      ?->getComponentTree()
      // phpcs:ignore Drupal.WhiteSpace.ObjectOperatorIndent.Indent
      ->get(0)
      ?->getValue()['inputs'];
    self::assertStringContainsString('"sourceType":"dynamic"', $raw_inputs_for_first_component_instance);
    self::assertStringNotContainsString('"sourceType":"entity-field"', $raw_inputs_for_first_component_instance);

    $this->runUpdates();

    $raw_inputs_for_first_component_instance = ContentTemplate::load('node.page.full')
      ?->getComponentTree()
      // phpcs:ignore Drupal.WhiteSpace.ObjectOperatorIndent.Indent
      ->get(0)
      ?->getValue()['inputs'];
    self::assertStringNotContainsString('"sourceType":"dynamic"', $raw_inputs_for_first_component_instance);
    self::assertStringContainsString('"sourceType":"entity-field"', $raw_inputs_for_first_component_instance);
  }

}
