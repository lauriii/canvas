<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Resolves the drupalSettings a component graph gets.
 *
 * A component gets only the site and page data its graph declares, through
 * the drupalSettings kind of its data dependencies. Each value carries
 * cacheability, so giving it more would invalidate the island on edits that
 * can't change its markup. It also gets its graph's interface translations,
 * which aren't declared, as Canvas gives them in the browser.
 *
 * A component that declares a key no provider gives would render with the
 * data missing rather than fail, so SSR bails for it. See ADR 7.
 *
 * @see \Drupal\canvas_ssr\DrupalSettings\AllProvidedData
 * @see \Drupal\canvas_ssr\Renderer
 */
final class DrupalSettingsResolver {

  /**
   * Constructs a DrupalSettingsResolver.
   *
   * @param \Drupal\canvas_ssr\DrupalSettings\AllProvidedData $allProvidedData
   *   Every provider's site and page data.
   * @param \Drupal\canvas_ssr\DrupalSettings\CanvasInterfaceTranslations|null $interfaceTranslations
   *   Builds a graph's interface translations, or NULL for none.
   */
  public function __construct(
    private readonly AllProvidedData $allProvidedData,
    private readonly ?CanvasInterfaceTranslations $interfaceTranslations = NULL,
  ) {}

  /**
   * Returns the drupalSettings for a component graph.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The component's graph.
   *
   * @return \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings|null
   *   The drupalSettings, or NULL if a component in the graph declares a
   *   key no provider gives.
   */
  public function forGraph(ComponentGraph $graph): ?GraphDrupalSettings {
    $declared = [];
    foreach ($graph->all() as $component) {
      $declared = [...$declared, ...self::declaredKeys($component)];
    }

    return $this->forDeclaredKeys($declared, $graph->all());
  }

  /**
   * Returns the drupalSettings for a list of declared keys.
   *
   * @param list<string> $declared
   *   The keys a graph's components declare, such as "v0.baseUrl".
   * @param list<\Drupal\canvas\Entity\JavaScriptComponent> $components
   *   The graph's components, for their interface translations.
   *
   * @return \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings|null
   *   The drupalSettings, or NULL if no provider gives a key.
   */
  public function forDeclaredKeys(array $declared, array $components = []): ?GraphDrupalSettings {
    $declared = \array_flip($declared);
    if (\array_diff_key($declared, \array_flip($this->allProvidedData->keys())) !== []) {
      return NULL;
    }

    return new GraphDrupalSettings(
      \array_intersect_key($this->allProvidedData->siteData(), $declared),
      \array_intersect_key($this->allProvidedData->pageData(), $declared),
      $components === [] ? NULL : $this->interfaceTranslations?->forComponents($components),
    );
  }

  /**
   * Returns drupalSettings with all of the site data and no page data.
   *
   * @return \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings
   *   The drupalSettings.
   */
  public function forSiteData(): GraphDrupalSettings {
    return new GraphDrupalSettings($this->allProvidedData->siteData(), []);
  }

  /**
   * Returns the cacheability of all site data.
   *
   * Modules evaluate with it set, so what a runner keeps of evaluated
   * modules depends on it, whichever components use it. Only its
   * cacheability is built, not its values.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheability.
   *
   * @see \Drupal\canvas_ssr\JsModules\BaseModules
   */
  public function siteDataCacheability(): CacheableMetadata {
    $cacheability = new CacheableMetadata();
    foreach ($this->allProvidedData->siteData() as $value) {
      $cacheability->addCacheableDependency($value);
    }

    return $cacheability;
  }

  /**
   * Returns the keys a component declares for drupalSettings.
   *
   * Canvas has no accessor for them. Its config schema makes
   * dataDependencies.drupalSettings a list of strings.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The component.
   *
   * @return list<string>
   *   The keys, such as "v0.baseUrl".
   */
  private static function declaredKeys(JavaScriptComponent $component): array {
    $data_dependencies = (array) ($component->get('dataDependencies') ?? []);

    return \array_values(\array_filter(
      (array) ($data_dependencies['drupalSettings'] ?? []),
      'is_string',
    ));
  }

}
