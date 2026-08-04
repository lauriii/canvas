<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\Component\Utility\NestedArray;
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
    // Use the config entity in $value, not the stored config: otherwise it is
    // impossible to validate a config entity with its translations prior to
    // saving: it'd compare the staged config translations to the stored config
    // entity instead of the given entity (e.g. an auto-saved one).
    $base_data = $value->toArray();
    if ($value instanceof ComponentTreeConfigEntityBase) {
      // An auto-save draft's base tree may still be delta-keyed: sequence
      // keys are only enforced once a translation exists, and reconstructed
      // drafts bypass ::preSave(). Overrides always target component
      // instances by UUID sequence key, so re-key the base or the merge
      // below would emit phantom, instance-less entries.
      // @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::getTranslatedComponentTree()
      $base_data['component_tree'] = ComponentTreeConfigEntityBase::asDeterministicallyAndTranslatableKeyedComponentTreeSequence(
        \array_values($base_data['component_tree'] ?? []),
      );
      // Calling ComponentTreeConfigEntityBase::getTranslation() has a static
      // caching side effect. Ensure that callers don't have to deal with the
      // consequences.
      $value = clone $value;
    }
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $languages = $this->languageManager->getLanguages();

    foreach ($languages as $langcode => $language) {
      if ($langcode === $default_langcode) {
        continue;
      }

      // For ComponentTreeConfigEntityBase-powered config entities, read the
      // translation from the entity rather than from live config override
      // storage. ::getTranslation() returns a StagedLanguageConfigOverride,
      // which may have had its component instances automatically updated to the
      // active version
      // @see \Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface
      // @see \Drupal\canvas\ComponentSource\ComponentSourceManager::updateComponentInstances()
      // @todo Refactor away the ComponentTreeConfigEntityBase checks in the future by checking for the presence of `type: canvas.component_tree` in the given config entity's config schema
      if ($value instanceof ComponentTreeConfigEntityBase) {
        $override_data = $value->getTranslation($langcode)->getData();
      }
      else {
        $override = $this->languageManager->getLanguageConfigOverride($langcode, $name);
        $override_data = $override->get();
      }
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
