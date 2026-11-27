<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Cache;

use Drupal\canvas\CanvasClientCapabilities;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Cache\Context\RequestStackCacheContextBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Varies by the selected headless content format.
 *
 * @todo Remove this cache context and its references in Canvas 2.0.0 when legacy Custom Elements JSON support is removed.
 */
final class HeadlessContentFormatCacheContext extends RequestStackCacheContextBase implements CacheContextInterface {

  public const CACHE_CONTEXT_ID = 'canvas_headless_content_format';

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): string {
    return (string) new TranslatableMarkup('Headless content format');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL || !CanvasClientCapabilities::has($request, 'json-render')) {
      return 'legacy';
    }
    // Experimental: SDKs that understand component styles opt in to them.
    // @see \Drupal\canvas_headless\RenderConverter\CanvasRenderConverter::requestsStyles()
    return CanvasClientCapabilities::has($request, 'styles') ? 'json-render+styles' : 'json-render';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
