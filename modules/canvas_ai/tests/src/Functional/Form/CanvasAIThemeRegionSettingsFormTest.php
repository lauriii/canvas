<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Functional\Form;

use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Canvas AI Page Variant Settings form.
 */
#[Group('canvas_ai')]
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
    // Create a user with the USE_CANVAS_AI permission.
    $user = $this->drupalCreateUser([
      CanvasAiPermissions::USE_CANVAS_AI,
    ]);
    \assert($user instanceof AccountInterface);
    $this->drupalLogin($user);

    // Navigate to the form URL.
    $this->drupalGet('/admin/config/ai/canvas-ai-theme-region-settings');

    // Assert that the form title is displayed.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Canvas AI Page Variant Settings');

    // With no page variants, assert that the informational message is shown.
    $this->assertSession()->pageTextContains('No page variants exist yet.');

    // Create two page variants.
    PageVariant::create([
      'id' => 'homepage',
      'label' => 'Homepage',
      'description' => 'The site homepage.',
    ])->save();
    PageVariant::create([
      'id' => 'landing',
      'label' => 'Landing',
      'description' => '',
    ])->save();

    // Navigate back to the form. The "no variants" message is gone.
    $this->drupalGet('/admin/config/ai/canvas-ai-theme-region-settings');
    $this->assertSession()->pageTextNotContains('No page variants exist yet.');

    // We should see the form to add descriptions for the variants.
    $this->assertSession()->pageTextContains('The following page variants can be used');
    $this->assertSession()->pageTextContains('Homepage');
    $this->assertSession()->pageTextContains('Landing');

    // The variant's own description pre-fills the textarea.
    $this->assertSession()->fieldValueEquals('homepage[description]', 'The site homepage.');

    // Submit the form with a description for the landing variant.
    $landing_description = 'Landing page for marketing campaigns.';
    $this->submitForm([
      'landing[description]' => $landing_description,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Navigate back to the form and verify the description was saved.
    $this->drupalGet('/admin/config/ai/canvas-ai-theme-region-settings');
    $this->assertSession()->fieldValueEquals('landing[description]', $landing_description);
  }

}
