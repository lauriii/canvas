<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Affects only Canvas-owned routes.
 *
 * @internal
 */
final class CanvasRouteOptionsEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  public function redirectCanvasToDefaultLanguage(RequestEvent $event): void {
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    // Only act on /canvas paths, but not canvas API paths - those handle
    // language negotiation themselves and must not be redirected.
    if (!preg_match('#^/[^/]+/canvas(/|$)#', $path) || str_contains($path, '/canvas/api/')) {
      return;
    }

    // If the current language differs from the default, the URL will contain a
    // language prefix (e.g. /es/canvas/editor/canvas_page/1). Strip it with a
    // 302 redirect so that Canvas always receives a prefix-free path
    // (/canvas/editor/canvas_page/1).
    // @todo Remove this redirect once Canvas natively supports
    //   language-prefixed URLs in
    //   https://git.drupalcode.org/project/canvas/-/work_items/3546597.
    // @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::preventRouteNormalization()
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $current_langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL)->getId();
    if ($current_langcode !== $default_langcode) {
      $base_path = $request->getBasePath();
      $canvas_path = preg_replace('#^/' . preg_quote($current_langcode, '#') . '/#', '/', $path);
      $event->setResponse(new LocalRedirectResponse($base_path . $canvas_path, 302));
    }
  }

  public function transformWrapperFormatRouteOption(RequestEvent $event): void {
    if (!str_starts_with($this->routeMatch->getRouteName() ?? '', 'canvas.api.')) {
      return;
    }

    // Allow Canvas routes to declare they must always use a particular main
    // content renderer, by accepting a `_wrapper_format` route option that is
    // upcast to the URL query parameter that Drupal core expects.
    // @see \Drupal\Core\EventSubscriber\MainContentViewSubscriber::WRAPPER_FORMAT
    // @see \Drupal\canvas\Render\MainContent\CanvasTemplateRenderer
    $route_object = $this->routeMatch->getRouteObject();
    if (!\is_null($route_object) && $wrapper_format = $route_object->getOption('_wrapper_format')) {
      $event->getRequest()->query->set(MainContentViewSubscriber::WRAPPER_FORMAT, $wrapper_format);
    }
  }

  public static function addCsrfToken(RouteBuildEvent $event): void {
    foreach ($event->getRouteCollection() as $name => $route) {
      if (str_starts_with($name, 'canvas.api.') &&
        // Drupal's AJAX submits to these URL and doesn't know that it needs to
        // add an X-CSRF-Token header. These routes use Drupal's form API which
        // already includes CSRF protection via a hidden input.
        $route->getOption('_wrapper_format') !== 'canvas_template') {
        if (array_intersect($route->getMethods(), ['POST', 'PATCH', 'DELETE'])) {
          $route->setRequirement('_csrf_request_header_token', 'TRUE');
        }
      }
    }
  }

  public static function preventRouteNormalization(RouteBuildEvent $event): void {
    foreach ($event->getRouteCollection()->getIterator() as $route_name => $route) {
      \assert($route instanceof Route);
      // This ensures our react based routing works with redirect module
      // enabled.
      // @see \Drupal\canvas\PathProcessor\CanvasPathProcessor::processInbound.
      if (str_starts_with($route_name, 'canvas.')) {
        $route->setDefault('_disable_route_normalizer', TRUE);
      }
    }
  }

  public static function enforceJsonFormatForApis(RouteBuildEvent $event): void {
    foreach ($event->getRouteCollection() as $route_name => $route) {
      if (str_starts_with($route_name, 'canvas.api.')) {
        $route->setRequirement('_format', 'json');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['redirectCanvasToDefaultLanguage', 100];
    $events[KernelEvents::REQUEST][] = ['transformWrapperFormatRouteOption'];
    $events[RoutingEvents::ALTER][] = ['addCsrfToken'];
    $events[RoutingEvents::ALTER][] = ['preventRouteNormalization'];
    $events[RoutingEvents::ALTER][] = ['enforceJsonFormatForApis'];
    return $events;
  }

}
