<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validation constraint for `type: object` prop shapes.
 *
 * Enforces that an object prop shape declares exactly one of:
 * - `$ref`: one of the well-known object shapes, or
 * - `properties`: a custom object shape ("group"), at most 1 level deep, whose
 *   sub-properties are plain strings (no `contentMediaType`), scalars or
 *   well-known `$ref` shapes — never another inline object.
 *
 * @see `type: canvas.json_schema.prop_shape.object`
 * @see docs/adr/0021-object-props-in-code-components.md
 */
#[Constraint(
  id: 'ValidCanvasObjectPropShape',
  type: 'mapping',
)]
final class ValidCanvasObjectPropShapeConstraint extends SymfonyConstraint {

  public string $bothMessage = 'An object prop must declare exactly one of "$ref" or "properties", but both are declared.';

  public string $neitherMessage = 'An object prop must declare exactly one of "$ref" or "properties", but neither is declared.';

  public string $nestedObjectMessage = 'The "%sub_property" sub-property declares an inline object. Object props are limited to one level of depth: use a well-known "$ref" shape or a scalar type instead.';

  public string $contentMediaTypeMessage = 'The "%sub_property" sub-property declares "contentMediaType". Formatted text is not supported inside object props: use a plain string instead.';

  public string $requiredWithoutPropertiesMessage = 'The "required" key is only supported for object props that declare "properties".';

  public string $unknownRequiredMessage = 'The "required" key lists "%sub_property", which is not one of the declared "properties".';

}
