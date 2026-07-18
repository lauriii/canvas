<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\PageDataController;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

#[RunTestsInSeparateProcesses]
#[CoversClass(PageDataController::class)]
#[Group('canvas')]
class PageDataControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  private const string URL_PREFIX = '/canvas/api/v0/page-data/canvas_page/';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * The target entity requests are made for: has an `fr` translation.
   */
  private Page $page;

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    // German exists as a site language but the page is never translated into
    // it, to cover the requested-vs-rendered language fallback.
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr', 'de' => 'de'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    // `administer code components` grants Canvas UI access; `access content`
    // grants view access to the published page.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);

    $page = Page::create([
      'title' => 'Test page',
      'status' => TRUE,
    ]);
    $page->addTranslation('fr', [
      'title' => 'Page de test',
      'status' => TRUE,
    ]);
    $page->save();
    $this->page = $page;
  }

  public function testGetReturns200WithPageData(): void {
    $response = $this->request(Request::create(self::URL_PREFIX . $this->page->id()));

    self::assertSame(200, $response->getStatusCode());

    $data = static::decodeResponse($response);
    self::assertSame('Test page', $data['pageTitle']);
    self::assertIsArray($data['breadcrumbs']);
    foreach ($data['breadcrumbs'] as $breadcrumb) {
      self::assertIsString($breadcrumb['key']);
      self::assertIsString($breadcrumb['text']);
      self::assertIsString($breadcrumb['url']);
    }

    $main_entity = $data['mainEntity'];
    self::assertSame('canvas_page', $main_entity['bundle']);
    self::assertSame('canvas_page', $main_entity['entityTypeId']);
    self::assertSame($this->page->uuid(), $main_entity['uuid']);
    self::assertSame('en', $main_entity['requestedLanguage']);
    self::assertSame('en', $main_entity['renderedLanguage']);

    $translations = \array_column($main_entity['translations'], NULL, 'langcode');
    self::assertEqualsCanonicalizing(['en', 'fr', 'de'], \array_keys($translations));
    self::assertTrue($translations['en']['current']);
    self::assertTrue($translations['en']['translationAvailable']);
    self::assertFalse($translations['fr']['current']);
    self::assertTrue($translations['fr']['translationAvailable']);
    self::assertSame('French', $translations['fr']['name']);
    self::assertSame('Français', $translations['fr']['nativeName']);
    self::assertFalse($translations['de']['current']);
    self::assertFalse($translations['de']['translationAvailable']);

    // Site-level canvasData.v0 fields belong to the site-data endpoint only.
    self::assertArrayNotHasKey('baseUrl', $data);
    self::assertArrayNotHasKey('branding', $data);
    self::assertArrayNotHasKey('themeAssets', $data);
    self::assertArrayNotHasKey('jsonapiSettings', $data);
  }

  public function testGetDeniedWithoutEntityViewAccess(): void {
    // Canvas UI access alone is not enough: view access to the target entity
    // (`access content` for a Canvas page) is also required.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->request(Request::create(self::URL_PREFIX . $this->page->id()));
  }

  public function testPreviewLangcodeAffectsReturnedLanguage(): void {
    // The hint redirects to the URL the site's language negotiation resolves
    // to the requested language (here: the `fr` path prefix).
    $response = $this->request(Request::create(self::URL_PREFIX . $this->page->id() . '?canvas_preview_langcode=fr'));
    self::assertSame(302, $response->getStatusCode());
    $location = $response->headers->get('Location');
    self::assertIsString($location);
    self::assertStringContainsString('/fr/canvas/api/v0/page-data/canvas_page/', $location);
    self::assertStringNotContainsString('canvas_preview_langcode', $location);

    $response = $this->request(Request::create($location));
    self::assertSame(200, $response->getStatusCode());
    $data = static::decodeResponse($response);
    self::assertSame('Page de test', $data['pageTitle']);
    self::assertSame('fr', $data['mainEntity']['requestedLanguage']);
    self::assertSame('fr', $data['mainEntity']['renderedLanguage']);

    // A language the page has no translation in: the requested language is
    // honored, the rendered translation falls back to the default one.
    $response = $this->request(Request::create(self::URL_PREFIX . $this->page->id() . '?canvas_preview_langcode=de'));
    self::assertSame(302, $response->getStatusCode());
    $location = $response->headers->get('Location');
    self::assertIsString($location);

    $response = $this->request(Request::create($location));
    self::assertSame(200, $response->getStatusCode());
    $data = static::decodeResponse($response);
    self::assertSame('Test page', $data['pageTitle']);
    self::assertSame('de', $data['mainEntity']['requestedLanguage']);
    self::assertSame('en', $data['mainEntity']['renderedLanguage']);
  }

}
