<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
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
   * The file extensions the URI target may use.
   *
   * @var list<string>
   */
  public array $allowedExtensions;

  #[HasNamedArguments]
  public function __construct(array $allowedExtensions, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct(groups: $groups, payload: $payload);
    if ($allowedExtensions === [] || !\array_is_list($allowedExtensions)) {
      throw new InvalidArgumentException('The option "allowedExtensions" must be a non-empty list of file extensions.');
    }
    $this->allowedExtensions = $allowedExtensions;
  }

  /**
   * Whether this constraint's extensions overlap with another's.
   */
  public function intersectsWith(self $other): bool {
    return \array_intersect($this->allowedExtensions, $other->allowedExtensions) !== [];
  }

}
