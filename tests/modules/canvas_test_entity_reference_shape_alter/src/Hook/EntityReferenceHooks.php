<?php

declare(strict_types=1);

namespace Drupal\canvas_test_entity_reference_shape_alter\Hook;

use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropShape\CandidateStorablePropShape;
use Drupal\Core\Hook\Attribute\Hook;

final class EntityReferenceHooks {

  /**
   * Implements hook_canvas_storable_prop_shape_alter().
   */
  #[Hook('canvas_storable_prop_shape_alter')]
  public function storablePropShapeAlter(CandidateStorablePropShape $storable_prop_shape): void {
    if ($storable_prop_shape->shape->schema === ['type' => 'string', 'minLength' => 10]) {
      $storable_prop_shape->fieldTypeProp = ReferenceFieldTypePropExpression::fromString('ℹ︎entity_reference␟entity␜␜entity:user␝name␞␟value');
      $storable_prop_shape->fieldInstanceSettings = [];
      $storable_prop_shape->fieldStorageSettings = ['target_type' => 'user'];
      $storable_prop_shape->fieldWidget = 'entity_reference_autocomplete';
    }
  }

}
