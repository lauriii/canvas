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

#[CoversMethod(CanvasConfigUpdater::class, 'needsConfigEntityWithComponentTreeSequenceKeysUpdate')]
#[CoversFunction('canvas_post_update_0017_pattern_component_tree_sequence_keys')]
#[CoversFunction('canvas_post_update_0017_page_region_component_tree_sequence_keys')]
#[CoversFunction('canvas_post_update_0017_content_template_component_tree_sequence_keys')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_data_model')]
final class ConfigComponentTreeSequenceKeysUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/collapsed_inputs/collapsed-inputs-fixture.php';
  }

  /**
   * Tests that component tree sequence keys are migrated to component instance UUIDs.
   *
   * Old Canvas versions stored component tree items with integer sequence keys
   * (e.g. 0, 1, 2). For reliable config translations, items must be keyed by
   * the component instance UUID.
   *
   * @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer
   */
  public function testSequenceKeysAreConvertedToUuids(): void {
    $pattern_before = Pattern::load('test_pattern');
    \assert($pattern_before instanceof Pattern);
    self::assertArrayHasKey(0, $pattern_before->get('component_tree'), 'Pattern uses old integer sequence keys before the update.');
    self::assertArrayNotHasKey('c28c3443-174c-4a83-a07a-8a071b133371', $pattern_before->get('component_tree'), 'Pattern does not yet have UUID sequence keys.');

    $template_before = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_before instanceof ContentTemplate);
    self::assertArrayHasKey(0, $template_before->get('component_tree'), 'Content template uses old integer sequence keys before the update.');

    $region_before = PageRegion::load('stark.sidebar_first');
    \assert($region_before instanceof PageRegion);
    self::assertArrayHasKey(0, $region_before->get('component_tree'), 'Page region uses old integer sequence keys before the update.');

    $this->runUpdates();

    $pattern_after = Pattern::load('test_pattern');
    \assert($pattern_after instanceof Pattern);
    self::assertEntityIsValid($pattern_after);
    self::assertArrayNotHasKey(0, $pattern_after->get('component_tree'), 'Pattern no longer uses old integer sequence keys after the update.');
    self::assertArrayHasKey('c28c3443-174c-4a83-a07a-8a071b133371', $pattern_after->get('component_tree'), 'Pattern uses the component instance UUID as sequence key.');

    $template_after = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_after instanceof ContentTemplate);
    self::assertEntityIsValid($template_after);
    self::assertArrayNotHasKey(0, $template_after->get('component_tree'), 'Content template no longer uses old integer sequence keys after the update.');
    self::assertArrayHasKey('c28c3443-174c-4a83-a07a-8a071b133371', $template_after->get('component_tree'), 'Content template uses the component instance UUID as sequence key for the first instance.');
    self::assertArrayHasKey('5f71027b-d9d3-4f3d-8990-a6502c0ba676', $template_after->get('component_tree'), 'Content template uses the component instance UUID as sequence key for the second instance.');

    $region_after = PageRegion::load('stark.sidebar_first');
    \assert($region_after instanceof PageRegion);
    self::assertEntityIsValid($region_after);
    self::assertArrayNotHasKey(0, $region_after->get('component_tree'), 'Page region no longer uses old integer sequence keys after the update.');
    self::assertArrayHasKey('c28c3443-174c-4a83-a07a-8a071b133371', $region_after->get('component_tree'), 'Page region uses the component instance UUID as sequence key.');

    $field_after = FieldConfig::load('node.article.field_canvas_demo');
    \assert($field_after instanceof FieldConfigInterface);
    self::assertEntityIsValid($field_after);
    // FieldConfig::$default_value is not a ComponentTreeConfigEntityBase
    // property. `default_value` must contain a zero-indexed array of values.
    // That means the default component tree for a configurable Canvas field
    // requires careful coordination to keep symmetrical translations in sync.
    self::assertTrue(\array_is_list($field_after->get('default_value')));
  }

}
