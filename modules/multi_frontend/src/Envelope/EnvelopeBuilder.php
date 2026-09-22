<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\RendererInterface;
use Drupal\multi_frontend\ProducerInvoker;

/**
 * Turns a render array into envelope nodes.
 *
 * A subtree that is a produced component becomes a typed component node.
 * Everything else becomes rendered markup, which is the graceful degradation
 * that lets unconverted modules keep working and is why this can be
 * introduced gradually.
 *
 * Known limit, stated rather than discovered: this walk descends only through
 * plain containers. A produced component nested inside a subtree that renders
 * itself, a block or a field formatter for instance, is rendered into that
 * subtree's markup and reaches the envelope as part of an html node rather
 * than as a component node. Lifting it out with a marker, and converting the
 * container chain so there is less to lift, are the two answers, and both are
 * design.md D10 work rather than something this reference implementation
 * does.
 */
final class EnvelopeBuilder {

  public function __construct(
    private readonly ProducerInvoker $invoker,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Builds the nodes for one render array.
   *
   * @param array<string, mixed> $element
   *   The render array.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Collector for the union of every node's cacheability.
   *
   * @return \Drupal\multi_frontend\Envelope\EnvelopeNodeInterface[]
   *   The nodes.
   */
  public function build(array $element, RefinableCacheableDependencyInterface $cacheability): array {
    // An element denied by #access renders as nothing, and must produce
    // nothing here too. Skipping this check would hand a consumer data the
    // HTML path would have withheld.
    if (\array_key_exists('#access', $element) && !self::isAccessAllowed($element['#access'], $cacheability)) {
      return [];
    }

    // Core resolves #access_callback into #access before it checks access, and
    // it does so through the trusted-callback policy. Reimplementing that here
    // would be both duplication and a place to get security wrong, so an
    // element carrying an unresolved callback is rendered rather than
    // produced: core applies the callback on that path, and a denied element
    // renders as nothing. The cost is one component arriving as an html node
    // instead of a typed one, which is the safe direction to be wrong in.
    $access_unresolved = \array_key_exists('#access_callback', $element) && !\array_key_exists('#access', $element);

    if (($element['#type'] ?? NULL) === 'produced_component' && !$access_unresolved) {
      return [
        $this->invoker->produceNode($element['#producer'], $element['#subject'], $cacheability),
      ];
    }

    if (self::isPlainContainer($element) && self::containsProducedComponent($element)) {
      // The container renders nothing itself, but its cacheability is still
      // the page's, so it moves up rather than being dropped with it.
      $cacheability->addCacheableDependency(CacheableMetadata::createFromRenderArray($element));
      $nodes = [];
      foreach (Element::children($element, TRUE) as $key) {
        $nodes = [...$nodes, ...$this->build($element[$key], $cacheability)];
      }
      return $nodes;
    }

    $node = $this->toHtmlNode($element, $cacheability);
    return $node === NULL ? [] : [$node];
  }

  /**
   * Renders a subtree into an html node.
   */
  private function toHtmlNode(array $element, RefinableCacheableDependencyInterface $cacheability): ?HtmlNode {
    $markup = (string) $this->renderer->renderInIsolation($element);
    $node_cacheability = CacheableMetadata::createFromRenderArray($element);
    $cacheability->addCacheableDependency($node_cacheability);
    return trim($markup) === '' ? NULL : new HtmlNode($markup, $node_cacheability);
  }

  /**
   * Whether an element is a bare array of children that renders nothing itself.
   */
  /**
   * Properties that do not stop a container from being split.
   *
   * #cache is metadata rather than rendering: it is merged into the parent
   * instead of blocking the descent, because otherwise a controller that does
   * the correct thing and declares a list cache tag on its wrapper turns its
   * whole page into one markup blob. Everything else with a "#" prefix,
   * #prefix and #theme and #attached and #pre_render alike, changes what the
   * subtree means or carries something the envelope has nowhere to put, so
   * descending past it would silently drop it.
   */
  private const SAFE_TO_DESCEND_PAST = ['#access', '#weight', '#sorted', '#cache'];

  private static function isPlainContainer(array $element): bool {
    foreach (\array_keys($element) as $key) {
      if (\is_string($key) && \str_starts_with($key, '#') && !\in_array($key, self::SAFE_TO_DESCEND_PAST, TRUE)) {
        return FALSE;
      }
    }
    return Element::children($element) !== [];
  }

  /**
   * Resolves an element's #access, recording what the decision varied on.
   */
  private static function isAccessAllowed(mixed $access, RefinableCacheableDependencyInterface $cacheability): bool {
    if ($access instanceof AccessResultInterface) {
      $cacheability->addCacheableDependency($access);
      return $access->isAllowed();
    }
    return $access !== FALSE;
  }

  /**
   * Whether a subtree contains a produced component anywhere below it.
   */
  private static function containsProducedComponent(array $element): bool {
    if (($element['#type'] ?? NULL) === 'produced_component') {
      return TRUE;
    }
    foreach (Element::children($element) as $key) {
      if (\is_array($element[$key]) && self::containsProducedComponent($element[$key])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
