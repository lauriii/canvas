<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Points editor requests at the forked translation named by the client.
 *
 * A forked translation owns its component tree, so the Canvas editor edits it
 * like the default translation. Editor requests name the translation with an
 * explicit `?canvas_langcode=` query parameter rather than through language
 * negotiation: the `canvas_preview_langcode` negotiation redirect is GET-only
 * by design, and redirecting request bodies through negotiation-specific URLs
 * is fragile.
 *
 * After routing, the upcast route entity is swapped for the named translation
 * and route access is checked again against it. Route controllers read the
 * swapped entity unchanged: auto-save keys and draft overlays follow its
 * language. Symmetric translations stay read-only in the editor, so a
 * non-default translation is only accepted when it is forked, as decided by
 * its auto-save draft when it has one. The discard route also accepts a
 * pending unfork (forked in storage only), so it can be discarded.
 *
 * Not gated on canvas_dev_translation: without fork support no translation is
 * forked, so only the default translation can be named.
 *
 * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasApiToPreviewLanguage()
 */
final class ForkedTranslationEditRequestSubscriber implements EventSubscriberInterface {

  public const string QUERY_KEY = 'canvas_langcode';

  /**
   * The editor routes that accept the parameter, keyed to their entity param.
   */
  private const array ROUTE_ENTITY_PARAMETERS = [
    'canvas.api.layout.get' => 'entity',
    'canvas.api.layout.patch' => 'entity',
    'canvas.api.layout.post' => 'entity',
    'canvas.api.form.component_instance' => 'entity',
    'canvas.api.form.content_entity' => 'entity',
    'canvas.api.content.auto-save.patch' => 'canvas_page',
    'canvas.api.auto-save.delete' => 'entity',
  ];

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly CurrentRouteMatch $currentRouteMatch,
    private readonly AccessManagerInterface $accessManager,
  ) {}

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    $langcode = $request->query->get(self::QUERY_KEY);
    if (!\is_string($langcode) || $langcode === '') {
      return;
    }
    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    $parameter = self::ROUTE_ENTITY_PARAMETERS[$route_name] ?? NULL;
    if ($parameter === NULL) {
      return;
    }
    $entity = $request->attributes->get($parameter);
    // Config entities (e.g. code components on the discard route) have no
    // content translations; the parameter does not apply to them.
    if (!$entity instanceof ContentEntityInterface || !$entity->isTranslatable()) {
      return;
    }

    $default_translation = $entity->getUntranslated();
    if ($default_translation->language()->getId() === $langcode) {
      $translation = $default_translation;
    }
    else {
      // Never fall back to another translation: acting on a translation the
      // client did not name would write to the wrong language.
      if (!$entity->hasTranslation($langcode)) {
        throw new NotFoundHttpException(\sprintf('%s %s has no %s translation.', $entity->getEntityTypeId(), $entity->id(), $langcode));
      }
      $translation = $entity->getTranslation($langcode);
      if (!$this->isForked($translation, accept_pending_unfork: $route_name === 'canvas.api.auto-save.delete')) {
        throw new AccessDeniedHttpException('Only forked translations can be edited in the Canvas editor.');
      }
    }

    $request->attributes->set($parameter, $translation);
    // The current route match caches the route parameters per request.
    $this->currentRouteMatch->resetRouteMatch();
    // The router checked route access against the translation it upcast,
    // which is the negotiated one, not the one named here.
    if (!$this->accessManager->checkRequest($request)) {
      throw new AccessDeniedHttpException();
    }
  }

  public static function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if ($response instanceof CacheableResponseInterface
      && isset(self::ROUTE_ENTITY_PARAMETERS[$event->getRequest()->attributes->get(RouteObjectInterface::ROUTE_NAME)])) {
      $response->addCacheableDependency((new CacheableMetadata())->setCacheContexts(['url.query_args:' . self::QUERY_KEY]));
    }
  }

  /**
   * Whether the translation is forked, as the editor sees it.
   *
   * A pending fork or unfork lives in the auto-save draft until it is
   * published, so a draft decides; without one, storage does. A pending unfork
   * is forked in storage only.
   */
  private function isForked(ContentEntityInterface $translation, bool $accept_pending_unfork): bool {
    $stored = ComponentTreeTranslationFork::isForkedTranslation($translation);
    $draft = $this->autoSaveManager->getAutoSaveEntity($translation);
    if ($draft->isEmpty() || !$draft->entity instanceof ContentEntityInterface) {
      return $stored;
    }
    return ComponentTreeTranslationFork::isForkedTranslation($draft->entity)
      || ($accept_pending_unfork && $stored);
  }

  public static function getSubscribedEvents(): array {
    // After the router (priority 32), which upcasts the route entity and
    // checks route access, and before the controller runs.
    $events[KernelEvents::REQUEST][] = ['onRequest', 31];
    // Before Dynamic Page Cache stores the response (priority 100).
    $events[KernelEvents::RESPONSE][] = ['onResponse', 101];
    return $events;
  }

}
