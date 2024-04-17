<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;

/**
 * Architectural Decision Record
 *
 * Since instantiated components in:
 * - content type templates
 * - content entities
 * must be able to map values from structured data (entity field props) into
 * component props, and many APIs and layers are involved in doing this:
 * - correctly
 * - securely
 * - performantly
 * It seems sensible to use a strongly typed approach to representing these
 * expressions.
 *
 * Furthermore, the Experience Builder UX must make it easy to surface viable
 * matches from the structured data that can fit in the components, as well as
 * the other way around.
 *
 * Therefore a base expression interface is provided, which guarantees a
 * stringable representation (simplifying both debugging as well as storing
 * these expressions), *and* the conversion back.
 * In other words: every possible expression used by Experience Builder can
 * always be converted from string to PHP object and vice versa.
 *
 * String representations of prop expressions probing into:
 * - components will always start with the symbol `⿲`
 * - structured data will always start with the symbol `ℹ`
 *
 *
 * String and storage representation of expressions referencing field types,
 * field instances, fields aka field item lists, field deltas aka field items,
 * field item properties:
 * - `␟` is the field item VS property name separator, because a field property
 *   is the smallest unit
 * - `␞` then is the field item list vs field item separator
 * - `␝` then is the field item list vs field item separator
 *
 * @see https://github.com/SixArm/usv
 */

interface PropExpressionInterface extends \Stringable {
  public static function fromString(string $representation);
}

interface ComponentPropExpressionInterface extends PropExpressionInterface {
  // Components are for graphical representations.
  const PREFIX = '⿲';
}

interface StructuredDataPropExpressionInterface extends PropExpressionInterface {
  // Structured data contains information.
  const PREFIX = 'ℹ︎';
}

// For pointing to a prop in a component.
final class ComponentPropExpression implements ComponentPropExpressionInterface {
  public function __construct(
    public readonly string $componentName,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟%s", $this->componentName, $this->propName);
  }

  public static function fromString(string $representation): static {
    $parts = explode('␟', mb_substr($representation, 1));
    return new static(...$parts);
  }

}

// For pointing to a prop in a field type (not considering any delta).
class FieldTypePropExpression implements StructuredDataPropExpressionInterface {
  public function __construct(
    public readonly string $fieldType,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟%s", $this->fieldType, $this->propName);
  }

  public static function fromString(string $representation): static {
    $parts = explode('␟', mb_substr($representation, 1));
    return new static(...$parts);
  }

}

// For pointing to a prop in a field type (not considering any delta).
final class ReferenceFieldTypePropExpression extends FieldTypePropExpression {
  public function __construct(
    public readonly string $fieldType,
    public readonly string $propName,
    public readonly FieldPropExpression $referenced,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␜%s", mb_substr(parent::__toString(), 1), mb_substr((string) $this->referenced, 1));
  }

  public static function fromString(string $representation): static {
    throw \Exception('todo');
  }

}

// For pointing to a prop in a concrete field.
final class FieldPropExpression implements StructuredDataPropExpressionInterface {
  public function __construct(
    // @todo will this break down once we support config entities? It must, because top-level config entity props ~= content entity fields, but deeper than that it is different.
    public readonly EntityDataDefinition $entityType,
    public readonly string $fieldName,
    // A content entity field item delta is optional.
    // @todo Should this allow expressing "all deltas"? Should that be represented using `NULL`, `TRUE`, `*` or `∀`? For now assuming NULL.
    public readonly int|null $delta,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "␜%s␝%s␞%s␟%s", $this->entityType->getDataType(), $this->fieldName, $this->delta ?? '', $this->propName);
  }

  public function withDelta(int $delta): static {
    return new static(
      $this->entityType,
      $this->fieldName,
      $delta,
      $this->propName,
    );
  }

  public static function fromString(string $representation): static {
    throw \Exception('todo');
  }

}

final class ReferenceFieldPropExpression implements StructuredDataPropExpressionInterface {

  public function __construct(
    public readonly FieldPropExpression $referencer,
    public readonly ReferenceFieldPropExpression|FieldPropExpression $referenced,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␜%s", mb_substr((string)$this->referencer, 1), mb_substr((string) $this->referenced, 1));
  }

  public static function fromString(string $representation): static {
    throw \Exception('todo');
  }

}
