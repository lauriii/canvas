<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropSource\StaticPropSource;

/**
 * A storable prop shape: a prop shape with corresponding field type + widget.
 *
 * @todo Support a `hook_storable_prop_shape_alter()`, which does NOT allow modifying the schema, but does allow modifying the field type, widget and storage settings. There is a risk here wrt deps, but the fact that after https://www.drupal.org/project/experience_builder/issues/3463999, dependency checking will be handled by config dependencies for us. 👍
 */
final class StorablePropShape {

  public function __construct(
    public readonly PropShape $shape,
    // The corresponding UX for the prop shape:
    // - field type to use + which field properties to extract from an instance of the field type
    public readonly FieldTypePropExpression|FieldTypeObjectPropsExpression $fieldTypeProp,
    // - which widget to use to populate an instance of the field type
    public readonly string $fieldWidget,
    // - (optionally) which storage settings to specify for an instance of this
    //   field type — crucial for e.g. the `enum` use case
    public readonly ?array $fieldStorageSettings = NULL,
  ) {
    // In theory, this could be validated: `$this->fieldTypeProp->fieldType` is
    // a field type plugin ID, which determines which field widgets
    // (`$this->fieldWidget`) would be acceptable, and what
    // `$this->fieldStorageSettings`, if any, would be acceptable.
    // In practice, we leave this to the Component config entity, because that
    // is where these values of the StorablePropShape object are persisted.
    // @see \Drupal\experience_builder\Entity\Component
    // @see `type: experience_builder.component.*`.
  }

  public function toStaticPropSource(): StaticPropSource {
    return StaticPropSource::generate($this->fieldTypeProp, $this->fieldStorageSettings);
  }

}
