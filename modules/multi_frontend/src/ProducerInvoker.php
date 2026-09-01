<?php

declare(strict_types=1);

namespace Drupal\multi_frontend;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\multi_frontend\Envelope\ComponentNode;

/**
 * Invokes producers, validates what they return, and collects cacheability.
 *
 * The single place a producer is called from. Every consumer, the render
 * element, the derived HTTP route and the envelope builder alike, goes
 * through here, which is what keeps the HTML and the data paths from
 * drifting: they are the same call.
 */
final class ProducerInvoker {

  public function __construct(
    private readonly ComponentProducerManager $producerManager,
    private readonly ComponentPluginManager $componentManager,
    private readonly ComponentValidator $componentValidator,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Produces props for one subject, validating them against the schema.
   *
   * Validation here is unconditional, not wrapped in assert(). Core's SDC
   * validation is a development assertion, correctly so for a Twig-only path,
   * and core's own documentation tells sites to run production with
   * zend.assertions=-1. A published contract cannot rest on a check that is
   * compiled out in production.
   *
   * @return array<string, mixed>
   *   The validated props.
   */
  public function produceProps(
    string $producer_id,
    mixed $subject,
    RefinableCacheableDependencyInterface $cacheability,
    int $depth = 0,
  ): array {
    /** @var \Drupal\multi_frontend\ComponentProducerInterface $producer */
    $producer = $this->producerManager->createInstance($producer_id);
    $context = new ProducerContext($cacheability, $this, $depth);

    // Producers run inside a render context, so that anything already
    // bubbling through an existing Drupal API, a generated URL, an access
    // check, a nested render, is collected without the producer asking. A
    // producer that forgets to record a dependency for something the renderer
    // already knows about still gets it right.
    $render_context = new RenderContext();
    $props = $this->renderer->executeInRenderContext(
      $render_context,
      static fn (): array => $producer->produce($subject, $context),
    );
    if (!$render_context->isEmpty()) {
      $cacheability->addCacheableDependency($render_context->pop());
    }

    $component_id = $producer->getComponentId();
    $definition = $this->componentManager->getDefinition($component_id);

    // An optional prop with no value is an absent prop, not a null one. This
    // matters more than it looks: a prop populated from an access-controlled
    // field is NULL exactly when the viewer may not see it, and a schema that
    // types it as a string would then refuse to render the whole component
    // for that viewer. Dropping the key is what JSON Schema means by optional,
    // and it keeps a producer from having to build its return array
    // conditionally, which is the render-array awkwardness this replaces.
    // A required prop that is NULL still fails validation, loudly.
    $required = $definition['props']['required'] ?? [];
    $props = \array_filter(
      $props,
      static fn (mixed $value, string $name): bool => $value !== NULL || \in_array($name, $required, TRUE),
      ARRAY_FILTER_USE_BOTH,
    );

    $this->componentValidator->validateProps($props, $this->componentManager->createInstance($component_id));

    return $props;
  }

  /**
   * Produces a complete envelope node for one subject.
   */
  public function produceNode(
    string $producer_id,
    mixed $subject,
    RefinableCacheableDependencyInterface $parent_cacheability,
    int $depth = 0,
  ): ComponentNode {
    // Each node collects its own cacheability first, so that per-node
    // metadata is real rather than a copy of the response's, and only then
    // merges upward.
    $node_cacheability = new CacheableMetadata();
    $props = $this->produceProps($producer_id, $subject, $node_cacheability, $depth);

    /** @var \Drupal\multi_frontend\ComponentProducerInterface $producer */
    $producer = $this->producerManager->createInstance($producer_id);
    $slots = $producer->produceSlots($subject, new ProducerContext($node_cacheability, $this, $depth));

    $component_id = $producer->getComponentId();
    $node = new ComponentNode(
      $component_id,
      $props,
      $slots,
      ['data-component-id' => $component_id],
      $node_cacheability,
    );
    $parent_cacheability->addCacheableDependency($node_cacheability);
    return $node;
  }

  /**
   * Renders a build in isolation, merging its cacheability into a collector.
   *
   * Used by ProducerContext::formattedText() so that a text format's cache
   * tags reach the node that used it.
   */
  public function renderInContext(array $build, RefinableCacheableDependencyInterface $cacheability): string {
    $markup = (string) $this->renderer->renderInIsolation($build);
    $cacheability->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));
    return $markup;
  }

  /**
   * Returns render cache keys for a producer and subject, or NULL.
   *
   * Computable before the producer runs, which is the property that keeps
   * render caching working: on a cache hit the producer is never invoked, in
   * the same way a render-cached views listing is not rebuilt from its query.
   * Cache contexts, unlike keys, are discovered during production, and core's
   * VariationCache already handles exactly that with cache redirects.
   *
   * @return string[]|null
   *   Cache keys, or NULL when the subject has no stable identity.
   */
  public static function cacheKeys(string $producer_id, mixed $subject): ?array {
    if ($subject instanceof EntityInterface) {
      return [
        'produced_component',
        $producer_id,
        $subject->getEntityTypeId(),
        (string) $subject->id(),
        $subject->language()->getId(),
      ];
    }
    return NULL;
  }

}
