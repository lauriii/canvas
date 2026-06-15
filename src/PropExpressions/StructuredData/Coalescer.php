<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;

/**
 * Coalesces and expands sets of scalar prop expressions.
 *
 * `::coalesce()` rewrites a list of scalar `PropExpression` strings into an
 * equivalent — but more compact — list whose entries merge overlapping
 * expressions. `::expand()` is its inverse: it returns the list to the atomic
 * form, with each entry targeting a single field property.
 *
 * Without coalescing, any consumer that keys an expression by its starting
 * point — its `(host, field, delta)` field item, or its reference chain —
 * collides whenever multiple sub-property expressions share that starting
 * point, and silently loses all but one of them.
 *
 * Three coalescing flavors are performed:
 * - Expressions overlapping at the field-item (delta) point but pointing to
 *   different non-reference field properties (`FieldPropExpression`) or descend
 *   into a reference field property (`ReferenceFieldPropExpression`) are merged
 *   into a single `FieldObjectPropsExpression`.
 *   Non-reference field properties are leaves that can be evaluated (`↠` in the
 *   string representation for the object expression), but reference field
 *   properties aren't (`↝`).
 *   Note that after following a reference, multiple field properties can be
 *   picked there, which means one `FieldObjectPropsExpression` can contain
 *   another `FieldObjectPropsExpression` via a reference.
 * - Reference expressions (`ReferenceFieldPropExpression`s) that share the same
 *   ancestry (same starting point, then overlapping reference chains up to some
 *   point) are coalesced into a `ReferenceFieldPropExpression` with a
 *   `FieldObjectPropsExpression` final target.
 * - Single-bundle reference expressions sharing a reference chain but
 *   targeting different bundles merge into a `ReferenceFieldPropExpression`
 *   whose `referenced` is a `ReferencedBundleSpecificBranches`.
 *
 * @internal
 */
final class Coalescer {

