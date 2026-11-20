<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the FolderItemExists constraint.
 *
 * @internal
 */
final class FolderItemExistsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
      $container->get(ConfigFactoryInterface::class),
    );
  }

  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FolderItemExistsConstraint) {
      throw new UnexpectedTypeException($constraint, FolderItemExistsConstraint::class);
    }
    if (!\is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    // @phpstan-ignore argument.type
    $entity_type_id = TypeResolver::resolveDynamicTypeName("[$constraint->configEntityTypeId]", $this->context->getObject());
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type === NULL) {
      // The Folder's type has its own constraint.
      return;
    }
    if ($entity_type instanceof ConfigEntityTypeInterface) {
      $prefix = $entity_type->getConfigPrefix() . '.';
      if (!\in_array($prefix . $value, $this->configFactory->listAll($prefix), TRUE)) {
        $this->context->addViolation($constraint->configMissing, ['@name' => $prefix . $value]);
      }
    }
    elseif (!Uuid::isValid($value)) {
      $this->context->addViolation($constraint->notUuid, ['@item' => $value]);
    }
  }

}
