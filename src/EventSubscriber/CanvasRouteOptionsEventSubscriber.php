<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
    private readonly ConfigFactoryInterface $configFactory,
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
      // Fetch the actual prefix configured for this language in Drupal's
      // URL language negotiation settings, falling back to the langcode.
      $config = $this->configFactory->get('language.negotiation');
      $url_settings = $config->get('url');
      $prefixes = $url_settings['prefixes'] ?? [];
      $prefix = $prefixes[$current_langcode] ?? $current_langcode;
      $prefix_path = "/$prefix/";
      if (\str_starts_with($path, $prefix_path)) {
        $canvas_path = substr_replace($path, '/', 0, strlen($prefix_path));
        $event->setResponse(new LocalRedirectResponse($base_path . $canvas_path, 302));
      }
    }
  }

  /**
   * Redirects layout GET requests to the hinted preview translation.
   *
   * The `?canvas_preview_langcode` hint names the translation to preview. It
   * must not conflict with e.g. the `session.parameter` language negotiation.
   *
   * This is an event subscriber rather than a `#[LanguageNegotiation]` plugin
   * because the goal is not to negotiate a language — it is to defer to the
   * negotiation the site already has. A negotiation plugin would compete with
   * the site's configured negotiators, would need a site builder to enable and
   * order it in the `language.types` configuration (which Canvas must not
   * modify on install), and would apply to every request on the site. This
   * subscriber instead applies to exactly three internal routes and lets the
   * active negotiation chain build the URL that resolves to the requested
   * language, redirecting there.
   */
  public function redirectCanvasApiToPreviewLanguage(RequestEvent $event): void {
    $request = $event->getRequest();
    // Only safe (GET/HEAD) requests carry the preview language hint; never
    // redirect a request that has a body.
    if (!$request->isMethodSafe()) {
      return;
    }
    // Only the GET routes the Canvas UI and Workbench preview through accept
    // a preview language hint.
    $route_name = $this->routeMatch->getRouteName();
    if (!\in_array($route_name, ['canvas.api.layout.get', 'canvas.api.layout.get.content_template', 'canvas.api.page_data'], TRUE)) {
      return;
    }
    $langcode = $request->query->get('canvas_preview_langcode');
    if (!\is_string($langcode) || $langcode === '') {
      return;
    }

    // A hint naming a language that does not exist is a request for a
    // translation that cannot exist: 404. This is the right place for it —
    // this subscriber owns the `canvas_preview_langcode` hint, and it runs
    // before the controller, which never sees the hint.
    $languages = $this->languageManager->getLanguages();
    if (!isset($languages[$langcode])) {
      throw new NotFoundHttpException();
    }
    $language = $languages[$langcode];

    // If the active negotiation chain already resolved the content language to
    // the requested one — e.g. a query/session/domain negotiator that honors
    // the incoming request directly, or the requested language being the
    // default — there is nothing to redirect.
    // @todo Assumes the interface and content languages negotiate to the same language; support divergent interface/content negotiation in https://git.drupalcode.org/project/canvas/-/work_items/3546597.
    if ($this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId() === $language->getId()) {
      return;
    }

    // Otherwise let the active negotiation chain build the URL that resolves
    // to the requested language instead of assuming a particular negotiation
    // method, and redirect there. Drop the `canvas_preview_langcode` hint; the
    // redirect carries whatever the negotiator needs.
    $query = $request->query->all();
    unset($query['canvas_preview_langcode']);
    $url = Url::fromRoute($route_name, $this->routeMatch->getRawParameters()->all());
    // Negotiators that read a query parameter declare it in their language
    // switch links — the same mechanism the language switcher block uses — so
    // merge in the query of the link that switches the content language.
    $switch_links = $this->languageManager->getLanguageSwitchLinks(LanguageInterface::TYPE_CONTENT, $url);
    $switch_query = $switch_links instanceof \stdClass ? ($switch_links->links[$langcode]['query'] ?? NULL) : NULL;
    if (\is_array($switch_query)) {
      unset($switch_query['canvas_preview_langcode']);
      $query = $switch_query + $query;
    }
    // Negotiators that read the language from the URL path or domain encode it
    // during URL generation, from the `language` option.
    $url->setOption('language', $language);
    $url->setOption('query', $query);
    // Not a LocalRedirectResponse: with domain negotiation, the URL that
    // resolves to the requested language lives on another domain of this same
    // site. The URL is generated above from the matched route, not from user
    // input, so it is trusted.
    $event->setResponse(new TrustedRedirectResponse($url->toString(), 302));
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
    // Runs after the router (priority 32) so the matched route and raw
    // parameters are available, but before the OpenAPI request validator
    // (priority 0) so a redirected request never reaches it.
    $events[KernelEvents::REQUEST][] = ['redirectCanvasApiToPreviewLanguage', 30];
    $events[KernelEvents::REQUEST][] = ['transformWrapperFormatRouteOption'];
    $events[RoutingEvents::ALTER][] = ['addCsrfToken'];
    $events[RoutingEvents::ALTER][] = ['preventRouteNormalization'];
    $events[RoutingEvents::ALTER][] = ['enforceJsonFormatForApis'];
    return $events;
  }

}
