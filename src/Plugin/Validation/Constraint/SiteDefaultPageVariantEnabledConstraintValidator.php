<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the `SiteDefaultPageVariantEnabled` constraint.
 */
final class SiteDefaultPageVariantEnabledConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(ConfigFactoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof SiteDefaultPageVariantEnabledConstraint);
    if ($value === NULL || (bool) $value === TRUE) {
      return;
    }
    // The constraint is on the `status` key; its parent mapping is the page
    // variant itself.
    $object = $this->context->getObject();
    \assert($object instanceof TypedDataInterface);
    $mapping = $object->getParent();
    if (!$mapping instanceof ComplexDataInterface) {
      return;
    }
    $id = $mapping->get('id')->getValue();
    if (\is_string($id) && $id !== '' && $this->configFactory->get('canvas.settings')->get(PageVariant::DEFAULT_SETTING) === $id) {
      $this->context->addViolation($constraint->message);
    }
  }

}
