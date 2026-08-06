<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\metatag\MetatagManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds safe, framework-neutral document-head data compatible with Unhead.
 *
 * @see https://unhead.unjs.io/
 */
final class CanvasContentHeadBuilder {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TitleResolverInterface $titleResolver,
    private readonly ?MetatagManagerInterface $metatagManager = NULL,
  ) {}

  /**
   * Builds the document head for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity whose document metadata is resolved.
   *
   * @return array{head: array, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The normalized head and all metadata needed to cache it.
   */
  public function build(ContentEntityInterface $entity): array {
    $cacheability = (new CacheableMetadata())->addCacheableDependency($entity);
    $elements = [];

    if ($this->metatagManager !== NULL) {
      $context = new RenderContext();
      $metatag_elements = $this->renderer->executeInRenderContext(
        $context,
        function () use ($entity): array {
          \assert($this->metatagManager !== NULL);
          $tags = $this->metatagManager->tagsFromEntityWithDefaults($entity);
          $alter_context = ['entity' => &$entity];
          $this->moduleHandler->alter('metatags', $tags, $alter_context);
          return $this->metatagManager->generateRawElements($tags, $entity);
        },
      );
      if (!$context->isEmpty()) {
        $cacheability->addCacheableDependency($context->pop());
      }
      if ($this->entityTypeManager->hasDefinition('metatag_defaults')) {
        $cacheability->addCacheTags(
          $this->entityTypeManager
            ->getDefinition('metatag_defaults')
            ->getListCacheTags(),
        );
      }
      $elements = array_values($metatag_elements);
    }

    return [
      'head' => self::normalize($elements, (string) ($entity->label() ?? '')),
      'cacheability' => $cacheability,
    ];
  }

  /**
   * Builds the document head from a resolved route title.
   *
   * @return array{head: array, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The normalized head and route metadata cacheability.
   */
  public function buildFromRoute(
    Request $request,
    RouteMatchInterface $route_match,
  ): array {
    $route = $route_match->getRouteObject();
    \assert($route !== NULL);
    $title = $this->titleResolver->getTitle($request, $route);
    $cacheability = new CacheableMetadata();
    if (\is_array($title)) {
      $context = new RenderContext();
      $title = $this->renderer->executeInRenderContext(
        $context,
        function () use (&$title): string {
          \assert(\is_array($title));
          return (string) $this->renderer->render($title);
        },
      );
      if (!$context->isEmpty()) {
        $cacheability->addCacheableDependency($context->pop());
      }
    }
    if (!\is_string($title) && !$title instanceof \Stringable) {
      $title = '';
    }
    return [
      'head' => self::normalize(
        [],
        Html::decodeEntities(trim(strip_tags((string) $title))),
      ),
      'cacheability' => $cacheability,
    ];
  }

  /**
   * Normalizes supported render elements to the public head shape.
   *
   * @param array[] $elements
   *   Head render elements.
   * @param string $fallback_title
   *   The title used when no supported title metadata exists.
   *
   * @return array{
   *   title: string,
   *   meta?: list<array<string, string>>,
   *   link?: list<array<string, string>>,
   *   script?: list<array{
   *     type: 'application/ld+json',
   *     textContent: array<array-key, mixed>
   *   }>
   *   }
   *   The normalized head object.
   */
  public static function normalize(array $elements, string $fallback_title): array {
    $head = ['title' => $fallback_title];
    foreach ($elements as $element) {
      if (!\is_array($element) || !isset($element['#tag']) || !\is_string($element['#tag'])) {
        continue;
      }
      $tag = strtolower($element['#tag']);
      $attributes = $element['#attributes'] ?? [];
      if (!\is_array($attributes)) {
        continue;
      }

      if ($tag === 'title') {
        $title = self::elementText($element);
        if ($title !== NULL && $attributes === []) {
          $head['title'] = $title;
        }
        continue;
      }

      if ($tag === 'meta') {
        $meta = self::normalizeAttributes($attributes);
        if ($meta === []) {
          continue;
        }
        if (($meta['name'] ?? NULL) === 'title' && isset($meta['content'])) {
          $head['title'] = $meta['content'];
          continue;
        }
        $head['meta'][] = $meta;
        continue;
      }

      if ($tag === 'link') {
        $link = self::normalizeAttributes($attributes);
        if (!isset($link['rel'], $link['href'])) {
          continue;
        }
        $link['rel'] = strtolower(trim($link['rel']));
        $link['href'] = trim($link['href']);
        $relations = preg_split('/\s+/', $link['rel'], flags: \PREG_SPLIT_NO_EMPTY);
        if (
          \is_array($relations) &&
          (
            \in_array('canonical', $relations, TRUE) ||
            \in_array('stylesheet', $relations, TRUE)
          )
        ) {
          continue;
        }
        $head['link'][] = $link;
        continue;
      }

      if ($tag === 'script') {
        $script_attributes = self::normalizeAttributes($attributes);
        $text = self::elementText($element);
        if (
          $script_attributes !== ['type' => 'application/ld+json'] ||
          $text === NULL
        ) {
          continue;
        }
        try {
          $decoded = json_decode($text, associative: TRUE, flags: \JSON_THROW_ON_ERROR);
        }
        catch (\JsonException) {
          continue;
        }
        if (!\is_array($decoded)) {
          continue;
        }
        $head['script'][] = [
          'type' => 'application/ld+json',
          'textContent' => $decoded,
        ];
      }
    }
    return $head;
  }

  /**
   * Normalizes non-executable scalar HTML attributes.
   */
  private static function normalizeAttributes(array $attributes): array {
    $normalized = [];
    foreach ($attributes as $name => $value) {
      $name = strtolower((string) $name);
      if (
        !preg_match('/^[a-z_:][a-z0-9:._-]*$/', $name) ||
        str_starts_with($name, 'on') ||
        !\is_scalar($value)
      ) {
        continue;
      }
      $normalized[$name] = (string) $value;
    }
    return $normalized;
  }

  /**
   * Reads a plain scalar value without rendering arbitrary markup.
   */
  private static function elementText(array $element): ?string {
    foreach (['#plain_text', '#value'] as $key) {
      if (isset($element[$key]) && (\is_scalar($element[$key]) || $element[$key] instanceof \Stringable)) {
        return (string) $element[$key];
      }
    }
    return NULL;
  }

}
