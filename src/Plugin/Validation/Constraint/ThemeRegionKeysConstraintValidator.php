<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @internal
 */
final class ThemeRegionKeysConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ThemeInitializationInterface $themeInitialization,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ThemeExtensionList::class),
      $container->get(ThemeInitializationInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\Validator\Exception\UnexpectedTypeException
   *   Thrown when the given constraint is not supported by this validator.
   */
  public function validate(mixed $sequence, Constraint $constraint): void {
    if (!$constraint instanceof ThemeRegionKeysConstraint) {
      throw new UnexpectedTypeException($constraint, ThemeRegionKeysConstraint::class);
    }

    if ($sequence === NULL) {
      return;
    }
    elseif (!is_array($sequence)) {
      throw new UnexpectedValueException($sequence, 'sequence');
    }

    // Resolve any dynamic tokens, like %parent, in the specified theme.
    // @phpstan-ignore argument.type
    $theme_name = TypeResolver::resolveDynamicTypeName("[$constraint->theme]", $this->context->getObject());
    try {
      $theme = $this->themeExtensionList->get($theme_name);
    }
    catch (UnknownExtensionException) {
      // @todo Ideally, we'd only validate this if and only if the `theme` is valid. That requires conditional/sequential execution of validation constraints, which Drupal does not currently support.
      // @see https://www.drupal.org/project/drupal/issues/2820364
      return;
    }
    $active_theme = $this->themeInitialization->getActiveTheme($theme);
    $expected_keys = $active_theme->getRegions();

    foreach ($expected_keys as $expected_key) {
      if (!array_key_exists($expected_key, $sequence)) {
        $this->context->buildViolation($constraint->missingRequiredKeyMessage)
          // @todo ActiveTheme provides no way to get the region label! 😬
          ->setParameter('%key_label', $expected_key)
          ->setParameter('%key', $expected_key)
          ->addViolation();
      }
    }

    $invalid_keys = array_diff(array_keys($sequence), $expected_keys);
    foreach ($invalid_keys as $key) {
      $this->context->buildViolation($constraint->invalidKeyMessage)
        ->setParameter('%key', $key)
        ->atPath((string) $key)
        ->setInvalidValue($key)
        ->addViolation();
    }
  }

}
