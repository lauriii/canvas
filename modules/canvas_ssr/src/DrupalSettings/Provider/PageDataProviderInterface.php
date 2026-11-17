<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings\Provider;

/**
 * Supplies page data for SSR: values that may differ from page to page.
 *
 * Page data is set only for the invoke that renders an island, never while
 * modules evaluate, since evaluated modules outlive the island.
 *
 * Tag a provider with canvas_ssr.page_data_provider. Where providers give
 * the same key, the one with the highest tag priority wins. A page data
 * provider's value for a key wins over a site data provider's, which makes
 * that key page data.
 *
 * @see \Drupal\canvas_ssr\DrupalSettings\AllProvidedData
 */
interface PageDataProviderInterface {

  /**
   * Returns this provider's page data.
   *
   * Keep this cheap. Build values in the DataValue's closures, which only
   * run for the keys a render uses.
   *
   * @return array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue>
   *   Values keyed as components declare them in dataDependencies, such as
   *   "v0.pageTitle".
   */
  public function getPageData(): array;

}
