<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests Code Component Data Provider.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class CodeComponentDataProviderTest extends FunctionalTestBase {

  use ContribStrictConfigSchemaTestTrait;

  protected static $modules = [
    'canvas',
    'canvas_test_code_components',
  ];

  protected $defaultTheme = 'stark';

  /**
   * Tests v 0 using drupal settings get site data.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataBaseUrlV0
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataBrandingV0
   */
  public function testV0UsingDrupalSettingsGetSiteData(): void {
    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.canvas_test_code_components_using_drupalsettings_get_site_data',
        ],
      ],
    ]);
    $page->save();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet($page->toUrl());

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);
    self::assertSame([
      'baseUrl' => \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl(),
      'branding' => [
        'homeUrl' => '/user/login',
        'siteName' => 'Drupal',
        'siteSlogan' => '',
      ],
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]);
  }

  /**
   * Tests v 0 using drupal settings get theme assets.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataThemeAssetsV0
   */
  public function testV0UsingDrupalSettingsGetThemeAssets(): void {
    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.canvas_test_code_components_using_drupalsettings_get_theme_assets',
        ],
      ],
    ]);
    $page->save();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet($page->toUrl());

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);
    self::assertSame([
      'themeAssets' => [
        'logo' => [
          'url' => '/core/themes/stark/logo.svg',
        ],
        'favicon' => [
          'url' => '/core/misc/favicon.ico',
          'mimeType' => 'image/vnd.microsoft.icon',
        ],
      ],
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]);
  }

  /**
   * Tests v 0 not using drupal settings.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider
   */
  public function testV0NotUsingDrupalSettings(): void {
    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.canvas_test_code_components_using_imports',
        ],
      ],
    ]);
    $page->save();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet($page->toUrl());

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayNotHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);
  }

  /**
   * Tests get canvas data main entity v 0 on canvas page route.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0OnCanvasPageRoute(): void {
    // Set up multiple languages so the translations data covers a translated
    // language (French), an untranslated one (German), and the default and
    // currently active language (English).
    $this->container->get(ModuleInstallerInterface::class)->install(['language']);
    // Refresh the container so the language schema is known before saving
    // language.negotiation below.
    $this->rebuildContainer();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    // Configure URL prefixes so translation links carry the language prefix.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr', 'de' => 'de'])
      ->save();
    $this->rebuildContainer();

    // Create a Canvas Page entity and add a French translation only.
    $page = Page::create([
      'title' => 'Test canvas page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.canvas_test_code_components_using_get_page_data',
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();
    $page->addTranslation('fr', ['title' => 'Page de test'])->save();
    $id = $page->id();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    // Visit the English (default) canonical route.
    $this->drupalGet($page->toUrl());

    // The code component that reads `mainEntity` carries the cache tags for the
    // language list and URL negotiation config the translation data depends on.
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'config:configurable_language_list');
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'config:language.negotiation');
    // Every listed language's name is read from that language's own config
    // object, which the list cache tag does not cover: renaming a language
    // without saving the entity — through a config translation of its name, a
    // config import or `drush cset` — invalidates only
    // `config:language.entity.<langcode>`.
    foreach (['en', 'fr', 'de'] as $langcode) {
      $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', "config:language.entity.$langcode");
    }
    // It also carries the cacheability of the per-translation view access
    // results embedded in the translation data.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Contexts', 'user.permissions');
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', "canvas_page:$id");

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);

    self::assertSame([
      'bundle' => 'canvas_page',
      'entityTypeId' => 'canvas_page',
      'uuid' => $page->uuid(),
      'requestedLanguage' => 'en',
      'renderedLanguage' => 'en',
      'translations' => [
        [
          'langcode' => 'en',
          'name' => 'English',
          'nativeName' => 'English',
          'url' => "/page/$id",
          'translationAvailable' => TRUE,
          'current' => TRUE,
        ],
        [
          'langcode' => 'fr',
          'name' => 'French',
          'nativeName' => 'Français',
          'url' => "/fr/page/$id",
          'translationAvailable' => TRUE,
          'current' => FALSE,
        ],
        [
          'langcode' => 'de',
          'name' => 'German',
          'nativeName' => 'Deutsch',
          'url' => "/de/page/$id",
          'translationAvailable' => FALSE,
          'current' => FALSE,
        ],
      ],
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']);

    // Visiting the German URL: German is requested and current, but the page has
    // no German translation so the content renders in English (the fallback).
    $de = $this->container->get(LanguageManagerInterface::class)->getLanguage('de');
    $this->drupalGet($page->toUrl('canonical', ['language' => $de]));
    $drupalSettings = $this->getDrupalSettings();
    self::assertSame([
      'bundle' => 'canvas_page',
      'entityTypeId' => 'canvas_page',
      'uuid' => $page->uuid(),
      'requestedLanguage' => 'de',
      'renderedLanguage' => 'en',
      'translations' => [
        [
          'langcode' => 'en',
          'name' => 'English',
          'nativeName' => 'English',
          'url' => "/page/$id",
          'translationAvailable' => TRUE,
          'current' => FALSE,
        ],
        [
          'langcode' => 'fr',
          'name' => 'French',
          'nativeName' => 'Français',
          'url' => "/fr/page/$id",
          'translationAvailable' => TRUE,
          'current' => FALSE,
        ],
        [
          'langcode' => 'de',
          'name' => 'German',
          'nativeName' => 'Deutsch',
          'url' => "/de/page/$id",
          'translationAvailable' => FALSE,
          'current' => TRUE,
        ],
      ],
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']);
  }

  /**
   * Tests that a translation the user cannot view is reported as untranslated.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0TranslationViewAccess(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['language']);
    // Refresh the container so the language schema is known before saving
    // language.negotiation below.
    $this->rebuildContainer();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->save();
    $this->rebuildContainer();

    // A published English page with an *unpublished* French translation.
    $page = Page::create([
      'title' => 'Test canvas page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.canvas_test_code_components_using_get_page_data',
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();
    $page->addTranslation('fr', ['title' => 'Page de test'])->save();
    $page->getTranslation('fr')->setUnpublished()->save();
    $id = $page->id();

    $expected = static fn (bool $fr_translation_available): array => [
      [
        'langcode' => 'en',
        'name' => 'English',
        'nativeName' => 'English',
        'url' => "/page/$id",
        'translationAvailable' => TRUE,
        'current' => TRUE,
      ],
      [
        'langcode' => 'fr',
        'name' => 'French',
        'nativeName' => 'Français',
        'url' => "/fr/page/$id",
        'translationAvailable' => $fr_translation_available,
        'current' => FALSE,
      ],
    ];

    // A user who can only access published content must not see the unpublished
    // French translation: it is reported as `translationAvailable: false` (a
    // fallback URL), indistinguishable from an untranslated language.
    $unprivileged_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $unprivileged_user);
    $this->drupalLogin($unprivileged_user);
    $this->drupalGet($page->toUrl());
    $drupalSettings = $this->getDrupalSettings();
    self::assertSame(
      $expected(FALSE),
      $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']['translations'],
    );
    // The response carries the cacheability of the access-gated result: it
    // varies by permission and depends on the page (whose translation's
    // published state determines the outcome).
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Contexts', 'user.permissions');
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', "canvas_page:$id");

    // A user who may edit pages can view the unpublished translation, so it is
    // reported as `translationAvailable: true`; the gate is per user, not a
    // blanket hide.
    $editor_user = $this->drupalCreateUser([Page::EDIT_PERMISSION, 'access content']);
    $this->assertInstanceOf(AccountInterface::class, $editor_user);
    $this->drupalLogin($editor_user);
    $this->drupalGet($page->toUrl());
    $drupalSettings = $this->getDrupalSettings();
    self::assertSame(
      $expected(TRUE),
      $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']['translations'],
    );
  }

  /**
   * Tests get canvas data main entity v 0 on preview entity route.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0OnPreviewEntityRoute(): void {
    // Preview route should use 'preview_entity' when present.
    $this->container->get(ModuleInstallerInterface::class)->install(['node', 'language']);
    // Refresh the container so the language schema is known before saving
    // language.negotiation below.
    $this->rebuildContainer();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->save();
    $this->rebuildContainer();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));

    $node = Node::create([
      'title' => 'Some article',
      'type' => 'article',
    ]);
    self::assertCount(0, $node->validate());
    $node->save();
    $admin_user = $this->drupalCreateUser([
      'access content',
      'administer nodes',
      'administer content templates',
    ]);
    $this->assertInstanceOf(AccountInterface::class, $admin_user);
    $this->drupalLogin($admin_user);
    $template_tree = ([
      [
        'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
        'component_id' => 'js.canvas_test_code_components_using_get_page_data',
        'component_version' => '8fe3be948e0194e1',
        'inputs' => [],
      ],
    ]);
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => $template_tree,
    ]);
    $template->save();

    // Request the Canvas internal API preview route.
    // We cannot use this as usually, as this returns a JSON including the HTML
    // output, which we need to parse instead of the entire request response.
    $this->drupalGet("/canvas/api/v0/layout-content-template/{$template->id()}/{$node->id()}");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('application/json', $this->getSession()->getResponseHeader('Content-Type'));
    $parsed_response = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('html', $parsed_response);

    // We cannot use getDrupalSettings(), as that requires the html to exist in
    // the actual request, but we have a json value from our internal API.
    $html = $parsed_response['html'];
    $drupalSettings = $this->getLayoutPreviewDrupalSettings($html);
    $this->assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);

    self::assertSame([
      'bundle' => 'article',
      'entityTypeId' => 'node',
      'uuid' => $node->uuid(),
      'requestedLanguage' => 'en',
      'renderedLanguage' => 'en',
      // Every enabled language is listed even though the `article` bundle is
      // not marked translatable (only the `content_translation` module sets
      // that flag): a language switcher must always list every language. The
      // untranslated language reports `translationAvailable: false` with a
      // fallback URL.
      'translations' => [
        [
          'langcode' => 'en',
          'name' => 'English',
          'nativeName' => 'English',
          'url' => "/node/{$node->id()}",
          'translationAvailable' => TRUE,
          'current' => TRUE,
        ],
        [
          'langcode' => 'fr',
          'name' => 'French',
          'nativeName' => 'Français',
          'url' => "/fr/node/{$node->id()}",
          'translationAvailable' => FALSE,
          'current' => FALSE,
        ],
      ],
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']);

    // A factual translation is reported even though the bundle is not marked
    // translatable: it keeps rendering at its URL regardless.
    $node->addTranslation('fr', ['title' => 'Un article'])->save();
    $this->drupalGet("/canvas/api/v0/layout-content-template/{$template->id()}/{$node->id()}");
    $this->assertSession()->statusCodeEquals(200);
    $parsed_response = Json::decode($this->getSession()->getPage()->getContent());
    $drupalSettings = self::getLayoutPreviewDrupalSettings($parsed_response['html']);
    self::assertSame(
      ['en' => TRUE, 'fr' => TRUE],
      \array_column($drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']['translations'], 'translationAvailable', 'langcode'),
    );
  }

  /**
   * Tests get canvas data main entity v 0 on invalid canvas route.
   *
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0OnInvalidCanvasRoute(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['node']);

    $regular_user = $this->drupalCreateUser([ContentTemplate::ADMIN_PERMISSION, 'access content', 'administer nodes']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet('canvas/not-a-real-route');

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);

    self::assertNull($drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']);
  }

  /**
   * Allow parsing Drupal settings from an HTML string.
   *
   * This is not the actual HTML from the current page.
   * The logic is the same as \Drupal\Tests\BrowserTestBase::getDrupalSettings(),
   * but using a crawler parsing the passed HTML instead of the current mink session raw HTML.
   *
   * @see \Drupal\Tests\BrowserTestBase::getDrupalSettings
   */
  private static function getLayoutPreviewDrupalSettings(string $html): array {
    $crawler = new Crawler($html);
    $elements = $crawler->filterXPath('//script[@type="application/json" and @data-drupal-selector="drupal-settings-json"]');
    if (count($elements) === 1) {
      $settings = Json::decode($elements->html());
      if (isset($settings['ajaxPageState']['libraries'])) {
        $settings['ajaxPageState']['libraries'] = UrlHelper::uncompressQueryParameter($settings['ajaxPageState']['libraries']);
      }
      return $settings;
    }
    return [];
  }

}
