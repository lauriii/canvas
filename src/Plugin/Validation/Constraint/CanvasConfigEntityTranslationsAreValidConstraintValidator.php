<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates all LanguageConfigOverrides for a Canvas config entity.
 *
 * Core never validates LanguageConfigOverride saves against config schema or
 * constraints — it only checks that values are scalars, arrays, or NULL.
 * This constraint catches invalid overrides by merging each override onto the
 * base config and validating the result via the typed config system.
 *
 * @todo Remove when https://www.drupal.org/project/drupal/issues/2270399 is fixed.
 */
final class CanvasConfigEntityTranslationsAreValidConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigurableLanguageManagerInterface $languageManager,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $language_manager = $container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    return new static(
      $language_manager,
      $container->get(TypedConfigManagerInterface::class),
      $container->get(ConfigFactoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof CanvasConfigEntityTranslationsAreValidConstraint);

    if (!$value instanceof ConfigEntityInterface) {
      return;
    }

    $name = $value->getConfigDependencyName();
    $base_data = $this->configFactory->get($name)->getRawData();
    $languages = $this->languageManager->getLanguages();

    foreach ($languages as $langcode => $language) {
      $override = $this->languageManager->getLanguageConfigOverride($langcode, $name);
      $override_data = $override->get();
      if (empty($override_data)) {
        continue;
      }

      // Simulate what Config::setOverriddenData() does: merge override onto
      // base to get the full picture that will be served to consumers.
      // @see \Drupal\Core\Config\Config::setOverriddenData()
      $merged = NestedArray::mergeDeepArray([$base_data, $override_data], TRUE);
      $typed = $this->typedConfigManager->createFromNameAndData($name, $merged);
      $violations = $typed->validate();

      foreach ($violations as $violation) {
        $this->context->addViolation(
          '[%langcode] [%path] %message',
          [
            '%langcode' => $langcode,
            '%path' => $violation->getPropertyPath(),
            '%message' => (string) $violation->getMessage(),
          ],
        );
      }
    }
  }

}
