<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Render;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\MainContent\MainContentRendererInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\multi_frontend\Envelope\CacheabilityHeaders;
use Drupal\multi_frontend\Envelope\EnvelopeBuilder;
use Drupal\multi_frontend\Envelope\PageEnvelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

/**
 * Renders a page as an envelope instead of as HTML.
 *
 * This is D5b's mechanism, and it turned out to be cheaper than the design
 * assumed: core already selects a main content renderer by wrapper format, so
 * the envelope response is produced by the route that already serves the
 * path. Access checking, parameter upcasting, redirects, error statuses and
 * language negotiation are therefore not reimplemented, they are the same
 * request. A catch-all /page-api/{path} route would have had to redo all of
 * them, which is where the equivalent designs go wrong.
 *
 * Reached as /any/path?_wrapper_format=envelope, or through the /page-api
 * prefix, which is a middleware alias for exactly that.
 *
 * @see \Drupal\multi_frontend\StackMiddleware\PageApiMiddleware
 * @see \Drupal\Core\EventSubscriber\MainContentViewSubscriber
 */
final class EnvelopeMainContentRenderer implements MainContentRendererInterface {

  public const FORMAT = 'envelope';

  public function __construct(
    private readonly EnvelopeBuilder $envelopeBuilder,
    private readonly TitleResolverInterface $titleResolver,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function renderResponse(array $main_content, Request $request, RouteMatchInterface $route_match): Response {
    $route = $route_match->getRouteObject();
    if ($route !== NULL && $route->getOption('_admin_route')) {
      // Administration rendering stays on render arrays and the Form API.
      // Serving an envelope for it would imply a contract this change
      // deliberately does not make.
      throw new NotAcceptableHttpException('The envelope format is not available for administration routes.');
    }

    $cacheability = new CacheableMetadata();
    // The envelope serializes the negotiated content language, and page
    // content can supply no language context of its own, so a static page
    // would otherwise let one language's envelope be reused for another.
    $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    $nodes = $this->envelopeBuilder->build($main_content, $cacheability);

    $envelope = new PageEnvelope(
      [
        'title' => $this->title($request, $route_match, $cacheability),
        'langcode' => $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
        'layout' => 'default',
      ],
      // Only the content region is populated. The remaining regions come from
      // the active theme's block layout, which is theme-scoped configuration
      // and is deliberately not reimplemented here.
      ['content' => $nodes],
      $cacheability,
    );

    $response = new CacheableJsonResponse($envelope->toArray());
    $response->addCacheableDependency($cacheability);
    CacheabilityHeaders::apply($response, $cacheability);
    return $response;
  }

  /**
   * Resolves the page title, collecting whatever cacheability it bubbles.
   */
  private function title(Request $request, RouteMatchInterface $route_match, CacheableMetadata $cacheability): ?string {
    $route = $route_match->getRouteObject();
    if ($route === NULL) {
      return NULL;
    }
    $title = $this->titleResolver->getTitle($request, $route);
    if ($title === NULL) {
      return NULL;
    }
    // The title crosses as plain text, always. Core can tell a markup title
    // from a raw one, because one is a MarkupInterface and Twig escapes the
    // other. A consumer receiving `string` cannot, and would have to choose
    // between escaping markup titles into gibberish and rendering a raw node
    // label as HTML. Flattening to text removes the choice.
    if (\is_array($title)) {
      $build = $title;
      $rendered = (string) $this->renderer->renderInIsolation($build);
      $cacheability->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));
      return PlainTextOutput::renderFromHtml($rendered);
    }
    if ($title instanceof MarkupInterface) {
      return PlainTextOutput::renderFromHtml((string) $title);
    }
    return (string) $title;
  }

}
