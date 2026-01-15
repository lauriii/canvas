<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions;

/**
 * Prop expressions succinctly describe >=1 properties of an object.
 *
 * They can be converted to and from string representations.
 *
 * @internal
 */
interface PropExpressionInterface extends \Stringable {

  /**
   * The prop expression type prefix.
   *
   * The purpose of this prefix is to:
   * - provide a string representation for every expression (storage)
   * - make that string representation easily greppable (DX)
   * - avoid collisions with other kinds of expressions (correctness)
   *
   * In other words: it is a namespacing mechanism for prop expressions that
   * also serves other purposes.
   */
  public const ?string PREFIX_EXPRESSION_TYPE = NULL;

  public static function fromString(string $representation): static;

}
