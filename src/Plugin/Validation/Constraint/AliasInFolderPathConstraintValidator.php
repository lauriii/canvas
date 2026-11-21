<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Folder;
use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the `AliasInFolderPath` constraint.
 */
final class AliasInFolderPathConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof AliasInFolderPathConstraint);
    \assert($value instanceof FieldItemListInterface);
    $page = $value->getEntity();
    $folder = Folder::loadByItemAndConfigEntityTypeId((string) $page->uuid(), $page->getEntityTypeId());
    $path = $folder?->getPath();
    if ($path === NULL) {
      return;
    }
    $alias = (string) $value->alias;
    if ($alias !== $path && !str_starts_with($alias, "$path/")) {
      $this->context->buildViolation($constraint->message, [
        '%folder' => $folder?->label(),
        '%path' => $path,
      ])->atPath('0.alias')->addViolation();
    }
  }

}
