<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the site's front-end theme cannot affect the Canvas editor.
 *
 * The editor renders Drupal-built form widgets (the component instance form)
 * into its own document, and renders the previewed page in `srcdoc` iframes.
 * Because the preview is isolated in those iframes, the editor document itself
 * has no need for the front-end theme's assets — and must not be built with
 * that theme, or a front-end theme's `libraries-override` silently governs the
 * admin UI.
 *
 * @see \Drupal\canvas\Theme\CanvasThemeNegotiator
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class EditorFrontEndThemeIsolationTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A front-end theme dropping a core stylesheet must not reach the editor.
   *
   * `canvas_test_frontend_theme_override` switches off `system/base`'s
   * `css/components/js.module.css`, which is what defines `.js-hide`. Core
   * hides functional markup with that class — for example the media library
   * widget's "Update widget" AJAX trigger, which the component instance form
   * renders for any image-typed prop. If the editor is built with the
   * front-end theme, that button becomes visible in the editor.
   */
  public function testFrontEndThemeDoesNotStyleTheEditor(): void {
    // Assert the individual stylesheets rather than an aggregate.
    $config = $this->container->get(ConfigFactoryInterface::class)->getEditable('system.performance');
    $config->set('css.preprocess', FALSE);
    $config->save();

    // A front-end theme that strips one of core's `system/base` stylesheets,
    // and an admin theme that does not.
    $this->container->get(ThemeInstallerInterface::class)->install(['canvas_test_frontend_theme_override', 'claro']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')
      ->set('default', 'canvas_test_frontend_theme_override')
      ->set('admin', 'claro')
      ->save();

    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
    ]);
    $this->assertSame(SAVED_NEW, $page->save());

    $this->drupalLogin($this->drupalCreateUser([Page::EDIT_PERMISSION]));

    $this->drupalGet('/canvas/editor/canvas_page/' . $page->id());
    $this->assertSession()->statusCodeEquals(200);

    // The editor must not be built with the front-end theme.
    $settings = $this->getDrupalSettings();
    $this->assertNotSame(
      'canvas_test_frontend_theme_override',
      $settings['ajaxPageState']['theme'] ?? NULL,
      "The Canvas editor was built with the site's front-end theme, so that theme's libraries-override governs the editor's admin UI.",
    );

    // The consequence: core's `.js-hide` utility, which the component instance
    // form's media library widget depends on, is missing from the editor.
    $this->assertStringContainsString(
      'js.module.css',
      $this->getSession()->getPage()->getContent(),
      "core's `js.module.css` (which defines `.js-hide`) is absent from the Canvas editor, so markup core hides — such as the media library widget's \"Update widget\" button — renders visibly.",
    );
  }

}
