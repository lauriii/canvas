<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the front-end theme styles component previews, not the editor.
 *
 * @see \Drupal\canvas\Theme\CanvasThemeNegotiator
 * @see \Drupal\Tests\canvas\Unit\Theme\CanvasThemeNegotiatorTest
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class EditorThemeIsolationTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'canvas_test_frontend_theme_override';

  public function testEditorAndPreviewThemes(): void {
    $this->config('system.performance')->set('css.preprocess', FALSE)->save();

    $page = Page::create(['title' => 'Test page', 'type' => 'page']);
    $page->save();
    $account = $this->drupalCreateUser([Page::EDIT_PERMISSION]);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);
    $this->drupalGet('/canvas/editor/canvas_page/' . $page->id());
    $this->assertSession()->statusCodeEquals(200);

    // The editor: core's js.module.css is loaded although the front-end theme
    // drops it, and the front-end theme's own CSS is not.
    $this->assertSession()->responseContains('js.module.css');
    $this->assertSession()->responseNotContains('canvas_test_frontend_theme_override/css/global.css');

    // Component previews: the front-end theme, including its overrides. Their
    // CSS is always aggregated, so assert the aggregates' contents.
    $preview_assets = \json_decode($this->drupalGet('/canvas/api/v0/ui/preview-assets'), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSession()->statusCodeEquals(200);
    \preg_match_all('/href="([^"]+)"/', $preview_assets['css'], $matches);
    $this->assertNotEmpty($matches[1]);
    $preview_css = '';
    foreach ($matches[1] as $href) {
      $preview_css .= $this->drupalGet($this->getAbsoluteUrl(\html_entity_decode($href)));
    }
    $this->assertStringContainsString('serif', $preview_css);
    $this->assertStringNotContainsString('.js-hide', $preview_css);
  }

  public function testPreviewAssetsAreNotSharedBetweenUsers(): void {
    $accounts = [
      $this->drupalCreateUser([Page::EDIT_PERMISSION]),
      $this->drupalCreateUser([Page::EDIT_PERMISSION]),
    ];
    foreach ($accounts as $account) {
      $this->assertInstanceOf(AccountInterface::class, $account);
      $this->drupalLogin($account);
      $preview_assets = \json_decode($this->drupalGet('/canvas/api/v0/ui/preview-assets'), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSession()->statusCodeEquals(200);
      // The footer's drupalSettings contain the current user's ID, so the
      // response must never be shared between users.
      $this->assertStringContainsString('"uid":"' . $account->id() . '"', $preview_assets['jsFooter']);
    }
  }

}
