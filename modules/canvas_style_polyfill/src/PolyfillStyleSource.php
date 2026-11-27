<?php

declare(strict_types=1);

namespace Drupal\canvas_style_polyfill;

use Drupal\canvas\Entity\StyleLibrary;
use Drupal\canvas\Style\ComponentStyles;
use Drupal\canvas\Style\StyleSourceInterface;
use Drupal\Component\Discovery\YamlDiscovery;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Style definitions for cores without the Style API.
 *
 * UPSTREAM(core): delete this module once Canvas requires a core version
 * with the Style API (https://www.drupal.org/project/drupal/issues/3517033).
 * It is only registered while core has no Style API.
 *
 * Reads `{provider}.utilities.yml` from installed modules and themes and
 * applies core's defaults, so the same files work before and after core
 * ships the API. Like core, a later provider replaces an earlier definition
 * with the same ID (modules first, then themes).
 *
 * Not polyfilled, because Canvas does not use them: `{provider}.themes.yml`
 * (page-level styles and modes), `hook_style_alter()` for other modules, and
 * the `#styles` render property.
 *
 * @see \Drupal\Core\Theme\Style\StylePluginManager
 * @see \Drupal\Core\Theme\Style\StyleDefinition::getProcessedOptions()
 *
 * @internal
 */
final class PolyfillStyleSource implements StyleSourceInterface {

  private const string CACHE_ID = 'canvas_style_polyfill';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
  ) {}

  public function isCore(): bool {
    return FALSE;
  }

  public function definitions(): array {
    $raw = $this->discover();
    // Canvas-managed style libraries, which core adds through
    // hook_style_alter(). Extensions win on collisions.
    $raw += StyleLibrary::rawDefinitions($this->configFactory);
    $definitions = [];
    foreach ($raw as $id => $definition) {
      $definitions[$id] = self::normalize($id, $definition);
    }
    return $definitions;
  }

  public function clearCachedDefinitions(): void {
    $this->cache->delete(self::CACHE_ID);
  }

  public function cacheTag(): string {
    return self::CACHE_ID;
  }

  /**
   * Discovers raw utility definitions, keyed by style ID.
   *
   * @return array<string, array<string, mixed>>
   */
  private function discover(): array {
    if ($cached = $this->cache->get(self::CACHE_ID)) {
      return $cached->data;
    }
    $directories = $this->moduleHandler->getModuleDirectories() + $this->themeHandler->getThemeDirectories();
    $definitions = [];
    foreach ((new YamlDiscovery('utilities', $directories))->findAll() as $provider => $styles) {
      foreach ((array) $styles as $id => $definition) {
        // Core rejects these IDs; skip them rather than break every page.
        if (!\is_array($definition) || !preg_match(ComponentStyles::STYLE_ID_PATTERN, (string) $id)) {
          continue;
        }
        $definitions[(string) $id] = ['provider' => (string) $provider] + $definition;
      }
    }
    // Installing or uninstalling an extension changes which files exist.
    $this->cache->set(self::CACHE_ID, $definitions, Cache::PERMANENT, [self::CACHE_ID, 'config:core.extension']);
    return $definitions;
  }

  /**
   * Applies core's defaults to a raw definition.
   *
   * @param array<string, mixed> $definition
   *
   * @return \Drupal\canvas\Style\StyleSourceInterface::SourceDefinition
   *
   * @see \Drupal\Core\Theme\Style\StyleDefinition::$definition
   * @see \Drupal\Core\Theme\Style\StyleDefinition::getProcessedOptions()
   */
  private static function normalize(string $id, array $definition): array {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    $translate = static fn (mixed $text): string => $text === NULL || $text === '' ? '' : (string) new TranslatableMarkup((string) $text);
    $attribute = (string) ($definition['attribute'] ?? 'class');
    $target = (string) ($definition['target'] ?? 'any');
    $options = [];
    foreach ((array) ($definition['options'] ?? []) as $option_id => $option) {
      $option = \is_array($option) ? $option : [];
      $options[(string) $option_id] = [
        'label' => $translate($option['label'] ?? $option_id),
        'description' => $translate($option['description'] ?? ''),
        'value' => (string) ($option['value'] ?? $option_id),
        'attribute' => (string) ($option['attribute'] ?? $attribute),
        'target' => (string) ($option['target'] ?? $target),
        'status' => (string) ($option['status'] ?? 'stable'),
        'icon' => isset($option['icon']) ? (string) $option['icon'] : NULL,
      ];
    }
    return [
      'id' => $id,
      'provider' => (string) ($definition['provider'] ?? ''),
      'canvas_library' => isset($definition['canvas_library']) ? (string) $definition['canvas_library'] : NULL,
      'enabled' => (bool) ($definition['enabled'] ?? TRUE),
      'label' => $translate($definition['label'] ?? $id),
      'description' => $translate($definition['description'] ?? ''),
      'category' => $translate($definition['category'] ?? 'Other'),
      'weight' => (int) ($definition['weight'] ?? 0),
      'status' => (string) ($definition['status'] ?? 'stable'),
      'library' => (string) ($definition['library'] ?? ''),
      'options' => $options,
      // Canvas extensions (style libraries); themes use a sidecar file.
      'tokens' => $definition['tokens'] ?? NULL,
      'custom' => $definition['custom'] ?? NULL,
    ];
  }

}
