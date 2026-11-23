<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path\Plugin\Field\FieldType\PathItem;
use Drupal\path_alias\PathAliasInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Validates the `StorablePathAlias` constraint.
 */
final class StorablePathAliasConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof StorablePathAliasConstraint);
    \assert($value instanceof PathItem);
    $alias = $value->get('alias')->getValue();
    // An empty alias intentionally clears the alias, so it is not invalid.
    // @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
    if (!\is_scalar($alias) || \trim((string) $alias) === '') {
      return;
    }

    $path_alias = $this->getStorablePathAlias($value);
    // Core normalizes the alias only when saving it, so normalize it before
    // validating it. This must match PathAlias::preSave(), or the validated
    // alias would differ from the stored one.
    // @see \Drupal\path_alias\Entity\PathAlias::preSave()
    $normalized_alias = \rtrim(\trim((string) $alias), "\\/");
    // Core would store an alias of only slashes as a path alias with a NULL
    // alias, which it reports as a missing value: report it clearly instead.
    if ($normalized_alias === '') {
      $this->context->buildViolation($constraint->slashOnlyMessage, ['%alias' => (string) $alias])
        ->atPath('alias')
        ->setInvalidValue($alias)
        ->addViolation();
      return;
    }
    $path_alias->setAlias($normalized_alias);

    foreach ($path_alias->validate() as $violation) {
      // A new page has no source path until it is saved.
      // @see \Drupal\path\Plugin\Field\FieldWidget\PathWidget::validateFormElement()
      if ($path_alias->getPath() === NULL && $violation->getPropertyPath() === 'path') {
        continue;
      }
      // Report the violation on the alias, with its message unchanged: building
      // a new violation from the message would escape its placeholders twice.
      $this->context->getViolations()->add(new ConstraintViolation(
        $violation->getMessage(),
        $violation->getMessageTemplate(),
        $violation->getParameters(),
        $this->context->getRoot(),
        $this->context->getPropertyPath('alias'),
        $alias,
        $violation->getPlural(),
        $violation->getCode(),
        $violation->getConstraint(),
        $violation->getCause(),
      ));
    }
  }

  /**
   * Gets the path alias that saving a path field item would create or update.
   *
   * @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
   */
  private function getStorablePathAlias(PathItem $item): PathAliasInterface {
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $pid = $item->get('pid')->getValue();
    $path_alias = $pid ? $path_alias_storage->load($pid) : NULL;
    if ($path_alias instanceof PathAliasInterface) {
      // Validate a clone, to keep the existing path alias' identity without
      // changing the loaded, statically cached original.
      return clone $path_alias;
    }
    $entity = $item->getEntity();
    $path_alias = $path_alias_storage->create([
      'path' => $entity->isNew() ? NULL : '/' . $entity->toUrl()->getInternalPath(),
      'langcode' => $item->getLangcode(),
    ]);
    \assert($path_alias instanceof PathAliasInterface);
    return $path_alias;
  }

}
