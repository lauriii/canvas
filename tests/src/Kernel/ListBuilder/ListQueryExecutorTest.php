<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ListBuilder;

use Drupal\canvas\ListBuilder\ListQueryExecutor;
use Drupal\canvas\ListBuilder\ListQueryResult;
use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the List element's constrained query DSL.
 */
#[CoversClass(ListQueryExecutor::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ListQueryExecutorTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The node_access_test_empty module activates the node grants system, so
   * that access-checked entity queries exclude inaccessible nodes at query
   * time — which is what keeps unpublished nodes from occupying a slot in a
   * ranged window.
   */
  protected static $modules = [
    'node',
    'field',
    'language',
    'node_access_test_empty',
  ];

  /**
   * The query executor under test.
   */
  private ListQueryExecutor $executor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'language']);
    $this->createContentType(['type' => 'article'], FALSE);
    $this->createContentType(['type' => 'page'], FALSE);

    // An integer field and an optional string field on articles, for
    // filtering and sorting.
    foreach (['field_count' => 'integer', 'field_note' => 'string'] as $field_name => $type) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'article',
      ])->save();
    }

    // An unprivileged user who only sees published content. Explicitly not
    // user 1, which bypasses all access checks.
    $this->setUpCurrentUser(['uid' => 2], ['access content']);

    $executor = $this->container->get(ListQueryExecutor::class);
    \assert($executor instanceof ListQueryExecutor);
    $this->executor = $executor;
  }

  /**
   * Returns valid canonical settings, with the given top-level overrides.
   */
  private static function settings(array $overrides = []): array {
    return $overrides + [
      'source' => ['entity_type' => 'node', 'bundle' => 'article'],
      'display' => ['mode' => 'title_linked'],
      'limit' => NULL,
      'pagination' => ['mode' => 'infinite_scroll', 'page_size' => 50],
      'filters' => ['conjunction' => 'and', 'conditions' => []],
      'sorts' => [['field' => 'created', 'direction' => 'asc']],
      'layout' => ['mode' => 'stack', 'gap' => 'medium'],
    ];
  }

  /**
   * Creates an article node.
   */
  private function createArticle(array $values = []): NodeInterface {
    $node = $this->createNode($values + ['type' => 'article']);
    \assert($node instanceof NodeInterface);
    return $node;
  }

  /**
   * Returns the entity IDs of a result window, in result order.
   *
   * @return list<int>
   */
  private static function resultIds(ListQueryResult $result): array {
    return \array_map(\intval(...), \array_keys($result->entities));
  }

  /**
   * Returns the entity ID of a node, as an integer.
   */
  private static function id(NodeInterface $node): int {
    return (int) $node->id();
  }

  public function testAccessFiltering(): void {
    $first = $this->createArticle(['created' => 1000]);
    $this->createArticle(['created' => 2000, 'status' => NodeInterface::NOT_PUBLISHED]);
    $second = $this->createArticle(['created' => 3000]);
    $third = $this->createArticle(['created' => 4000]);

    // Only the published nodes are returned.
    $result = $this->executor->execute(self::settings());
    self::assertSame([self::id($first), self::id($second), self::id($third)], self::resultIds($result));
    self::assertFalse($result->hasMore);

    // The unpublished node does not occupy a slot in a ranged window: the
    // first window is filled entirely with published nodes, and the last
    // published node is the sole occupant of the second window.
    $paged = self::settings(['pagination' => ['mode' => 'infinite_scroll', 'page_size' => 2]]);
    $result = $this->executor->execute($paged);
    self::assertSame([self::id($first), self::id($second)], self::resultIds($result));
    self::assertTrue($result->hasMore);
    $result = $this->executor->execute($paged, 2);
    self::assertSame([self::id($third)], self::resultIds($result));
    self::assertFalse($result->hasMore);
  }

  public function testBundleScoping(): void {
    $article = $this->createArticle(['created' => 1000]);
    $page = $this->createNode(['type' => 'page', 'created' => 2000]);

    $result = $this->executor->execute(self::settings());
    self::assertSame([self::id($article)], self::resultIds($result));

    $result = $this->executor->execute(self::settings([
      'source' => ['entity_type' => 'node', 'bundle' => 'page'],
    ]));
    self::assertSame([(int) $page->id()], self::resultIds($result));
  }

  public function testConjunction(): void {
    $high_match = $this->createArticle(['title' => 'Alpha one', 'field_count' => 10, 'created' => 1000]);
    $high_other = $this->createArticle(['title' => 'Beta two', 'field_count' => 10, 'created' => 2000]);
    $low_match = $this->createArticle(['title' => 'Alpha three', 'field_count' => 1, 'created' => 3000]);
    $this->createArticle(['title' => 'Beta four', 'field_count' => 1, 'created' => 4000]);

    $conditions = [
      ['field' => 'field_count', 'operator' => 'gte', 'value' => 5],
      ['field' => 'title', 'operator' => 'contains', 'value' => 'Alpha'],
    ];

    // "and" only matches nodes satisfying both conditions.
    $result = $this->executor->execute(self::settings([
      'filters' => ['conjunction' => 'and', 'conditions' => $conditions],
    ]));
    self::assertSame([self::id($high_match)], self::resultIds($result));

    // "or" matches nodes satisfying either condition.
    $result = $this->executor->execute(self::settings([
      'filters' => ['conjunction' => 'or', 'conditions' => $conditions],
    ]));
    self::assertSame([self::id($high_match), self::id($high_other), self::id($low_match)], self::resultIds($result));
  }

  public function testSortPriority(): void {
    $low_old = $this->createArticle(['field_count' => 1, 'created' => 1000]);
    $low_new = $this->createArticle(['field_count' => 1, 'created' => 2000]);
    $high_first = $this->createArticle(['field_count' => 2, 'created' => 3000]);
    $high_second = $this->createArticle(['field_count' => 2, 'created' => 3000]);

    // Primary sort: integer field ascending. Secondary sort: created
    // descending. Full ties (the two nodes sharing count 2 and an identical
    // creation date) are broken by the deterministic ID DESC tiebreaker.
    $result = $this->executor->execute(self::settings([
      'sorts' => [
        ['field' => 'field_count', 'direction' => 'asc'],
        ['field' => 'created', 'direction' => 'desc'],
      ],
    ]));
    self::assertSame([
      self::id($low_new),
      self::id($low_old),
      self::id($high_second),
      self::id($high_first),
    ], self::resultIds($result));
  }

  public function testWindowingAndHasMore(): void {
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
      $ids[] = self::id($this->createArticle(['created' => 1000 + $i]));
    }

    $settings = self::settings(['pagination' => ['mode' => 'infinite_scroll', 'page_size' => 3]]);

    $result = $this->executor->execute($settings);
    self::assertSame(\array_slice($ids, 0, 3), self::resultIds($result));
    self::assertTrue($result->hasMore);

    $result = $this->executor->execute($settings, 3);
    self::assertSame(\array_slice($ids, 3, 3), self::resultIds($result));
    self::assertTrue($result->hasMore);

    $result = $this->executor->execute($settings, 6);
    self::assertSame(\array_slice($ids, 6, 1), self::resultIds($result));
    self::assertFalse($result->hasMore);
  }

  public function testLimitInteraction(): void {
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
      $ids[] = self::id($this->createArticle(['created' => 1000 + $i]));
    }

    // A limit caps the total result set: the second window is truncated to
    // the limit, and reaching the limit means no more pages — even though
    // more matching content exists.
    $limited = self::settings(['limit' => 5, 'pagination' => ['mode' => 'load_more', 'page_size' => 3]]);
    $result = $this->executor->execute($limited);
    self::assertSame(\array_slice($ids, 0, 3), self::resultIds($result));
    self::assertTrue($result->hasMore);
    $result = $this->executor->execute($limited, 3);
    self::assertSame(\array_slice($ids, 3, 2), self::resultIds($result));
    self::assertFalse($result->hasMore);

    // Without pagination the initial window is the only window: it holds
    // `limit` items, and any further offset yields nothing.
    $unpaginated = self::settings(['limit' => 3, 'pagination' => ['mode' => 'none', 'page_size' => 10]]);
    $result = $this->executor->execute($unpaginated);
    self::assertSame(\array_slice($ids, 0, 3), self::resultIds($result));
    self::assertFalse($result->hasMore);
    $result = $this->executor->execute($unpaginated, 3);
    self::assertSame([], $result->entities);
    self::assertFalse($result->hasMore);
  }

  public function testCacheability(): void {
    $this->createArticle();

    $result = $this->executor->execute(self::settings());
    self::assertSame(['node_list:article'], $result->cacheability->getCacheTags());
    self::assertEqualsCanonicalizing(['languages:language_content', 'user.permissions'], $result->cacheability->getCacheContexts());

    // The empty-window early return carries the same cacheability.
    $result = $this->executor->execute(self::settings(['limit' => 1, 'pagination' => ['mode' => 'none', 'page_size' => 10]]), 5);
    self::assertSame([], $result->entities);
    self::assertSame(['node_list:article'], $result->cacheability->getCacheTags());
    self::assertEqualsCanonicalizing(['languages:language_content', 'user.permissions'], $result->cacheability->getCacheContexts());
  }

  public function testInertConditionsAreSkipped(): void {
    $first = $this->createArticle(['title' => 'Alpha', 'created' => 1000]);
    $second = $this->createArticle(['title' => 'Beta', 'created' => 2000]);

    // Conditions whose operator needs a value but that have none stored are
    // inert: they must be skipped, not applied. A between condition is only
    // complete with both boundaries.
    $result = $this->executor->execute(self::settings([
      'filters' => [
        'conjunction' => 'and',
        'conditions' => [
          ['field' => 'title', 'operator' => 'contains'],
          ['field' => 'created', 'operator' => 'between', 'value' => ['min' => '2024-01-01']],
        ],
      ],
    ]));
    self::assertSame([self::id($first), self::id($second)], self::resultIds($result));
  }

  public function testIsSetAndNotSet(): void {
    $with_note = $this->createArticle(['field_note' => 'x', 'created' => 1000]);
    $with_other_note = $this->createArticle(['field_note' => 'y', 'created' => 2000]);
    $without_note = $this->createArticle(['created' => 3000]);

    $result = $this->executor->execute(self::settings([
      'filters' => [
        'conjunction' => 'and',
        'conditions' => [['field' => 'field_note', 'operator' => 'is_set']],
      ],
    ]));
    self::assertSame([self::id($with_note), self::id($with_other_note)], self::resultIds($result));

    $result = $this->executor->execute(self::settings([
      'filters' => [
        'conjunction' => 'and',
        'conditions' => [['field' => 'field_note', 'operator' => 'not_set']],
      ],
    ]));
    self::assertSame([self::id($without_note)], self::resultIds($result));
  }

  public function testLanguageFiltering(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $english_only = $this->createArticle(['title' => 'English only', 'created' => 1000]);
    $translated = $this->createArticle(['title' => 'English source', 'created' => 2000]);
    $translated->addTranslation('fr', ['title' => 'French translation', 'created' => 2000])->save();
    $french_only = $this->createArticle(['title' => 'French only', 'langcode' => 'fr', 'created' => 3000]);
    $neutral = $this->createArticle([
      'title' => 'Language neutral',
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
      'created' => 4000,
    ]);

    // With English as the current content language: English and
    // language-neutral nodes are returned, the French-only node is not.
    $result = $this->executor->execute(self::settings());
    self::assertEqualsCanonicalizing(
      [self::id($english_only), self::id($translated), self::id($neutral)],
      self::resultIds($result),
    );

    // Switch the current content language to French.
    $french = ConfigurableLanguage::load('fr');
    \assert($french !== NULL);
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->container->get('language.default')->set($french);
    $this->container->get('language_manager')->reset();

    // Now the French and language-neutral nodes are returned, the
    // English-only node is not, and the translated node is returned in its
    // French translation.
    $result = $this->executor->execute(self::settings());
    self::assertEqualsCanonicalizing(
      [self::id($translated), self::id($french_only), self::id($neutral)],
      self::resultIds($result),
    );
    $translated_result = $result->entities[self::id($translated)];
    self::assertSame('fr', $translated_result->language()->getId());
    self::assertSame('French translation', $translated_result->label());
  }

}
