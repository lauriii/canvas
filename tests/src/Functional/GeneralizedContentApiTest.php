<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content HTTP API generalized beyond canvas_page.
 *
 * Covers listing, filtering, sorting, draft creation with deferred
 * validation, the entity-form-fields auto-save endpoint, and the
 * publish-blocked-until-valid flow for templated bundles.
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiContentControllers::class)]
#[Group('canvas')]
#[Group('#slow')]
final class GeneralizedContentApiTest extends HttpApiTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    $this->createContentType(['type' => 'untemplated', 'name' => 'Untemplated']);
    // A required field with no default: drafts of this bundle are invalid
    // until it is filled, which is exactly what deferred validation allows.
    FieldStorageConfig::create([
      'field_name' => 'field_required_text',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_required_text',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Required text',
      'required' => TRUE,
    ])->save();
    $form_display = \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article');
    $form_display->setComponent('field_required_text', ['type' => 'string_textfield'])->save();

    $this->generateComponentConfig();
    $component = Component::load('sdc.canvas_test_sdc.props-no-slots');
    $this->assertInstanceOf(Component::class, $component);
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => 'b4937e4c-93b7-4172-abd1-0b03a4c5c1a6',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
    ])->setStatus(TRUE)->save();
  }

  public function testGeneralizedListCreateAndPublish(): void {
    $account = $this->drupalCreateUser([
      'access administration pages',
      'bypass node access',
      'administer url aliases',
      'publish auto-saves',
      'access content',
    ]);
    $this->assertNotFalse($account);
    $this->drupalLogin($account);

    Node::create([
      'type' => 'article',
      'title' => 'Zulu article',
      'field_required_text' => 'ok',
      'status' => TRUE,
    ])->save();
    Node::create([
      'type' => 'article',
      'title' => 'Alpha article',
      'field_required_text' => 'ok',
      'status' => TRUE,
    ])->save();
    Node::create([
      'type' => 'untemplated',
      'title' => 'Untemplated node',
      'status' => TRUE,
    ])->save();

    // Listing nodes covers only templated bundles, with the browser columns.
    $list_url = Url::fromUri('base:/canvas/api/v0/content/node');
    $body = $this->requestJson('GET', $list_url, []);
    $this->assertSame(200, $this->lastResponseStatus);
    $titles = \array_column($body['data'], 'title');
    $this->assertContains('Zulu article', $titles);
    $this->assertContains('Alpha article', $titles);
    $this->assertNotContains('Untemplated node', $titles);
    $row = $body['data'][\array_search('Alpha article', $titles, TRUE)];
    $this->assertSame('node', $row['entityType']);
    $this->assertSame('article', $row['bundle']);
    $this->assertSame('Article', $row['bundleLabel']);
    $this->assertSame($account->getDisplayName(), $row['authorName']);
    $this->assertIsInt($row['created']);
    $this->assertIsInt($row['changed']);

    // Sorting by title ascending.
    $sorted_url = Url::fromUri('base:/canvas/api/v0/content/node', ['query' => ['sort' => 'title']]);
    $body = $this->requestJson('GET', $sorted_url, []);
    $this->assertSame(200, $this->lastResponseStatus);
    $sorted_titles = \array_column($body['data'], 'title');
    $this->assertSame('Alpha article', $sorted_titles[0]);

    // An invalid sort is rejected.
    $bad_sort_url = Url::fromUri('base:/canvas/api/v0/content/node', ['query' => ['sort' => 'uid']]);
    $this->requestJson('GET', $bad_sort_url, []);
    $this->assertSame(400, $this->lastResponseStatus);

    // The bundle filter accepts only editable bundles.
    $filtered_url = Url::fromUri('base:/canvas/api/v0/content/node', ['query' => ['filter' => ['bundle' => 'article']]]);
    $body = $this->requestJson('GET', $filtered_url, []);
    $this->assertSame(200, $this->lastResponseStatus);
    $this->assertNotEmpty($body['data']);
    $bad_filter_url = Url::fromUri('base:/canvas/api/v0/content/node', ['query' => ['filter' => ['bundle' => 'untemplated']]]);
    $this->requestJson('GET', $bad_filter_url, []);
    $this->assertSame(400, $this->lastResponseStatus);

    // A type with no editable bundles is denied at the route.
    $this->requestJson('GET', Url::fromUri('base:/canvas/api/v0/content/user'), []);
    $this->assertSame(403, $this->lastResponseStatus);

    // Creating a draft: a real unpublished entity with a placeholder label,
    // field defaults, and NO constraint validation, although
    // field_required_text is required and empty.
    $create_url = Url::fromUri('base:/canvas/api/v0/content/node');
    $body = $this->requestJson('POST', $create_url, [
      RequestOptions::JSON => ['bundle' => 'article'],
    ]);
    $this->assertSame(201, $this->lastResponseStatus);
    $this->assertSame('Untitled Article', $body['title']);
    $this->assertFalse($body['status']);
    $this->assertTrue($body['isNew']);
    $draft_id = (int) $body['entity_id'];
    $draft = Node::load($draft_id);
    $this->assertNotNull($draft);
    $this->assertFalse($draft->isPublished());
    $this->assertTrue($draft->get('field_required_text')->isEmpty());

    // A bundle without an enabled full-view template is rejected.
    $this->requestJson('POST', $create_url, [
      RequestOptions::JSON => ['bundle' => 'untemplated'],
    ]);
    $this->assertSame(400, $this->lastResponseStatus);

    // Auto-save field values through the entity-form-fields endpoint: the
    // edit becomes the draft's own pending change.
    $fields_url = Url::fromUri("base:/canvas/api/v0/content/node/$draft_id/entity-form-fields");
    $this->requestJson('PATCH', $fields_url, [
      RequestOptions::JSON => [
        'entity_form_fields' => [
          'title[0][value]' => 'Campaign article',
        ],
      ],
    ]);
    $this->assertSame(204, $this->lastResponseStatus);
    $auto_save_manager = $this->container->get('Drupal\canvas\AutoSave\AutoSaveManager');
    $draft = Node::load($draft_id);
    $this->assertNotNull($draft);
    $auto_saved = $auto_save_manager->getAutoSaveEntity($draft);
    $this->assertFalse($auto_saved->isEmpty());
    $this->assertSame('Campaign article', (string) $auto_saved->entity?->label());

    // Publishing the draft is blocked while the required field is empty: the
    // response is a 422 whose violation names the field, so the client can
    // highlight it in the Content tab.
    $publish_url = Url::fromUri('base:/canvas/api/v0/auto-saves/publish');
    $auto_save_key = "node:$draft_id:en";
    $auto_saves = $this->requestJson('GET', Url::fromUri('base:/canvas/api/v0/auto-saves/pending'), []);
    $this->assertArrayHasKey($auto_save_key, $auto_saves['data']);
    $publish_payload = [$auto_save_key => $auto_saves['data'][$auto_save_key]];
    $body = $this->requestJson('POST', $publish_url, [RequestOptions::JSON => $publish_payload]);
    $this->assertSame(422, $this->lastResponseStatus);
    $violation_paths = \array_column($body['errors'] ?? [], 'source');
    $this->assertNotEmpty($violation_paths);
    $this->assertStringContainsString('field_required_text', \json_encode($body['errors']));

    // Fix the field in place and republish: this time it succeeds and the
    // published entity carries both edits.
    $this->requestJson('PATCH', $fields_url, [
      RequestOptions::JSON => [
        'entity_form_fields' => [
          'title[0][value]' => 'Campaign article',
          'field_required_text[0][value]' => 'Now valid',
        ],
      ],
    ]);
    $this->assertSame(204, $this->lastResponseStatus);
    $draft = Node::load($draft_id);
    $this->assertNotNull($draft);
    $auto_saves = $this->requestJson('GET', Url::fromUri('base:/canvas/api/v0/auto-saves/pending'), []);
    $publish_payload = [$auto_save_key => $auto_saves['data'][$auto_save_key]];
    $this->requestJson('POST', $publish_url, [RequestOptions::JSON => $publish_payload]);
    $this->assertSame(200, $this->lastResponseStatus);
    $published = Node::load($draft_id);
    $this->assertNotNull($published);
    $this->assertSame('Campaign article', $published->label());
    $this->assertSame('Now valid', $published->get('field_required_text')->getString());
    $this->assertTrue($published->isPublished());
  }

  public function testListAccessGates(): void {
    $author = $this->drupalCreateUser([
      'access content',
      'create article content',
      'edit own article content',
      'access administration pages',
    ]);
    $this->assertNotFalse($author);
    Node::create([
      'type' => 'article',
      'title' => 'Owned by author',
      'field_required_text' => 'ok',
      'status' => TRUE,
      'uid' => $author->id(),
    ])->save();
    Node::create([
      'type' => 'article',
      'title' => 'Owned by somebody else',
      'field_required_text' => 'ok',
      'status' => TRUE,
      'uid' => 1,
    ])->save();
    $list_url = Url::fromUri('base:/canvas/api/v0/content/node');

    // A user with no Canvas editorial capability cannot list content at all:
    // the list is an editorial surface (auto-save labels of unpublished
    // work), gated by _canvas_ui_access.
    $viewer = $this->drupalCreateUser(['access content']);
    $this->assertNotFalse($viewer);
    $this->drupalLogin($viewer);
    $this->requestJson('GET', $list_url, []);
    $this->assertSame(403, $this->lastResponseStatus);
    // Creating without create access is denied in the controller.
    $this->requestJson('POST', $list_url, [
      RequestOptions::JSON => ['bundle' => 'article'],
    ]);
    $this->assertSame(403, $this->lastResponseStatus);

    // An editor who may update only their own articles sees only those rows.
    $this->drupalLogin($author);
    $body = $this->requestJson('GET', $list_url, []);
    $this->assertSame(200, $this->lastResponseStatus);
    $titles = \array_column($body['data'], 'title');
    $this->assertContains('Owned by author', $titles);
    $this->assertNotContains('Owned by somebody else', $titles);
  }

  /**
   * The status code of the last ::requestJson() response.
   */
  private int $lastResponseStatus = 0;

  /**
   * Performs an API request and decodes the JSON response body.
   *
   * @return array
   *   The decoded body, or [] when empty.
   */
  private function requestJson(string $method, Url $url, array $request_options): array {
    if (\in_array($method, ['POST', 'PATCH', 'DELETE'], TRUE)) {
      $request_options[RequestOptions::HEADERS]['X-CSRF-Token'] = $this->drupalGet('session/token');
    }
    $request_options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
    $response = $this->makeApiRequest($method, $url, $request_options);
    $this->lastResponseStatus = $response->getStatusCode();
    $body = (string) $response->getBody();
    return $body === '' ? [] : (\json_decode($body, TRUE) ?? []);
  }

}
