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
 * Carries the file extensions a URI target may use. A prop shape declares its
 * list via the `x-allowed-file-extensions` schema annotation; a file field
 * derives its list from its `FileExtension` constraint. Shape matching
 * compares the two lists by intersection.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\UriTargetMediaTypeConstraint
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates a URI target file extension', [], ['context' => 'Validation']),
  type: [
    'uri',
  ],
)]
final class UriTargetFileExtensionsConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'UriTargetFileExtensions';

  /**
   * Validation constraint option: file extensions the URI target may use.
   *
   * @var list<string>
   */
  public $allowedExtensions;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions() : array {
    return ['allowedExtensions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption() : string {
    return 'allowedExtensions';
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(mixed $options = NULL, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct($options, $groups, $payload);
    $extensions = $options['allowedExtensions'];
    if (!\is_array($extensions) || $extensions === [] || !\array_is_list($extensions)) {
      throw new InvalidArgumentException('The option "allowedExtensions" must be a non-empty list of file extensions.');
    }
  }

  /**
   * Whether this constraint's extensions overlap with another's.
   */
  public function intersectsWith(self $other): bool {
    return \array_intersect($this->allowedExtensions, $other->allowedExtensions) !== [];
  }

}
