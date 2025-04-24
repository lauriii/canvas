<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropShape\StorablePropShape;

/**
 * @covers \Drupal\experience_builder\PropShape\PropShape::getStorage()
 * @group experience_builder
 */
class HookStoragePropAlterTest extends PropShapeRepositoryTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // @see xb_test_storage_prop_shape_alter_storage_prop_shape_alter()
    // @see xb_test_storage_prop_shape_alter_field_widget_info_alter()
    'xb_test_storage_prop_shape_alter',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getExpectedStorablePropShapes(): array {
    $storable_prop_shapes = parent::getExpectedStorablePropShapes();
    $storable_prop_shapes['type=string&format=uri'] = new StorablePropShape(
      shape: $storable_prop_shapes['type=string&format=uri']->shape,
      fieldTypeProp: new FieldTypePropExpression('uri', 'value'),
      fieldWidget: 'uri',
    );
    return $storable_prop_shapes;
  }

}
