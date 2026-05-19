<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\link\LinkItemInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\system\Functional\Form\StubForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests normalized widget state for unlimited multivalue prop source widgets.
 */
#[CoversClass(StaticPropSource::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class StaticPropSourceMultivalueWidgetStateTest extends CanvasKernelTestBase {

  private const FIELD_NAME = 'my_prop';

  /**
   * Tests that the initial render removes Drupal core's implicit trailing row.
   */
  #[DataProvider('providerWidgetTypes')]
  public function testInitialRenderRemovesTrailingRow(string $field_type, string $prop_name, string $widget_plugin_id, array $values, array $field_storage_settings, array $field_instance_settings): void {
    $prop_source = $this->buildMultivaluePropSource($field_type, $prop_name, $values, $field_storage_settings, $field_instance_settings);
    $form_state = $this->createFormState();

    $rendered_widget = $this->renderWidget($prop_source, $form_state, $widget_plugin_id);

    $this->assertSame([0, 1], $this->getRenderedDeltas($rendered_widget['widget']));
    $this->assertSame(1, $rendered_widget['widget']['#max_delta']);

    $widget_state = $this->getWidgetState($prop_source, $form_state, $widget_plugin_id);
    $this->assertSame(1, $widget_state['items_count']);
  }

  /**
   * Tests that an explicitly requested blank row survives a later rebuild.
   */
  #[DataProvider('providerWidgetTypes')]
  public function testRebuildPreservesExplicitlyAddedRow(string $field_type, string $prop_name, string $widget_plugin_id, array $values, array $field_storage_settings, array $field_instance_settings): void {
    $prop_source = $this->buildMultivaluePropSource($field_type, $prop_name, $values, $field_storage_settings, $field_instance_settings);
    $form_state = $this->createFormState();

    $this->renderWidget($prop_source, $form_state, $widget_plugin_id);

    $widget = $prop_source->getWidget(
      'irrelevant-for-this-test',
      'irrelevant-for-this-test',
      self::FIELD_NAME,
      $this->randomString(),
      $widget_plugin_id,
    );
    $widget_state = $widget::getWidgetState([], self::FIELD_NAME, $form_state);
    \assert(\is_array($widget_state));
    $widget_state['items_count']++;
    $widget::setWidgetState([], self::FIELD_NAME, $form_state, $widget_state);

    $rendered_widget = $this->renderWidget($prop_source, $form_state, $widget_plugin_id);

    $this->assertSame([0, 1, 2], $this->getRenderedDeltas($rendered_widget['widget']));
    $this->assertSame(2, $rendered_widget['widget']['#max_delta']);

    $widget_state = $this->getWidgetState($prop_source, $form_state, $widget_plugin_id);
    $this->assertSame(2, $widget_state['items_count']);
  }

  /**
   * Tests that an empty multivalue prop source renders a single blank row.
   */
  #[DataProvider('providerWidgetTypes')]
  public function testEmptyMultivalueRendersOneVisibleRow(string $field_type, string $prop_name, string $widget_plugin_id, array $values, array $field_storage_settings, array $field_instance_settings): void {
    $prop_source = $this->buildMultivaluePropSource($field_type, $prop_name, [], $field_storage_settings, $field_instance_settings);
    $form_state = $this->createFormState();

    $rendered_widget = $this->renderWidget($prop_source, $form_state, $widget_plugin_id);

    $this->assertSame([0], $this->getRenderedDeltas($rendered_widget['widget']));
    $this->assertSame(0, $rendered_widget['widget']['#max_delta']);

    $widget_state = $this->getWidgetState($prop_source, $form_state, $widget_plugin_id);
    $this->assertSame(0, $widget_state['items_count']);
  }

  /**
   * Provides widget type configurations for multivalue normalization tests.
   */
  public static function providerWidgetTypes(): \Generator {
    yield 'string_textfield' => [
      'field_type' => 'string',
      'prop_name' => 'value',
      'widget_plugin_id' => 'string_textfield',
      'values' => ['Alpha', 'Beta'],
      'field_storage_settings' => [],
      'field_instance_settings' => [],
    ];
    yield 'integer' => [
      'field_type' => 'integer',
      'prop_name' => 'value',
      'widget_plugin_id' => 'number',
      'values' => [10, 20],
      'field_storage_settings' => [],
      'field_instance_settings' => [],
    ];
    yield 'float' => [
      'field_type' => 'float',
      'prop_name' => 'value',
      'widget_plugin_id' => 'number',
      'values' => [3.14, 2.71],
      'field_storage_settings' => [],
      'field_instance_settings' => [],
    ];
    yield 'link_default' => [
      'field_type' => 'link',
      'prop_name' => 'url',
      'widget_plugin_id' => 'link_default',
      'values' => [
        ['uri' => '/foo', 'options' => []],
        ['uri' => '/bar', 'options' => []],
      ],
      'field_storage_settings' => [],
      'field_instance_settings' => [
        'title' => 0,
        'link_type' => LinkItemInterface::LINK_GENERIC,
      ],
    ];
  }

  /**
   * Builds an unlimited multivalue prop source.
   */
  private static function buildMultivaluePropSource(string $field_type, string $prop_name, array $values = [], array $field_storage_settings = [], array $field_instance_settings = []): StaticPropSource {
    $prop_source = StaticPropSource::generate(
      new FieldTypePropExpression($field_type, $prop_name),
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      $field_storage_settings ?: NULL,
      $field_instance_settings ?: NULL,
    );

    return $values !== [] ? $prop_source->withValue($values) : $prop_source;
  }

  /**
   * Renders a widget for the test prop source.
   */
  private function renderWidget(StaticPropSource $prop_source, FormState $form_state, string $widget_plugin_id): array {
    $widget = $prop_source->getWidget(
      'irrelevant-for-this-test',
      'irrelevant-for-this-test',
      self::FIELD_NAME,
      $this->randomString(),
      $widget_plugin_id,
    );

    $form = ['#parents' => []];
    return $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, self::FIELD_NAME, FALSE, NULL, $form, $form_state);
  }

  /**
   * Creates a form state suitable for prop source widget rendering.
   */
  private static function createFormState(): FormState {
    $form_state = new FormState();
    $form_state->setFormObject(new StubForm('some_id', []));
    return $form_state;
  }

  /**
   * Gets the stored widget state for a widget under test.
   */
  private function getWidgetState(StaticPropSource $prop_source, FormState $form_state, string $widget_plugin_id): array {
    $widget = $prop_source->getWidget(
      'irrelevant-for-this-test',
      'irrelevant-for-this-test',
      self::FIELD_NAME,
      $this->randomString(),
      $widget_plugin_id,
    );
    $widget_state = $widget::getWidgetState([], self::FIELD_NAME, $form_state);
    \assert(\is_array($widget_state));
    return $widget_state;
  }

  /**
   * Returns the numeric row deltas rendered in the widget form.
   *
   * @return list<int>
   *   The rendered row deltas.
   */
  private static function getRenderedDeltas(array $widget): array {
    $deltas = [];
    foreach (Element::children($widget) as $key) {
      if (\is_numeric($key)) {
        $deltas[] = (int) $key;
      }
    }
    return $deltas;
  }

}
