<?php

namespace Drupal\Tests\same_page_preview\FunctionalJavascript;

use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\ExpectationException;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests the same page preview edit form.
 *
 * @group same_page_preview
 */
class SamePagePreviewEditFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Theme to use for tests.
   *
   * @var string
   */
  protected $defaultTheme = 'olivero';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['same_page_preview'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set the administrative theme to be Claro.
    $this->config('system.theme')->set('admin', 'claro')->save();
    $this->config('node.settings')->set('use_admin_theme', '1')->save();

    // Set the administrative theme to be Claro.
    $this->adminUser = $this->drupalCreateUser([
      'edit own page content',
      'create page content',
      'access same_page_preview',
      'view the administration theme',
    ]);

    $this->drupalLogin($this->adminUser);
  }

  /**
   * Test node edit form with new same_page_preview.
   */
  public function testNodePreview() {
    // Prerequisites.
    $this->drupalGet('node/add/page');
    $session = $this->assertSession();

    try {
      // Does the page have the toggle preview button?
      $session->assert($this->getSession()
        ->getPage()
        ->hasLink('Toggle Preview'), "Has Toggle Preview button.");

      // Open the preview pane.  Does it show?
      $toggle_preview = $this->getSession()->getPage()->findLink('Toggle Preview');
      $this->assertTrue($toggle_preview->isVisible(), 'Toggle Preview button is visible.');
      $toggle_preview->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $preview_dialog = $this->getSession()->getPage()->find('css', 'div.ui-dialog');
      $this->assertTrue($preview_dialog->isVisible(), 'Preview dialog is visible.');

      // Add a title, refresh preview, is the title in the preview?
      $this->getSession()
        ->getPage()
        ->fillField('Title', 'same_page_preview test');
      $this->getSession()->getPage()->pressButton('Refresh');
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertNotNull($this->assertSession()
        ->waitForElementVisible('css', 'iframe.preview'));
      $this->getSession()->switchToIFrame('preview');
      $this->assertNotNull($this->assertSession()
        ->waitForElementVisible('css', 'h1.page-title'));

      $this->getSession()->getPage()->hasContent('same_page_preview test');

      // Close the dialog again.
      $this->getSession()->switchToIFrame();
      $closeButton = $this->getSession()->getPage()->find('css', '.ui-dialog-titlebar-close');
      $closeButton->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $dialog = $this->getSession()->getPage()->find('css', '.ui-dialog');
      $this->assertNull($dialog, 'Dialog is closed after clicking the close button.');
    }
    catch (ElementNotFoundException $e) {
      $this->fail('Not all elements were found on the page.');
    }
    catch (ExpectationException $e) {
      $this->fail('Preview Functionality broken.');
    }
    // Finally.
  }

}
