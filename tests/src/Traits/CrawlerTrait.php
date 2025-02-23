<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Defines a trait with various methods for working with symfony/dom-crawler.
 */
trait CrawlerTrait {

  /**
   * Builds a crawler for a render array.
   *
   * @param array $build
   *   Render array.
   * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
   *   (optional) Bubbleable metadata to add render dependencies to.
   */
  protected function crawlerForRenderArray(array &$build, ?BubbleableMetadata $metadata = NULL): Crawler {
    $renderer = \Drupal::service(RendererInterface::class);
    \assert($renderer instanceof RendererInterface);
    $context = new RenderContext();
    // We don't use an arrow function here as we want $build to be modified by
    // reference and that isn't possible with an arrow function.
    $out = (string) $renderer->executeInRenderContext($context, function () use (&$build, $renderer) {
      return $renderer->render($build);
    });
    if ($metadata && !$context->isEmpty()) {
      $metadata->addCacheableDependency($context->pop());
    }
    return new Crawler($out);
  }

}
