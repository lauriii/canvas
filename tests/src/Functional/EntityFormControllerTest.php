<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\EntityFormController;
use Drupal\canvas\Entity\Pattern;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

#[RunTestsInSeparateProcesses]
#[CoversClass(EntityFormController::class)]
#[Group('canvas')]
class EntityFormControllerTest extends FunctionalTestBase {

  use ImageFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  protected function setUp(): void {
    parent::setUp();
    // Drupal 11.4's `standard` profile no longer ships the `article` node type
    // (it is created via a recipe, which is not applied during test installs).
    if (!NodeType::load('article')) {
      $this->createContentType(['type' => 'article', 'name' => 'Article']);
      // The `standard` profile's `article` bundle ships an image field, whose
      // file-upload widget makes the entity form use `multipart/form-data`.
      // Recreate it so the form's `enctype` matches the expectation below.
      // @see ::assertFormResponse()
      $this->createImageField('field_image', 'node', 'article');
    }
    $this->createComponentTreeField('node', 'article', 'field_component_tree');
  }

  /**
   * Tests form.
   *
   * @legacy-covers ::form
   * @legacy-covers \Drupal\canvas\Hook\ContentTemplateHooks::entityFormDisplayAlter
   */

  /**
   * Non-fieldable entities have no content entity form: empty, not an error.
   */
  public function testFormForNonFieldableEntity(): void {
    $this->drupalLogin($this->rootUser);
    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test pattern',
      'component_tree' => [],
    ])->save();
    $response = $this->drupalGet('canvas/api/v0/form/content-entity/pattern/test_pattern/default');
    $this->assertSession()->statusCodeEquals(200);
    $parsed_response = json_decode($response, TRUE);
    // Only the hyperscriptify wrapper: no form, no fields.
    $crawler = new Crawler($parsed_response['html'] ?? '');
    self::assertCount(0, $crawler->filter('form, drupal-canvas-form, input, select'));
  }

  public function testForm(): void {
    $assert = $this->assertSession();
    $this->createTestNode();

    $this->assertFormResponse('canvas/api/v0/form/content-entity/node/1/default', TRUE);
    $this->assertFormResponse('canvas/api/v0/form/content-entity/node/1', TRUE);

    $new_form_mode_path = 'canvas/api/v0/form/content-entity/node/1/mode2';
    // Try to retrieve the form using the new form mode before it is created.
    $this->drupalGet($new_form_mode_path);
    $assert->statusCodeEquals(500);
    $assert->responseHeaderEquals('Content-Type', 'application/json');
    $json = json_decode($this->getSession()->getPage()->getContent());
    $this->assertSame('The "mode2" form display was not found', $json->message);
    // We are logged in as user 1 so we should see the trace.
    $this->assertObjectHasProperty('trace', $json);

    $user = $this->drupalCreateUser(['administer display modes', 'administer node form display', 'edit any article content']);
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
    $parsed_response = json_decode($response, TRUE);
    $html = $parsed_response['html'];

    // Ensure the `status` field has been removed.
    // @see \canvas_entity_form_display_alter()
    $this->assertStringNotContainsString('edit-status-value', $html);

    $crawler = new Crawler($html);
    self::assertCount(1, $crawler->filter('template[data-hyperscriptify]'));
    $form = $crawler->filter('drupal-canvas-form');
    self::assertCount(1, $form);

    $attributes = \json_decode($form->attr('attributes') ?? '{}', TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertEquals(['node-article-form', 'node-form'], $attributes['class']);
    self::assertEquals('node-article-form', $attributes['data-drupal-selector']);
    self::assertEquals('multipart/form-data', $attributes['enctype']);

    self::assertGreaterThanOrEqual($expected_menu_element ? 1 : 0, $crawler->filter('div[data-drupal-selector="edit-menu"] drupal-canvas-input[attributes*="edit-menu-title"]')->count());
  }

}
