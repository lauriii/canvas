<?php

declare(strict_types=1);

namespace Drupal\canvas_test_recipe\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config action that writes a language config override.
 *
 * Workaround for RecipeConfigInstaller only processing the default config
 * collection, which causes language override files in config/language/{langcode}/
 * subdirectories to be silently ignored. Writes directly to the correct language
 * collection in storage.
 *
 * @todo Remove this class and use config/language/{langcode}/ files in recipes
 *   once https://drupal.org/i/3453331 is fixed.
 */
#[ConfigAction(
  id: 'setLanguageOverride',
  admin_label: new TranslatableMarkup('Set language config override'),
  entity_types: ['*'],
)]
final class SetLanguageOverride implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly ConfigurableLanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $languageManager = $container->get('language_manager');
    \assert($languageManager instanceof ConfigurableLanguageManagerInterface);
    return new static(
      $languageManager,
      $container->get(ConfigFactoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function apply(string $configName, mixed $value): void {
    if (!\is_array($value) || !isset($value['language'], $value['data'])) {
      throw new ConfigActionException(\sprintf(
        'setLanguageOverride for %s requires an array with "language" and "data" keys.',
        $configName,
      ));
    }

    $langcode = $value['language'];
    $data = $value['data'];

    if (!\is_array($data)) {
      throw new ConfigActionException(\sprintf(
        'setLanguageOverride "data" for %s must be an array.',
        $configName,
      ));
    }

    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL) {
      throw new ConfigActionException(\sprintf(
        'Language "%s" does not exist. Create language.entity.%s before using setLanguageOverride.',
        $langcode,
        $langcode,
      ));
    }

    if ($this->configFactory->get($configName)->isNew()) {
      throw new ConfigActionException(\sprintf(
        'Config %s does not exist. Create it before setting a language override.',
        $configName,
      ));
    }

    $override = $this->languageManager->getLanguageConfigOverride($langcode, $configName);
    $override->setData($data)->save();
  }

}
