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

}
