<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\TestWith;
use Prophecy\Prophet;

/**
 * Tests Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression.
 *
 * @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testLabel()
 * @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testCalculateDependencies()
 *
 * @phpstan-import-type ConfigDependenciesArray from \Drupal\canvas\Entity\VersionedConfigEntityInterface
 */
#[CoversClass(StructuredDataPropExpression::class)]
#[CoversClass(FieldPropExpression::class)]
#[CoversClass(ReferenceFieldPropExpression::class)]
#[CoversClass(FieldObjectPropsExpression::class)]
#[CoversClass(FieldTypePropExpression::class)]
#[CoversClass(ReferenceFieldTypePropExpression::class)]
#[CoversClass(FieldTypeObjectPropsExpression::class)]
#[CoversClass(ReferencedBundleSpecificBranches::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
class PropExpressionTest extends UnitTestCase {

  private const EXPECT_NO_DEPRECATION = NULL;
  public const string EXPECT_DEPRECATION_3563451 = 'Creating Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression that targets multiple bundles is deprecated in canvas:1.1.0 and will be removed from canvas:2.0.0. See https://www.drupal.org/node/3563451';
  public const string EXPECT_DEPRECATION_3563451_REFERENCE = 'Creating Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression that contains references targeting multiple bundles is deprecated in canvas:1.1.0 and will be removed from canvas:2.0.0. Instead, create a Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression, then use its ::withAdditionalBranch() to create multiple expression branches, each pointing to a single-bundle Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression. See https://www.drupal.org/node/3563451';
  public const string EXPECT_DEPRECATION_3563451_OBJECT = 'Creating Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression that contains references targeting multiple bundles is deprecated in canvas:1.1.0 and will be removed from canvas:2.0.0. Instead, create a Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression, then use its ::withAdditionalBranch() to create multiple expression branches, each pointing to a single-bundle Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression. See https://www.drupal.org/node/3563451';
  const string LABELER_TYPE_ERROR_MESSAGE_FORMAT = 'Drupal\canvas\PropExpressions\StructuredData\Labeler::label(): Argument #1 ($expr) must be of type Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface, %s given';