  /**
   * Coalesces a list of scalar prop-expression strings.
   *
   * Three flavors of coalescing are performed:
   * - `FieldPropExpression` + `FieldPropExpression` (or +
   *   `ReferenceFieldPropExpression`, whose "referencer" also is just another
   *   `FieldPropExpression`) sharing `(host, field, delta)` →
   *   `FieldObjectPropsExpression`:
   *   @code
   *   // IN:
   *   ℹ︎␜entity:node:article␝uid␞␟url
   *   ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value
   *   // OUT:
   *   ℹ︎␜entity:node:article␝uid␞␟{name↝entity␜␜entity:user␝name␞␟value,url↠url}
   *   @endcode
   * - `ReferenceFieldPropExpression` + `ReferenceFieldPropExpression` sharing
   *   the same full reference chain and final target field →
   *   `ReferenceFieldPropExpression` with a `FieldObjectPropsExpression` final
   *   target:
   *   @code
   *   // IN:
   *   ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟width
   *   ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟height
   *   // OUT:
   *   ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{height↠height,width↠width}
   *   @endcode
   * - Single-bundle `ReferenceFieldPropExpression` + single-bundle
   *   `ReferenceFieldPropExpression` sharing the same referencer but targeting
   *   different bundles → `ReferenceFieldPropExpression` with a
   *   `ReferencedBundleSpecificBranches` `referenced`:
   *   @code
   *   // IN:
   *   ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value
   *   ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝name␞␟value
   *   // OUT:
   *   ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]
   *   @endcode
   *   The `ReferencedBundleSpecificBranches` constructor validates that all
   *   branches evaluate to the same shape; entries that fail pass through
   *   unchanged for constraint validators to report.
   *
   * Already-coalesced multi-bundle `ReferenceFieldPropExpression` entries pass
   * through unchanged.
   *
   * @param list<string> $expression_strings
   *   The scalar expression strings to coalesce, each targeting a single field
   *   property.
   *
   * @return list<string>
   *   The coalesced list of expression strings.
   */
  public static function coalesce(array $expression_strings): array {
    \assert(\array_is_list($expression_strings));
    \assert(Inspector::assertAllStrings($expression_strings));

    $parsed = [];
    foreach ($expression_strings as $expression_string) {
      // Let parse errors propagate — the ValidStructuredDataPropExpression
      // constraint catches invalid strings on save.
      $parsed[] = StructuredDataPropExpression::fromString($expression_string);
    }

    // Buckets:
    // - $host_groups: loose host-entity field expressions grouped by
    //   host+field. Coalesced references descending through a field that also
    //   has a loose bucket are folded in later.
    // - $ref_groups:  reference expressions grouped by full chain + final
    //   field.
    // - $passthrough: anything we deliberately do not try to coalesce
    //   (multi-bundle references, today).
    /** @var array<string, list<FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression>> $host_groups */
    $host_groups = [];
    /** @var array<string, list<ReferenceFieldPropExpression>> $ref_groups */
    $ref_groups = [];
    $passthrough = [];
    foreach ($parsed as $expression) {
      if ($expression instanceof FieldPropExpression || $expression instanceof FieldObjectPropsExpression) {
        $host_groups[$expression->getStartingPointKey()][] = $expression;
        continue;
      }
      if ($expression instanceof ReferenceFieldPropExpression && !$expression->targetsMultipleBundles()) {
        $final_target = $expression->getFinalTargetExpression();
        // Group by `<full reference chain>|<final target host|field|delta>` so
        // only expressions sharing the same chain AND the same final field
        // end up in one bucket.
        $ref_groups[$expression->getFullReferenceChain() . '|' . $final_target->getStartingPointKey()][] = $expression;
        continue;
      }
      $passthrough[] = $expression;
    }

    $coalesced = \array_map(static fn (object $expression): string => (string) $expression, $passthrough);

    // Coalesce reference groups: same chain + same final field → one
    // ReferenceFieldPropExpression with a FieldObjectPropsExpression as final
    // target. Collect results as objects for the subsequent folding and
    // branch passes.
    /** @var list<ReferenceFieldPropExpression> $coalesced_refs */
    $coalesced_refs = [];
    foreach ($ref_groups as $group_expressions) {
      if (\count($group_expressions) === 1) {
        $coalesced_refs[] = $group_expressions[0];
        continue;
      }
      /** @var list<FieldPropExpression|FieldObjectPropsExpression> $final_targets */
      $final_targets = [];
      foreach ($group_expressions as $expression) {
        $final_target = $expression->getFinalTargetExpression();
        // `getFinalTargetExpression()` is declared to return the interface
        // union; in practice for a ReferenceFieldPropExpression's leaf the
        // only concrete implementations are FieldPropExpression and
        // FieldObjectPropsExpression (the only types coalesceSameFieldGroup
        // knows how to merge).
        \assert($final_target instanceof FieldPropExpression || $final_target instanceof FieldObjectPropsExpression);
        $final_targets[] = $final_target;
      }
      $coalesced_target = self::coalesceSameFieldGroup($final_targets);
      if ($coalesced_target === NULL) {
        // Same-property collision across the reference: pass through verbatim,
        // leaving the validation layer to flag the duplicate.
        foreach ($group_expressions as $expression) {
          $coalesced[] = (string) $expression;
        }
        continue;
      }
      $coalesced_refs[] = $group_expressions[0]->withFinalTargetReplaced($coalesced_target);
    }

    // Fold coalesced references into the loose bucket on their referencer
    // field, when one exists. A loose expression and a reference descending
    // through the same field share a starting point, so any consumer keying
    // by starting point would silently lose one of them. The reference becomes
    // a follow-reference (`↝`) object entry, named by its final target's
    // developer-facing key.
    $standalone_refs = [];
    foreach ($coalesced_refs as $ref) {
      $referencer_key = $ref->getStartingPointKey();
      if (\array_key_exists($referencer_key, $host_groups)) {
        $host_groups[$referencer_key][] = $ref;
        continue;
      }
      $standalone_refs[] = $ref;
    }

    // Coalesce loose host-entity field groups (including folded references).
    foreach ($host_groups as $group_expressions) {
      $coalesced_one = self::coalesceSameFieldGroup($group_expressions);
      if ($coalesced_one === NULL) {
        // Pass un-coalescable entries through verbatim — the validator will
        // flag them as duplicates on the same field.
        foreach ($group_expressions as $expression) {
          $coalesced[] = (string) $expression;
        }
        continue;
      }
      $coalesced[] = (string) $coalesced_one;
    }

    // Coalesce branches: single-bundle reference expressions that share the
    // same referencer but target different bundles merge into one
    // ReferenceFieldPropExpression with ReferencedBundleSpecificBranches.
    $coalesced = [...$coalesced, ...self::coalesceBranches($standalone_refs)];

    return $coalesced;
  }

