<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks a string consists of specific parts found in the parent mapping.
 *
 * @Constraint(
 *   id = "StringParts",
 *   label = @Translation("String consists of specific parts", context = "Validation")
 * )
 *
 * @todo Remove this when https://www.drupal.org/i/3324140 lands.
 */
class StringPartsConstraint extends Constraint {

  /**
   * The error message if the string does not match.
   *
   * @var string
   */
  public string $message = "Expected '@expected_string', not '@value'. Format: '@expected_format'.";

  /**
   * The separator separating the parts.
   *
   * @var string
   */
  public string $separator;

  /**
   * Reserved characters — if any — that are to be substituted in each part.
   *
   * @var string[]
   */
  public array $reservedCharacters = [];

  /**
   * Any reserved characters that will be substituted by this character.
   *
   * @var ?string
   */
  public ?string $reservedCharactersSubstitute;

  /**
   * The parent mapping's elements string values that should be used as parts.
   *
   * @var array
   */
  public array $parts;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['separator', 'parts', 'reservedCharacters'];
  }

}
