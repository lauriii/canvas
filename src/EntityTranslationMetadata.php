<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;

/**
 * Shared language-switcher metadata for Code Components and headless routes.
 *
 * @phpstan-type TranslationMetadata array{langcode: string, name: string, nativeName: string, url: string, translationAvailable: bool, current: bool}
 *
 * @internal
 */
final class EntityTranslationMetadata {

  /**
   * Lists enabled languages, leaving URL processing to the consumer.
   *
   * @param callable(EntityInterface, \Drupal\Core\Language\LanguageInterface): string $generate_url
   *   Generates a URL for the viewable translation, or the supplied entity when
   *   unavailable, using the entry's language. This preserves Code Component
   *   fallback behavior; an unavailable entry's URL is not an access guarantee.
   *
   * @return list<TranslationMetadata>
   *   The shared translation entries, empty on monolingual sites.
   */
  public static function build(
    EntityInterface&TranslatableInterface $entity,
    LanguageManagerInterface $language_manager,
    string $requested_langcode,
    callable $generate_url,
    ?AccountInterface $account = NULL,
    ?RefinableCacheableDependencyInterface $cacheability = NULL,
  ): array {
    $cacheability?->addCacheTags(['config:configurable_language_list']);
    $cacheability?->addCacheContexts(['languages:language_interface']);
    $cacheability?->addCacheableDependency($entity);
    if (!$language_manager->isMultilingual()) {
      return [];
    }

    $native_names = LanguageManager::getStandardLanguageList();
    $translations = [];
    foreach ($language_manager->getLanguages() as $language) {
      $langcode = $language->getId();
      // The manager returns runtime Language objects, not cacheable config
      // entities. Include each name's config tag, including override changes.
      $cacheability?->addCacheTags(['config:language.entity.' . $langcode]);
      $available = FALSE;
      $translation = $entity;
      if ($entity->hasTranslation($langcode)) {
        $candidate = $entity->getTranslation($langcode);
        \assert($candidate instanceof EntityInterface);
        $access = $candidate->access('view', $account, TRUE);
        // Denials matter too: permission/publication changes can make an
        // unavailable entry available without changing the current translation.
        $cacheability?->addCacheableDependency($candidate);
        $cacheability?->addCacheableDependency($access);
        $available = $access->isAllowed();
        if ($available) {
          $translation = $candidate;
        }
      }
      $translations[] = [
        'langcode' => $langcode,
        'name' => $language->getName(),
        'nativeName' => $native_names[$langcode][1] ?? $language->getName(),
        'url' => $generate_url($translation, $language),
        'translationAvailable' => $available,
        'current' => $langcode === $requested_langcode,
      ];
    }
    return $translations;
  }

}
