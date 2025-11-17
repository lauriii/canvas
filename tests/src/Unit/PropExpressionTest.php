<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

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
use Prophecy\Prophet;

/**
 * @coversDefaultClass \Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression
 * @coversClass \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression
 * @coversClass \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression
 * @coversClass \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression
 * @coversClass \Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression
 * @coversClass \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression
 * @coversClass \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression
 * @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testLabel()
 * @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testCalculateDependencies()
 * @group canvas
 *
 * @phpstan-import-type ConfigDependenciesArray from \Drupal\canvas\Entity\VersionedConfigEntityInterface
 */
class PropExpressionTest extends UnitTestCase {

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
   * @dataProvider provider
   */
  public function testToString(string $string_representation, StructuredDataPropExpressionInterface $expression): void {
    $this->assertSame($string_representation, (string) $expression);
  }

  /**
   * @dataProvider provider
   */
  public function testFromString(string $string_representation, StructuredDataPropExpressionInterface $expression): void {
    $reconstructed = call_user_func([get_class($expression), 'fromString'], $string_representation);
    $this->assertEquals($expression, $reconstructed);
    $this->assertEquals($expression, StructuredDataPropExpression::fromString($string_representation));
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression::getReferenceChainPrefixes()
   * @dataProvider providerReferenceFieldPropExpression
   */
  public function testGetReferenceChainPrefixes(string $string_representation, StructuredDataPropExpressionInterface $expression, string|\Exception $irrelevant_here_one, array|\Exception $irrelevant_here_two, null|array $irrelevant_here_three, array $expected_reference_chain_prefixes): void {
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
   * @return array<array{0: string, 1: FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression|FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression, 2: string|\Exception, 3: ConfigDependenciesArray|\Exception}>
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
        array_map(fn (int|string $key) => sprintf("$prefix - %s", is_string($key) ? $key : "#$key"), array_keys($cases)),
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
      + $generate_meaningful_case_label('FieldTypeObjectPropsExpression', self::providerFieldTypeObjectPropsExpression());
  }

  /**
   * @return array<array{0: string, 1: FieldPropExpression, 2: string|\Exception, 3: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldPropExpression(): array {
    return [
      // Context: entity type, base field.
      ['ℹ︎␜entity:node␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', NULL, 'value'),
        'Title',
        [
          'module' => ['node'],
        ],
      ],
      ['ℹ︎␜entity:node␝title␞0␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 0, 'value'),
        'Title␞1st item',
        [
          'module' => ['node'],
        ],
      ],
      ['ℹ︎␜entity:node␝title␞99␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 99, 'value'),
        'Title␞100th item',
        [
          'module' => ['node'],
        ],
      ],

      // Context: bundle of entity type, base field.
      ['ℹ︎␜entity:node:article␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', NULL, 'value'),
        'Title',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝title␞0␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', 0, 'value'),
        'Title␞1st item',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝title␞99␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', 99, 'value'),
        'Title␞100th item',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟url', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'uid', NULL, 'url'),
        'Authored by␟URL',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
          'content' => ['user:user:some-user-uuid'],
        ],
      ],
      'all tag URLs' => ['ℹ︎␜entity:node:article␝field_tags␞␟url', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_tags', NULL, 'url'),
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
        'field_image␟Title',
        [
          'module' => ['node', 'file'],
          'config' => ['node.type.article', 'field.field.node.article.field_image', 'image.style.canvas_parametrized_width'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝field_image␞0␟title', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', 0, 'title'),
        'field_image␞1st item␟Title',
        [
          'module' => ['node', 'file'],
          'config' => ['node.type.article', 'field.field.node.article.field_image', 'image.style.canvas_parametrized_width'],
        ],
      ],
      ['ℹ︎␜entity:node:article␝field_image␞99␟title', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', 99, 'title'),
        'field_image␞100th item␟Title',
        [
          'module' => ['node', 'file'],
          'config' => ['node.type.article', 'field.field.node.article.field_image', 'image.style.canvas_parametrized_width'],
        ],
      ],

      // Context: >1 bundle of entity type, base field.
      ['ℹ︎␜entity:node:article|news␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['news', 'article']), 'title', NULL, 'value'),
        'Title',
        [
          'module' => ['node'],
          'config' => ['node.type.article', 'node.type.news'],
        ],
      ],

      // Context: >1 bundle of entity type, bundle/configurable field.
      // ⚠️ Note the inconsistent ordering in the object representation, and the
      // consistent ordering based on alphabetical bundle ordering in the string
      // representation.
      ['ℹ︎␜entity:node:article|news|product␝field_image|field_photo|field_product_packaging_photo␞␟target_id', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['news', 'article', 'product']), ['article' => 'field_image', 'news' => 'field_photo', 'product' => 'field_product_packaging_photo'], NULL, 'target_id'),
        'field_image',
        [
          'module' => ['node', 'file', 'file', 'file'],
          'config' => [
            'node.type.article',
            'node.type.news',
            'node.type.product',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'field.field.node.news.field_photo',
            'image.style.canvas_parametrized_width',
            'field.field.node.product.field_product_packaging_photo',
            'image.style.canvas_parametrized_width',
          ],
        ],
      ],

      // Context: >1 bundle of entity type, bundle/configurable field, with
      // fields of different types and hence different field properties.
      // ⚠️ Note the inconsistent ordering in the object representation, and the
      // consistent ordering based on alphabetical bundle ordering in the string
      // representation.
      ['ℹ︎␜entity:node:article|foo|xyz␝field_image|bar|abc␞␟target_id|url|␀', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['article', 'foo', 'xyz']), ['article' => 'field_image', 'foo' => 'bar', 'xyz' => 'abc'], NULL, ['field_image' => 'target_id', 'bar' => 'url', 'abc' => StructuredDataPropExpressionInterface::SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP]),
        'field_image',
        [
          'module' => ['node', 'file'],
          'config' => [
            'node.type.article',
            'node.type.foo',
            'node.type.xyz',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'field.field.node.foo.bar',
            'field.field.node.xyz.abc',
          ],
        ],
      ],

      // Context: >2 bundles of entity type, with a subset of the bundles using
      // the same field name: it is possible that different bundles use the same
      // field, which will require less information to be stored.
      // ⚠️ Note the inconsistent ordering in the object representation, and the
      // consistent ordering based on alphabetical bundle ordering in the string
      // representation. Also note that the same field name for two bundle
      // and thus same property name for those two fields.
      ['ℹ︎␜entity:node:article|news|product␝field_image|field_photo|field_photo␞␟alt|value|value', new FieldPropExpression(BetterEntityDataDefinition::create('node', ['news', 'article', 'product']), ['article' => 'field_image', 'news' => 'field_photo', 'product' => 'field_photo'], NULL, ['field_image' => 'alt', 'field_photo' => 'value']),
        'field_image␟Alternative text',
        [
          'module' => ['node', 'file', 'file', 'file'],
          'config' => [
            'node.type.article',
            'node.type.news',
            'node.type.product',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'field.field.node.news.field_photo',
            'image.style.canvas_parametrized_width',
            'field.field.node.product.field_photo',
            'image.style.canvas_parametrized_width',
          ],
        ],
      ],

      // Structured data expressions do NOT introspect the data model, they are
      // just stand-alone expressions with a string representation and a PHP
      // object representation. Hence nonsensical values are accepted for all
      // aspects:
      'invalid entity type' => ['ℹ︎␜entity:non_existent␝title␞␟value', new FieldPropExpression(BetterEntityDataDefinition::create('non_existent'), 'title', NULL, 'value'),
        new \LogicException('Expression expects entity type `non_existent`, actual entity type is `node`.'),
        new PluginNotFoundException('non_existent', 'The "non_existent" entity type does not exist.'),
      ],
      'invalid delta' => ['ℹ︎␜entity:node:article␝title␞-1␟value', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', -1, 'value'),
        'Title␞0th item',
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
      'invalid prop name' => ['ℹ︎␜entity:node:article␝title␞␟non_existent', new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'title', NULL, 'non_existent'),
        new \LogicException('Property `non_existent` does not exist on field type `string`. The following field properties exist: `value`.'),
        [
          'module' => ['node'],
          'config' => ['node.type.article'],
        ],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: ReferenceFieldPropExpression, 2: string|\Exception, 3: ConfigDependenciesArray|\Exception}>
   */
  public static function providerReferenceFieldPropExpression(): array {
    $referencer_delta_null = new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'uid', NULL, 'entity');
    $referencer_delta_zero = new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'uid', 0, 'entity');
    $referencer_delta_high = new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'uid', 123, 'entity');

    return [
      // 1. References that point to a FieldPropExpression.
      ['ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝name␞␟value', new ReferenceFieldPropExpression($referencer_delta_null, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', NULL, 'value')),
        'Authored by␜User␝Name',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        NULL,
        ['ℹ︎␜entity:node␝uid␞␟entity␜'],
      ],
      ['ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝name␞0␟value', new ReferenceFieldPropExpression($referencer_delta_null, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', 0, 'value')),
        'Authored by␜User␝Name␞1st item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        NULL,
        ['ℹ︎␜entity:node␝uid␞␟entity␜'],
      ],
      ['ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝name␞99␟value', new ReferenceFieldPropExpression($referencer_delta_null, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', 99, 'value')),
        'Authored by␜User␝Name␞100th item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        NULL,
        ['ℹ︎␜entity:node␝uid␞␟entity␜'],
      ],

      ['ℹ︎␜entity:node␝uid␞0␟entity␜␜entity:user␝name␞␟value', new ReferenceFieldPropExpression($referencer_delta_zero, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', NULL, 'value')),
        'Authored by␞1st item␜User␝Name',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        NULL,
        ['ℹ︎␜entity:node␝uid␞0␟entity␜'],
      ],
      ['ℹ︎␜entity:node␝uid␞0␟entity␜␜entity:user␝name␞0␟value', new ReferenceFieldPropExpression($referencer_delta_zero, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', 0, 'value')),
        'Authored by␞1st item␜User␝Name␞1st item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        NULL,
        ['ℹ︎␜entity:node␝uid␞0␟entity␜'],
      ],
      ['ℹ︎␜entity:node␝uid␞0␟entity␜␜entity:user␝name␞99␟value', new ReferenceFieldPropExpression($referencer_delta_zero, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', 99, 'value')),
        'Authored by␞1st item␜User␝Name␞100th item',
        [
          'module' => ['node', 'user'],
          'content' => ['user:user:some-user-uuid'],
        ],
        NULL,
        ['ℹ︎␜entity:node␝uid␞0␟entity␜'],
      ],

      // 2. References that point to a reference.
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
        new ReferenceFieldPropExpression(
          $referencer_delta_null,
          new ReferenceFieldPropExpression(
            new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'entity'),
            new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
        ),
        'Authored by␜User␝Picture␝URI␟Root-relative file URL',
        [
          'module' => ['node', 'user', 'file', 'file'],
          'content' => ['user:user:some-user-uuid'],
          'config' => [
            'field.field.user.user.user_picture',
            // @todo 🐛 This is not actually used by this expression!
            'image.style.canvas_parametrized_width',
          ],
        ],
        NULL,
        [
          'ℹ︎␜entity:node␝uid␞␟entity␜',
          'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜',
        ],
      ],

      // 3. References that point to a FieldObjectPropsExpression.
      [
        'ℹ︎␜entity:node␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
        new ReferenceFieldPropExpression(
          $referencer_delta_null,
          new FieldObjectPropsExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, [
            'src' => new ReferenceFieldPropExpression(
              new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'entity'),
              new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
            ),
            'alt' => new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'alt'),
            'width' => new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'width'),
            'height' => new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'user_picture', NULL, 'height'),
          ]),
        ),
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
            // @todo 🐛 This is not actually used by this expression!
            'image.style.canvas_parametrized_width',
            'field.field.user.user.user_picture',
            'image.style.canvas_parametrized_width',
            'field.field.user.user.user_picture',
            'image.style.canvas_parametrized_width',
            'field.field.user.user.user_picture',
            'image.style.canvas_parametrized_width',
          ],
        ],
        NULL,
        [
          'ℹ︎␜entity:node␝uid␞␟entity␜',
        ],
      ],

      // Intentional nonsense: labels MUST work if at all possible (invalid
      // deltas do not make this impossible), even when evaluation fails.
      ['ℹ︎␜entity:node␝uid␞123␟entity␜␜entity:user␝name␞␟value', new ReferenceFieldPropExpression($referencer_delta_high, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', NULL, 'value')),
        'Authored by␞124th item␜User␝Name',
        new \LogicException('Requested delta 123 for single-cardinality field, must be either zero or omitted.'),
        NULL,
        ['ℹ︎␜entity:node␝uid␞123␟entity␜'],
      ],
      ['ℹ︎␜entity:node␝uid␞123␟entity␜␜entity:user␝name␞0␟value', new ReferenceFieldPropExpression($referencer_delta_high, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', 0, 'value')),
        'Authored by␞124th item␜User␝Name␞1st item',
        new \LogicException('Requested delta 123 for single-cardinality field, must be either zero or omitted.'),
        NULL,
        ['ℹ︎␜entity:node␝uid␞123␟entity␜'],
      ],
      ['ℹ︎␜entity:node␝uid␞123␟entity␜␜entity:user␝name␞99␟value', new ReferenceFieldPropExpression($referencer_delta_high, new FieldPropExpression(BetterEntityDataDefinition::create('user'), 'name', 99, 'value')),
        'Authored by␞124th item␜User␝Name␞100th item',
        new \LogicException('Requested delta 123 for single-cardinality field, must be either zero or omitted.'),
        NULL,
        ['ℹ︎␜entity:node␝uid␞123␟entity␜'],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldObjectPropsExpression, 2: string|\Exception, 3: ConfigDependenciesArray|\Exception}>
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
          // 1. one (non-leaf) field property that follows an entity reference
          'src' => new ReferenceFieldPropExpression(
            new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'entity'),
            new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
          // 2. one (leaf) field property
          'width' => new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'width'),
        ]),
        'field_image',
        [
          'module' => ['node', 'file', 'file', 'node', 'file'],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
          ],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],
      [
        'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,width↠width}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (leaf) field property that is computed and has its own
          // dependencies
          'src' => new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'src_with_alternate_widths'),
          // 2. one (leaf) field property
          'width' => new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'width'),
        ]),
        'field_image',
        // Expected content-aware dependencies.
        [
          'module' => ['node', 'file', 'file', 'node', 'file'],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
          ],
          'content' => ['file:file:some-image-uuid'],
        ],
        // Expected content-unaware dependencies.
        [
          'module' => ['node', 'file', 'node', 'file'],
          'config' => [
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
            'node.type.article',
            'field.field.node.article.field_image',
            'image.style.canvas_parametrized_width',
          ],
        ],
      ],

      // Digs into multiple levels of an entity reference field to return values
      // from different levels of that reference.
      [
        'ℹ︎␜entity:node:article␝yo_ho␞␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt}',
        new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, [
          'src' => new ReferenceFieldPropExpression(
            new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
            new ReferenceFieldPropExpression(
              new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'entity'),
              new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
            ),
          ),
          'alt' => new ReferenceFieldPropExpression(
            new FieldPropExpression(BetterEntityDataDefinition::create('node', 'article'), 'yo_ho', NULL, 'entity'),
            new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'field_media_image', NULL, 'alt'),
          ),
        ]),
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
            'node.type.article',
            'field.field.node.article.yo_ho',
            'media.type.image',
            'media.type.image',
            'field.field.media.image.field_media_image',
            'image.style.canvas_parametrized_width',
            'node.type.article',
            'field.field.node.article.yo_ho',
            'media.type.image',
            'media.type.image',
            'field.field.media.image.field_media_image',
            'image.style.canvas_parametrized_width',
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
   * @return array<array{0: string, 1: FieldTypePropExpression, 2: \Error, 3: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldTypePropExpression(): array {
    return [
      // Field type with single property.
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
      ['ℹ︎string␟value', new FieldTypePropExpression('string', 'value'),
        new \TypeError(),
        [],
      ],

      // Field type with >1 properties.
      // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
      ['ℹ︎image␟width', new FieldTypePropExpression('image', 'width'),
        new \TypeError(),
        [
          'module' => ['image'],
        ],
      ],
      ['ℹ︎image␟src', new FieldTypePropExpression('image', 'src'),
        new \TypeError(),
        [
          'module' => ['image'],
        ],
      ],
      ['ℹ︎image␟src_with_alternate_widths', new FieldTypePropExpression('image', 'src_with_alternate_widths'),
        new \TypeError(),
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
        ],
        [
          'module' => [
            'image',
          ],
        ],
      ],

      // Structured data expressions do NOT introspect the data model, they are
      // just stand-alone expressions with a string representation and a PHP
      // object representation. Hence nonsensical values are accepted:
      'invalid prop name' => ['ℹ︎string␟non_existent', new FieldTypePropExpression('string', 'non_existent'),
        new \TypeError(),
        [],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: ReferenceFieldTypePropExpression, 2: \Error, 3: ConfigDependenciesArray|\Exception}>
   */
  public static function providerReferenceFieldTypePropExpression(): array {
    return [
      // Reference field type for a single property.
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
      [
        'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
        new ReferenceFieldTypePropExpression(
          new FieldTypePropExpression('image', 'entity'),
          new FieldPropExpression(
            BetterEntityDataDefinition::create('file'),
          'uri',
          0,
          'value'
          )
        ),
        new \TypeError(),
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
          new FieldTypePropExpression('image', 'entity'),
          new FieldObjectPropsExpression(
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
        new \TypeError(),
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
          new FieldTypePropExpression('entity_reference', 'entity'),
          new ReferenceFieldPropExpression(
            new FieldPropExpression(BetterEntityDataDefinition::create('media', ['baby_photos', 'vacation_photos']), ['baby_photos' => 'field_media_image_1', 'vacation_photos' => 'field_media_image_2'], \NULL, 'entity'),
            new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
          ),
        ),
        new \TypeError(),
        [
          'content' => [
            'media:baby_photos:baby-photos-media-uuid',
            'file:file:photo-baby-jack-uuid',
          ],
          'module' => [
            'media',
            'file',
            'file',
            'file',
          ],
          'config' => [
            'media.type.baby_photos',
            'media.type.vacation_photos',
            'field.field.media.baby_photos.field_media_image_1',
            'image.style.canvas_parametrized_width',
            'field.field.media.vacation_photos.field_media_image_2',
            'image.style.canvas_parametrized_width',
          ],
        ],
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldTypeObjectPropsExpression, 2: \Error, 3: ConfigDependenciesArray|\Exception}>
   */
  public static function providerFieldTypeObjectPropsExpression(): array {
    return [
      // Context: entity type, base field.
      [
        'ℹ︎string␟{label↠value}',
        new FieldTypeObjectPropsExpression('string', [
          // SDC prop accepting an object, with a single mapped key-value pair.
          'label' => new FieldTypePropExpression('string', 'value'),
        ]),
        new \TypeError(),
        [],
      ],

      // Context: bundle of entity type, configurable field.
      [
        'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,width↠width}',
        new FieldTypeObjectPropsExpression('image', [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (non-leaf) field property that follows an entity reference
          'src' => new ReferenceFieldTypePropExpression(
            new FieldTypePropExpression('image', 'entity'),
            new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
          // 2. one (leaf) field property
          'width' => new FieldTypePropExpression('image', 'width'),
        ]),
        new \TypeError(),
        [
          'module' => ['image', 'file', 'image'],
          'content' => ['file:file:some-image-uuid'],
        ],
      ],
    ];
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()
   * @testWith [null]
   *           ["article"]
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
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()
   * @testWith [null]
   *           ["article"]
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
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()
   */
  public function testInvalidFieldPropExpressionDueToMultipleFieldPropNamesWithoutMultipleFieldNames(): void {
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
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()
   */
  public function testInvalidFieldPropExpressionDueToOnlyNullFieldPropNames(): void {
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
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()
   */
  public function testInvalidFieldPropExpressionDueToDuplicateBundles(): void {
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
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()
   * @testWith [{"foo": "field_media_image", "bar": "field_media_image_1", "baz": "field_media_image_2"}]
   *           [{"foo": "field_media_image", "baz": "field_media_image_2"}]
   *           [{}]
   *           [{"foo": "field_media_image", "bar": "field_media_image_1"}]
   */
  public function testInvalidFieldPropExpressionDueToFieldNameMismatch(array $field_name): void {
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
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct()
   */
  public function testInvalidFieldObjectPropsExpressionDueToPropName(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:node␝title␞0␟value` is not a valid expression, because it does not map the same field item (entity type `entity:node`, field name `field_image`, delta `0`).');
    new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node'), 'field_image', 0, [
      'label' => new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', 0, 'value'),
    ]);
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct()
   */
  public function testInvalidFieldObjectPropsExpressionDueToDelta(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:node␝title␞␟value` is not a valid expression, because it does not map the same field item (entity type `entity:node`, field name `title`, delta `0`).');
    new FieldObjectPropsExpression(BetterEntityDataDefinition::create('node'), 'title', 0, [
      'label' => new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'title', NULL, 'value'),
    ]);
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct()
   */
  public function testInvalidFieldObjectPropsExpressionInsideReferenceFieldTypeExpression(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:file␝bytes␞0␟value` is not a valid expression, because it does not map the same field item (entity type `entity:file`, field name `uri`, delta `0`).');

    // @phpstan-ignore-next-line new.resultUnused
    new ReferenceFieldTypePropExpression(
      new FieldTypePropExpression('image', 'entity'),
      new FieldObjectPropsExpression(
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

}
