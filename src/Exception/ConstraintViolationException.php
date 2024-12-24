<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines an exception for a constraint violation.
 */
final class ConstraintViolationException extends \Exception {

  private function __construct(protected ConstraintViolationListInterface $constraintViolationList, string $message) {
    parent::__construct($message);
  }

  public static function forViolationList(ConstraintViolationListInterface $violation_list): static {
    return new static(
      $violation_list,
      'Validation errors exist',
    );
  }

  /**
   * Gets value of ConstraintViolationList.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   Value of ConstraintViolationList.
   */
  public function getConstraintViolationList(): ConstraintViolationListInterface {
    return $this->constraintViolationList;
  }

}
