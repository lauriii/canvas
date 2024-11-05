<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\experience_builder\Entity\Component;

/**
 * Provides the client side with all information needed of all XB Components.
 *
 * @see ui/src/types/Component.ts
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 * @phpstan-type ComponentClientSideTypeAny array{'id': string, 'name': string, 'default_markup': string|\Stringable, 'css': string|\Stringable, 'js_header': string|\Stringable, 'js_footer': string|\Stringable}
 * @phpstan-type ComponentClientSideTypeSdc array{'id': string, 'name': string, 'default_markup': string|\Stringable, 'css': string|\Stringable, 'js_header': string|\Stringable, 'js_footer': string|\Stringable, 'metadata': array<string, mixed>, 'field_data': array<string, mixed>, 'dynamic_prop_source_candidates': array<string, mixed>,}
 */
final class ApiComponentsController {

  public function __construct(
    private readonly RendererInterface $renderer,
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
    $cacheability = new CacheableMetadata();

    $component_info_list = [];
    foreach (Component::loadMultiple() as $component) {
      // Hide disabled components.
      if (!$component->status()) {
        continue;
      }
      try {
        $context = new RenderContext();
        $component_info_list[] = $this->renderer->executeInRenderContext($context, function () use ($component) {
           return $component->getComponentSource()->getClientSideInfo($component, FALSE);
        });

        // @todo refactor in https://www.drupal.org/project/experience_builder/issues/3484678 to use value objects instead, to avoid this awkward rendering in a render context.
        $component_client_side_info_cacheability = $context->pop();
        // Ignore the cache tags for individual XB Component config entities,
        // because this response lists them, so the list cache tag is sufficient
        // and the rest is pointless noise.
        // @see \Drupal\Core\Entity\EntityTypeInterface::getListCacheTags()
        $relevant_cache_tags = array_filter(
          $component_client_side_info_cacheability->getCacheTags(),
          fn (string $tag) => !str_starts_with($tag, 'config:experience_builder.component.'),
        );
        $component_client_side_info_cacheability->setCacheTags($relevant_cache_tags);
        $cacheability->addCacheableDependency($component_client_side_info_cacheability);
      }
      catch (\Exception) {
        // @todo Skip failed plugins for now, see https://www.drupal.org/project/experience_builder/issues/3484672
      }
    }

    // Even if one or more of the component previews is technically not
    // cacheable, cache it anyway for up to 1 minute, precisely because it is
    // just a preview.
    if ($cacheability->getCacheMaxAge() === 0) {
      $cacheability->setCacheMaxAge(60);
    }

    return [
      // Component array is keyed by ID.
      array_combine(array_column($component_info_list, 'id'), $component_info_list),
      $cacheability,
    ];
  }

}
