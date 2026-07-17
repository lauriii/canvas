<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Controller\ApiListElementController;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests the List element pagination endpoint against a real canvas_page.
 *
 * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ListComponentTest
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiListElementController::class)]
#[Group('canvas')]
final class ListElementPaginationTest extends FunctionalTestBase {

  use GenerateComponentConfigTrait;

  private const string LIST_UUID = '3f2e60ea-3f56-46f4-b267-3ae89aed4bd5';
  private const string NON_LIST_UUID = 'ab72924b-a1f6-4b07-a0e7-6a3d3b03d8f7';
  private const string UNKNOWN_UUID = '00000000-0000-4000-8000-000000000000';

  /**
   * The number of published article nodes.
   */
  private const int PUBLISHED_COUNT = 7;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'node',
    // Activates the node grants system, so that the executor's access-checked
    // entity queries exclude inaccessible nodes at query time — which is what
    // keeps unpublished nodes from occupying a slot in a ranged window.
    // @see \Drupal\Tests\canvas\Kernel\ListBuilder\ListQueryExecutorTest
    'node_access_test_empty',
    'page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The canvas_page entity hosting the List element.
   */
  private Page $page;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Make anonymous responses page-cacheable, to assert cacheability below.
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    // The List element is only discovered once >=1 content type exists.
    $this->generateComponentConfig();

    // Anonymous visitors may view published content, including the page
    // hosting the List element.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);

