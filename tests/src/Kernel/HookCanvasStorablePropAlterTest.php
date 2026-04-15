<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Hook Canvas Storable Prop Alter.
 *
 * @legacy-covers \Drupal\canvas\PropShape\PersistentPropShapeRepository
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
class HookCanvasStorablePropAlterTest extends PropShapeRepositoryTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // 1. Test CHANGING a Canvas decision about a prop shape.
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::fieldWidgetInfoAlter()
    // 2. Test ADDING support for an arbitrary prop shape.
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType\MultipleOfItem
    'canvas_test_storable_prop_shape_alter',
    // 3. Test ADDING support for a well-known prop shape.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::datetimeRangeStorablePropShapeAlter()
    'datetime_range',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getExpectedStorablePropShapes(): array {
    $storable_prop_shapes = parent::getExpectedStorablePropShapes();

    // 1. This proves it is possible toc change a Canvas decision:
    // - field type: `link` → `uri`
    // - … and field widget, too.
    $storable_prop_shapes['type=string&format=uri'] = new StorablePropShape(
      shape: $storable_prop_shapes['type=string&format=uri']->shape,
      fieldTypeProp: new FieldTypePropExpression('uri', 'value'),
      fieldWidget: 'uri',
    );

    // 1b. The `link` → `uri` alteration also cascades to the array variants.
    $storable_prop_shapes['type=array&items[type]=string&items[format]=uri'] = new StorablePropShape(
      shape: new PropShape(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']]),
      fieldTypeProp: new FieldTypePropExpression('uri', 'value'),
      cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      fieldWidget: 'uri',
    );
    $storable_prop_shapes['type=array&items[type]=string&items[format]=uri&maxItems=3'] = new StorablePropShape(
      shape: new PropShape(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri'], 'maxItems' => 3]),
      fieldTypeProp: new FieldTypePropExpression('uri', 'value'),
      cardinality: 3,
      fieldWidget: 'uri',
    );

    // 2. This proves it is possible to add support for an arbitrary (so not
    // well-known, no `$ref`) prop shape that Canvas does not natively support.
    $storable_prop_shapes['type=integer&multipleOf=12'] = new StorablePropShape(
      shape: parent::getExpectedUnstorablePropShapes()['type=integer&multipleOf=12'],
      fieldTypeProp: new FieldTypePropExpression('multiple_of', 'value'),
      fieldStorageSettings: ['must_be_divisible_by' => 12],
      fieldWidget: 'number',
    );

    // 3. This proves it is possible to add support for a well-known prop shape
    // (so: `$ref`) that Canvas does not natively support.
    $storable_prop_shapes['type=object&$ref=json-schema-definitions://canvas.module/date-range'] = new StorablePropShape(
      shape: parent::getExpectedUnstorablePropShapes()['type=object&$ref=json-schema-definitions://canvas.module/date-range'],
      // @phpstan-ignore argument.type
      fieldTypeProp: StructuredDataPropExpression::fromString('ℹ︎daterange␟{from↠value,to↠end_value}'),
      fieldStorageSettings: ['datetime_type' => DateTimeItem::DATETIME_TYPE_DATE],
      fieldWidget: 'daterange_default',
    );

    return $storable_prop_shapes;
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpectedUnstorablePropShapes(): array {
    $unstorable_prop_shapes = parent::getExpectedUnstorablePropShapes();
    unset($unstorable_prop_shapes['type=integer&multipleOf=12']);
    unset($unstorable_prop_shapes['type=object&$ref=json-schema-definitions://canvas.module/date-range']);
    return $unstorable_prop_shapes;
  }

}
