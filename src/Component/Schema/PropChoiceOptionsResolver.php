<?php

declare(strict_types=1);

namespace Drupal\canvas\Component\Schema;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Resolves enum option labels for component prop schemas.
 *
 * @internal
 */
final class PropChoiceOptionsResolver {

  /**
   * Constructs a PropChoiceOptionsResolver.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    private readonly TranslationInterface $stringTranslation,
  ) {
  }

  /**
   * Resolve label/value options for a prop schema fragment.
   *
   * @param array $prop_schema
   *   The prop schema fragment.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Cacheability metadata to bubble.
   *
   * @return array<int, array{label: string, value: mixed}>
   *   A list of label/value options for the enum.
   */
  public function resolveEnumOptions(array $prop_schema, ?CacheableMetadata $cacheability = NULL): array {
    if (!isset($prop_schema['enum']) || !\is_array($prop_schema['enum'])) {
      return [];
    }

    $meta_enum = $prop_schema['meta:enum'] ?? NULL;
    $has_meta_enum = \is_array($meta_enum);
    $meta_enum_is_list = $has_meta_enum && array_is_list($meta_enum);

    $translation_context = isset($prop_schema['x-translation-context'])
      ? (string) $prop_schema['x-translation-context']
      : '';
    $translate_labels = $translation_context !== '';
    if ($translate_labels && $cacheability) {
      $cacheability->addCacheContexts(['languages:language_interface']);
    }

    $options = [];
    foreach ($prop_schema['enum'] as $index => $value) {
      $label = NULL;
      if ($has_meta_enum) {
        if ($meta_enum_is_list) {
          if (\array_key_exists($index, $meta_enum)) {
            $label = $meta_enum[$index];
          }
        }
        else {
          $lookup = $this->stringifyEnumValue($value);
          if (\array_key_exists($lookup, $meta_enum)) {
            $label = $meta_enum[$lookup];
          }
        }
      }

      if ($label === NULL) {
        $label = $value;
      }

      $options[] = [
        'label' => $this->normalizeLabel($label, $translation_context, $translate_labels),
        'value' => $value,
      ];
    }

    return $options;
  }

  /**
   * Normalizes a label to a string, optionally translating it.
   *
   * @param mixed $label
   *   The label value.
   * @param string $translation_context
   *   Translation context string.
   * @param bool $translate
   *   Whether to run translation on plain strings.
   *
   * @return string
   *   The normalized label.
   */
  private function normalizeLabel(mixed $label, string $translation_context, bool $translate): string {
    if ($label instanceof TranslatableMarkup) {
      return (string) $label;
    }
    if ($label instanceof \Stringable) {
      return (string) $label;
    }

    if ($label === TRUE) {
      $label = 'true';
    }
    elseif ($label === FALSE) {
      $label = 'false';
    }
    elseif ($label === NULL) {
      $label = 'null';
    }
    elseif (\is_scalar($label)) {
      $label = (string) $label;
    }
    else {
      $label = $this->encodeFallback($label);
    }

    if ($translate && $translation_context !== '') {
      return (string) $this->stringTranslation->translate($label, [], ['context' => $translation_context]);
    }

    return (string) $label;
  }

  /**
   * Stringifies an enum value for meta:enum lookup.
   *
   * @param mixed $value
   *   Enum value.
   *
   * @return string
   *   Stringified enum value.
   */
  private function stringifyEnumValue(mixed $value): string {
    if ($value === TRUE) {
      return 'true';
    }
    if ($value === FALSE) {
      return 'false';
    }
    if ($value === NULL) {
      return 'null';
    }
    if (\is_scalar($value)) {
      return (string) $value;
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    return $this->encodeFallback($value);
  }

  /**
   * Encodes a fallback label for non-scalar values.
   *
   * @param mixed $value
   *   The value to encode.
   *
   * @return string
   *   The encoded value or placeholder.
   */
  private function encodeFallback(mixed $value): string {
    $encoded = Json::encode($value);
    return $encoded === FALSE ? '[unable to encode]' : $encoded;
  }

}
