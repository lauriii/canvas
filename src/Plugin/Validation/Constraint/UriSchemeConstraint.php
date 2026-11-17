<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that a given URI uses one of the specified schemes.
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates a URI scheme', [], ['context' => 'Validation']),
  type: [
    'uri',
  ],
)]
final class UriSchemeConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'UriScheme';

  /**
   * If an absolute URL, an allowlist of schemes can be specified.
   *
   * @var list<string>
   */
  public array $allowedSchemes;

  #[HasNamedArguments]
  public function __construct(
    array $allowedSchemes,
    // The violation message when the URI scheme is not allowed.
    public string $messageInvalidUriScheme = "'@scheme' is not allowed, must be one of the allowed schemes: @allowed-schemes.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
    // Sort the schemes to ensure deterministic instances of this class.
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::dataTypeShapeRequirementMatchesFinalConstraintSet()
    sort($allowedSchemes);
    $this->allowedSchemes = $allowedSchemes;
  }

}
