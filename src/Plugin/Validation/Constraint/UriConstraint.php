<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that a given URI (or optionally, URI reference) is valid.
 *
 * ⚠️ The Symfony `Url` constraint is not viable to use because it does not
 * support validating relative URLs. It only supports validating protocols.
 *
 * @see \Symfony\Component\Validator\Constraints\Url
 * @see \Symfony\Component\Validator\Constraints\UrlValidator
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates a URI', [], ['context' => 'Validation']),
  type: [
    'uri',
  ],
)]
final class UriConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'Uri';

  #[HasNamedArguments]
  public function __construct(
    // Whether URI references (relative URIs) are allowed.
    public bool $allowReferences,
    // The violation message when the URI is invalid.
    public string $messageInvalidUri = "This value should be a valid URI.",
    // The violation message when the URI reference is invalid.
    public string $messageInvalidUriReference = "This value should be a valid URI reference.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

  public function isSupersetOf(UriConstraint $constraint) : bool {
    return $this->allowReferences === TRUE && $constraint->allowReferences === FALSE;
  }

}
