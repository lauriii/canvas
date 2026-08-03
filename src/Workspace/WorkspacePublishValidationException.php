<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Publish aborted: one or more tracked items failed validation.
 *
 * Carries one violation list per offending item, so callers can report
 * grouped per-item violations.
 *
 * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher::publish()
 */
final class WorkspacePublishValidationException extends \RuntimeException {

  /**
   * @param list<\Symfony\Component\Validator\ConstraintViolationListInterface> $violationSets
   */
  public function __construct(
    public readonly array $violationSets,
    string $message = 'The workspace cannot be published because some of its items are invalid.',
  ) {
    parent::__construct($message);
  }

  /**
   * @return list<\Symfony\Component\Validator\ConstraintViolationListInterface>
   */
  public function getViolationSets(): array {
    return $this->violationSets;
  }

}
