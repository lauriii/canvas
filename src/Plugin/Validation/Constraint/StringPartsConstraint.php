<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks a string consists of specific parts found in the parent mapping.
 *
 * @todo Remove this when https://www.drupal.org/i/3324140 lands.
 */
#[Constraint(
  id: "StringParts",
  label: new TranslatableMarkup("String consists of specific parts", [], ['context' => 'Validation'])
)]
class StringPartsConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    // The separator separating the parts.
    public string $separator,
    // The parent mapping's elements string values that should be used as
    // parts.
    public array $parts,
    // Reserved characters (if any) that are to be substituted in each part.
    public array $reservedCharacters = [],
    // Any reserved characters that will be substituted by this character.
    public ?string $reservedCharactersSubstitute = NULL,
    // The error message if the string does not match.
    public string $message = "Expected '@expected_string', not '@value'. Format: '@expected_format'.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
