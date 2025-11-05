<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

/**
 * No-op validation constraint to enable informed data connection suggestions.
 *
 * Note: this MUST be a validation constraint, not an interface, because:
 * - a field or data type's semantics may be context-dependent
 * - a field or data type's semantics may be overridden using
 *   constraints
 * - therefore it must be defined as a validation constraint too.
 * There is precedent for in Drupal core: the `FullyValidatable` constraint.
 *
 * @see https://github.com/json-schema-org/json-schema-spec/issues/1557
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates a URI target', [], ['context' => 'Validation']),
  type: [
    'uri',
  ],
)]
final class UriTargetMediaTypeConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'UriTargetMediaType';

  /**
   * Validation constraint option to define the MIME type targeted by this URI.
   *
   * @var string
   */
  public $mimeType;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions() : array {
    return ['mimeType'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption() : string {
    return 'mimeType';
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(mixed $options = NULL, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct($options, $groups, $payload);
    $mime_type = $options['mimeType'];
    if (!(self::isValidWildCard($mime_type) || self::isValid($mime_type))) {
      throw new InvalidArgumentException('The option "mimeType" must be a valid MIME type or wildcard.');
    }
  }

  /**
   * Validates wildcard MIME type: specifying only the media type.
   *
   * Example: `image/*`, `video/*`.
   */
  public static function isValidWildCard(string $mimetype): bool {
    return preg_match('/\w+\/\*/', $mimetype) === 1;
  }

  /**
   * Validates MIME type: type, subtype and optionally a suffix.
   *
   * Example: `image/avif`, `application/json`.
   */
  public static function isValid(string $mimetype): bool {
    return preg_match('/\w+\/\w+/', $mimetype) === 1;
  }

}
