<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\experience_builder\PropExpressions\PropExpressionInterface;

interface StructuredDataPropExpressionInterface extends PropExpressionInterface {

  // Structured data contains information, hence a prefix that conveys that..
  const PREFIX = 'ℹ︎';

  // All prefixes for denoting the pieces inside structured data expressions.
  // @see https://github.com/SixArm/usv
  const PREFIX_ENTITY_LEVEL = '␜';
  const PREFIX_FIELD_LEVEL = '␝';
  const PREFIX_FIELD_ITEM_LEVEL = '␞';
  const PREFIX_PROPERTY_LEVEL = '␟';

  const PREFIX_OBJECT = '{';
  const SUFFIX_OBJECT = '}';
  const SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE = '↝';
  const SYMBOL_OBJECT_MAPPED_USE_PROP = '↠';

  public function isSupported(EntityInterface|FieldItemInterface $entity_or_field): bool;

}
