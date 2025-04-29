<?php

declare(strict_types=1);

namespace Drupal\xb_test_storage_prop_shape_alter\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropShape\CandidateStorablePropShape;

class XbTestStoragePropShapeAlterHooks {

  /**
   * Implements hook_storage_prop_shape_alter().
   */
  #[Hook('storage_prop_shape_alter')]
  public function storagePropShapeAlter(CandidateStorablePropShape $storable_prop_shape): void {
    if ($storable_prop_shape->shape->schema == [
      'type' => 'string',
      'format' => 'uri',
    ]) {
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem::propertyDefinitions()
      // @phpstan-ignore-next-line
      $storable_prop_shape->fieldTypeProp = StructuredDataPropExpression::fromString('ℹ︎uri␟value');
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem::defaultFieldSettings()
      $storable_prop_shape->fieldInstanceSettings = \NULL;
      // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget
      $storable_prop_shape->fieldWidget = 'uri';
    }
  }

  /**
   * Implements hook_field_widget_info_alter().
   */
  #[Hook('field_widget_info_alter')]
  public function fieldWidgetInfoAlter(array &$info): void {
    $info['uri']['xb'] = ['transforms' => ['mainProperty' => []]];
  }

}
