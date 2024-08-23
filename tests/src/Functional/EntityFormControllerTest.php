<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\EntityFormController
 * @group experience_builder
 */
class EntityFormControllerTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * @covers ::form
   */
  public function testForm(): void {
    $assert = $this->assertSession();
    $this->createTestNode1();

    $this->assertFormResponse('xb/api/entity-form/node/1/default', TRUE);
    $this->assertFormResponse('xb/api/entity-form/node/1', TRUE);

    $new_form_mode_path = 'xb/api/entity-form/node/1/mode2';
    // Try to retrieve the form using the new form mode before it is created.
    $this->drupalGet($new_form_mode_path);
    $assert->statusCodeEquals(500);
    $assert->pageTextContains('The "mode2" form display was not found');

    $user = $this->drupalCreateUser(['administer display modes', 'administer node form display', 'access administration pages']);
    $this->assertInstanceOf(User::class, $user);
    $this->drupalLogin($user);
    $this->drupalGet('admin/structure/display-modes/form/add/node');
    $assert->statusCodeEquals(200);

    $edit = [
      'id' => 'mode2',
      'label' => 'Mode 2',
      'bundles_by_entity[article]' => 'article',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("Saved the Mode 2 form mode.");

    // The menu element should not appear in the 'mode2' form mode.
    $this->assertFormResponse($new_form_mode_path, FALSE);
  }

  private function assertFormResponse(string $path, bool $expected_menu_element): void {
    $response = $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertJson($response);
    $decoded = json_decode($response, TRUE);
    $this->assertSame(['html'], array_keys($decoded));
    $html = $decoded['html'];
    $this->assertStringStartsWith('<form class="node-article-form node-form" data-drupal-selector="node-article-form" enctype="multipart/form-data"', $html);
    $menu_form_element_html_snippet = '<input data-drupal-selector="edit-menu-title"';
    $expected_menu_element ?
      $this->assertStringContainsString($menu_form_element_html_snippet, $html) :
      $this->assertStringNotContainsString($menu_form_element_html_snippet, $html);
  }

}
