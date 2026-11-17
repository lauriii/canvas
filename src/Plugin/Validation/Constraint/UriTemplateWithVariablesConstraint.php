<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates a URI template has certain variables', [], ['context' => 'Validation']),
)]
final class UriTemplateWithVariablesConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'UriTemplateWithVariables';

  #[HasNamedArguments]
  public function __construct(
    // The variables that are required to be present in the URI template.
    public array $requiredVariables = [],
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
