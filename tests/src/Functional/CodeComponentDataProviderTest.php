<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Session\AccountInterface;
use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\Page;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @group canvas
 */
class CodeComponentDataProviderTest extends FunctionalTestBase {

  use ContribStrictConfigSchemaTestTrait;

  protected static $modules = [
    'canvas',
    'canvas_test_code_components',
  ];

  protected $defaultTheme = 'stark';

  /**
   * @covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataBrandingV0
   * @covers \Drupal\canvas\CodeComponentDataProvider::getRequiredCanvasDataLibraries
   * @covers \Drupal\canvas\CodeComponentDataProvider::getPartialCanvasDataFromSettingsV0
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
   * @covers \Drupal\canvas\CodeComponentDataProvider::getRequiredCanvasDataLibraries
   * @covers \Drupal\canvas\CodeComponentDataProvider::getPartialCanvasDataFromSettingsV0
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
   * @covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0OnCanvasPageRoute(): void {
    // Create a Canvas Page entity and visit its canonical route.
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

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet($page->toUrl());

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $drupalSettings);

    self::assertSame([
      'bundle' => 'canvas_page',
      'entityTypeId' => 'canvas_page',
      'uuid' => $page->uuid(),
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']);
  }

  /**
   * @covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0OnPreviewEntityRoute(): void {
    // Preview route should use 'preview_entity' when present.
    $this->container->get('module_installer')->install(['node']);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    self::assertTrue($this->container->get('module_handler')->moduleExists('canvas'));

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
    ], $drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['mainEntity']);
  }

  /**
   * @covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0OnInvalidCanvasRoute(): void {
    $this->container->get('module_installer')->install(['node']);

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
  private function getLayoutPreviewDrupalSettings(string $html): array {
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
