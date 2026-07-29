<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

/**
 * Thrown when a branch descends through a multi-bundle reference (nesting).
 *
 * That would be a "branch inside a branch" (nested branching), which is not yet
 * supported: the string representation cannot express it and the parser rejects
 * it. This dedicated type lets callers tell nested branching apart from other
 * invalid branch sets (which throw \InvalidArgumentException) — the Coalescer
 * bails on those but lets this one surface so the validation layer can report a
 * precise "not yet supported" message instead of a misleading one.
 *
 * @todo Remove this exception once nested branching is supported, in https://git.drupalcode.org/project/canvas/-/work_items/3591865
 * @internal
 */
final class NestedBranchNotSupportedException extends \LogicException {

}
