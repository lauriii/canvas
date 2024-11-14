<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\experience_builder\Entity\Component;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides the client side with all information needed of all XB Components.
 *
 * @see ui/src/types/Component.ts
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 * @phpstan-type ComponentClientSideTypeAny array{'id': string, 'name': string, 'default_markup': string|\Stringable, 'css': string|\Stringable, 'js_header': string|\Stringable, 'js_footer': string|\Stringable}
 * @phpstan-type ComponentClientSideTypeSdc array{'id': string, 'name': string, 'default_markup': string|\Stringable, 'css': string|\Stringable, 'js_header': string|\Stringable, 'js_footer': string|\Stringable, 'metadata': array<string, mixed>, 'field_data': array<string, mixed>, 'dynamic_prop_source_candidates': array<string, mixed>,}
 *
 * This controller provides a critical response for the XB UI. Therefore it
 * should hence be as fast and cacheable as possible. High-cardinality cache
 * contexts (such as 'user' and 'session') result in poor cacheability.
 * Fortunately, these cache contexts only are present for the markup used for
 * previewing XB Components. So XB chooses to sacrifice accuracy of the preview
 * slightly to be able to guarantee strong cacheability and fast responses.
 *
 * @see \Drupal\Core\Render\PlaceholderGenerator::shouldAutomaticallyPlaceholder()
 * @see \Drupal\Core\Render\PlaceholderingRenderCache
 */
final class ApiComponentsController {

  public function __construct(
    private readonly RendererInterface $renderer,
    #[Autowire(param: 'renderer.config')]
    private readonly array $rendererConfig,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Provides a list of XB Components as JSON.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The components list.
   */
  public function __invoke() : CacheableJsonResponse {
    [$component_list, $cacheability] = $this->getComponentsList();
    return (new CacheableJsonResponse())
      ->addCacheableDependency($cacheability->addCacheTags(['config:component_list']))
      ->setData($component_list);
  }

  /**
   * Gets an array of all XB Components, prepared for XB's client side.
   *
   * @return array{array<ComponentConfigEntityId, ComponentClientSideTypeAny|ComponentClientSideTypeSdc>, CacheableMetadata}
   *   A pair, with the second value the cacheability, and the first value an
   *   array of XB Component config entities, with for each:
   *   - `id`: the Component config entity ID
   *   - `name`: human-readable name
   *   - `default_markup`: without providing user input, this is what
   *     Component's markup would look like — used to preview the Component
   *     prior to placing it
   *   - `css`: markup to load CSS assets associated with `default_markup`
   *   - `js_header`: markup to load header JS assets associated with
   *     `default_markup`
   *   - `js_footer`: markup to load footer JS assets associated with
   *     `default_markup`
   *
   *   And when the XB Component type is `sdc`, it also adds:
   *   - `metadata`: SDC metadata
   *   - `field_data`: the StaticPropSources to use for each SDC prop
   *   - `dynamic_prop_source_candidates`: the DynamicPropSources that match
   *      each
   */
  private function getComponentsList(): mixed {
    $component_info_list = [];
    $cacheability = new CacheableMetadata();

    foreach (Component::loadMultiple() as $component) {
      // Hide disabled components.
      if (!$component->status()) {
        continue;
      }
      try {
        [$info, $component_cacheability] = $this->getCacheableClientSideInfo($component);
        $component_info_list[] = $info;
        // @todo refactor in https://www.drupal.org/project/experience_builder/issues/3484678 to use value objects instead, to avoid this awkward rendering in a render context.
        $cacheability->addCacheableDependency($component_cacheability);
      }
      catch (\Exception) {
        // @todo Skip failed plugins for now, see https://www.drupal.org/project/experience_builder/issues/3484672
      }
    }

    // Ignore the cache tags for individual XB Component config entities,
    // because this response lists them, so the list cache tag is sufficient
    // and the rest is pointless noise.
    // @see \Drupal\Core\Entity\EntityTypeInterface::getListCacheTags()
    $cacheability->setCacheTags(array_filter(
      $cacheability->getCacheTags(),
      fn (string $tag): bool => !str_starts_with($tag, 'config:experience_builder.component.'),
    ));

    // Set a minimum cache time of one hour, because this is only a preview.
    // (Cache tag invalidations will still result in an immediate update.)
    $max_age = $cacheability->getCacheMaxAge();
    if ($max_age !== Cache::PERMANENT) {
      $cacheability->setCacheMaxAge(max($max_age, 3600));
    }

    return [
      // Component array is keyed by ID.
      array_combine(array_column($component_info_list, 'id'), $component_info_list),
      $cacheability,
    ];
  }

  /**
   * Gets the client-side info for a Component, ensuring strong cacheability.
   *
   * @phpstan-return array{0: ComponentClientSideTypeAny|ComponentClientSideTypeSdc, 1: \Drupal\Core\Cache\CacheableDependencyInterface}
   *   An array containing:
   *   - Component metadata for the client side.
   *   - The cacheability of that metadata.
   *
   * @see \Drupal\Core\Render\PlaceholderGenerator::shouldAutomaticallyPlaceholder()
   * @see \Drupal\Core\Render\PlaceholderingRenderCache
   */
  private function getCacheableClientSideInfo(Component $component): array {
    $get_client_side_info = function (Component $component) {
      $context = new RenderContext();
      $info = $this->renderer->executeInRenderContext($context, fn () => $component->getComponentSource()->getClientSideInfo($component, FALSE));
      return [$info, $context->pop()];
    };

    [$info, $cacheability] = $get_client_side_info($component);

    // Use core's `renderer.config` container parameter to determine which cache
    // contexts are considered poorly cacheable.
    $problematic_cache_contexts = array_intersect(
      $cacheability->getCacheContexts(),
      $this->rendererConfig['auto_placeholder_conditions']['contexts']
    );

    // If problematic cache contexts are present, attempt to re-render in a way
    // that the Component preview is strongly cacheable while still
    // sufficiently accurate.
    if (!empty($problematic_cache_contexts)) {
      $ignorable_cache_contexts = ['session', 'user'];

      if (array_diff($problematic_cache_contexts, $ignorable_cache_contexts)) {
        throw new \LogicException(sprintf('No PHP API exists yet to allow specifying a technique to avoid the `%s` cache context(s) while still generating an acceptable preview', implode(',', $problematic_cache_contexts)));
      }

      try {
        $this->accountSwitcher->switchTo(new AnonymousUserSession());
        [$info, $cacheability] = $get_client_side_info($component);
      }
      finally {
        $this->accountSwitcher->switchBack();
      }

      // Ignore these cache contexts because it's been re-rendered as the
      // anonymous user.
      $cacheability->setCacheContexts(array_filter(
        $cacheability->getCacheContexts(),
        fn (string $context): bool => !in_array($context, $ignorable_cache_contexts, TRUE),
      ));
    }

    return [$info, $cacheability];
  }

}
