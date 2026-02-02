<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\canvas\PropExpressions\PropExpressionInterface;
use Drupal\canvas\PropSource\ContentAwareDependentInterface;

/**
 * @internal
 */
interface StructuredDataPropExpressionInterface extends PropExpressionInterface, ContentAwareDependentInterface {

  /**
   * {@inheritdoc}
   *
   * Structured data contains information, hence a prop expression type prefix
   * that conveys that: the Unicode information source symbol.
   */
  public const string PREFIX_EXPRESSION_TYPE = 'ℹ︎';

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
  // @todo Remove this constant in Canvas 2.0.0.
  // @see https://www.drupal.org/node/3563451
  const SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP = '␀';

  // References may point to different bundles of the same entity type. Each
  // bundle may contain different fields (of different field types and hence
  // different field properties) that populate the same prop shape.
  // For example: multiple Media entities referenced from a single field of
  // different MediaTypes (and different MediaSources), but they all can
  // populate a "video URL" prop shape.
  const PREFIX_BRANCH = '[';
  const SUFFIX_BRANCH = ']';

  /**
   * Assesses whether the given evaluation context is supported.
   *
   * @param \Drupal\Core\Entity\EntityInterface|\Drupal\Core\Field\FieldItemInterface|\Drupal\Core\Field\FieldItemListInterface $entity_or_field
   *   Possibilities are:
   *   - An entity when the expression starts in an entity.
   *   - A field item list when the expression starts in a multiple-cardinality
   *     field type.
   *   - A field item when the expression starts in a single-cardinality field
   *     type.
   *
   * @return void
   */
  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $entity_or_field): void;

}
