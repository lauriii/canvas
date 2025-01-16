<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Exception;

use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines an exception for a constraint violation.
 */
final class ConstraintViolationException extends \Exception {

  public function __construct(protected ConstraintViolationListInterface $constraintViolationList, string $message = 'Validation errors exist') {
    parent::__construct("$message:\n $this->constraintViolationList");
  }

  public function renamePropertyPaths(array $map): self {
    foreach ($map as $prefix_original => $prefix_new) {
      foreach ($this->constraintViolationList as $key => $v) {
        if (str_starts_with($v->getPropertyPath(), $prefix_original)) {
          $this->constraintViolationList[$key] = new ConstraintViolation(
            $v->getMessage(),
            $v->getMessageTemplate(),
            $v->getParameters(),
            $v->getRoot(),
            preg_replace('/^' . preg_quote($prefix_original, '/') . '/', $prefix_new, $v->getPropertyPath()),
            $v->getInvalidValue(),
            $v->getPlural(),
            $v->getCode(),
            $v->getConstraint(),
            $v->getCause(),
          );
        }
      }
    }
    return $this;
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
