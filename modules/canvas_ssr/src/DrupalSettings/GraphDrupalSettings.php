<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings;

use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The drupalSettings a component graph gets.
 *
 * It's the site and page data the graph declares, and the graph's interface
 * translations, under drupalSettings.canvasData, where Canvas puts them in
 * the browser. It builds the two things a render sets them with: the
 * drupalSettings module, which sets the site data when it evaluates, and the
 * value the payload carries for every invoke. Its cacheability is the
 * values'.
 *
 * drupalSettings is the only global SSR sets for components.
 *
 * @see \Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver::forGraph()
 */
final readonly class GraphDrupalSettings implements CacheableDependencyInterface {

  /**
   * Constructs a GraphDrupalSettings.
   *
   * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $siteData
   *   The site data the graph gets, keyed as components declare it.
   * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $pageData
   *   The page data the graph gets, keyed as components declare it.
   * @param \Drupal\canvas_ssr\DrupalSettings\DataValue|null $interfaceTranslations
   *   The graph's interface translations, or NULL on a Canvas without them.
   *   Like page data, they're set only while the island renders.
   */
  public function __construct(
    public array $siteData,
    public array $pageData,
    public ?DataValue $interfaceTranslations = NULL,
  ) {}

  /**
   * Returns the island's drupalSettings, for the payload.
   *
   * @return array<string, mixed>|null
   *   The drupalSettings, or NULL for a graph that declares nothing and has
   *   no translated strings, which leaves it undefined. Otherwise an island
   *   could read the previous island's, without bubbling their cacheability.
   */
  public function value(): ?array {
    $values = $this->siteData + $this->pageData;
    // Canvas leaves the key unset where nothing is translated.
    if ($this->interfaceTranslations?->getValue() !== NULL) {
      $values[CanvasInterfaceTranslations::KEY] = $this->interfaceTranslations;
    }

    return self::settings($values);
  }

  /**
   * Returns the island's site data alone, for the payload.
   *
   * A module that evaluates during a render gets this, as one that
   * evaluates before it does, rather than page data or interface
   * translations it could keep for other islands.
   *
   * @return array<string, mixed>|null
   *   The site data as drupalSettings, or NULL if the graph declares none.
   */
  public function siteValue(): ?array {
    return self::settings($this->siteData);
  }

  /**
   * Builds the drupalSettings module.
   *
   * Evaluating it sets the site data, for component code that reads it at
   * module scope, such as `new JsonApiClient()`. Leaving out the page data
   * and interface translations keeps the module the same on every page, and
   * in every interface language. The main module sets all of the island's
   * data, with value(), on every invoke.
   *
   * @return string
   *   The drupalSettings module's JS source.
   */
  public function module(): string {
    return \sprintf(<<<'JS'
import { setDrupalSettings } from %1$s;

setDrupalSettings(%2$s);
JS,
      \json_encode(SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
      \json_encode(self::settings($this->siteData), JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Builds drupalSettings from values keyed as components declare them.
   *
   * Canvas's key "v0.branding" is drupalSettings.canvasData.v0.branding.
   *
   * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $values
   *   The values.
   *
   * @return array<string, mixed>|null
   *   The drupalSettings, or NULL if there are no values.
   */
  private static function settings(array $values): ?array {
    if ($values === []) {
      return NULL;
    }
    $settings = [];
    foreach ($values as $key => $value) {
      NestedArray::setValue($settings, ['canvasData', ...\explode('.', (string) $key)], $value->getValue());
    }

    return $settings;
  }

  /**
   * Returns the values' combined cacheability.
   */
  private function cacheability(): CacheableMetadata {
    $cacheability = new CacheableMetadata();
    foreach ($this->siteData + $this->pageData as $value) {
      $cacheability->addCacheableDependency($value);
    }
    // Even where nothing is translated: a translation added later must
    // invalidate the island.
    if ($this->interfaceTranslations !== NULL) {
      $cacheability->addCacheableDependency($this->interfaceTranslations);
    }

    return $cacheability;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->cacheability()->getCacheMaxAge();
  }

}
