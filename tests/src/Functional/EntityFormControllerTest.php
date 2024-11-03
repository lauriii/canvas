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
    $assert->responseHeaderEquals('Content-Type', 'application/json');
    $json = json_decode($this->getSession()->getPage()->getContent());
    $this->assertSame('The "mode2" form display was not found', $json->message);
    // We are logged in as user 1 so we should see the trace.
    $this->assertObjectHasProperty('trace', $json);

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
    $expected_start = '<template hyperscriptify><drupal-form attributes="' . htmlspecialchars(
      '{"class":["node-article-form","node-form"],"data-drupal-selector":"node-article-form","enctype":"multipart\/form-data"',
      ENT_QUOTES,
    );
    $this->assertStringStartsWith($expected_start, $response);
    $menu_form_element_html_snippet = '<drupal-input attributes="' . htmlspecialchars(
      '{"data-drupal-selector":"edit-menu-title"',
      ENT_QUOTES,
    );
    $expected_menu_element ?
      $this->assertStringContainsString($menu_form_element_html_snippet, $response) :
      $this->assertStringNotContainsString($menu_form_element_html_snippet, $response);
  }

}
