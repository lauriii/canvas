<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that canvas URLs with a language prefix are redirected.
 *
 * @see CanvasRouteOptionsEventSubscriber::redirectCanvasToDefaultLanguage().
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
   * Tests that a prefix configured for the default language is stripped too.
   *
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToDefaultLanguage()
   */
  public function testDefaultLanguagePrefixedCanvasUrlRedirects(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    // A prefix may be configured for every language, including the default.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'es' => 'es'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    $response = $this->request(Request::create('/en/canvas'));
    self::assertSame(
      302,
      $response->getStatusCode(),
      'A default-language-prefixed /canvas URL must trigger a 302 redirect.',
    );
    self::assertSame(
      '/canvas',
      $response->headers->get('Location'),
      'A prefix configured for the default language must be stripped like any other prefix.',
    );
  }

  /**
   * Verifies redirect safety for languages with custom URL prefixes.
   *
   * @see \Drupal\canvas\EventSubscriber\CanvasRouteOptionsEventSubscriber::redirectCanvasToDefaultLanguage()
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

}
