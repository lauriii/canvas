<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;

/**
 * @group experience_builder
 */
class PageTemplateEnableTest extends BrowserTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'experience_builder', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  public function test(): void {
    $this->drupalLogin($this->rootUser);
    $this->generateComponentConfig();

    // No XB settings on the global settings page.
    $this->drupalGet('/admin/appearance/settings');
    $this->assertSession()->pageTextNotContains('Experience Builder');
    $this->assertSession()->fieldNotExists('use_xb');

    // XB checkbox on the Olivero theme page.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->assertSession()->pageTextContains('Experience Builder');
    $this->assertSession()->fieldExists('use_xb');

    // We start with no templates.
    $this->assertEmpty(PageTemplate::loadMultiple());

    // No template is created if we do not enable XB.
    $this->submitForm(['use_xb' => FALSE], 'Save configuration');
    $this->assertEmpty(PageTemplate::loadMultiple());

    // A template is created when we enable XB.
    $this->submitForm(['use_xb' => TRUE], 'Save configuration');
    $templates = PageTemplate::loadMultiple();
    $this->assertCount(1, $templates);
    $this->assertArrayHasKey('olivero', $templates);
    $template = $templates['olivero'];
    $this->assertTrue($template->status());

    // Check the template is created correctly.
    $trees = iterator_to_array($template->getComponentTrees());
    $expected_regions = \array_filter([
      'breadcrumb',
      'content',
      'content_above',
      // System powered by block is not fully validatable until Drupal 11.
      // @see https://www.drupal.org/project/drupal/issues/3379725
      Component::load('block.system_powered_by_block') ? 'footer_bottom' : NULL,
      'header',
      'highlighted',
      'primary_menu',
      'secondary_menu',
    ]);
    $this->assertSame(\array_values($expected_regions), array_keys($trees));

    foreach ($trees as $field) {
      $tree = $field->toArray();
      $this->assertJson($tree['tree']);
      $tree = json_decode($tree['tree'], TRUE);
      $this->assertArrayHasKey(ComponentTreeStructure::ROOT_UUID, $tree);
      foreach ($tree[ComponentTreeStructure::ROOT_UUID] as $component) {
        $this->assertTrue(Uuid::isValid($component['uuid']));
        $this->assertStringStartsWith('block.', $component['component']);
      }
    }
    $front = Url::fromRoute('<front>');
    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '#primary-tabs-title', 1);

    // The template is disabled again when we disable XB.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_xb' => FALSE], 'Save configuration');
    $template = PageTemplate::load('olivero');
    $this->assertInstanceOf(PageTemplate::class, $template);
    $this->assertFalse($template->status());

    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
  }

}