  /**
   * Expands a list of coalesced expression strings back to atomic leaves.
   *
   * The atomic form is always per-property entries: one `FieldPropExpression`
   * per simple field property, one `ReferenceFieldPropExpression` (with a
   * `FieldPropExpression` final target) per reference-chain leaf. Being the
   * exact inverse of `::coalesce()` means callers never have to parse or
   * assemble expression strings themselves.
   *
   * Expanding drops object-prop names (the atomic form has none). For
   * canonically-named objects — all `::coalesce()` produces — re-coalescing
   * restores them from each leaf's developer-facing key, so
   * `coalesce(expand())` is the identity. Custom-named objects do not
   * round-trip this way.
   *
   * @param list<string> $expression_strings
   *
   * @return list<string>
   */
  public static function expand(array $expression_strings): array {
    \assert(\array_is_list($expression_strings));
    \assert(Inspector::assertAllStrings($expression_strings));

    $expanded = [];
    foreach ($expression_strings as $expression_string) {
      $parsed = StructuredDataPropExpression::fromString($expression_string);
      // entityFields entries are restricted by config schema to one of these
      // three expression types.
      // @see canvas.schema.yml (canvas.js_component.*: dataDependencies.entityFields)
      \assert(
        $parsed instanceof FieldPropExpression
        || $parsed instanceof FieldObjectPropsExpression
        || $parsed instanceof ReferenceFieldPropExpression
      );
      foreach (self::toLeafExpressions($parsed) as $leaf) {
        $expanded[] = (string) $leaf;
      }
    }
    return $expanded;
  }

  /**
   * Merges same-host-and-field expressions into a single combined expression.
   *
   * Used both for direct host-entity expressions (in which case the result is
   * substituted as-is in the list) and for the final-target leaf of
   * reference-chain expressions (in which case the caller re-wraps the result
   * via `ReferenceFieldPropExpression::withFinalTargetReplaced()`).
   *
   * @param list<FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression> $group_expressions
   *   All expressions on the same `(host, field, delta)` field item. A
   *   ReferenceFieldPropExpression entry descends through that field item; it
   *   is folded in as a follow-reference (`↝`) object entry.
   *
   * @return \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression|\Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression|null
   *   The combined expression — a `FieldPropExpression` when only one leaf
   *   property is referenced (no wrapping needed), or a
   *   `FieldObjectPropsExpression` when multiple are. NULL signals a
   *   same-property collision; the caller is responsible for emitting the
   *   un-coalesced entries so the validator can surface the duplicate.
   */
  private static function coalesceSameFieldGroup(array $group_expressions): FieldPropExpression|FieldObjectPropsExpression|NULL {
    /** @var array<string, FieldPropExpression|ReferenceFieldPropExpression> $flat */
    $flat = [];
    foreach ($group_expressions as $expression) {
      if ($expression instanceof FieldPropExpression) {
        \assert(\is_string($expression->propName));
        $leaf_name = $expression->getFieldPropertyName();
        if (\array_key_exists($leaf_name, $flat)) {
          return NULL;
        }
        $flat[$leaf_name] = $expression;
        continue;
      }
      if ($expression instanceof ReferenceFieldPropExpression) {
        // Name the follow-reference entry by its final target's developer-
        // facing key. That allows the coalesced result to losslessly be
        // expanded and re-coalesced.
        $leaf_name = $expression->getFinalTargetExpression()->getDeveloperFacingKey();
        if (\array_key_exists($leaf_name, $flat)) {
          return NULL;
        }
        $flat[$leaf_name] = $expression;
        continue;
      }
      foreach ($expression->objectPropsToFieldProps as $object_prop_name => $leaf_expression) {
        if (\array_key_exists($object_prop_name, $flat)) {
          return NULL;
        }
        $flat[$object_prop_name] = $leaf_expression;
      }
    }
    \assert($flat !== [], 'coalesceSameFieldGroup() must be called with at least one expression.');
    if (\count($flat) === 1) {
      $single = \reset($flat);
      if ($single instanceof FieldPropExpression) {
        return $single;
      }
      // A lone follow-reference entry stays wrapped in its object expression:
      // unwrapping it to a bare reference would change the evaluation result
      // (inline mapped value vs nested entity object).
    }
    \ksort($flat);
    $first = $group_expressions[0];
    return new FieldObjectPropsExpression(
      $first->getHostEntityDataDefinition(),
      $first->getFieldName(),
      $first->getDelta(),
      $flat,
    );
  }

