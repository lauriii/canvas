<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the `PageVariantSelectable` constraint.
 */
final class PageVariantSelectableConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
    \assert($constraint instanceof PageVariantSelectableConstraint);
    if (!\is_string($value) || $value === '') {
      return;
    }
    $variant = $this->entityTypeManager->getStorage(PageVariant::ENTITY_TYPE_ID)->load($value);
    // A missing variant is the ConfigExists constraint's concern.
    if (!$variant instanceof PageVariant || $variant->status()) {
      return;
    }
    // Disabled variants keep rendering where they are already selected: only
    // selecting one anew is rejected, so a template whose persisted selection
    // is this variant stays valid (and publishable) after the variant was
    // disabled.
    // @see \Drupal\canvas\Entity\PageVariant::allowedValues()
    $object = $this->context->getObject();
    \assert($object instanceof TypedDataInterface);
    $mapping = $object->getParent();
    if ($mapping instanceof ComplexDataInterface) {
      $id = $mapping->get('id')->getValue();
      if (\is_string($id) && $id !== '') {
        // The entity under validation may be a modified copy of the stored
        // one; only the *persisted* selection exempts, so bypass the static
        // entity cache.
        $persisted = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->loadUnchanged($id);
        if ($persisted instanceof ContentTemplate && $persisted->getPageVariant() === $value) {
          return;
        }
      }
    }
    $this->context->addViolation($constraint->message, ['%variant' => $value]);
  }

}
