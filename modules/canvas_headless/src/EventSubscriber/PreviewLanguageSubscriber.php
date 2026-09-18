<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas_headless\PreviewLanguageRedirectResponse;
use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects read-only previews through the site's language negotiation.
 *
 * @internal
 */
final class PreviewLanguageSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?InboundPathProcessorInterface $languagePathProcessor,
  ) {}

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || !$request->attributes->has(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE) || !PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount())) {
      return;
    }
    $context = $request->attributes->get(CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY, []);
    $langcode = $context[CanvasContentApiRequest::PREVIEW_LANGUAGE_QUERY] ?? NULL;
    if ($langcode === NULL) {
      return;
    }
    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL) {
      throw new NotFoundHttpException();
    }
    if ($this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId() === $langcode) {
      return;
    }
    // One transport redirect is enough for core prefix/session negotiation.
    // If the site cannot honor the hint, fail rather than loop or render EN.
    if ($this->languagePathProcessor === NULL || $request->attributes->get(CanvasContentApiRequest::LANGUAGE_REDIRECT_QUERY)) {
      throw new NotFoundHttpException('The requested preview language could not be negotiated.');
    }

    // Internal URLs use core inbound processing to resolve prefixes and aliases
    // to routes before generating the target-language URL. When a detached
    // preview has no route, let core strip the language prefix instead.
    $path = $request->getPathInfo();
    $url = Url::fromUri('internal:' . $path);
    if (!$url->isRouted()) {
      $path = $this->languagePathProcessor->processInbound($path, $request);
      $url = Url::fromUri('base:' . $path, ['path_processing' => TRUE]);
    }
    $query = ($url->getOption('query') ?? []) + $request->query->all();
    $links = $this->languageManager->getLanguageSwitchLinks(LanguageInterface::TYPE_CONTENT, $url);
    if ($links instanceof \stdClass) {
      $query = ($links->links[$langcode]['query'] ?? []) + $query;
    }
    // The middleware reconstructs this cache-context mirror on the next hop.
    unset($query[CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY]);
    $url->setOption('language', $language)->setOption('query', $query);
    // Routing has not run yet: do not rely on the router's request context for
    // the installation base. Core outbound processors can still reject/change
    // this base (e.g. domain negotiation), which is checked below.
    $url->setOption('base_url', $request->getBaseUrl());
    $target = $url->toString();
    // Never redirect a bearer credential to another origin. Domain negotiation
    // remains unsupported, just as it is for the embedded preview session.
    if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
      throw new NotFoundHttpException('Cross-domain preview language negotiation is not supported.');
    }
    // Generated URLs include the installation base; requestUri is Drupal-root
    // relative. Remove only that exact base, never a language/path segment.
    $base = $request->getBaseUrl();
    if ($base !== '') {
      if (!str_starts_with($target, $base . '/') && !str_starts_with($target, $base . '?') && $target !== $base) {
        throw new NotFoundHttpException('The preview language URL is outside the Drupal installation.');
      }
      $target = substr($target, strlen($base));
      if ($target === '' || str_starts_with($target, '?')) {
        $target = '/' . $target;
      }
    }
    $event->setResponse(new PreviewLanguageRedirectResponse($base . CanvasContentApiRequest::API_PATH . '?' . http_build_query([
      'requestUri' => $target,
      ...$context,
      CanvasContentApiRequest::LANGUAGE_REDIRECT_QUERY => '1',
    ])));
  }

  public static function getSubscribedEvents(): array {
    // After authentication and language initialization, before routing/access.
    return [KernelEvents::REQUEST => ['onRequest', 250]];
  }

}
