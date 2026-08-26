<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests that canvas URLs with a language prefix are redirected.
 *
 * @see CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath().
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class CanvasLanguageRoutesTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Tests that language-prefixed /canvas URLs redirect to their bare equivalents.
   */
  public function testLanguagePrefixedCanvasUrlRedirectsToDefaultLanguage(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    $page = Page::create([
      'title' => 'Test page',
      'path' => '/test-page',
      'status' => TRUE,
    ]);
    $page->save();
    $page_id = $page->id();

    // Assert /es/canvas redirects to /canvas.
    $response = $this->request(Request::create('/es/canvas'));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A language-prefixed /canvas URL must trigger a 302 redirect.',
    );
    self::assertSame(
      '/canvas',
      $response->headers->get('Location'),
      'The redirect must point to /canvas with the language prefix stripped.',
    );

    // Assert /es/canvas/editor/canvas_page/{id} redirects to
    // /canvas/editor/canvas_page/{id}.
    $editor_path = "/canvas/editor/canvas_page/$page_id";
    $response = $this->request(Request::create("/es$editor_path"));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A language-prefixed /canvas/editor URL must trigger a 302 redirect.',
    );
    self::assertSame(
      $editor_path,
      $response->headers->get('Location'),
      'The redirect must point to the editor URL with the language prefix stripped.',
    );

    // Assert /canvas/api/v0/layout/canvas_page/$page_id is not redirected.
    $api_layout_path = "/canvas/api/v0/layout/canvas_page/$page_id";
    $response = $this->request(Request::create("/es$api_layout_path"));
    self::assertSame(
      200,
      $response->getStatusCode(),
      'A language-prefixed /canvas/api URL must NOT trigger a 302 redirect.',
    );
  }

  /**
   * Tests that /canvas under the default language's own URL prefix redirects.
   *
   * A site can give the default language a non-empty URL prefix (e.g.
   * 'en' => 'en'), so every path — including /canvas — is served under it
   * (/en/canvas). The Canvas client-side router is mounted at /canvas, so if it
   * receives the prefixed /en/canvas its basename never matches the browser URL
   * and the app renders nothing: a white screen. The prefix must be stripped
   * even when it belongs to the default language.
   *
   * @see https://git.drupalcode.org/project/canvas/-/issues/3569487
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath()
   */
  public function testDefaultLanguagePrefixedCanvasUrlRedirects(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    // Give the default language a non-empty URL prefix.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'es' => 'es'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    // Assert /en/canvas (the default language's own prefix) redirects to the
    // prefix-free /canvas.
    $response = $this->request(Request::create('/en/canvas'));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A /canvas URL under the default language prefix must trigger a 302 redirect.',
    );
    self::assertSame(
      '/canvas',
      $response->headers->get('Location'),
      'The redirect must point to /canvas with the default language prefix stripped.',
    );

    // Assert the editor route under the default language prefix strips it too.
    $page = Page::create([
      'title' => 'Test page',
      'path' => '/test-page',
      'status' => TRUE,
    ]);
    $page->save();
    $editor_path = "/canvas/editor/canvas_page/{$page->id()}";
    $response = $this->request(Request::create("/en$editor_path"));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A /canvas/editor URL under the default language prefix must trigger a 302 redirect.',
    );
    self::assertSame(
      $editor_path,
      $response->headers->get('Location'),
      'The redirect must point to the editor URL with the default language prefix stripped.',
    );
  }

  /**
   * Tests that a single-language site with a URL prefix still redirects.
   *
   * A site with only one configured language is not multilingual, yet URL path
   * prefixes are not gated on being multilingual in Drupal core: an admin can
   * still give the sole language a path prefix. The prefix must be stripped, or
   * /en/canvas white-screens exactly as on a multilingual site. This guards
   * against reintroducing an isMultilingual() gate on the redirect, which would
   * skip the strip whenever only one language is configured.
   *
   * @see https://git.drupalcode.org/project/canvas/-/issues/3569487
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath()
   */
  public function testSingleLanguageSiteWithUrlPrefixRedirects(): void {
    // No extra language is added: the site stays monolingual.
    self::assertFalse($this->container->get(LanguageManagerInterface::class)->isMultilingual());

    // The sole (default) language is given a non-empty URL path prefix.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    // The site is still monolingual after configuring the prefix.
    self::assertFalse($this->container->get(LanguageManagerInterface::class)->isMultilingual());

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    $response = $this->request(Request::create('/en/canvas'));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A single-language site with a URL prefix must still 302-redirect /canvas.',
    );
    self::assertSame(
      '/canvas',
      $response->headers->get('Location'),
      'The prefix must be stripped even when the site is not multilingual.',
    );
  }

  /**
   * Tests that the query string survives the redirect, `destination` included.
   *
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath()
   * @see \Drupal\Core\EventSubscriber\RedirectResponseSubscriber
   */
  public function testQueryStringSurvivesRedirect(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    // Admin overview links carry a `destination` query parameter, which
    // RedirectResponseSubscriber uses to override redirect targets.
    $response = $this->request(Request::create('/es/canvas?destination=/admin/content/pages'));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A language-prefixed /canvas URL must trigger a 302 redirect.',
    );
    self::assertSame(
      '/canvas?' . Request::normalizeQueryString('destination=/admin/content/pages'),
      $response->headers->get('Location'),
      'The redirect must keep the query string and must not be overridden by `destination`.',
    );
  }

  /**
   * Verifies redirect safety for languages with custom URL prefixes.
   *
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath()
   */
  public function testLanguageWithCustomPrefixDoesNotCauseRedirectLoop(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Setting up a configurable language uses the langcode as the prefix by
    // default. This works fine.
    $negotiation_config = $this->config('language.negotiation');
    self::assertSame('fr', $negotiation_config->get('url.prefixes.fr'));

    // Customizing the prefix to not match the langcode is what causes problems.
    $negotiation_config->set('url.prefixes.fr', 'francais')->save();
    self::assertSame('francais', $negotiation_config->get('url.prefixes.fr'));
    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);
    $response = $this->request(Request::create('/francais/canvas'));

    self::assertSame(
      302,
      $response->getStatusCode(),
      'A language-prefixed /canvas URL must trigger a 302 redirect.',
    );
    self::assertSame(
      '/canvas',
      $response->headers->get('Location'),
      'The redirect must point to /canvas, not back to /francais/canvas.',
    );
  }

  /**
   * Tests that a leading segment that is not a language prefix is left alone.
   *
   * The redirect strips the URL-negotiated language's prefix. A `/xx/canvas`
   * whose `xx` is not any language's prefix negotiates to the default language,
   * whose prefix (here a non-empty 'en') does not match the path. The path must
   * be passed through untouched, not stripped into a mangled `//canvas`.
   *
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath()
   */
  public function testUnknownLeadingSegmentIsNotRedirected(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    // The default language carries a non-empty prefix, so a non-matching
    // leading segment still reaches the prefix comparison.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'es' => 'es'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    // The redirect subscriber runs before the router. If it stripped the
    // non-matching `foo` segment it would return a (mangled) 302 and the router
    // would never run. Reaching the router — which has no route for /foo/canvas
    // and so throws — proves the segment was left intact.
    $this->expectException(NotFoundHttpException::class);
    $this->request(Request::create('/foo/canvas'));
  }

  /**
   * Tests that domain negotiation does not strip a matching path segment.
   *
   * Under domain URL negotiation the language comes from the host, not the
   * path, so a leading path segment is a real path — not a language prefix —
   * even when it happens to match a configured prefix. Core still populates
   * url.prefixes ('es' => 'es') on language save, so without a source check the
   * redirect would misread /es/canvas on the Spanish domain as prefixed and
   * strip it.
   *
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToUnprefixedPath()
   * @see \Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl::processInbound()
   */
  public function testDomainNegotiationDoesNotStripPathSegment(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    // The language is negotiated from the host. url.prefixes is left populated
    // (core sets 'es' => 'es' on language save) to prove the source check, not
    // an empty prefix, is what prevents the strip.
    $this->config('language.negotiation')
      ->set('url.source', LanguageNegotiationUrl::CONFIG_DOMAIN)
      ->set('url.domains', ['en' => 'example.com', 'es' => 'example.es'])
      ->save();
    self::assertSame('es', $this->config('language.negotiation')->get('url.prefixes.es'));

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    // On the Spanish domain, /es/canvas has `/es/` as a real path segment. If
    // the subscriber stripped it, it would return a 302 and the router would
    // never run; reaching the router (no route for /es/canvas) proves the
    // segment was left intact.
    $this->expectException(NotFoundHttpException::class);
    $this->request(Request::create('http://example.es/es/canvas'));
  }

}
