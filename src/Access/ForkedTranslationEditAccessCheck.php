<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\ContentTranslation\TranslationForkStateResolver;
use Drupal\canvas\Routing\NamedTranslationConverter;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Allows editing a non-default translation only when it is forked.
 *
 * A forked translation owns its component tree, so the Canvas editor edits it
 * like the default translation. Symmetric translations stay preview-only. The
 * check applies to the translation named by `?canvas_langcode=`, as decided by
 * its auto-save draft when it has one.
 *
 * @see \Drupal\canvas\Routing\NamedTranslationConverter
 *
 * @internal
 */
final readonly class ForkedTranslationEditAccessCheck implements AccessInterface {

  public function __construct(
    private TranslationForkStateResolver $forkStateResolver,
  ) {}

  public function access(Route $route, RouteMatchInterface $route_match, ?Request $request = NULL): AccessResultInterface {
    // The response depends on the named translation.
    $result = AccessResult::allowed()->addCacheContexts(['url.query_args:' . NamedTranslationConverter::QUERY_KEY]);
    // Access checks for a named route, e.g. Url::access() while filtering
    // language switch links, have no request: they name no translation.
    // @see \Drupal\Core\Access\AccessManager::checkNamedRoute()
    if (!$request?->query->has(NamedTranslationConverter::QUERY_KEY)) {
      return $result;
    }
    foreach ($route->getOption('parameters') ?? [] as $name => $definition) {
      $translation = $route_match->getParameter($name);
      if (!isset($definition['canvas_translation'])
        || !$translation instanceof ContentEntityInterface
        || $translation->isDefaultTranslation()) {
        continue;
      }
      if (!$this->forkStateResolver->resolve($translation, $translation->language()->getId())->isForked()) {
        return AccessResult::forbidden('Only forked translations can be edited in the Canvas editor.')->addCacheableDependency($result);
      }
    }
    return $result;
  }

}
