<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Cache;

use Drupal\canvas_ssr\JsRunner\RunnerGuard;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Varies by whether an island is rendering, so this render is inside it.
 *
 * A fetch is a subrequest, and what Drupal builds for it, an error page,
 * say, can have islands on it. The runner is busy with the island that
 * fetched, so each falls back to CSR, every time. Every island varies by
 * this, so that fallback is cached as a render, apart from what a page
 * that shows the island caches.
 *
 * Cache context ID: 'canvas_ssr_nested'.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerGuard
 * @see \Drupal\canvas_ssr\Element\AstroIslandSsr
 */
final readonly class NestedRenderCacheContext implements CacheContextInterface {

  /**
   * The cache context's ID.
   */
  public const string ID = 'canvas_ssr_nested';

  public function __construct(
    private RunnerGuard $runnerGuard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): string {
    return (string) new TranslatableMarkup('Rendered inside an island');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    return $this->runnerGuard->isInvoking() ? '1' : '0';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
