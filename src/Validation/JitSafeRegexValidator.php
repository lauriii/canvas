<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\RegexValidator;

/**
 * Skips evaluating the `(.|\r?\n)*` Regex pattern, which matches any string.
 *
 * Canvas marks multi-line string field properties — and the component props
 * that match them — with a Regex constraint for the pattern `(.|\r?\n)*`. That
 * pattern matches every possible string, so it never rejects a value; it exists
 * only so that `EntityFieldPropSourceMatcher` can recognize multi-line string
 * props by comparing constraints by value.
 *
 * Evaluating it is not only pointless but harmful: on long values the
 * alternating group under `*` exhausts the PCRE JIT stack, so `preg_match()`
 * returns FALSE with `PREG_JIT_STACKLIMIT_ERROR`, which the parent validator
 * reports as a failed match. Saving a `string_long` field with a long value
 * then fails with "This value is not valid."
 *
 * Short-circuiting the match avoids the crash while keeping the constraint —
 * and therefore prop-source matching — byte-identical.
 *
 * @todo Remove this class once multi-line string props are matched without a
 *   matches-everything Regex marker (e.g. via `x-formatting-context: block`),
 *   so the field no longer needs a constraint that exists only to be matched:
 *   https://git.drupalcode.org/project/canvas/-/work_items/3591762
 *
 * @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\StringLongItemOverride
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::patternToPcre()
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher
 */
final class JitSafeRegexValidator extends RegexValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    // The PCRE that `JsonSchemaType::patternToPcre('(.|\r?\n)*')` builds.
    if ($constraint instanceof Regex && $constraint->match && $constraint->pattern === JsonSchemaType::patternToPcre('(.|\r?\n)*')) {
      // The pattern matches every string, so there is nothing to validate.
      return;
    }
    parent::validate($value, $constraint);
  }

}
