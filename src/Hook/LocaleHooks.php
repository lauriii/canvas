<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\locale\StringStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs code component strings with the Locale module.
 *
 * Without this, Locale only discovers a string when a page that renders the
 * code component is first viewed in a non-English language, and never forgets
 * it. Code components are saved and deleted through entity CRUD in the UI, the
 * HTTP API (used by the Canvas CLI), the synchronization of external code
 * components and config import, so these hooks cover all of them.
 *
 * The code editor, the Canvas CLI and the Headless SDK find the strings in the
 * source code.
 * Each string is tagged with a `js_component` location per code component. A
 * string is deleted once no code component uses it, unless it has another
 * location or any translation. Locale does not record every use of a string:
 * PHP code translating an existing string adds no location. So translated
 * strings are kept, as their translations may still be in use, while an
 * untranslated string that other code still uses can be deleted; Locale
 * registers it again the next time that code translates it.
 *
 * @see \Drupal\canvas\Entity\JavaScriptComponent::getTranslatableStrings()
 */
final readonly class LocaleHooks {

  public function __construct(
    private ContainerInterface $container,
    private CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() and hook_ENTITY_TYPE_update().
   */
  #[Hook('js_component_insert')]
  #[Hook('js_component_update')]
  public function syncTranslatableStrings(JavaScriptComponent $component): void {
    $strings = $component->getTranslatableStrings();
    // Keep the strings while they are unknown, rather than deleting them: the
    // source code may still use them.
    if ($strings === NULL) {
      return;
    }
    $this->sync((string) $component->id(), $strings);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete().
   */
  #[Hook('js_component_delete')]
  public function deleteTranslatableStrings(JavaScriptComponent $component): void {
    $this->sync((string) $component->id(), []);
  }

  /**
   * Makes the strings tagged with a code component match the given strings.
   *
   * @param string $id
   *   The code component ID.
   * @param array<array{source: string, context: string}> $strings
   *   The strings the code component uses.
   */
  private function sync(string $id, array $strings): void {
    // @todo Refactor to use `HookDependsOnModule` once Canvas depends on Drupal 11.5's https://www.drupal.org/node/3548805
    if (!$this->container->has(StringStorageInterface::class)) {
      return;
    }
    $storage = $this->container->get(StringStorageInterface::class);
    \assert($storage instanceof StringStorageInterface);
    $type = JavaScriptComponent::ENTITY_TYPE_ID;

    $used = [];
    $deleted = FALSE;
    foreach ($strings as $string) {
      $source = $storage->findString($string) ?? $storage->createString($string);
      $source->addLocation($type, $id)->save();
      $used[] = (int) $source->getId();
    }

    foreach ($storage->getStrings(['type' => $type, 'name' => $id]) as $source) {
      if (\in_array((int) $source->getId(), $used, TRUE)) {
        continue;
      }
      // A falsy location ID makes Locale delete that location.
      // @see \Drupal\locale\StringDatabaseStorage::updateLocation()
      $locations = $source->getLocations();
      $source->locations[$type][$id] = FALSE;
      $source->save();
      unset($locations[$type][$id]);
      if (\array_filter($locations) !== []) {
        continue;
      }
      // Keep translated strings: their translations may still be in use.
      if ($storage->getTranslations(['lid' => $source->getId(), 'translated' => TRUE]) !== []) {
        continue;
      }
      // Not ::delete(), which deletes locations by location ID, not string ID.
      $storage->deleteStrings(['lid' => $source->getId()]);
      $deleted = TRUE;
    }
    // Locale caches lookups, including those of untranslated strings, so
    // without this it would not register a deleted string again when other
    // code translates it.
    // @see \Drupal\locale\LocaleLookup::resolveCacheMiss()
    if ($deleted) {
      $this->cacheTagsInvalidator->invalidateTags(['locale']);
    }
  }

}
