<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\EntityFormController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
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

  use CommentTestTrait;
  use GenerateComponentConfigTrait;
  use ImageFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas', 'canvas_test_sdc', 'comment'];

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

  /**
   * Tests the per-content form partition (content-entity-editing phase 2).
   *
   * When the entity's bundle has an enabled full view mode content template,
   * the form served to the Canvas editor is partitioned rather than trimmed:
   * content field widgets stay in the response, annotated with
   * `data-canvas-form-partition="content"` so the editor's Content tab can
   * render them, while page-level metadata (the label field plus the elements
   * the node form attaches to its sidebar: URL alias, menu settings,
   * authoring information, comment settings) stays unannotated for the Page
   * data tab.
   *
   * @legacy-covers \Drupal\canvas\Hook\ContentTemplateHooks::formAlter
   */
  public function testPerContentFormPartition(): void {
    // Drupal 11.4's `standard` profile creates `article` without a comment
    // field (see ::setUp()); ensure one exists because it is the canonical
    // case of a configurable field whose widget renders in the sidebar.
    if (!FieldConfig::loadByName('node', 'article', 'comment')) {
      $this->addDefaultCommentField('node', 'article');
    }
    $this->createTestNode();
    $path = 'canvas/api/v0/form/content-entity/node/1/default';

    // Without a template, the full entity form is served unpartitioned.
    $html = $this->getFormHtml($path);
    $this->assertStringContainsString('edit-body-0-value', $html);
    $this->assertStringNotContainsString('data-canvas-form-partition', $html);

    // Expose a slot on the article's full-view template: the bundle enters
    // per-content mode and the served form is partitioned.
    $this->generateComponentConfig();
    $component = Component::load('sdc.canvas_test_sdc.props-slots');
    $this->assertInstanceOf(Component::class, $component);
    $host_uuid = '414f6e2e-fa5f-4e37-b0c2-8a4bcb2b573b';
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $host_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => 'Host'],
        ],
      ],
      'exposed_slots' => [
        'main' => [
          'component_uuid' => $host_uuid,
          'slot_name' => 'the_body',
          'label' => 'Main content',
        ],
      ],
    ])->setStatus(TRUE)->save();

    $html = $this->getFormHtml($path);
    $crawler = new Crawler($html);
    // Content fields are still served, annotated into the content partition.
    $this->assertStringContainsString('edit-body-0-value', $html);
    $this->assertCount(1, $crawler->filter('div[data-canvas-form-partition="content"].field--name-body'));
    $this->assertCount(1, $crawler->filter('div[data-canvas-form-partition="content"].field--name-field-image'));
    // Page-data elements are not annotated: only content fields carry the
    // partition marker.
    foreach ($crawler->filter('[data-canvas-form-partition]') as $element) {
      $class = $element->getAttribute('class');
      $this->assertStringNotContainsString('field--name-comment', $class);
      $this->assertStringNotContainsString('field--name-path', $class);
    }
    // The read-only meta block (published state, last saved, author) is gone.
    $this->assertStringNotContainsString('edit-meta', $html);
    // The label field and the sidebar elements stay.
    $this->assertStringContainsString('edit-title-0-value', $html);
    $this->assertStringContainsString('edit-menu', $html);
    $this->assertStringContainsString('edit-path-0-alias', $html);
    $this->assertStringContainsString('edit-uid-0-target-id', $html);
    $this->assertStringContainsString('edit-comment-0', $html);
    // The URL alias renders right below the title, ahead of the sidebar
    // groups, matching the canvas_page page-data form.
    $title_pos = \strpos($html, 'edit-title-0-value');
    $path_pos = \strpos($html, 'edit-path-0-alias');
    $menu_pos = \strpos($html, 'edit-menu');
    $this->assertNotFalse($title_pos);
    $this->assertNotFalse($path_pos);
    $this->assertNotFalse($menu_pos);
    $this->assertGreaterThan($title_pos, $path_pos);
    $this->assertLessThan($menu_pos, $path_pos);
    // And as a plain field, not the sidebar "URL path settings" details.
    $this->assertStringNotContainsString('URL path settings', $html);

    // A template without exposed slots partitions the form the same way: the
    // "no creative freedom" tier still edits fields in the Content tab.
    $template = ContentTemplate::load('node.article.full');
    $this->assertInstanceOf(ContentTemplate::class, $template);
    $template->set('exposed_slots', [])->save();
    $html = $this->getFormHtml($path);
    $crawler = new Crawler($html);
    $this->assertCount(1, $crawler->filter('div[data-canvas-form-partition="content"].field--name-body'));

    // Drupal's own node edit form is unaffected.
    $this->drupalGet('node/1/edit');
    $this->assertSession()->fieldExists('body[0][value]');
  }

  private function getFormHtml(string $path): string {
    $response = $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $parsed_response = json_decode($response, TRUE);
    $this->assertIsArray($parsed_response);
    $this->assertArrayHasKey('html', $parsed_response);
    return $parsed_response['html'];
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
