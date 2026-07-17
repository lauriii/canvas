<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests the `list` component source: rendering, metadata, and validation.
 *
 * This is a focused test of the List element rather than a
 * ComponentSourceTestBase subclass: the List element is one fixed component
 * whose behavior is driven entirely by per-instance settings, so the base
 * class's discovery, fallback, and uninstall matrix does not apply without
 * heavy ceremony.
 *
 * @see \Drupal\Tests\canvas\Functional\ListElementPaginationTest
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ListComponent::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class ListComponentTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;
  use CrawlerTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string COMPONENT_ID = 'list.list';
  private const string UUID = 'f6c3dead-7fdd-47b4-9c9b-0aa3f7b5b6a5';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    // The List element is only discovered once >=1 content type exists.
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  /**
   * Returns canonical List element settings, with per-test overrides.
   */
  private static function settings(array $overrides = []): array {
    return $overrides + [
      'source' => ['entity_type' => 'node', 'bundle' => 'article'],
      'display' => ['mode' => 'title_linked'],
      'limit' => 3,
      'pagination' => ['mode' => 'none', 'page_size' => 10],
      'filters' => ['conjunction' => 'and', 'conditions' => []],
      'sorts' => [['field' => 'created', 'direction' => 'desc']],
      'layout' => ['mode' => 'stack', 'gap' => 'medium'],
    ];
  }

  /**
   * Returns the single List component source instance.
   */
  private function listSource(): ListComponent {
    $component = Component::load(self::COMPONENT_ID);
    $this->assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();
    $this->assertInstanceOf(ListComponent::class, $source);
    return $source;
  }

  /**
   * Renders List element settings the way the component tree does.
   */
  private function renderSettings(array $settings, bool $is_preview = FALSE): array {
    // When calling ::renderComponent() directly there is no hydration step, so
    // only the explicit input is passed; the host context key is only present
    // during real tree rendering.
    return $this->listSource()->renderComponent(
      [ListComponent::EXPLICIT_INPUT_NAME => $settings],
      [],
      self::UUID,
      $is_preview,
    );
  }

  /**
   * Creates published article nodes, newest first.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   The created nodes, keyed 1 (newest) through $count (oldest).
   */
  private static function createArticles(int $count): array {
    $base = \Drupal::time()->getRequestTime();
    $nodes = [];
    for ($i = 1; $i <= $count; $i++) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'List article ' . $i,
        'status' => NodeInterface::PUBLISHED,
        'created' => $base - $i * 100,
      ]);
      $node->save();
      $nodes[$i] = $node;
    }
    return $nodes;
  }

  /**
   * The generated Component config entity is enabled, versioned, and valid.
   */
  public function testComponentEntity(): void {
    $component = Component::load(self::COMPONENT_ID);
    $this->assertInstanceOf(Component::class, $component);
    self::assertTrue($component->status());
    self::assertNotSame('', $component->getActiveVersion());
    self::assertEntityIsValid($component);
    self::assertInstanceOf(ListComponent::class, $component->getComponentSource());
  }

  /**
   * Rendering: built-in display, view modes, layouts, cacheability, paging.
   */
  public function testRenderComponent(): void {
    $nodes = self::createArticles(5);

    // The built-in "Title (linked)" display renders one link per matching
    // node, newest first, respecting the limit.
    $build = $this->renderSettings(self::settings());
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    $crawler = $this->crawlerForRenderArray($build);
    $links = $crawler->filter('a.canvas-list__item-title-link');
    self::assertCount(3, $links);
    self::assertSame(
      ['List article 1', 'List article 2', 'List article 3'],
      $links->each(static fn (Crawler $link): string => trim($link->text())),
    );
    self::assertSame(
      [
        $nodes[1]->toUrl()->toString(),
        $nodes[2]->toUrl()->toString(),
        $nodes[3]->toUrl()->toString(),
      ],
      $links->each(static fn (Crawler $link): ?string => $link->attr('href')),
    );
    self::assertStringNotContainsString('List article 4', $crawler->html());

    // Stack layout markup.
    self::assertCount(1, $crawler->filter('.canvas-list.canvas-list--stack.canvas-list--gap-medium'));

    // The build carries the query's cache metadata.
    self::assertContains('node_list:article', $cacheability->getCacheTags());
    self::assertContains('languages:language_content', $cacheability->getCacheContexts());
    self::assertContains('user.permissions', $cacheability->getCacheContexts());

    // Without pagination there is no load more button and no scroll sentinel.
    self::assertCount(0, $crawler->filter('.canvas-list-element__load-more'));
    self::assertCount(0, $crawler->filter('.canvas-list-element__sentinel'));

    // The view mode display renders items through the entity view builder.
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'teaser',
      'status' => TRUE,
    ])->save();
    $build = $this->renderSettings(self::settings([
      'display' => ['mode' => 'view_mode', 'view_mode' => 'teaser'],
    ]));
    $crawler = $this->crawlerForRenderArray($build);
    // Each item's root element is the node template's <article> wrapper. Note
    // that a teaser may contain further, nested <article> elements: the
    // author is rendered through the user entity's "compact" view mode.
    self::assertCount(3, $crawler->filter('.canvas-list__item > article'));
    self::assertCount(0, $crawler->filter('a.canvas-list__item-title-link'));

    // Grid layout markup, with the per-row custom property.
    $build = $this->renderSettings(self::settings([
      'layout' => ['mode' => 'grid', 'gap' => 'medium', 'max_per_row' => 3],
    ]));
    $crawler = $this->crawlerForRenderArray($build);
    $grid = $crawler->filter('.canvas-list--grid');
    self::assertCount(1, $grid);
    self::assertSame('--canvas-list-per-row: 3;', $grid->attr('style'));

    // Row layout markup, with the per-row custom property.
    $build = $this->renderSettings(self::settings([
      'layout' => ['mode' => 'row', 'gap' => 'small', 'items_per_row' => 2],
    ]));
    $crawler = $this->crawlerForRenderArray($build);
    $row = $crawler->filter('.canvas-list--row.canvas-list--gap-small');
    self::assertCount(1, $row);
    self::assertSame('--canvas-list-per-row: 2;', $row->attr('style'));

    // A paginated list with more matching content than one page renders the
    // load more button on the live site. Without a host entity (which only
    // exists during real tree rendering) the endpoint wiring is omitted.
    $build = $this->renderSettings(self::settings([
      'limit' => 5,
      'pagination' => ['mode' => 'load_more', 'page_size' => 2],
    ]));
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(2, $crawler->filter('.canvas-list__item'));
    self::assertCount(1, $crawler->filter('button.canvas-list-element__load-more'));
    self::assertCount(0, $crawler->filter('.canvas-list-element__sentinel'));
    self::assertCount(0, $crawler->filter('[data-canvas-list-endpoint]'));

    // Infinite scroll renders a sentinel instead of a button.
    $build = $this->renderSettings(self::settings([
      'limit' => NULL,
      'pagination' => ['mode' => 'infinite_scroll', 'page_size' => 2],
    ]));
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(2, $crawler->filter('.canvas-list__item'));
    self::assertCount(1, $crawler->filter('.canvas-list-element__sentinel'));
    self::assertCount(0, $crawler->filter('button.canvas-list-element__load-more'));
  }

  /**
   * Empty result sets and misconfigured settings render per surface.
   */
  public function testEmptyAndMisconfiguredStates(): void {
    // No matching content, live: the empty list container renders (so client
    // side behaviors have a stable target), without any state message.
    $build = $this->renderSettings(self::settings());
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('.canvas-list'));
    self::assertCount(0, $crawler->filter('.canvas-list__item'));
    self::assertCount(0, $crawler->filter('.canvas-list-element__state'));

    // No matching content, preview: editors see an explanatory state.
    $build = $this->renderSettings(self::settings(), TRUE);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('.canvas-list-element__state--empty'));
    self::assertStringContainsString('No content matches these settings.', $crawler->text());

    // Misconfigured (nonexistent bundle), live: renders nothing at all.
    $misconfigured = self::settings([
      'source' => ['entity_type' => 'node', 'bundle' => 'does_not_exist'],
    ]);
    self::assertSame([], $this->renderSettings($misconfigured));

    // Misconfigured, preview: editors see the warning state.
    $build = $this->renderSettings($misconfigured, TRUE);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('.canvas-list-element--warning .canvas-list-element__state'));
    self::assertStringContainsString('This List is misconfigured', $crawler->text());
  }

  /**
   * Input validation prefixes property paths with the instance UUID.
   */
  public function testValidateComponentInput(): void {
    $source = $this->listSource();

    self::assertCount(0, $source->validateComponentInput(self::settings(), self::UUID, NULL));

    $violations = $source->validateComponentInput(self::settings([
      'filters' => [
        'conjunction' => 'and',
        'conditions' => [
          // `between` is a date operator; it is invalid for the title field.
          ['field' => 'title', 'operator' => 'between'],
        ],
      ],
      'layout' => ['mode' => 'stack', 'gap' => 'huge'],
    ]), self::UUID, NULL);

    $violations_array = self::violationsToArray($violations);
    self::assertSame([
      \sprintf('inputs.%s.filters.conditions.0.operator', self::UUID),
      \sprintf('inputs.%s.layout.gap', self::UUID),
    ], \array_keys($violations_array));
    self::assertStringContainsString('operator is not allowed', (string) $violations_array[\sprintf('inputs.%s.filters.conditions.0.operator', self::UUID)]);
    self::assertStringContainsString('not one of the allowed gaps', (string) $violations_array[\sprintf('inputs.%s.layout.gap', self::UUID)]);
  }

}
