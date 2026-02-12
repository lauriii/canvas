<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Functional\Form;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\canvas_ai\CanvasAiPermissions;

/**
 * Tests the Canvas AI Theme Region Settings form.
 *
 * @group canvas_ai
 */
final class CanvasAIThemeRegionSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_ai',
    'user',
  ];

  /**
   * Tests the form.
   */
  public function testForm(): void {
    // Create a user with the USE_CANVAS_AI permission and administer themes.
    $user = $this->drupalCreateUser([
      CanvasAiPermissions::USE_CANVAS_AI,
      'administer themes',
    ]);
    \assert($user instanceof AccountInterface);
    $this->drupalLogin($user);

    // Navigate to the form URL.
    $this->drupalGet('/admin/config/ai/canvas-ai-theme-region-settings');

    // Assert that the form title is displayed.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Canvas AI Theme Region Settings');

    // By default, no page regions are enabled in the theme.
    // Assert that the appropriate message is displayed.
    $this->assertSession()->pageTextContains('No page regions are enabled in your theme.');
    $this->assertSession()->linkExists('theme settings');

    // Enable Drupal Canvas for the Stark theme to enable page regions.
    $this->drupalGet('/admin/appearance/settings/stark');
    $this->assertSession()->pageTextContains('Drupal Canvas');
    $this->assertSession()->fieldExists('use_canvas');

    // Enable Canvas for the Stark theme.
    $this->submitForm(['use_canvas' => TRUE], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $this->drupalGet('/admin/appearance/settings/stark');
    // Disable some regions.
    $this->submitForm([
      'editable[stark.sidebar_first]' => FALSE,
      'editable[stark.header]' => FALSE,
      'editable[stark.page_bottom]' => FALSE,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Now navigate back to the Canvas AI Theme Region Settings form.
    $this->drupalGet('/admin/config/ai/canvas-ai-theme-region-settings');
    // The "no regions" message should no longer be displayed.
    $this->assertSession()->pageTextNotContains('No page regions are enabled in your theme.');

    // We should see the form to add descriptions to the enabled regions.
    $this->assertSession()->pageTextContains('The following page regions are available in your theme');
    $this->assertSession()->pageTextNotContains('Left sidebar');
    $this->assertSession()->pageTextContains('Right sidebar');
    $this->assertSession()->pageTextContains('Content');
    $this->assertSession()->pageTextNotContains('Header');
    $this->assertSession()->pageTextContains('Primary menu');
    $this->assertSession()->pageTextContains('Secondary menu');
    $this->assertSession()->pageTextContains('Footer');
    $this->assertSession()->pageTextContains('Highlighted');
    $this->assertSession()->pageTextContains('Help');
    $this->assertSession()->pageTextContains('Page top');
    $this->assertSession()->pageTextNotContains('Page bottom');
    $this->assertSession()->pageTextContains('Breadcrumb');

    // Submit the form with a description for the breadcrumb region.
    $breadcrumb_description = 'Breadcrumb region description updated';
    $this->submitForm([
      'stark[breadcrumb][description]' => $breadcrumb_description,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Navigate back to the form and verify the description was saved.
    $this->drupalGet('/admin/config/ai/canvas-ai-theme-region-settings');
    $this->assertSession()->fieldValueEquals('stark[breadcrumb][description]', $breadcrumb_description);
  }

}