    $base = \time();
    for ($i = 1; $i <= self::PUBLISHED_COUNT; $i++) {
      $this->drupalCreateNode([
        'type' => 'article',
        'title' => 'Pagination article ' . $i,
        'status' => NodeInterface::PUBLISHED,
        'created' => $base - $i * 60,
      ]);
    }
    // An unpublished node whose creation date falls in the middle of the
    // result set: were it wrongly counted, every page offset would shift.
    $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Unpublished pagination article',
      'status' => NodeInterface::NOT_PUBLISHED,
      'created' => $base - 150,
    ]);

    $this->page = Page::create([
      'title' => 'List pagination test page',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::LIST_UUID,
          'component_id' => 'list.list',
          'inputs' => self::listSettings(NULL, ['mode' => 'infinite_scroll', 'page_size' => 3]),
        ],
        // A non-List component instance, to prove the endpoint rejects it.
        [
          'uuid' => self::NON_LIST_UUID,
          'component_id' => 'sdc.canvas_test_sdc.druplicon',
          'inputs' => [],
        ],
      ],
    ]);
    self::assertCount(0, $this->page->validate());
    $this->page->save();
  }

  /**
   * Returns canonical List element settings for articles, newest first.
   */
  private static function listSettings(?int $limit, array $pagination): array {
    return [
      'source' => ['entity_type' => 'node', 'bundle' => 'article'],
      'display' => ['mode' => 'title_linked'],
      'limit' => $limit,
      'pagination' => $pagination,
      'filters' => ['conjunction' => 'and', 'conditions' => []],
      'sorts' => [['field' => 'created', 'direction' => 'desc']],
      'layout' => ['mode' => 'stack', 'gap' => 'medium'],
    ];
  }

  /**
   * Returns the pagination endpoint URL for a component instance UUID.
   */
  private function endpointUrl(string $component_instance_uuid = self::LIST_UUID): Url {
    return Url::fromRoute('canvas.list_element.page', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => (string) $this->page->id(),
      'component_instance_uuid' => $component_instance_uuid,
    ]);
  }

  /**
   * GETs one page of the List element and decodes the JSON response.
   *
   * @return array{html: string, more: bool}
   */
  private function getListPage(array $query): array {
    $body = $this->drupalGet($this->endpointUrl(), ['query' => $query]);
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->responseHeaderContains('Content-Type', 'application/json');
    $data = Json::decode($body);
    self::assertIsArray($data);
    self::assertArrayHasKey('html', $data);
    self::assertArrayHasKey('more', $data);
    return $data;
  }

  /**
   * Returns the item titles in one page of rendered List element HTML.
   *
   * @return list<string>
   */
  private static function itemTitles(string $html): array {
    $crawler = new Crawler($html);
    // Every rendered item is a title link: nothing else sneaks into a page.
    self::assertCount(
      $crawler->filter('.canvas-list__item')->count(),
      $crawler->filter('a.canvas-list__item-title-link'),
    );
    return $crawler->filter('a.canvas-list__item-title-link')
      ->each(static fn (Crawler $link): string => \trim($link->text()));
  }

  /**
   * Covers the endpoint end to end, as an anonymous visitor.
   */
  public function testPaginationEndpoint(): void {
    $assert_session = $this->assertSession();

    // The published page renders the first window (3 of 7, newest first) and
    // wires up the pagination endpoint for the visitor.
    $this->drupalGet($this->page->toUrl());
    $assert_session->statusCodeEquals(200);
    foreach ([1, 2, 3] as $i) {
      $assert_session->pageTextContains('Pagination article ' . $i);
    }
    foreach ([4, 5, 6, 7] as $i) {
      $assert_session->pageTextNotContains('Pagination article ' . $i);
    }
    $assert_session->pageTextNotContains('Unpublished pagination article');
    $assert_session->elementExists('css', \sprintf(
      '[data-canvas-list-endpoint*="canvas/list-element/%s/%s/%s"][data-canvas-list-mode="infinite_scroll"][data-canvas-list-offset="3"]',
      Page::ENTITY_TYPE_ID,
      $this->page->id(),
      self::LIST_UUID,
    ));

    // Offset 3 returns exactly the next 3 titles, with more pages remaining.
    $second_page = $this->getListPage(['offset' => 3]);
    $assert_session->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    self::assertTrue($second_page['more']);
    self::assertSame(
      ['Pagination article 4', 'Pagination article 5', 'Pagination article 6'],
      self::itemTitles($second_page['html']),
    );

    // Offset 6 returns the single remaining item, and no more pages.
    $last_page = $this->getListPage(['offset' => 6]);
    self::assertFalse($last_page['more']);
    self::assertSame(['Pagination article 7'], self::itemTitles($last_page['html']));

    // The unpublished node never appears and does not occupy a result slot:
    // its creation date falls between articles 2 and 3, so were it counted,
    // it would have shifted the initial, offset-3, and offset-6 windows
    // asserted above.
    self::assertStringNotContainsString('Unpublished pagination article', $second_page['html']);
    self::assertStringNotContainsString('Unpublished pagination article', $last_page['html']);

    // Tampering with query parameters cannot change the result: every setting
    // that shapes the query comes from the stored, validated instance inputs.
    $tampered = $this->getListPage([
      'offset' => 3,
      'page_size' => 1,
      'limit' => 1,
      'bundle' => 'page',
      'sorts' => [['field' => 'title', 'direction' => 'asc']],
      'filters' => ['conditions' => [['field' => 'title', 'operator' => 'contains', 'value' => '7']]],
    ]);
    self::assertSame($second_page, $tampered);

    // A non-integer offset is rejected before the controller even runs:
    // \Symfony\Component\HttpFoundation\InputBag::filter() throws a
    // BadRequestException for it.
    $this->drupalGet($this->endpointUrl(), ['query' => ['offset' => 'garbage']]);
    $assert_session->statusCodeEquals(400);

    // The response is cacheable for anonymous visitors: repeating the first
    // paginated request is served from the page cache.
    $repeated = $this->getListPage(['offset' => 3]);
    $assert_session->responseHeaderEquals('X-Drupal-Cache', 'HIT');
    self::assertSame($second_page, $repeated);
  }

  /**
   * Requests that do not identify a paginated List element are 404s.
   */
  public function testNotFoundPaths(): void {
    $assert_session = $this->assertSession();

    // Only positive integer offsets exist: offset 0 is the initial render,
    // which is only ever served as part of the host page.
    foreach (['0', '-1', NULL] as $offset) {
      $this->drupalGet($this->endpointUrl(), $offset === NULL ? [] : ['query' => ['offset' => $offset]]);
      $assert_session->statusCodeEquals(404);
    }

    // A UUID without a component instance in this entity's tree is a 404.
    $this->drupalGet($this->endpointUrl(self::UNKNOWN_UUID), ['query' => ['offset' => 3]]);
    $assert_session->statusCodeEquals(404);

    // A component instance that is not a List element is a 404.
    $this->drupalGet($this->endpointUrl(self::NON_LIST_UUID), ['query' => ['offset' => 3]]);
    $assert_session->statusCodeEquals(404);

    // Without pagination the endpoint does not exist for this List element:
    // the entity update invalidates any previously cached response.
    $this->page->setComponentTree([
      [
        'uuid' => self::LIST_UUID,
        'component_id' => 'list.list',
        'inputs' => self::listSettings(3, ['mode' => 'none', 'page_size' => 3]),
      ],
      [
        'uuid' => self::NON_LIST_UUID,
        'component_id' => 'sdc.canvas_test_sdc.druplicon',
        'inputs' => [],
      ],
    ]);
    self::assertCount(0, $this->page->validate());
    $this->page->save();
    $this->drupalGet($this->endpointUrl(), ['query' => ['offset' => 3]]);
    $assert_session->statusCodeEquals(404);
  }

}
