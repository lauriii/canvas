<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\FieldConfigInterface;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversMethod(CanvasConfigUpdater::class, 'needsConfigEntityWithComponentTreeInputsAsArrays')]
#[CoversMethod(CanvasConfigUpdater::class, 'updateConfigEntityWithComponentTreeInputsAsArrays')]
#[CoversFunction('canvas_post_update_0016_pattern_component_inputs_must_be_arrays')]
#[CoversFunction('canvas_post_update_0016_page_region_component_inputs_must_be_arrays')]
#[CoversFunction('canvas_post_update_0016_content_template_component_inputs_must_be_arrays')]
#[CoversFunction('canvas_post_update_0016_component_tree_field_default_value_inputs')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_data_model')]
final class ConfigComponentInputsMustBeArraysUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    // This fixture stores inputs as arrays. This is not the typical scenario,
    // but it is essential to test that the update path does not break such
    // config on existing sites, as this was possible due to `type: ignore`.
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/collapsed_inputs/collapsed-inputs-fixture.php';
  }

  /**
   * Scenario 1: config component trees that contain JSON blobs for `inputs`.
   *
   * This is the typical scenario. Most sites will see exactly this before vs
   * after scenario.
   */
  public function testInputsAreJsonBlobStringsBefore(): void {
    // For the typical scenario, tweak the `collapsed_inputs` fixture.
    require \dirname(__DIR__, 3) . '/fixtures/update/config_component_inputs_must_be_arrays/config-component-inputs-must-be-arrays-fixture.php';

    $pattern_before = Pattern::load('test_pattern');
    \assert($pattern_before instanceof Pattern);
    self::assertIsString($pattern_before->get('component_tree')[0]['inputs'], '`inputs` contains a JSON blob.');

    $template_before = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_before instanceof ContentTemplate);
    self::assertIsString($template_before->get('component_tree')[0]['inputs'], '`inputs` contains a JSON blob.');

    $field_before = FieldConfig::load('node.article.field_canvas_demo');
    \assert($field_before instanceof FieldConfigInterface);
    self::assertIsString($field_before->get('default_value')[0]['inputs'], '`inputs` contains a JSON blob.');

    $region_before = PageRegion::load('stark.sidebar_first');
    \assert($region_before instanceof PageRegion);
    self::assertIsString($region_before->get('component_tree')[0]['inputs'], '`inputs` contains a JSON blob.');

    $this->runUpdates();

    self::assertNoComponentInstanceInputsJsonBlobStrings();
  }

  /**
   * Scenario 2: config component trees that contain arrays for `inputs`.
   *
   * Prior to #3582478, the `inputs` of a component instance in a config-defined
   * component tree could be stored as either an array or a JSON blob. This was
   * possible due to the use of `type: ignore`.
   *
   * This is testing an atypical scenario, but to ensure the update path does
   * not break such config on existing sites, this is essential.
   * smoothly, this is essential.
   */
  public function testInputsAlreadyAreArrays(): void {
    $pattern_before = Pattern::load('test_pattern');
    \assert($pattern_before instanceof Pattern);
    self::assertIsArray($pattern_before->get('component_tree')[0]['inputs'], '`inputs` atypically does NOT contain a JSON blob, due to `type: ignore`.');

    $template_before = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_before instanceof ContentTemplate);
    self::assertIsArray($pattern_before->get('component_tree')[0]['inputs'], '`inputs` atypically does NOT contain a JSON blob, due to `type: ignore`.');

    $field_before = FieldConfig::load('node.article.field_canvas_demo');
    \assert($field_before instanceof FieldConfigInterface);
    self::assertIsArray($pattern_before->get('component_tree')[0]['inputs'], '`inputs` atypically does NOT contain a JSON blob, due to `type: ignore`.');

    $region_before = PageRegion::load('stark.sidebar_first');
    \assert($region_before instanceof PageRegion);
    self::assertIsArray($pattern_before->get('component_tree')[0]['inputs'], '`inputs` atypically does NOT contain a JSON blob, due to `type: ignore`.');

    $this->runUpdates();

    self::assertNoComponentInstanceInputsJsonBlobStrings();
  }

  private static function assertNoComponentInstanceInputsJsonBlobStrings(): void {
    $pattern_after = Pattern::load('test_pattern');
    \assert($pattern_after instanceof Pattern);
    self::assertEntityIsValid($pattern_after);
    // Component tree sequence keys are component instance UUIDs, not integers.
    self::assertIsArray(\array_values($pattern_after->get('component_tree'))[0]['inputs'], '`inputs` not encoded as a JSON blob.');

    $template_after = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_after instanceof ContentTemplate);
    self::assertEntityIsValid($template_after);
    self::assertIsArray(\array_values($template_after->get('component_tree'))[0]['inputs'], '`inputs` not encoded as a JSON blob.');

    $field_after = FieldConfig::load('node.article.field_canvas_demo');
    \assert($field_after instanceof FieldConfigInterface);
    self::assertEntityIsValid($field_after);
    // FieldConfig::$default_value is not a ComponentTreeConfigEntityBase property
    // and is not subject to the UUID sequence key migration, so [0] is correct.
    self::assertIsArray($field_after->get('default_value')[0]['inputs'], '`inputs` not encoded as a JSON blob.');

    $region_after = PageRegion::load('stark.sidebar_first');
    \assert($region_after instanceof PageRegion);
    self::assertEntityIsValid($region_after);
    self::assertIsArray(\array_values($region_after->get('component_tree'))[0]['inputs'], '`inputs` not encoded as a JSON blob.');
  }

}
