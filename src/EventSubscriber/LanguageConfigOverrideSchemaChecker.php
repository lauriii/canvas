<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Hook\ConfigTranslationHooks;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\language\Config\LanguageConfigOverrideCrudEvent;
use Drupal\language\Config\LanguageConfigOverrideEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks LanguageConfigOverrides for Canvas config entities on save.
 *
 * Analogous to \Drupal\Core\Config\Development\ConfigSchemaChecker.
 *
 * Not registered in production.
 *
 * Core never validates LanguageConfigOverride saves against config schema or
 * constraints — it only checks that values are scalars, arrays, or NULL.
 * This checker catches invalid overrides at save time by merging the override
 * onto the base config and validating the result via the typed config system.
 *
 * @todo Remove when https://www.drupal.org/project/drupal/issues/2270399 is fixed *and* makes ConfigSchemaChecker also validate config translation saves.
 * @see \Drupal\Core\Config\Development\ConfigSchemaChecker
 * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraint
 *
 * @internal
 *
 * @see \Drupal\language\Config\LanguageConfigOverride::save()
 * @see \Drupal\language\Config\LanguageConfigOverrideEvents::SAVE_OVERRIDE
 */
final readonly class LanguageConfigOverrideSchemaChecker implements EventSubscriberInterface {

  /**
   * Config name prefixes for translatable Canvas config entities.
   *
   * Derived from ConfigTranslationHooks::TRANSLATABLE_ENTITY_TYPE_IDS, which is
   * the canonical list of Canvas config entity types that support translation.
   *
   * @var string[]
   */
  private array $translatableConfigPrefixes;

  public function __construct(
    private TypedConfigManagerInterface $typedConfigManager,
    private ConfigFactoryInterface $configFactory,
  ) {
    $this->translatableConfigPrefixes = \array_map(
      static fn(string $entity_type_id): string => 'canvas.' . $entity_type_id . '.',
      ConfigTranslationHooks::TRANSLATABLE_ENTITY_TYPE_IDS,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // @phpstan-ignore-next-line canvas.undeclaredModuleDependency (test-only subscriber; `language` is always installed wherever it is registered — see CanvasKernelTestBase::register())
      LanguageConfigOverrideEvents::SAVE_OVERRIDE => 'onSaveOverride',
    ];
  }

  /**
   * Checks a LanguageConfigOverride after it is persisted.
   *
   * Merges the override data onto the base config and validates the combined
   * result against the config schema and constraints. Throws if invalid.
   *
   * @param \Drupal\language\Config\LanguageConfigOverrideCrudEvent $event
   *   The language config override event.
   *
   * @throws \Drupal\Core\Config\ConfigException
   *   Thrown when the merged config (base + override) fails validation.
   */
  public function onSaveOverride(LanguageConfigOverrideCrudEvent $event): void {
    $config = $event->getLanguageConfigOverride();
    $name = $config->getName();

    if (!$this->isTranslatable($name)) {
      return;
    }

    // The override data is a subset — merge it onto the base config to get a
    // complete, validatable picture of what will be returned to consumers.
    // @see \Drupal\Core\Config\Config::setOverriddenData()
    $base_data = $this->configFactory->get($name)->getRawData();
    $override_data = $config->get();
    $merged = NestedArray::mergeDeepArray([$base_data, $override_data], TRUE);

    // Validate the merged result against config schema and constraints.
    $typed = $this->typedConfigManager->createFromNameAndData($name, $merged);
    $violations = $typed->validate();
    if ($violations->count() === 0) {
      return;
    }

    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = \sprintf('[%s] %s', $violation->getPropertyPath(), (string) $violation->getMessage());
    }
    throw new ConfigException(\sprintf(
      'Validation of the "%s" LanguageConfigOverride failed: %s',
      $name,
      \implode(', ', $messages),
    ));
  }

  private function isTranslatable(string $config_name): bool {
    foreach ($this->translatableConfigPrefixes as $prefix) {
      if (\str_starts_with($config_name, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
