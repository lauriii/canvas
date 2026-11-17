<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;

/**
 * Merges every provider's site data and page data.
 *
 * Within each kind, the first provider to give a key wins it. In the
 * container, provider order is tag priority, highest first. A key that a
 * page data provider gives is page data, even if a site data provider
 * gives it too.
 *
 * DrupalSettingsResolver picks which values a component gets.
 *
 * The values are kept, each built once, until one of their cache tags is
 * invalidated, as when a request pushes and then renders, and at the end
 * of the request, so a process that keeps its services builds them afresh.
 *
 * @see \Drupal\canvas_ssr\DrupalSettings\Provider\SiteDataProviderInterface
 * @see \Drupal\canvas_ssr\DrupalSettings\Provider\PageDataProviderInterface
 */
final class AllProvidedData implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * The site data, once collected.
   *
   * @var array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue>|null
   */
  private ?array $siteData = NULL;

  /**
   * The page data, once collected.
   *
   * @var array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue>|null
   */
  private ?array $pageData = NULL;

  /**
   * Constructs an AllProvidedData.
   *
   * @param iterable<\Drupal\canvas_ssr\DrupalSettings\Provider\SiteDataProviderInterface> $siteDataProviders
   *   The site data providers.
   * @param iterable<\Drupal\canvas_ssr\DrupalSettings\Provider\PageDataProviderInterface> $pageDataProviders
   *   The page data providers.
   */
  public function __construct(
    private readonly iterable $siteDataProviders,
    private readonly iterable $pageDataProviders,
  ) {}

  /**
   * Returns every provider's site data, less the keys that are page data.
   *
   * @return array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue>
   *   Values keyed as components declare them, such as "v0.branding".
   */
  public function siteData(): array {
    if ($this->siteData === NULL) {
      $values = [];
      foreach ($this->siteDataProviders as $provider) {
        $values += $provider->getSiteData();
      }
      $this->siteData = \array_diff_key($values, $this->pageData());
    }

    return $this->siteData;
  }

  /**
   * Returns every provider's page data.
   *
   * @return array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue>
   *   Values keyed as components declare them, such as "v0.pageTitle".
   */
  public function pageData(): array {
    if ($this->pageData === NULL) {
      $values = [];
      foreach ($this->pageDataProviders as $provider) {
        $values += $provider->getPageData();
      }
      $this->pageData = $values;
    }

    return $this->pageData;
  }

  /**
   * {@inheritdoc}
   *
   * Forgets the values if one of theirs is invalidated.
   */
  public function invalidateTags(array $tags): void {
    $tags = \array_flip($tags);
    foreach ([...$this->siteData ?? [], ...$this->pageData ?? []] as $value) {
      if (\array_intersect_key(\array_flip($value->getCacheTags()), $tags) !== []) {
        $this->destruct();
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Forgets the values, at the end of the request.
   */
  public function destruct(): void {
    $this->siteData = NULL;
    $this->pageData = NULL;
  }

  /**
   * Returns every key some provider gives, such as "v0.branding".
   *
   * SSR bails for a component whose graph declares any other.
   *
   * @return list<string>
   *   The keys.
   */
  public function keys(): array {
    return \array_keys($this->siteData() + $this->pageData());
  }

}
