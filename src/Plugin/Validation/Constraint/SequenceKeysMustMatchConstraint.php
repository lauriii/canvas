<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;

/**
 * Checks the validated sequence's keys against another sequence's keys.
 *
 * The comparison mode is controlled by $matchType:
 * - 'same-set' (default): the validated sequence must contain the exact same
 *   keys as the target sequence.
 * - 'subset': the validated sequence's keys must all exist in the target
 *   sequence, but missing keys are allowed.
 *
 * The $conditions array contains optional filter conditions to apply to the
 * sequence before extracting keys. Each condition is a
 * `key => primitive-value` pair. A mapping element in the target sequence
 * passes the filter only if all listed keys exist on it and
 * each of their cast primitive values equals the expected value.
 *
 * A key can be a dotted path to a key in a nested mapping, for example
 * `items.$ref`. The element does not pass the filter if a parent of the
 * nested key is absent or is not a mapping.
 *
 * Instead of one set of conditions, this can be a list of condition sets. The
 * element passes the filter if at least one of the sets passes.
 *
 * For example:
 * @code
 * ['status' => TRUE]
 * @endcode
 * or
 * @code
 * [
 *   'type' => 'object',
 *   '$ref' =>'json-schema-definitions://canvas.module/content-entity-reference',
 * ]
 * @endcode
 * or, with a dotted key and a list of two condition sets:
 * @code
 * [
 *   [
 *     'type' => 'object',
 *     '$ref' => 'json-schema-definitions://canvas.module/content-entity-reference',
 *   ],
 *   [
 *     'type' => 'array',
 *     'items.type' => 'object',
 *     'items.$ref' => 'json-schema-definitions://canvas.module/content-entity-reference',
 *   ],
 * ]
 * @endcode
 *
 * @see \Drupal\Core\Validation\Plugin\Validation\Constraint\SequenceKeyExistsConstraint
 */
#[Constraint(
  id: "SequenceKeysMustMatch",
  label: new TranslatableMarkup("Sequence keys must match.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class SequenceKeysMustMatchConstraint extends SequenceDependentConstraintBase {

  public const MATCH_TYPE_SAME_SET = 'same-set';
  public const MATCH_TYPE_SUBSET = 'subset';

  #[HasNamedArguments]
  public function __construct(
    string $propertyPathToSequence,
    string $configPrefix = '',
    ?string $configName = NULL,
    // How the validated sequence's keys must relate to the target sequence:
    // one of the MATCH_TYPE_* constants.
    public string $matchType = self::MATCH_TYPE_SAME_SET,
    public ?array $conditions = NULL,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($propertyPathToSequence, $configPrefix, $configName, $groups, $payload);
  }

}
