<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\Page;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests AssetLibrary config entities' generated assets load successfully.
 *
 * @group experience_builder
 */
final class AssetLibraryAttachmentTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * @covers \Drupal\experience_builder\Hook\ComponentSourceHooks::pageAttachments
   */
  public function test(): void {
    // We need to disable CSS/JS aggregation to test the raw assets.
    $config = $this->container->get(ConfigFactoryInterface::class)->getEditable('system.performance');
    $config->set('js.preprocess', FALSE);
    $config->set('css.preprocess', FALSE);
    $config->save();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($library instanceof AssetLibrary);
    // Ensure the library has both JavaScript and CSS.
    $library->set('css', [
      'original' => '.regular-content { color: blue; }',
      'compiled' => '.regular-content{color:blue}',
    ]);
    $library->set('js', [
      'original' => 'console.log("Regular Content")',
      'compiled' => 'console.log("Regular Content")',
    ]);
    $library->save();

    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
    ]);
    $this->assertSame(SAVED_NEW, $page->save());

    $url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    $regular_css_path = $url_generator->generateString($library->getCssPath());
    $regular_js_path = $url_generator->generateString($library->getJsPath());
    $auto_save_css_path = base_path() . 'xb/api/v0/auto-saves/css/xb_asset_library/' . $library->id();
    $auto_save_js_path = base_path() . 'xb/api/v0/auto-saves/js/xb_asset_library/' . $library->id();

    $assert_library_global_library = function (string $path, bool $is_preview) use ($regular_css_path, $regular_js_path, $auto_save_css_path, $auto_save_js_path) {
      $response = $this->drupalGet($path);
      $parsed_response = json_decode($response, TRUE);
      if ($parsed_response === NULL) {
        $html = $response;
      }
      else {
        $parsed_response = json_decode($response, TRUE);
        $html = $parsed_response['html'];
      }
      $crawler = new Crawler($html);
      self::assertCount($is_preview ? 0 : 1, $crawler->filter('link[href^="' . $regular_css_path . '"]'));
      self::assertCount($is_preview ? 0 : 1, $crawler->filter('script[src^="' . $regular_js_path . '"]'));
      self::assertCount($is_preview ? 1 : 0, $crawler->filter('link[href^="' . $auto_save_css_path . '"]'));
      self::assertCount($is_preview ? 1 : 0, $crawler->filter('script[src^="' . $auto_save_js_path . '"]'));
    };
    // Case 1: Regular page should use regular asset library.
    $assert_library_global_library('/user', FALSE);

    // Case 2: An admin user should see the regular asset library on the regular
    // page also.
    $admin_user = $this->drupalCreateUser([
      'access administration pages',
      'administer themes',
      Page::EDIT_PERMISSION,
    ]);
    $this->assertInstanceOf(AccountInterface::class, $admin_user);
    $this->drupalLogin($admin_user);
    $assert_library_global_library('/user', FALSE);

    $this->drupalGet($regular_css_path);
    $this->assertSame($library->getCss(), $this->getTextContent());

    $this->drupalGet($regular_js_path);
    $this->assertSame($library->getJs(), $this->getTextContent());

    // Case 3: Route with _xb_use_template_draft should use regular asset
    // library if there is no auto-saved version.
    $assert_library_global_library('/xb/api/v0/layout/xb_page/' . $page->id(), TRUE);

    // Create auto-save data for the global asset library.
    $auto_save_data = [
      'css' => [
        'original' => '.auto-save-content { color: red; }',
        'compiled' => '.auto-save-content{color:red}',
      ],
      'js' => [
        'original' => 'console.log("Auto-save Content")',
        'compiled' => 'console.log("Auto-save Content")',
      ],
    ];

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    $library->updateFromClientSide($auto_save_data);
    $auto_save_manager->saveEntity($library);

    // Case 4: Route with _xb_use_template_draft should use auto-saved version
    // library if it exists.
    $assert_library_global_library('/xb/api/v0/layout/xb_page/' . $page->id(), TRUE);

    // Case 5: Test that on regular page the user sees the regular version even
    // if the auto-save version exists.
    $assert_library_global_library('/user', FALSE);
  }

}