  private const EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES = [
    'node.type.article',
    'field.field.node.article.yo_ho',
    // @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::setUp()
    'media.type.baby_photos',
    'media.type.image',
    'media.type.remote_image',
    'media.type.vacation_photos',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $this->prophesize(TypedDataManagerInterface::class)->reveal());
    \Drupal::setContainer($container);
  }

  /**
 * Tests to string.
 */
  #[DataProvider('provider')]
  public function testToString(string $string_representation, StructuredDataPropExpressionInterface $expression): void {
    $this->assertSame($string_representation, (string) $expression);
  }

  /**
 * Tests from string.
 */
  #[DataProvider('provider')]
  public function testFromString(string $string_representation, StructuredDataPropExpressionInterface $expression, null|string|array $expected_deprecation_message): void {
    // TRICKY: work around PHPUnit limitations. ::expectDeprecation() does not
    // work here.
    // @see https://www.drupal.org/project/canvas/issues/3550750#comment-16412314
    $expected_deprecation_message_detected = 0;
    $previous_error_handler = set_error_handler(function ($severity, $message, $file, $line) use ($expected_deprecation_message, &$previous_error_handler, &$expected_deprecation_message_detected) {
      if ($severity === E_USER_DEPRECATED) {
        if ($expected_deprecation_message === NULL) {
          self::fail(\sprintf('Unexpected deprecation detected: %s', $message));
        }
        else {
          if (\is_string($expected_deprecation_message)) {
            self::assertSame($expected_deprecation_message, $message);
          }
          else {
            self::assertContains($message, $expected_deprecation_message);
          }
          // The expected deprecation message was detected; nothing else needed.
          $expected_deprecation_message_detected++;
          return FALSE;
        }
      }
      if ($previous_error_handler) {
        return $previous_error_handler($severity, $message, $file, $line);
      }
    });

    $reconstructed = call_user_func([get_class($expression), 'fromString'], $string_representation);
    $this->assertEquals($expression, $reconstructed);
    $this->assertEquals($expression, StructuredDataPropExpression::fromString($string_representation));

    if ($expected_deprecation_message === NULL) {
      self::assertSame(0, $expected_deprecation_message_detected);
    }
    else {
      // All (recursive) ::fromString() calls should have triggered the
      // deprecation, if any. That means at least two, because ::fromString()
      // was called in two different ways.
      self::assertGreaterThanOrEqual(2, $expected_deprecation_message_detected);
    }

    restore_error_handler();
  }

  /**
   * Tests get reference chain prefixes.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression::getReferenceChainPrefixes
   */
  #[DataProvider('providerReferenceFieldPropExpression')]
  public function testGetReferenceChainPrefixes(string $string_representation, StructuredDataPropExpressionInterface $expression, ?string $irrelevant_here_one, string|\Exception $irrelevant_here_two, array|\Exception $irrelevant_here_three, array $expected_reference_chain_prefixes): void {
    $reconstructed = call_user_func([get_class($expression), 'fromString'], $string_representation);
    self::assertInstanceOf(ReferenceFieldPropExpression::class, $reconstructed);
    // PHPStan bug: despite the above test assertion, which PHPStan understands,
    // it instantly forgets that that also means any method on it can be called.
    // @phpstan-ignore-next-line method.notFound
    self::assertSame($expected_reference_chain_prefixes, $expression->getReferenceChainPrefixes());
  }

  /**
   * Combines the cases of all individual data providers, assigns clear labels.
   *
   * @return array<array{0: string, 1: FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression|FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression, 2: null|string|string[], 3: string|\Exception, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function provider(): array {
    // Allow this provider to be called by a kernel test, too.
    $original_container = \Drupal::hasContainer() ? \Drupal::getContainer() : FALSE;

    $container = new ContainerBuilder();
    $prophet = new Prophet();
    $container->set('typed_data_manager', $prophet->prophesize(TypedDataManagerInterface::class)->reveal());
    \Drupal::setContainer($container);
    $generate_meaningful_case_label = function (string $prefix, array $cases) : array {
      return array_combine(
        \array_map(fn (int|string $key) => \sprintf("$prefix - %s", \is_string($key) ? $key : "#$key"), \array_keys($cases)),
        $cases,
      );
    };

    if ($original_container) {
      \Drupal::setContainer($original_container);
    }

    return $generate_meaningful_case_label('FieldPropExpression', self::providerFieldPropExpression())
      + $generate_meaningful_case_label('FieldReferencePropExpression', self::providerReferenceFieldPropExpression())
      + $generate_meaningful_case_label('FieldObjectPropsExpression', self::providerFieldObjectPropsExpression())
      + $generate_meaningful_case_label('FieldTypePropExpression', self::providerFieldTypePropExpression())
      + $generate_meaningful_case_label('ReferenceFieldTypePropExpression', self::providerReferenceFieldTypePropExpression())
      + $generate_meaningful_case_label('FieldTypeObjectPropsExpression', self::providerFieldTypeObjectPropsExpression())
      + $generate_meaningful_case_label('deprecated FieldTypeObjectPropsExpression', self::providerDeprecatedFieldTypeObjectPropsExpression());
  }

  /**
   * @return array<array{0: string, 1: FieldPropExpression, 2: null|string|string[], 3: string|\Exception, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldPropExpression(): array {
    // @phpstan-ignore return.type
    return [
      // Context: entity type, base field.
      ['ℹ︎␜entity:node␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', NULL, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title',
        [
          'module' => ['node'],
        ],
      ],
      ['ℹ︎␜entity:node␝title␞0␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 0, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title␞1st item',
        [
          'module' => ['node'],
        ],
      ],
      ['ℹ︎␜entity:node␝title␞99␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 99, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title␞100th item',
        [
          'module' => ['node'],
        ],
      ],

      // Context: bundle of entity type, base field.
      ['ℹ︎␜entity:node:article␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', NULL, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝title␞0␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', 0, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title␞1st item',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝title␞99␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', 99, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title␞100th item',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟url', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'uid', NULL, 'url'),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␟URL',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
          'content' => ['user:user:some-user-uuid'],
        ],
      ],
      'all tag URLs' => ['ℹ︎␜entity:node:article␝field_tags␞␟url', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_tags', NULL, 'url'),
        self::EXPECT_NO_DEPRECATION,
        'Tags␟URL',
        [
          'module' => [
            'node',
            // TRICKY: because `field_tags` is not a base field.
            'taxonomy',
          ],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_tags',
            'taxonomy.vocabulary.tags',
          ],
          'content' => [
            // All entities referenced — because no delta is specified.
            'taxonomy_term:tags:some-term-uuid',
            'taxonomy_term:tags:another-term-uuid',
          ],
        ],
      ],
      'second (and last) tag URL' => ['ℹ︎␜entity:node:article␝field_tags␞1␟url', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_tags', 1, 'url'),
        self::EXPECT_NO_DEPRECATION,
        'Tags␞2nd item␟URL',
        [
          'module' => [
            'node',
            // TRICKY: because `field_tags` is not a base field.
            'taxonomy',
          ],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_tags',
            'taxonomy.vocabulary.tags',
          ],
          'content' => [
            // Only the entity referenced by the specified delta.
            'taxonomy_term:tags:another-term-uuid',
          ],
        ],
      ],
      'third (and non-existent delta!) tag URL' => ['ℹ︎␜entity:node:article␝field_tags␞2␟url', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_tags', 2, 'url'),
        self::EXPECT_NO_DEPRECATION,
        'Tags␞3rd item␟URL',
        [
          'module' => [
            'node',
            // TRICKY: because `field_tags` is not a base field.
            'taxonomy',
          ],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_tags',
            'taxonomy.vocabulary.tags',
          ],
          // TRICKY: no `content` dependencies because non-existent delta.
        ],
      ],

      // Context: bundle of entity type, configurable field.
      ['ℹ︎␜entity:node:article␝field_image␞␟title', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'title'),
        self::EXPECT_NO_DEPRECATION,
        'field_image␟Title',
        [
          'module' => ['node', 'file'],
          'config' => ['node.type.article', 'field.field.node.article.field_image'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝field_image␞0␟title', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', 0, 'title'),
        self::EXPECT_NO_DEPRECATION,
        'field_image␞1st item␟Title',
        [
          'module' => ['node', 'file'],
          'config' => ['node.type.article', 'field.field.node.article.field_image'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝field_image␞99␟title', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', 99, 'title'),
        self::EXPECT_NO_DEPRECATION,
        'field_image␞100th item␟Title',
        [
          'module' => ['node', 'file'],
          'config' => ['node.type.article', 'field.field.node.article.field_image'],
        ],
      ],

      // Context: >1 bundle of entity type, base field.
      ['ℹ︎␜entity:node:article|news␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['news', 'article']), 'title', NULL, 'value'),
        static::EXPECT_DEPRECATION_3563451,
      ],

      // Context: >1 bundle of entity type, bundle/configurable field.
      // ⚠️ Note the inconsistent ordering in the object representation, and the
      // consistent ordering based on alphabetical bundle ordering in the string
      // representation.
      ['ℹ︎␜entity:node:article|news|product␝field_image|field_photo|field_product_packaging_photo␞␟target_id', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['news', 'article', 'product']), ['article' => 'field_image', 'news' => 'field_photo', 'product' => 'field_product_packaging_photo'], NULL, 'target_id'),
        static::EXPECT_DEPRECATION_3563451,
      ],

      // Context: >1 bundle of entity type, bundle/configurable field, with
      // fields of different types and hence different field properties.
      // ⚠️ Note the inconsistent ordering in the object representation, and the
      // consistent ordering based on alphabetical bundle ordering in the string
      // representation.
      ['ℹ︎␜entity:node:article|foo|xyz␝field_image|bar|abc␞␟target_id|url|␀', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['article', 'foo', 'xyz']), ['article' => 'field_image', 'foo' => 'bar', 'xyz' => 'abc'], NULL, ['field_image' => 'target_id', 'bar' => 'url', 'abc' => StructuredDataPropExpressionInterface::SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP]),
        static::EXPECT_DEPRECATION_3563451,
      ],

      // Context: >2 bundles of entity type, with a subset of the bundles using
      // the same field name: it is possible that different bundles use the same
      // field, which will require less information to be stored.
      // ⚠️ Note the inconsistent ordering in the object representation, and the
      // consistent ordering based on alphabetical bundle ordering in the string
      // representation. Also note that the same field name for two bundle
      // and thus same property name for those two fields.
      ['ℹ︎␜entity:node:article|news|product␝field_image|field_photo|field_photo␞␟alt|value|value', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['news', 'article', 'product']), ['article' => 'field_image', 'news' => 'field_photo', 'product' => 'field_photo'], NULL, ['field_image' => 'alt', 'field_photo' => 'value']),
        static::EXPECT_DEPRECATION_3563451,
      ],

      // Structured data expressions do NOT introspect the data model, they are
      // just stand-alone expressions with a string representation and a PHP
      // object representation. Hence nonsensical values are accepted for all
      // aspects:
      'invalid entity type' => ['ℹ︎␜entity:non_existent␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('non_existent'), 'title', NULL, 'value'),
        self::EXPECT_NO_DEPRECATION,
        new \LogicException('Expression expects entity type `non_existent`, actual entity type is `node`.'),
        new PluginNotFoundException('non_existent', 'The "non_existent" entity type does not exist.'),
      ],
      'invalid delta' => ['ℹ︎␜entity:node:article␝title␞-1␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', -1, 'value'),
        self::EXPECT_NO_DEPRECATION,
        'Title␞0th item',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      'invalid prop name' => ['ℹ︎␜entity:node:article␝title␞␟non_existent', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', NULL, 'non_existent'),
        self::EXPECT_NO_DEPRECATION,
        new \LogicException('Property `non_existent` does not exist on field type `string`. The following field properties exist: `value`.'),
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: ReferenceFieldPropExpression, 2: null|string|string[], 3: string|\Exception, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function providerReferenceFieldPropExpression(): array {
    $referencer_delta_null = new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'uid', NULL, 'entity');
    $referencer_delta_zero = new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'uid', 0, 'entity');
    $referencer_delta_high = new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'uid', 123, 'entity');

    return [
      // 1. References that point to a FieldPropExpression.
      // Entity reference field with a single target bundle.
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝name␞␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_null,
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', NULL, 'value')
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␜User␝Name',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        ['ℹ︎␜entity:node␝uid␞␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝name␞0␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_null,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            0,
            'value'
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␜User␝Name␞1st item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        ['ℹ︎␜entity:node␝uid␞␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝name␞99␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_null,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            99,
            'value'
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␜User␝Name␞100th item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        ['ℹ︎␜entity:node␝uid␞␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞0␟entity␜␜entity:user␝name␞␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_zero,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            NULL,
            'value'
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␞1st item␜User␝Name',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        ['ℹ︎␜entity:node␝uid␞0␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞0␟entity␜␜entity:user␝name␞0␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_zero,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            0,
            'value'
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␞1st item␜User␝Name␞1st item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        ['ℹ︎␜entity:node␝uid␞0␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞0␟entity␜␜entity:user␝name␞99␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_zero,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            99,
            'value',
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␞1st item␜User␝Name␞100th item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        ['ℹ︎␜entity:node␝uid␞0␟entity␜'],
      ],
      // Entity reference field with multiple target bundles.
      [
        'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟alt][␜entity:media:image␝field_media_image␞␟alt]',
        new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:baby_photos' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'alt'),
            'entity:media:image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'alt'),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Yo Ho␜Baby photos, Image',
        [
          'module' => [
            'node',
            'media',
            'media',
            'file',
            'media',
            'file',
          ],
          'config' => [
            ...self::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES,
            // The "baby_photos" branch.
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            // The "image" branch.
            'media.type.image',
            'field.field.media.image.field_media_image',
          ],
          'content' => [
            'media:image:some-media-uuid',
          ],
        ],
        [
          'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜',
        ],
      ],

      // 2. References that point to a reference.
      // Entity reference field with a single target bundle.
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_null,
          referenced: new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␜User␝Picture␝URI␟Root-relative file URL',
        [
          'module' => ['node', 'user', 'file', 'file'],
          'content' => ['user:user:some-user-uuid'],
          'config' => [
            'field.field.user.user.user_picture',
          ],
        ],
        [
          'ℹ︎␜entity:node␝uid␞␟entity␜',
          'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜',
        ],
      ],
      // Entity reference field with multiple target bundles.
      [
        'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value]',
        new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:baby_photos' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
            'entity:media:image' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Yo Ho␜Baby photos, Image',
        [
          'module' => [
            'node',
            'media',
            'media',
            'file',
            'file',
            'media',
            'file',
            'file',
          ],
          'config' => [
            ...self::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES,
            // The "baby_photos" branch.
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            // The "image" branch.
            'media.type.image',
            'field.field.media.image.field_media_image',
          ],
          'content' => [
            'media:image:some-media-uuid',
            'file:file:some-image-uuid',
          ],
        ],
        [
          'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜',
        ],
      ],

      // 3. References that point to a FieldObjectPropsExpression.
      // Entity reference field with a single target bundle.
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_null,
          referenced: new FieldObjectPropsExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, [
            'src' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
            ),
            'alt' => new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'alt'),
            'width' => new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'width'),
            'height' => new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'height'),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␜User␝Picture',
        [
          'module' => [
            'node', 'user', 'file', 'file',
            'user', 'file',
            'user', 'file',
            'user', 'file',
          ],
          'content' => ['user:user:some-user-uuid'],
          'config' => [
            'field.field.user.user.user_picture',
            'field.field.user.user.user_picture',
            'field.field.user.user.user_picture',
            'field.field.user.user.user_picture',
          ],
        ],
        [
          'ℹ︎␜entity:node␝uid␞␟entity␜',
        ],
      ],
      // Entity reference field with multiple target bundles.
      [
        'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}]',
        new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:baby_photos' => new FieldObjectPropsExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, [
              'src' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'src_with_alternate_widths'),
              'alt' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'alt'),
              'width' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'width'),
              'height' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'height'),
            ]),
            'entity:media:image' => new FieldObjectPropsExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, [
              'src' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'src_with_alternate_widths'),
              'alt' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'alt'),
              'width' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'width'),
              'height' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'height'),
            ]),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Yo Ho␜Baby photos, Image',
        [
          'module' => [
            'node',
            'media',
            'media',
            'file',
            'file',
            'media',
            'file',
            'media',
            'file',
            'media',
            'file',
            'media',
            'file',
            // ⚠️This dependency is absent for the content-unaware dependencies.
            'file',
            'media',
            'file',
            'media',
            'file',
            'media',
            'file',
          ],
          'config' => [
            ...self::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES,
            // The 4 props for the "baby_photos" branch.
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            'image.style.canvas_parametrized_width',
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            // The 4 props for the "image" branch.
            'media.type.image',
            'field.field.media.image.field_media_image',
            'image.style.canvas_parametrized_width',
            'media.type.image',
            'field.field.media.image.field_media_image',
            'media.type.image',
            'field.field.media.image.field_media_image',
            'media.type.image',
            'field.field.media.image.field_media_image',
          ],
          'content' => [
            'media:image:some-media-uuid',
            'file:file:some-image-uuid',
          ],
        ],
        [
          'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜',
        ],
      ],

      // 4. References that point to a reference OR a field, depending on the
      // branch. (Only possible for multi-bundle references.)
      [
        'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:remote_image␝field_media_test␞␟non_existent_computed_property][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]',
        new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:baby_photos' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
            'entity:media:image' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
            'entity:media:remote_image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property'),
            'entity:media:vacation_photos' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'vacation_photos'), 'field_media_image_2', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Yo Ho␜Baby photos, Image, Remote image, Vacation photos',
        [
          'module' => [
            'node',
            'media',
            'media',
            'file',
            'file',
            'media',
            'file',
            'file',
            'media',
            'media',
            'file',
            'file',
          ],
          'config' => [
            ...self::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES,
            // The "baby_photos" branch.
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            // The "image" branch.
            'media.type.image',
            'field.field.media.image.field_media_image',
            // The "remote_image" branch.
            'media.type.remote_image',
            'field.field.media.remote_image.field_media_test',
            // The "vacation_photos" branch.
            'media.type.vacation_photos',
            'field.field.media.vacation_photos.field_media_image_2',
          ],
          'content' => [
            'media:image:some-media-uuid',
            'file:file:some-image-uuid',
          ],
        ],
        [
          'ℹ︎␜entity:node:article␝yo_ho␞␟entity␜',
        ],
      ],

      // Intentional nonsense: labels MUST work if at all possible (invalid
      // deltas do not make this impossible), even when evaluation fails.
      ['ℹ︎␜entity:node␝uid␞123␟entity␜␜entity:user␝name␞␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_high,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            NULL,
            'value',
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␞124th item␜User␝Name',
        new \LogicException('Requested delta 123 for single-cardinality field, must be either zero or omitted.'),
        ['ℹ︎␜entity:node␝uid␞123␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞123␟entity␜␜entity:user␝name␞0␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_high,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            0,
            'value',
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␞124th item␜User␝Name␞1st item',
        new \LogicException('Requested delta 123 for single-cardinality field, must be either zero or omitted.'),
        ['ℹ︎␜entity:node␝uid␞123␟entity␜'],
      ],
      [
        'ℹ︎␜entity:node␝uid␞123␟entity␜␜entity:user␝name␞99␟value',
        new ReferenceFieldPropExpression(
          referencer: $referencer_delta_high,
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('user'),
            'name',
            99,
            'value',
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        'Authored by␞124th item␜User␝Name␞100th item',
        new \LogicException('Requested delta 123 for single-cardinality field, must be either zero or omitted.'),
        ['ℹ︎␜entity:node␝uid␞123␟entity␜'],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldObjectPropsExpression, 2: null|string|string[], 3: string|\Exception, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldObjectPropsExpression(): array {
    return [
      // Context: entity type, base field.
      [
        'ℹ︎␜entity:node␝title␞0␟{label↠value}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node'), 'title', 0, [
          // SDC prop accepting an object, with a single mapped key-value pair.
          'label' => new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 0, 'value'),
        ]),
        self::EXPECT_NO_DEPRECATION,
        'Title␞1st item',
        [
          'module' => ['node'],
        ],
      ],
      [
        'ℹ︎␜entity:node␝title␞␟{label↠value}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node'), 'title', NULL, [
          // SDC prop accepting an object, with a single mapped key-value pair.
          'label' => new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', NULL, 'value'),
        ]),
        self::EXPECT_NO_DEPRECATION,
        'Title',
        [
          'module' => ['node'],
        ],
      ],

      // Context: bundle of entity type, configurable field.
      [
        'ℹ︎␜entity:node:article␝field_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,width↠width}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (non-leaf) field property that follows an entity reference.
          'src' => new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
          // 2. one (leaf) field property
          'width' => new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'width'),
        ]),
        self::EXPECT_NO_DEPRECATION,
        'field_image',
        [
          'module' => ['node', 'file', 'file', 'node', 'file'],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_image',
            'node.type.article',
            'field.field.node.article.field_image',
          ],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],
      [
        'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,width↠width}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (leaf) field property that is computed and has its own
          // dependencies.
          'src' => new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'src_with_alternate_widths'),
          // 2. one (leaf) field property
          'width' => new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'width'),
        ]),
        self::EXPECT_NO_DEPRECATION,
        'field_image',
        [
          'module' => ['node', 'file', 'file', 'node', 'file'],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'node.type.article',
            'field.field.node.article.field_image',
          ],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],

      // Digs into multiple levels of an entity reference field to return values
      // from different levels of that reference.
      [
        'ℹ︎␜entity:node:article␝yo_ho␞␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, [
          'src' => new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
            referenced: new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
            ),
          ),
          'alt' => new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'alt'),
          ),
        ]),
        self::EXPECT_NO_DEPRECATION,
        'Yo Ho',
        [
          'module' => [
            'node',
            'media',
            'media',
            'file',
            'file',
            'node',
            'media',
            'media',
            'file',
          ],
          'config' => [
            // The "src" object prop.
            ...self::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES,
            'media.type.image',
            'field.field.media.image.field_media_image',
            // The "alt" object prop.
            ...self::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES,
            'media.type.image',
            'field.field.media.image.field_media_image',
          ],
          'content' => [
            'media:image:some-media-uuid',
            'file:file:some-image-uuid',
            'media:image:some-media-uuid',
          ],
        ],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldTypePropExpression, 2: null|string|string[], 3: \Error, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldTypePropExpression(): array {
    // Labeler does not allow FieldTypePropExpression and will throw
    // TypeError. This is expected and intentional.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Labeler::label()
    $type_error_message = \sprintf(self::LABELER_TYPE_ERROR_MESSAGE_FORMAT, FieldTypePropExpression::class);
    return [
      // Field type with single property.
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
      ['ℹ︎string␟value', new FieldTypePropExpression('string', 'value'),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [],
      ],

      // Field type with >1 properties.
      // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
      ['ℹ︎image␟width', new FieldTypePropExpression('image', 'width'),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'module' => ['image'],
        ],
      ],
      ['ℹ︎image␟src', new FieldTypePropExpression('image', 'src'),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'module' => ['image'],
        ],
      ],
      ['ℹ︎image␟src_with_alternate_widths', new FieldTypePropExpression('image', 'src_with_alternate_widths'),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'module' => [
            'image',
            'image',
            'file',
            'image',
          ],
          'content' => [
            'file:file:some-image-uuid',
          ],
          'config' => [
            'image.style.canvas_parametrized_width',
          ],
        ],
      ],

      // Structured data expressions do NOT introspect the data model, they are
      // just stand-alone expressions with a string representation and a PHP
      // object representation. Hence nonsensical values are accepted:
      'invalid prop name' => ['ℹ︎string␟non_existent', new FieldTypePropExpression('string', 'non_existent'),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: ReferenceFieldTypePropExpression, 2: null|string|string[], 3: \Error, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function providerReferenceFieldTypePropExpression(): array {
    // Labeler does not allow ReferenceFieldTypePropExpression and will throw
    // TypeError. This is expected and intentional.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Labeler::label()
    $type_error_message = \sprintf(self::LABELER_TYPE_ERROR_MESSAGE_FORMAT, ReferenceFieldTypePropExpression::class);
    // @phpstan-ignore return.type
    return [
      // Reference field type for a single property.
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
      [
        'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
        new ReferenceFieldTypePropExpression(
          referencer: new FieldTypePropExpression('image', 'entity',),
          referenced: new FieldPropExpression(
            BetterEntityDataDefinition::create('file'),
            'uri',
            0,
            'value',
          )
        ),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'module' => ['image', 'file'],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],

      // Field type with >1 properties.
      // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
      [
        'ℹ︎image␟entity␜␜entity:file␝uri␞0␟{stream_wrapper_uri↠value,public_url↠url}',
        new ReferenceFieldTypePropExpression(
          referencer: new FieldTypePropExpression('image', 'entity'),
          referenced: new FieldObjectPropsExpression(
            BetterEntityDataDefinition::create('file'),
            'uri',
            0,
            [
              'stream_wrapper_uri' => new FieldPropExpression(
                BetterEntityDataDefinition::create('file'),
                'uri',
                0,
                'value'
              ),
              'public_url' => new FieldPropExpression(
                BetterEntityDataDefinition::create('file'),
                'uri',
                0,
                'url'
              ),
            ]
          ),
        ),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'module' => ['image', 'file', 'file'],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],

      // Reference field type that fetches a reference of a reference.
      // ℹ️ This test case requires quite some simulating in the sibling kernel
      // test that tests the expected dependencies. To ensure it is accurate,
      // this particular test case also has a functional test.
      // @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testCalculateDependencies()
      // @see \Drupal\Tests\canvas\Functional\PropExpressionDependenciesTest::testIntermediateDependencies()
      [
        'ℹ︎entity_reference␟entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image_1|field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value',
        new ReferenceFieldTypePropExpression(
          referencer: new FieldTypePropExpression('entity_reference', 'entity'),
          referenced: new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', ['baby_photos', 'vacation_photos']), ['baby_photos' => 'field_media_image_1', 'vacation_photos' => 'field_media_image_2'], NULL, 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
          ),
        ),
        [self::EXPECT_DEPRECATION_3563451, self::EXPECT_DEPRECATION_3563451_REFERENCE],
      ],

      // Test an expression for a reference field that has 3 branches based on
      // the referenced entity type and bundle:
      // 1. in two branches, it fetches a reference of a reference (local image)
      // 2. in the other branch, it fetches a computed property (remote image)
      // ⚠️🚨 Drupal core's oEmbed MediaSource plugin does not play nice with
      // Typed Data! It uses the `string` field type to store a string with
      // no additional semantic information, whereas it's really storing the oEmbed URL
      // entered by the content author. It then uses a field formatter to
      // fetch the actual image URL from oEmbed provider. That's why the "other"
      // branch uses the `non_existent_computed_property` property in its
      // expression, to simulate a computed property that does nota actually
      // exist, but will be necessary to be able to use oEmbed-powered media in
      // Drupal Canvas.
      // For example, this is entered (and stored!):
      // @code
      // https://giphy.com/gifs/service-department-customer-T8Dhl1KPyzRqU
      // @endcode
      // And the field formatter fetches the oEmbed information to determine the
      // image URL to load is:
      // @code
      // https://media3.giphy.com/media/T8Dhl1KPyzRqU/giphy.gif
      // @endcode
      // @see https://www.drupal.org/project/media_remote_image
      // @todo Update this to use the relevant computed property instead of "non_existent_computed_property" after Canvas depends on a Drupal core version that includes https://www.drupal.org/project/drupal/issues/3567249
      [
        'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:remote_image␝field_media_test␞␟non_existent_computed_property][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]',
        new ReferenceFieldTypePropExpression(
          referencer: new FieldTypePropExpression('entity_reference', 'entity'),
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:baby_photos' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
            'entity:media:remote_image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property'),
            'entity:media:vacation_photos' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'vacation_photos'), 'field_media_image_2', \NULL, 'entity'),
              referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'content' => [
            'media:baby_photos:baby-photos-media-uuid',
            'file:file:photo-baby-jack-uuid',
          ],
          'module' => [
            'media',
            'file',
            'file',
            'media',
            'media',
            'file',
            'file',
          ],
          'config' => [
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            'media.type.remote_image',
            'field.field.media.remote_image.field_media_test',
            'media.type.vacation_photos',
            'field.field.media.vacation_photos.field_media_image_2',
          ],
        ],
      ],

      // Reference field type that branches based on the referenced entity type+
      // bundle:
      // 1. in two branches, it fetches a reference of a reference (local image)
      // 2. in another branch, it fetches a computed property (remote image)
      // … and in all branches, it populates an object shape with one required
      // key ("src").
      [
        'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟{src↝entity␜␜entity:file␝uri␞␟value}][␜entity:media:remote_image␝field_media_test␞␟{src↠non_existent_computed_property}][␜entity:media:vacation_photos␝field_media_image_2␞␟{src↝entity␜␜entity:file␝uri␞␟value}]',
        new ReferenceFieldTypePropExpression(
          referencer: new FieldTypePropExpression('entity_reference', 'entity'),
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:baby_photos' => new FieldObjectPropsExpression(
              BetterEntityDataDefinition::create('media', 'baby_photos'),
              'field_media_image_1',
              \NULL,
              [
                'src' => new ReferenceFieldPropExpression(
                  referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'entity'),
                  referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
                ),
              ],
            ),
            'entity:media:remote_image' => new FieldObjectPropsExpression(
              BetterEntityDataDefinition::create('media', ['remote_image']),
              'field_media_test',
              \NULL,
              ['src' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property')]
            ),
            'entity:media:vacation_photos' => new FieldObjectPropsExpression(
              BetterEntityDataDefinition::create('media', 'vacation_photos'),
              'field_media_image_2',
              \NULL,
              [
                'src' => new ReferenceFieldPropExpression(
                  referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'vacation_photos'), 'field_media_image_2', NULL, 'entity'),
                  referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
                ),
              ],
            ),
          ]),
        ),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'content' => [
            'media:baby_photos:baby-photos-media-uuid',
            'file:file:photo-baby-jack-uuid',
          ],
          'module' => [
            'media',
            'file',
            'file',
            'media',
            'media',
            'file',
            'file',
          ],
          'config' => [
            'media.type.baby_photos',
            'field.field.media.baby_photos.field_media_image_1',
            'media.type.remote_image',
            'field.field.media.remote_image.field_media_test',
            'media.type.vacation_photos',
            'field.field.media.vacation_photos.field_media_image_2',
          ],
        ],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldTypeObjectPropsExpression, 2: null|string|string[], 3: \Error, 4: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldTypeObjectPropsExpression(): array {
    // Labeler does not allow FieldTypeObjectPropsExpression and will throw
    // TypeError. This is expected and intentional.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Labeler::label()
    $type_error_message = \sprintf(self::LABELER_TYPE_ERROR_MESSAGE_FORMAT, FieldTypeObjectPropsExpression::class);
    return [
      // Context: entity type, base field.
      [
        'ℹ︎string␟{label↠value}',
        new FieldTypeObjectPropsExpression('string', [
          // SDC prop accepting an object, with a single mapped key-value pair.
          'label' => new FieldTypePropExpression('string', 'value'),
        ]),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [],
      ],

      // Context: bundle of entity type, configurable field.
      [
        'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,width↠width}',
        new FieldTypeObjectPropsExpression('image', [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (non-leaf) field property that follows an entity reference.
          'src' => new ReferenceFieldTypePropExpression(
            referencer: new FieldTypePropExpression('image', 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
          // 2. one (leaf) field property
          'width' => new FieldTypePropExpression('image', 'width'),
        ]),
        self::EXPECT_NO_DEPRECATION,
        new \TypeError($type_error_message),
        [
          'module' => ['image', 'file', 'image'],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldTypeObjectPropsExpression, 2: null|string|string[]}>
   */
  public static function providerDeprecatedFieldTypeObjectPropsExpression(): array {
    return [
      // Context: >1 bundle of referenced entity type. Now deprecated.
      // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
      // @see https://www.drupal.org/node/3563451
      '[LEGACY, deprecated since 1.1.0] field type to object expression, with object props using deprecated multi-bundle reference expressions' => [
        'ℹ︎entity_reference␟{src↝entity␜␜entity:media:baby_photos|image|remote_image␝field_media_image_1|field_media_image|field_media_test␞␟src_with_alternate_widths|src_with_alternate_widths|value,alt↝entity␜␜entity:media:baby_photos|image|remote_image␝field_media_image_1|field_media_image|field_media_test␞␟alt|alt|␀,width↝entity␜␜entity:media:baby_photos|image|remote_image␝field_media_image_1|field_media_image|field_media_test␞␟width|width|␀,height↝entity␜␜entity:media:baby_photos|image|remote_image␝field_media_image_1|field_media_image|field_media_test␞␟height|height|␀}',
        new FieldTypeObjectPropsExpression('entity_reference', [
          'src' => new ReferenceFieldTypePropExpression(
            referencer: new FieldTypePropExpression('entity_reference', 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', ['baby_photos', 'image', 'remote_image']), ['baby_photos' => 'field_media_image_1', 'image' => 'field_media_image', 'remote_image' => 'field_media_test'], NULL, ['field_media_image_1' => 'src_with_alternate_widths', 'field_media_image' => 'src_with_alternate_widths', 'field_media_test' => 'value']),
          ),
          'alt' => new ReferenceFieldTypePropExpression(
            referencer: new FieldTypePropExpression('entity_reference', 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', ['baby_photos', 'image', 'remote_image']), ['baby_photos' => 'field_media_image_1', 'image' => 'field_media_image', 'remote_image' => 'field_media_test'], NULL, ['field_media_image_1' => 'alt', 'field_media_image' => 'alt', 'field_media_test' => '␀']),
          ),
          'width' => new ReferenceFieldTypePropExpression(
            referencer: new FieldTypePropExpression('entity_reference', 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', ['baby_photos', 'image', 'remote_image']), ['baby_photos' => 'field_media_image_1', 'image' => 'field_media_image', 'remote_image' => 'field_media_test'], NULL, ['field_media_image_1' => 'width', 'field_media_image' => 'width', 'field_media_test' => '␀']),
          ),
          'height' => new ReferenceFieldTypePropExpression(
            referencer: new FieldTypePropExpression('entity_reference', 'entity'),
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', ['baby_photos', 'image', 'remote_image']), ['baby_photos' => 'field_media_image_1', 'image' => 'field_media_image', 'remote_image' => 'field_media_test'], NULL, ['field_media_image_1' => 'height', 'field_media_image' => 'height', 'field_media_test' => '␀']),
          ),
        ]),
        [self::EXPECT_DEPRECATION_3563451, self::EXPECT_DEPRECATION_3563451_OBJECT],
      ],
    ];
  }

  /**
   * Tests invalid field prop expression due to multiple field names without multiple bundles.
   *
   * @testWith [null]
   *           ["article"]
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct
   */
  public function testInvalidFieldPropExpressionDueToMultipleFieldNamesWithoutMultipleBundles(?string $bundle): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('When targeting a (single bundle of) an entity type, only a single field name can be specified.');
    new FieldPropExpression(
      BetterEntityDataDefinition::create('node', $bundle),
      [
        'bundle_a' => 'field_image',
        'bundle_b' => 'field_image_1',
      ],
      0,
      'alt',
    );
  }

  /**
   * Tests invalid field prop expression due to multiple field prop names without multiple bundles.
   *
   * @testWith [null]
   *           ["article"]
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct
   */
  public function testInvalidFieldPropExpressionDueToMultipleFieldPropNamesWithoutMultipleBundles(?string $bundle): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('When targeting a (single bundle of) an entity type, only a single field property name can be specified.');
    new FieldPropExpression(
      BetterEntityDataDefinition::create('node', $bundle),
      'field_image',
      0,
      [
        'field_image' => 'alt',
        'field_media' => 'description',
      ],
    );
  }

  /**
   * Tests invalid field prop expression due to multiple field prop names without multiple field names.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct
   */
  #[IgnoreDeprecations]
  public function testInvalidFieldPropExpressionDueToMultipleFieldPropNamesWithoutMultipleFieldNames(): void {
    $this->expectDeprecation(self::EXPECT_DEPRECATION_3563451);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('A field property name must be specified for every field name, and in the same order.');
    new FieldPropExpression(
      BetterEntityDataDefinition::create('node', ['bundle_a', 'bundle_b', 'bundle_c']),
      [
        'bundle_a' => 'field_image',
        'bundle_b' => 'field_media_1',
        'bundle_c' => 'field_media',
      ],
      0,
      [
        'field_image' => 'alt',
        'field_media' => 'description',
      ],
    );
  }

  /**
   * Tests invalid field prop expression due to only null field prop names.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct
   */
  #[IgnoreDeprecations]
  public function testInvalidFieldPropExpressionDueToOnlyNullFieldPropNames(): void {
    $this->expectDeprecation(self::EXPECT_DEPRECATION_3563451);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('At least one of the field names must have a field property specified; otherwise it should be omitted (␀ can only be used when a subset of the bundles does not provide a certain value).');
    new FieldPropExpression(
      BetterEntityDataDefinition::create('node', ['bundle_a', 'bundle_b']),
      [
        'bundle_a' => 'field_image',
        'bundle_b' => 'field_media_1',
      ],
      0,
      [
        'field_image' => '␀',
        'field_media_1' => '␀',
      ],
    );
  }

  /**
   * Tests invalid field prop expression due to duplicate bundles.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct
   */
  #[IgnoreDeprecations]
  public function testInvalidFieldPropExpressionDueToDuplicateBundles(): void {
    $this->expectDeprecation(self::EXPECT_DEPRECATION_3563451);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Duplicate bundles are nonsensical.');
    new FieldPropExpression(
      BetterEntityDataDefinition::create('node', ['foo', 'bar', 'foo']),
      [],
      0,
      'alt',
    );
  }

  /**
   * Tests invalid field prop expression due to field name mismatch.
   *
   * @testWith [{"foo": "field_media_image", "bar": "field_media_image_1", "baz": "field_media_image_2"}]
   *           [{"foo": "field_media_image", "baz": "field_media_image_2"}]
   *           [{}]
   *           [{"foo": "field_media_image", "bar": "field_media_image_1"}]
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct
   */
  #[IgnoreDeprecations]
  public function testInvalidFieldPropExpressionDueToFieldNameMismatch(array $field_name): void {
    $this->expectDeprecation(self::EXPECT_DEPRECATION_3563451);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('A field name must be specified for every bundle, and in the same order.');
    new FieldPropExpression(
      BetterEntityDataDefinition::create('node', ['foo', 'bar']),
      $field_name,
      0,
      'alt',
    );
  }

  /**
   * Tests invalid field object props expression due to prop name.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct
   */
  public function testInvalidFieldObjectPropsExpressionDueToPropName(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:node␝title␞0␟value` is not a valid expression, because it does not map the same field item (entity type `entity:node`, field name `field_image`, delta `0`).');
    new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node'), 'field_image', 0, [
      'label' => new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 0, 'value'),
    ]);
  }

  /**
   * Tests invalid field object props expression due to delta.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct
   */
  public function testInvalidFieldObjectPropsExpressionDueToDelta(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:node␝title␞␟value` is not a valid expression, because it does not map the same field item (entity type `entity:node`, field name `title`, delta `0`).');
    new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node'), 'title', 0, [
      'label' => new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', NULL, 'value'),
    ]);
  }

  /**
   * Tests invalid field object props expression inside reference field type expression.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct
   */
  public function testInvalidFieldObjectPropsExpressionInsideReferenceFieldTypeExpression(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:file␝bytes␞0␟value` is not a valid expression, because it does not map the same field item (entity type `entity:file`, field name `uri`, delta `0`).');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('image', 'entity'),
      referenced: new FieldObjectPropsExpression(
        BetterEntityDataDefinition::create('file'),
        'uri',
        0,
        [
          'src' => new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', 0, 'value'),
          'bytes' => new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'bytes', 0, 'value'),
        ]
      )
    );
  }

  /**
   * Tests invalid reference field type prop expression due to single branch.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testInvalidReferenceFieldTypePropExpressionDueToSingleBranch(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Inappropriate use of reference bundle-specific branches: only a single branch specified.');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      // @phpstan-ignore argument.type
      referenced: new ReferencedBundleSpecificBranches([
        new FieldTypePropExpression('image', 'src_with_alternate_widths'),
      ]),
    );
  }

  /**
   * Tests invalid reference field type prop expression due to bundleless field prop expression.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testInvalidReferenceFieldTypePropExpressionDueToBundlelessFieldPropExpression(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:file␝uri␞␟value` is not a bundle-specific reference expression.');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      // @phpstan-ignore argument.type
      referenced: new ReferencedBundleSpecificBranches([
        'entity:file' => new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        new FieldTypePropExpression('image', 'src_with_alternate_widths'),
      ]),
    );
  }

  /**
   * Tests invalid reference field type prop expression due to unsupported branch expression.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testInvalidReferenceFieldTypePropExpressionDueToUnsupportedBranchExpression(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎image␟src_with_alternate_widths` is not a supported branch expression: an entity field-based prop expression must be given.');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      // @phpstan-ignore argument.type
      referenced: new ReferencedBundleSpecificBranches([
        'entity:media:baby_photos' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'media_image', NULL, 'src_with_alternate_widths'),
        new FieldTypePropExpression('image', 'src_with_alternate_widths'),
      ]),
    );
  }

  /**
   * Tests invalid reference field type prop expression due to incorrect order.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testInvalidReferenceFieldTypePropExpressionDueToIncorrectOrder(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Bundle-specific expressions are not in alphabetical order (by their keys).');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      referenced: new ReferencedBundleSpecificBranches([
        'entity:media:remote_image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property'),
        'entity:media:baby_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', \NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
        'entity:media:vacation_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'vacation_photos'), 'field_media_image_2', \NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
      ]),
    );
  }

  /**
   * Tests invalid reference field type prop expression due to mismatched leaf expression classes.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testInvalidReferenceFieldTypePropExpressionDueToMismatchedLeafExpressionClasses(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Bundle-specific expressions have inconsistent leaf expressions: they must all populate the same shape, and hence must use the same expression class for the leaf expression.');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      referenced: new ReferencedBundleSpecificBranches([
        // Returns a FieldPropExpression, unlike the other branch.
        'entity:media:baby_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', \NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
        // Returns a FieldObjectPropExpression, unlike the other branch.
        'entity:media:remote_image' => new FieldObjectPropsExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, [
          'key-in-object' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property'),
        ]),
      ]),
    );
  }

  /**
   * @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testInvalidReferencePropExpressionDueToMismatchedLeafExpressionCardinality()
   */
  public function testInvalidReferencePropExpressionDueToMismatchedLeafExpressionCardinality(): void {
    // @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testInvalidReferenceFieldTypePropExpressionDueToMismatchedLeafExpressionCardinality
    $this->markTestSkipped('This cannot be tested in a unit test, because it relies on certain bundles (config entities) and fields (also config entities) to exist.');
  }

  /**
   * Tests invalid reference field type prop expression due to inconsistent leaf expression deltas.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  #[TestWith([NULL, NULL, NULL])]
  #[TestWith([0, 0, NULL])]
  #[TestWith([0, 5, NULL])]
  #[TestWith([0, NULL, new \InvalidArgumentException('Bundle-specific expressions have inconsistent leaf expressions: either all or none must specify a field delta.')])]
  #[TestWith([NULL, 4, new \InvalidArgumentException('Bundle-specific expressions have inconsistent leaf expressions: either all or none must specify a field delta.')])]
  public function testInvalidReferenceFieldTypePropExpressionDueToInconsistentLeafExpressionDeltas(int|null $first_expression_branch_leaf_delta, int|null $second_expression_branch_leaf_delta, \Exception|null $expected_exception): void {
    if ($expected_exception) {
      $this->expectException($expected_exception::class);
      $this->expectExceptionMessage($expected_exception->getMessage());
    }

    $expr = new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      referenced: new ReferencedBundleSpecificBranches([
        'entity:media:remote_image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', $first_expression_branch_leaf_delta, 'non_existent_computed_property'),
        'entity:media:vacation_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'vacation_photos'), 'field_media_image_2', \NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', $second_expression_branch_leaf_delta, 'value'),
        ),
      ]),
    );

    if ($expected_exception === NULL) {
      self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $expr->referenced);
    }
  }

  /**
   * Tests closing unopened branch exception.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testClosingUnopenedBranchException(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Closing unopened branch.');
    ReferenceFieldTypePropExpression::fromString('ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value]][␜entity:media:remote_image␝field_media_test␞␟non_existent_computed_property]');
  }

  /**
   * Tests unclosed branch exception.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testUnclosedBranchException(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Unclosed branch');
    ReferenceFieldTypePropExpression::fromString('ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value[␜entity:media:remote_image␝field_media_test␞␟non_existent_computed_property]');
  }

  /**
   * Tests nested branch exception.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct
   */
  public function testNestedBranchException(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Nested branching is not supported.');
    ReferenceFieldTypePropExpression::fromString('ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞[␟entity␜␜entity:file␝uri␞␟value][␟entity␜␜entity:file␝test␞␟value]][␜entity:media:remote_image␝field_media_test␞␟non_existent_computed_property]');
  }

  /**
   * Tests add and remove bundle specific expressions.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::hasBranch
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::withAdditionalBranch
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::withoutBranch
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::generateBundleSpecificBranches
   */
  public function testAddAndRemoveBundleSpecificExpressions(): void {
    $alphabetically_first = new FieldPropExpression(BetterEntityDataDefinition::create('media', ['alphabetically_first']), 'whatever', NULL, 'something');

    $v1 = new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      referenced: new ReferencedBundleSpecificBranches([
        'entity:media:baby_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', \NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
        'entity:media:vacation_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'vacation_photos'), 'field_media_image_2', \NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
      ]),
    );

    self::assertNotInstanceOf(ReferenceFieldPropExpression::class, $v1->referenced);
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v1->referenced);
    self::assertCount(2, $v1->referenced->bundleSpecificReferencedExpressions);
    self::assertFalse($v1->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v1->hasBranch('entity:media:baby_photos'));
    self::assertFalse($v1->hasBranch('entity:media:remote_image'));
    self::assertTrue($v1->hasBranch('entity:media:vacation_photos'));

    // Simulate result of a `hook_canvas_storable_prop_shape_alter()` calling
    // `::withAdditionalBranch()`.
    $v2 = $v1->withAdditionalBranch(
      new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property'),
    );
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v2->referenced);
    self::assertCount(3, $v2->referenced->bundleSpecificReferencedExpressions);
    self::assertFalse($v2->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v2->hasBranch('entity:media:baby_photos'));
    self::assertTrue($v2->hasBranch('entity:media:remote_image'));
    self::assertTrue($v2->hasBranch('entity:media:vacation_photos'));

    // Prove that it is indeed automatically handling the ordering requirements
    // for the caller.
    $v3 = $v2->withAdditionalBranch($alphabetically_first);
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v3->referenced);
    self::assertCount(4, $v3->referenced->bundleSpecificReferencedExpressions);
    self::assertTrue($v3->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v3->hasBranch('entity:media:baby_photos'));
    self::assertTrue($v3->hasBranch('entity:media:remote_image'));
    self::assertTrue($v3->hasBranch('entity:media:vacation_photos'));
    $v2alt = $v1->withAdditionalBranch($alphabetically_first);
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v2alt->referenced);
    self::assertCount(3, $v2alt->referenced->bundleSpecificReferencedExpressions);
    self::assertTrue($v2alt->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v2alt->hasBranch('entity:media:baby_photos'));
    self::assertFalse($v2alt->hasBranch('entity:media:remote_image'));
    self::assertTrue($v2alt->hasBranch('entity:media:vacation_photos'));

    // Simulate a `hook_canvas_storable_prop_shape_alter()` calling
    // `::withoutBranch()`.
    $v4 = $v3->withoutBranch($alphabetically_first->getHostEntityDataDefinition()->getDataType());
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v4->referenced);
    self::assertCount(3, $v4->referenced->bundleSpecificReferencedExpressions);
    self::assertSame((string) $v2, (string) $v4);
    self::assertFalse($v4->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v4->hasBranch('entity:media:baby_photos'));
    self::assertTrue($v4->hasBranch('entity:media:remote_image'));
    self::assertTrue($v4->hasBranch('entity:media:vacation_photos'));
    $v3alt = $v2alt->withoutBranch($alphabetically_first->getHostEntityDataDefinition()->getDataType());
    self::assertNotInstanceOf(ReferenceFieldPropExpression::class, $v3alt->referenced);
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v3alt->referenced);
    self::assertCount(2, $v3alt->referenced->bundleSpecificReferencedExpressions);

    // Simulate a `hook_canvas_storable_prop_shape_alter()` calling
    // `::withoutBranch()`.
    $v5 = $v3->withoutBranch('entity:media:alphabetically_first');
    self::assertInstanceOf(ReferencedBundleSpecificBranches::class, $v5->referenced);
    self::assertSame((string) $v2, (string) $v5);
    self::assertCount(3, $v5->referenced->bundleSpecificReferencedExpressions);
    self::assertFalse($v5->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v5->hasBranch('entity:media:baby_photos'));
    self::assertTrue($v5->hasBranch('entity:media:remote_image'));
    self::assertTrue($v5->hasBranch('entity:media:vacation_photos'));

    // Removing all branches except for the last automatically causes the last
    // remaining branch to be simplified back to a ReferenceFieldPropExpression.
    $v4alt = $v3alt->withoutBranch('entity:media:vacation_photos');
    self::assertInstanceOf(ReferenceFieldPropExpression::class, $v4alt->referenced);
    // @phpstan-ignore staticMethod.alreadyNarrowedType
    self::assertNotInstanceOf(ReferencedBundleSpecificBranches::class, $v4alt->referenced);
    self::assertFalse($v4alt->hasBranch('entity:media:alphabetically_first',));
    self::assertTrue($v4alt->hasBranch('entity:media:baby_photos'));
    self::assertFalse($v4alt->hasBranch('entity:media:remote_image'));
    self::assertFalse($v4alt->hasBranch('entity:media:vacation_photos'));
  }

  /**
   * Tests update path for 3563451.
   *
   * @param string $original
   *   The original expression string.
   * @param string|null $updated
   *   The expected updated expression string, or NULL if no update is needed.
   *
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @see https://www.drupal.org/node/3563451
   * @see \Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface::SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::generateBundleSpecificBranches
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::liftReferenceAndCreateBranchesIfNeeded
   */
  #[TestWith([
    'ℹ︎entity_reference␟entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image_1|field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value',
    'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]',
  ], 'reference to a multi-bundle scalar')]
  #[TestWith([
    'ℹ︎entity_reference␟{src↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟alt,width↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟width,height↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟height}',
    'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:vacation_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}]',
  ], 'object with each key-value pair the same multi-bundle reference without using ␀: needs lifting + branching')]
  #[TestWith([
    // @see \Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface::SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP
    'ℹ︎entity_reference␟{src↝entity␜␜entity:media:baby_photos|file|image␝field_media_image_1|field_media_file|field_media_image␞␟src_with_alternate_widths|src_with_alternate_widths|value,alt↝entity␜␜entity:media:baby_photos|file|image␝field_media_image_1|field_media_file|field_media_image␞␟alt|␀|alt,width↝entity␜␜entity:media:baby_photos|file|image␝field_media_image_1|field_media_file|field_media_image␞␟width|␀|width,height↝entity␜␜entity:media:baby_photos|file|image␝field_media_image_1|field_media_file|field_media_image␞␟height|␀|height}',
    'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:file␝field_media_file␞␟{src↠src_with_alternate_widths}][␜entity:media:image␝field_media_image␞␟{src↠value,alt↠alt,width↠width,height↠height}]',
  ], 'object with each key-value pair the same multi-bundle reference AND using ␀ for one branch: needs lifting + branching')]
  #[TestWith([
    'ℹ︎entity_reference␟entity␜␜entity:media:baby_photos|file|image␝field_media_image_1|field_media_file|field_media_image␞␟entity␜␜entity:file␝uri␞␟value',
    'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:file␝field_media_file␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value]',
  ], 'reference to a multi-bundle reference that fetches a scalar')]
  #[TestWith([
    'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
    'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
  ], 'object with each key-value pair the same single-bundle reference: needs lifting')]
  #[TestWith([
    'ℹ︎entity_reference␟{src↝entity␜␜entity:media:video␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url}',
    'ℹ︎entity_reference␟entity␜␜entity:media:video␝field_media_video_file␞␟{src↝entity␜␜entity:file␝uri␞␟url}',
  ], 'object with each key-value pair the same single-bundle reference: needs lifting because plain entity_reference field')]
  #[TestWith([
    'ℹ︎file␟{src↝entity␜␜entity:file␝uri␞␟url}',
    NULL,
  ], 'object with each key-value pair the same single-bundle reference: does NOT need lifting because not plain entity_reference field')]
  #[IgnoreDeprecations]
  public function testUpdatePathFor3563451(string $original, string|null $updated): void {
    $original_expr = StructuredDataPropExpression::fromString($original);

    self::assertSame($updated !== NULL, match ($original_expr::class) {
      ReferenceFieldTypePropExpression::class => $original_expr->needsMultiBundleReferencePropExpressionUpdate(),
      FieldTypeObjectPropsExpression::class => $original_expr->needsMultiBundleReferencePropExpressionUpdate() || $original_expr->needsLiftedReferencePropExpressionUpdate(),
      default => throw new \OutOfRangeException(),
    });

    if ($updated === NULL) {
      self::expectException(\LogicException::class);
    }

    $updated_expr = match ($original_expr::class) {
      ReferenceFieldTypePropExpression::class => $original_expr->generateBundleSpecificBranches(),
      FieldTypeObjectPropsExpression::class => $original_expr->liftReferenceAndCreateBranchesIfNeeded(),
    };

    self::assertSame($updated, (string) $updated_expr);
  }

}
