<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
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

  public string $messageInvalidUriScheme = "'@scheme' is not allowed, must be one of the allowed schemes: @allowed-schemes.";

  /**
   * If an absolute URL, an allowlist of schemes can be specified.
   *
   * @var non-empty-array<string>
   */
  public array $allowedSchemes;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions() : array {
    return [
      'allowedSchemes',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'allowedSchemes';
  }

  /**
   * {@inheritdoc}
   */
  protected function normalizeOptions(mixed $options): array {
    $normalized = parent::normalizeOptions($options);
    // Ensure deterministic instances of this class.
    // @see \Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher::dataTypeShapeRequirementMatchesFinalConstraintSet()
    sort($normalized['allowedSchemes']);
    return $normalized;
  }

}
