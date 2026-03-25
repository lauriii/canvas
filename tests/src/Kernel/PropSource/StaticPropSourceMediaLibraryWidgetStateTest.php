<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\media\Entity\Media;
use Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget;
use Drupal\Tests\system\Functional\Form\StubForm;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that MediaLibraryWidget state is initialized with existing items.
 *
 * When a multi-cardinality media_library_widget prop source calls
 * formTemporaryRemoveThisExclamationExclamationExclamation(), the widget state
 * must be pre-populated with the existing field items so that AJAX rebuilds
 * do not lose them.
 *
 * @see \Drupal\canvas\PropSource\StaticPropSource::formTemporaryRemoveThisExclamationExclamationExclamation()
 * @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::form()
 * @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::addItems()
 */
#[CoversClass(StaticPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
#[RunTestsInSeparateProcesses]
class StaticPropSourceMediaLibraryWidgetStateTest extends PropSourceTestBase {

  /**
   * Expression for an image-typed entity_reference field (single bundle).
   */
  private const EXPRESSION = "ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}";

  /**
   * Builds a StaticPropSource for a media_library_widget with the given cardinality.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED|int<1, max>|null $cardinality
   *   Field cardinality: -1 for unlimited, positive int for a fixed limit, or NULL.
   */
  private function buildMediaPropSource(?int $cardinality = FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED): StaticPropSource {
    $expression = StructuredDataPropExpression::fromString(self::EXPRESSION);
    \assert($expression instanceof FieldTypeBasedPropExpressionInterface);
    return StaticPropSource::generate(
      $expression,
      $cardinality,
      ['target_type' => 'media'],
      [
        'handler' => 'default:media',
        'handler_settings' => ['target_bundles' => ['image' => 'image']],
      ],
    );
  }

  /**
   * Widget state IS initialized with existing items on first call.
   *
   * This is the primary regression guard: without the initialization logic,
   * AJAX rebuilds would forget about existing media items and the component
   * would show zero images after a page reload + add operation.
   */
  public function testWidgetStateIsInitializedWithExistingItems(): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);

    $media1 = Media::load(1);
    $media2 = Media::load(2);
    \assert($media1 !== NULL && $media2 !== NULL);

    $prop_source = $this->buildMediaPropSource()->withValue([
      ['target_id' => (int) $media1->id()],
      ['target_id' => (int) $media2->id()],
    ]);

    $widget = $prop_source->getWidget('irrelevant', 'irrelevant', 'my_prop', $this->randomString(), 'media_library_widget');
    $form = ['#parents' => []];
    $form_state = new FormState();
    $form_state->setFormObject(new StubForm('some_id', []));

    $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'my_prop', FALSE, User::create([]), $form, $form_state);

    $widget_state = MediaLibraryWidget::getWidgetState([], 'my_prop', $form_state);

    $this->assertArrayHasKey('items', $widget_state, 'Widget state must have an "items" key after initialization.');
    $this->assertSame(
      [
        ['target_id' => (int) $media1->id(), 'weight' => 0],
        ['target_id' => (int) $media2->id(), 'weight' => 1],
      ],
      $widget_state['items'],
    );
  }

  /**
   * Widget state is NOT overwritten when already set (idempotent on rebuilds).
   *
   * On AJAX rebuilds, the form state already contains the latest items. The
   * initialization must not overwrite them, otherwise user selections made
   * during the AJAX interaction would be lost.
   */
  public function testWidgetStateIsNotOverwrittenOnRebuild(): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);

    $media1 = Media::load(1);
    \assert($media1 !== NULL);

    $prop_source = $this->buildMediaPropSource()->withValue([
      ['target_id' => (int) $media1->id()],
    ]);

    $widget = $prop_source->getWidget('irrelevant', 'irrelevant', 'my_prop', $this->randomString(), 'media_library_widget');
    $form = ['#parents' => []];
    $form_state = new FormState();
    $form_state->setFormObject(new StubForm('some_id', []));

    // Pre-populate widget state with a different set of items, simulating a
    // prior AJAX interaction where the user already added/removed items.
    $pre_existing_items = [
      ['target_id' => 999, 'weight' => 0],
    ];
    MediaLibraryWidget::setWidgetState([], 'my_prop', $form_state, ['items' => $pre_existing_items]);

    $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'my_prop', FALSE, User::create([]), $form, $form_state);

    $widget_state = MediaLibraryWidget::getWidgetState([], 'my_prop', $form_state);
    $this->assertSame($pre_existing_items, $widget_state['items'], 'Pre-existing widget state items must not be overwritten.');
  }

  /**
   * Widget state is NOT initialized for cardinality=1 fields.
   *
   * The initialization only applies to multi-cardinality fields. Single-value
   * fields use a different code path in MediaLibraryWidget and do not rely on
   * the 'items' widget state key.
   */
  public function testWidgetStateIsNotInitializedForCardinalityOne(): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);

    $media1 = Media::load(1);
    \assert($media1 !== NULL);

    $prop_source = $this->buildMediaPropSource(cardinality: 1)->withValue(['target_id' => (int) $media1->id()]);

    $widget = $prop_source->getWidget('irrelevant', 'irrelevant', 'my_prop', $this->randomString(), 'media_library_widget');
    $form = ['#parents' => []];
    $form_state = new FormState();
    $form_state->setFormObject(new StubForm('some_id', []));

    $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'my_prop', FALSE, User::create([]), $form, $form_state);

    $widget_state = MediaLibraryWidget::getWidgetState([], 'my_prop', $form_state);
    $this->assertArrayNotHasKey('items', $widget_state, 'Widget state must NOT have an "items" key for cardinality=1 fields.');
  }

  /**
   * Widget state is NOT initialized when the field has no items.
   *
   * An empty field has nothing to preserve; initializing widget state with an
   * empty array would interfere with the widget's own empty-state handling.
   */
  public function testWidgetStateIsNotInitializedForEmptyField(): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);

    // buildMediaPropSource() produces a prop source with no value.
    $prop_source = $this->buildMediaPropSource();

    $widget = $prop_source->getWidget('irrelevant', 'irrelevant', 'my_prop', $this->randomString(), 'media_library_widget');
    $form = ['#parents' => []];
    $form_state = new FormState();
    $form_state->setFormObject(new StubForm('some_id', []));

    $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'my_prop', FALSE, User::create([]), $form, $form_state);

    $widget_state = MediaLibraryWidget::getWidgetState([], 'my_prop', $form_state);
    $this->assertArrayNotHasKey('items', $widget_state, 'Widget state must NOT have an "items" key when the field is empty.');
  }

}