  /**
   * Merges same-referencer-different-bundle references into one expression.
   *
   * Groups single-bundle reference expressions by their reference chain. When
   * a group spans multiple bundles AND each bundle appears exactly once, the
   * group is coalesced into a single `ReferenceFieldPropExpression` whose
   * `referenced` is a `ReferencedBundleSpecificBranches`. The constructor of
   * that class validates that all branches evaluate to the same shape (same
   * leaf expression class, field cardinality, delta presence); if validation
   * fails the entries pass through un-coalesced for the constraint validators
   * to flag.
   *
   * @param list<ReferenceFieldPropExpression> $refs
   *
   * @return list<string>
   */
  private static function coalesceBranches(array $refs): array {
    if ($refs === []) {
      return [];
    }

    $chain_groups = [];
    foreach ($refs as $ref) {
      $chain_groups[$ref->getFullReferenceChain()][] = $ref;
    }

    $result = [];
    foreach ($chain_groups as $chain_refs) {
      if (\count($chain_refs) === 1) {
        $result[] = (string) $chain_refs[0];
        continue;
      }

      $branches = [];
      $referencer = $chain_refs[0]->referencer;
      $collision = FALSE;
      foreach ($chain_refs as $ref) {
        \assert(!$ref->targetsMultipleBundles());
        \assert($ref->referenced instanceof EntityFieldBasedPropExpressionInterface);
        $branch_key = $ref->referenced->getHostEntityDataDefinition()->getDataType();
        if (\array_key_exists($branch_key, $branches)) {
          $collision = TRUE;
          break;
        }
        $branches[$branch_key] = $ref->referenced;
      }

      if ($collision || \count($branches) < 2) {
        foreach ($chain_refs as $ref) {
          $result[] = (string) $ref;
        }
        continue;
      }

      \ksort($branches);
      try {
        $bundle_branches = new ReferencedBundleSpecificBranches($branches);
        $result[] = (string) new ReferenceFieldPropExpression($referencer, $bundle_branches);
      }
      catch (\InvalidArgumentException) {
        foreach ($chain_refs as $ref) {
          $result[] = (string) $ref;
        }
      }
    }

    return $result;
  }

  /**
   * Returns the atomic leaf expressions a coalesced entry represents.
   *
   * @return list<FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression>
   *   One or more expressions, each targeting a single field property.
   */
  private static function toLeafExpressions(FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expression): array {
    // Object: expand every entry. A `↠` entry is already a FieldPropExpression
    // leaf; a `↝` entry is a reference that expands further. Object-prop names
    // are NOT preserved — the atomic form has no names. Only objects whose
    // names equal each leaf's developer-facing key survive `coalesce(expand())`
    // unchanged; for the rest, re-coalescing derives different names.
    if ($expression instanceof FieldObjectPropsExpression) {
      $leaves = [];
      foreach ($expression->objectPropsToFieldProps as $entry) {
        // An object entry is a `↠` leaf (FieldPropExpression) or a `↝`
        // reference (ReferenceFieldPropExpression).
        \assert($entry instanceof FieldPropExpression || $entry instanceof ReferenceFieldPropExpression);
        foreach (self::toLeafExpressions($entry) as $leaf) {
          $leaves[] = $leaf;
        }
      }
      return $leaves;
    }
    // Multi-bundle reference: expand each bundle branch in its own context.
    if ($expression instanceof ReferenceFieldPropExpression && $expression->targetsMultipleBundles()) {
      \assert($expression->referenced instanceof ReferencedBundleSpecificBranches);
      $leaves = [];
      foreach ($expression->referenced->bundleSpecificReferencedExpressions as $branch_expr) {
        $single_branch = new ReferenceFieldPropExpression($expression->referencer, $branch_expr);
        foreach (self::toLeafExpressions($single_branch) as $leaf) {
          $leaves[] = $leaf;
        }
      }
      return $leaves;
    }
    // Single-bundle reference: expand the referenced expression and re-wrap
    // each leaf in the referencer, reconstructing the chain one leaf at a time.
    if ($expression instanceof ReferenceFieldPropExpression) {
      $referenced = $expression->referenced;
      \assert($referenced instanceof FieldPropExpression || $referenced instanceof FieldObjectPropsExpression || $referenced instanceof ReferenceFieldPropExpression);
      return \array_map(
        fn (FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $leaf): ReferenceFieldPropExpression => new ReferenceFieldPropExpression($expression->referencer, $leaf),
        self::toLeafExpressions($referenced),
      );
    }
    return [$expression];
  }

}
