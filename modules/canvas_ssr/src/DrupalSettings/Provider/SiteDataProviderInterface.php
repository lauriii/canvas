<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings\Provider;

/**
 * Supplies site data for SSR: values the same on every page.
 *
 * A value may still vary by host or theme. Site data is set while modules
 * evaluate, for component code that reads it at module scope, such as
 * `new JsonApiClient()`. So it's part of the base, and its cacheability
 * decides what the base varies by.
 *
 * Tag a provider with canvas_ssr.site_data_provider. Where providers give
 * the same key, the one with the highest tag priority wins, and a page data
 * provider's value for it wins over all of them.
 *
 * @see \Drupal\canvas_ssr\DrupalSettings\AllProvidedData
 */
interface SiteDataProviderInterface {

  /**
   * Returns this provider's site data.
   *
   * Keep this cheap. Build values in the DataValue's closures, which only
   * run for the keys a render uses.
   *
   * @return array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue>
   *   Values keyed as components declare them in dataDependencies, such as
   *   "v0.branding".
   */
  public function getSiteData(): array;

}
