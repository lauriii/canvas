<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

/**
 * @internal
 */
trait CompoundExpressionTrait {

  /**
   * Alias to improve code readability in this trait.
   */
  const PREFIX_BRANCH = StructuredDataPropExpressionInterface::PREFIX_BRANCH;

  /**
   * Alias to improve code readability in this trait.
   */
  const SUFFIX_BRANCH = StructuredDataPropExpressionInterface::SUFFIX_BRANCH;

  /**
   * Strips the structured data prop expression type prefix `ℹ`.
   *
   * @param string $representation
   *   A string.
   *
   * @return string
   *   The same string without the `ℹ` prefix.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface::PREFIX_EXPRESSION_TYPE
   */
  private static function withoutExpressionTypePrefix(string $representation): string {
    \assert(mb_substr($representation, 0, 2) === StructuredDataPropExpressionInterface::PREFIX_EXPRESSION_TYPE);
    return mb_substr($representation, mb_strlen(StructuredDataPropExpressionInterface::PREFIX_EXPRESSION_TYPE));
  }

  /**
   * Determines the max branch depth for any valid representation string.
   *
   * @param string $representation
   *   String representation of expression containing branches
   *   ([branch1][branch2]) such as
   *   @code
   *   [␜entity:media:anything_is_possible␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value]
   *   @endcode
   *
   * @return int
   *   The max branching depth. For example:
   *   - "[branch1][branch2]" has a max depth of 1
   *   - "[branch1[branch1a][branch1b]][branch2]" has a max depth of 2
   *   - et cetera
   *
   * @throws \LogicException
   *   If an unopened branch is being closed (`…]…` or `[…]…]` …), or an opened
   *   branch is not being closed (`…[…` or `[…[…]` …). These are invalid (or
   *   even nonsensical) string representations of prop expressions.
   *
   * @see ::parseBranches()
   */
  private static function computeMaxBranchDepth(string $representation): int {
    $current_depth = 0;
    $max_depth = 0;
    $currently_open = FALSE;
    $offset = 0;
    while ($offset < mb_strlen($representation)) {
      $next_open = mb_strpos($representation, self::PREFIX_BRANCH, $offset);
      $next_close = mb_strpos($representation, self::SUFFIX_BRANCH, $offset);

      // If both a prefix and suffix are found, pick the one with the earliest
      // position in the string, let the other be handled in a subsequent
      // iteration.
      if (is_int($next_open) && is_int($next_close)) {
        if ($next_open < $next_close) {
          $next_close = FALSE;
        }
        else {
          $next_open = FALSE;
        }
      }

      if ($next_open !== FALSE) {
        $currently_open = TRUE;
        $current_depth++;
        $max_depth = max($max_depth, $current_depth);
        $offset = $next_open + 1;
      }

      if ($next_close !== FALSE) {
        if ($current_depth === 0) {
          throw new \LogicException('Closing unopened branch.');
        }
        $current_depth--;
        if ($current_depth === 0) {
          $currently_open = FALSE;
        }
        $offset = $next_close + 1;
      }
    }

    if ($currently_open) {
      throw new \LogicException('Unclosed branch');
    }

    return $max_depth;
  }

  /**
   * Parses (unnested) branches.
   *
   * @param string $branches_as_string
   *   A string such as
   *   @code
   *   [␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:remote_image␝field_media_oembed_image␞␟non_existent_computed_property]
   *   @endcode
   *
   * @return non-empty-list<string>
   *   An array of strings such as
   *   @code
   *   ␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value
   *   ␜entity:media:remote_image␝field_media_oembed_image␞␟non_existent_computed_property
   *   @endcode
   */
  private static function parseBranches(string $branches_as_string): array {
    if (self::computeMaxBranchDepth($branches_as_string) > 1) {
      throw new \LogicException('Nested branching is not supported.');
    }
    \assert(mb_substr($branches_as_string, 0, 1) === self::PREFIX_BRANCH);
    \assert(mb_substr($branches_as_string, -1) === self::SUFFIX_BRANCH);
    $branch_count = mb_substr_count($branches_as_string, self::PREFIX_BRANCH);

    // Omit the first branch prefix ("opening") and the last branch suffix
    // ("closing").
    $without_branch_prefix = mb_substr($branches_as_string, mb_strlen(self::PREFIX_BRANCH));
    $without_branch_prefix_and_suffix = mb_substr($without_branch_prefix, 0, mb_strlen($without_branch_prefix) - mb_strlen(self::PREFIX_BRANCH));
    $branches = explode(self::SUFFIX_BRANCH . self::PREFIX_BRANCH, $without_branch_prefix_and_suffix);
    \assert(count($branches) === $branch_count);
    return $branches;
  }

  /**
   * Gets the root expression from the given string representation expression.
   *
   * The root expression is the one that starts at position zero, and without
   * any composition. For example, the Reference* expressions are compound:
   * ReferenceFieldPropExpression uses FieldPropExpression, and
   * ReferenceFieldTypePropExpression uses FieldTypePropExpression.
   *
   * For example, for the expression
   * @code
   * ℹ︎image␟entity␜␜entity:file␝uri␞0␟{stream_wrapper_uri↠value,public_url↠url}
   * @endcode
   *
   * The top-level expression is
   * @code
   * ℹ︎image␟entity
   * @endcode
   *
   * And for
   * @code
   * ℹ︎␜entity:file␝uri␞0␟{stream_wrapper_uri↠value,public_url↠url}
   * @endcode
   *
   * it is that entire expression.
   *
   * @return string
   *   A substring of $expression_representation, representing the root
   *   expression.
   */
  private static function parseRootExpression(string $expression_representation): string {
    // Every expression representation MUST contains a property prefix (`␟`).
    $property_prefix_pos = mb_strpos($expression_representation, StructuredDataPropExpressionInterface::PREFIX_PROPERTY_LEVEL);
    \assert(is_int($property_prefix_pos) && $property_prefix_pos < mb_strlen($expression_representation) - 1);

    // In case of an *ObjectProps expression, the first character after the
    // property prefix (`␟`) will be an open curly brace (`{`). Consequently
    // the corresponding matching closing brace (`}`) must be found, and is part
    // of this expression.
    // @code
    // ℹ︎␜entity:node␝title␞0␟{label↠value}
    // @endcode
    if (mb_substr($expression_representation, $property_prefix_pos + 1, 1) === StructuredDataPropExpressionInterface::PREFIX_OBJECT) {
      // Find the matching closing brace: simply the first one.
      // @todo If nested object expressions must one day be supported, this logic will need to be updated, because the closing brace will not be the first one anymore.
      $closing_brace_pos = mb_strpos($expression_representation, StructuredDataPropExpressionInterface::SUFFIX_OBJECT, $property_prefix_pos);
      return mb_substr($expression_representation, 0, $closing_brace_pos + 1);
    }

    // In case of a Reference* expression, the next character will be an entity
    // prefix (`␜`).
    $entity_prefix_pos = mb_strpos($expression_representation, StructuredDataPropExpressionInterface::PREFIX_ENTITY_LEVEL, $property_prefix_pos);
    return $entity_prefix_pos === FALSE
      // No entity prefix present : this already is the top-level expression:
      // @code
      // ℹ︎image␟entity
      // @endcode
      ? $expression_representation
      // Entity prefix is present, for example:
      // @code
      // ℹ︎image␟entity␜␜entity:file␝filemime␞0␟value
      // @endcode
      // Which means the top-level is:
      // @code
      // ℹ︎image␟entity
      // @endcode
      : mb_substr($expression_representation, 0, $entity_prefix_pos);
  }

}
