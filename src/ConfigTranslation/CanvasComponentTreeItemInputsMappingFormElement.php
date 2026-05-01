<?php

declare(strict_types=1);

namespace Drupal\canvas\ConfigTranslation;

use Drupal\config_translation\FormElement\ListElement;
use Drupal\Core\Config\Config;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\Config\LanguageConfigOverride;

/**
 * Config translation form element for Canvas component instance's inputs.
 *
 * Tweaks compared to ListElement:
 *  - Ensures omitted inputs are still translatable
 *
 * @internal
 */
final class CanvasComponentTreeItemInputsMappingFormElement extends ListElement {

  private function ensureOmittedOptionalInputsAreTranslatable(): void {
    // Due to the nature of how config schema works, it silently ignores omitted
    // (optional) key-value pairs. But it is important that even an optional
    // component input that has no value in the default translation is still
    // translatable.
    // @see \Drupal\Core\Config\Schema\ArrayElement::parse()
    // @see \Drupal\Core\Config\Schema\ArrayElement::getAllKeys()
    $populated_input_key_value_pairs = $this->element->getValue();
    // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
    $available_input_keys = \array_keys($this->element->getDataDefinition()['mapping']);
    $available_input_key_value_pairs = \array_combine(
      // Use all available input keys, in the defined order.
      $available_input_keys,
      // Use the populated value for each, fall back to NULL if unpopulated.
      \array_map(
        // @phpstan-ignore-next-line argument.type
        fn (string $key) => \array_key_exists($key, $populated_input_key_value_pairs)
          ? $populated_input_key_value_pairs[$key]
          : NULL,
        $available_input_keys
      ),
    );

    // The parent iterates $this->element to build the form, so set the updated
    // value there.
    $this->element->setValue($available_input_key_value_pairs);
  }

  /**
   * {@inheritdoc}
   */
  public function getTranslationBuild(LanguageInterface $source_language, LanguageInterface $translation_language, $source_config, $translation_config, array $parents, $base_key = NULL) {
    // TRICKY: this illustrates that Config Translation's "form_element_class"
    // is a weak abstraction layer.
    $config_name = $parents[1];
    // @phpstan-ignore-next-line method.notFound
    $is_new_translation = \Drupal::languageManager()->getLanguageConfigOverride($translation_language->getId(), $config_name)->isNew();

    // The parent generates a form based on $this->element.
    $this->ensureOmittedOptionalInputsAreTranslatable();

    // The parent uses `$source_config[$key]` to populate the "source" half of
    // the translation form. $this->element gained NULL values for omitted
    // optional keys to generate the necessary form elements. Ensure
    // $source_config is populated accordingly to avoid PHP warnings.
    $source_config = $this->element->getValue();

    // Same thing for `$translation_config[$key]`, but only if there is no
    // translation yet.
    if ($is_new_translation) {
      $translation_config = $this->element->getValue();
    }
    return parent::getTranslationBuild($source_language, $translation_language, $source_config, $translation_config, $parents, $base_key);
  }

  /**
   * {@inheritdoc}
   */
  public function setConfig(Config $base_config, LanguageConfigOverride $config_translation, $config_values, $base_key = NULL) {
    $this->ensureOmittedOptionalInputsAreTranslatable();
    parent::setConfig($base_config, $config_translation, $config_values, $base_key);
  }

}
