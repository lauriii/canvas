<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\HostEntityPropSource;
use Drupal\canvas\ShapeMatcher\HostEntityPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[CoversClass(HostEntityPropSourceMatcher::class)]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class HostEntityPropSourceMatcherTest extends PropSourceMatcherTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'sdc_test_all_props',
    'node',
    // @todo Merge these components into canvas_test_code_components once the
    //   entity_reference_autocomplete field-widget client-side transform lands
    //   in https://www.drupal.org/i/3574857.
    'canvas_test_code_components_content_entity_ref',
  ];

  /**
   * {@inheritdoc}
   */
  protected string $testedPropSourceMatcherClass = HostEntityPropSourceMatcher::class;

  /**
   * {@inheritdoc}
   *
   * Populated per-iteration by test() from the data provider — the matcher's
   * output depends on host context, so each host configuration yields its
   * own expected match set.
   */
  protected array $expectedMatches = [];

  /**
   * Host entity type for performMatch(); set per-iteration by test().
   */
  private string $hostEntityType = '';

  /**
   * Host entity bundle for performMatch(); set per-iteration by test().
   */
  private string $hostEntityBundle = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // The `News listing` fixture's `dataDependencies.entityFields` references
    // `entity:node:news_item`, which is validated against the entity type
    // manager during config install — the bundle must exist beforehand.
    NodeType::create(['type' => 'news_item', 'name' => 'News item'])->save();
    $this->installConfig(['canvas_test_code_components_content_entity_ref']);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition(HostEntityPropSourceMatcher::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function performMatch(bool $is_required, PropShape $prop_shape): array {
    \assert($this->matcher instanceof HostEntityPropSourceMatcher);
    return $this->matcher->match($is_required, $prop_shape, $this->hostEntityType, $this->hostEntityBundle);
  }

  /**
   * {@inheritdoc}
   */
  public function test(): void {
    self::markTestSkipped('Replaced by testHostContext() — host context varies per case via a data provider.');
  }

  /**
   * @param array<string, mixed> $expected_matches
   */
  #[DataProvider('provideHostContexts')]
  public function testHostContext(string $host_entity_type, string $host_entity_bundle, array $expected_matches): void {
    $this->hostEntityType = $host_entity_type;
    $this->hostEntityBundle = $host_entity_bundle;
    $this->expectedMatches = $expected_matches;
    parent::test();
  }

  public static function provideHostContexts(): \Generator {
    // Article's `author` content-entity-reference targets `user` (bundle-less) — matches.
    yield 'host=user/user (matches Article)' => [
      'user',
      'user',
      [
        'type=object&$ref=json-schema-definitions://canvas.module/content-entity-reference&x-allowed-entity-type-id=user' => [
          'sourceType' => 'host-entity',
        ],
      ],
    ];
    // News listing's `featured_news` content-entity-reference targets `node:news_item` — matches.
    yield 'host=node/news_item (matches News listing)' => [
      'node',
      'news_item',
      [
        'type=object&$ref=json-schema-definitions://canvas.module/content-entity-reference&x-allowed-bundle=news_item&x-allowed-entity-type-id=node' => [
          'sourceType' => 'host-entity',
        ],
      ],
    ];
    // Bundle mismatch via real fixture: news_item content-entity-reference vs article host bundle.
    yield 'host=node/article (bundle mismatch — rejects News listing)' => [
      'node',
      'article',
      [],
    ];
  }

  public function testNoXAllowedEntityTypeIdReturnsEmpty(): void {
    $shape = PropShape::normalize(['type' => 'string']);
    self::assertSame([], HostEntityPropSourceMatcher::match(FALSE, $shape, 'node', 'article'));
  }

  public function testTypeMismatchReturnsEmpty(): void {
    $shape = PropShape::normalize(
      JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray()
      + ['x-allowed-entity-type-id' => 'node', 'x-allowed-bundle' => 'article']
    );
    self::assertSame([], HostEntityPropSourceMatcher::match(FALSE, $shape, 'user', 'user'));
  }

  public function testTypeAndBundleMatchReturnsHostEntityPropSource(): void {
    $shape = PropShape::normalize(
      JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray()
      + ['x-allowed-entity-type-id' => 'node', 'x-allowed-bundle' => 'article']
    );
    $result = HostEntityPropSourceMatcher::match(FALSE, $shape, 'node', 'article');
    self::assertSame(
      [(new HostEntityPropSource())->toArray()],
      \array_map(fn (HostEntityPropSource $s): array => $s->toArray(), $result),
    );
  }

  public function testBundleMismatchReturnsEmpty(): void {
    $shape = PropShape::normalize(
      JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray()
      + ['x-allowed-entity-type-id' => 'node', 'x-allowed-bundle' => 'page']
    );
    self::assertSame([], HostEntityPropSourceMatcher::match(FALSE, $shape, 'node', 'article'));
  }

  public function testBundleAbsentForBundlelessTargetReturnsHostEntityPropSource(): void {
    $shape = PropShape::normalize(
      JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray()
      + ['x-allowed-entity-type-id' => 'user']
    );
    $result = HostEntityPropSourceMatcher::match(FALSE, $shape, 'user', 'user');
    self::assertSame(
      [(new HostEntityPropSource())->toArray()],
      \array_map(fn (HostEntityPropSource $s): array => $s->toArray(), $result),
    );
  }

}
