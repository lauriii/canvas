<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Routing;

use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\Routing\Route;

/**
 * Identifies the entity and view mode shared by Drupal and frontend rendering.
 *
 * Standard entity canonical, preview, revision, and latest-version route names
 * are supported. Conversion and access remain the route provider's
 * responsibility.
 */
final readonly class CanvasContentRoute {

  private function __construct(
    public ContentEntityInterface $entity,
    public string $viewMode,
    public bool $isPreview,
  ) {}

  /**
   * Resolves a display route from its already-converted parameters.
   *
   * @param array<string, mixed> $parameters
   *   Converted route parameters and defaults.
   */
  public static function resolve(?string $route_name, ?Route $route, array $parameters): ?self {
    // Forms and revision management screens never render an entity preview.
    if ($route?->hasDefault('_entity_form') || $route?->hasDefault('_form')) {
      return NULL;
    }
    if ($route_name !== NULL && preg_match('/^entity\.([a-z0-9_]+)\.(canonical|preview|revision|latest_version)$/', $route_name, $matches)) {
      [, $entity_type, $operation] = $matches;
      $candidates = match ($operation) {
        'preview' => [$entity_type . '_preview', $entity_type],
        'revision' => [$entity_type . '_revision'],
        default => [$entity_type],
      };
      // Route providers may use a different parameter name. Revision routes
      // must select the revision parameter, never the current stored entity.
      $parameter_type = ($operation === 'revision' ? 'entity_revision:' : 'entity:') . $entity_type;
      $options = $route?->getOption('parameters');
      foreach (\is_array($options) ? $options : [] as $name => $definition) {
        if (\is_array($definition) && ($definition['type'] ?? NULL) === $parameter_type) {
          $candidates[] = (string) $name;
        }
      }
    }
    else {
      return NULL;
    }

    foreach ($candidates as $parameter) {
      $entity = $parameters[$parameter] ?? NULL;
      if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== $entity_type) {
        continue;
      }
      $view_mode = $parameters['view_mode_id'] ?? $parameters['view_mode'] ?? $route?->getDefault('view_mode');
      $entity_view = $route?->getDefault('_entity_view');
      if ($view_mode === NULL && \is_string($entity_view) && str_contains($entity_view, '.')) {
        [, $view_mode] = explode('.', $entity_view, 2);
      }
      $view_mode ??= 'full';
      if (!\is_string($view_mode) || $view_mode === '') {
        return NULL;
      }
      return new self($entity, $view_mode, $operation !== 'canonical');
    }
    return NULL;
  }

}
