<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\Prophet;

/**
 * @coversDefaultClass \Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression
 * @coversClass \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression
 * @coversClass \Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression
 * @coversClass \Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression
 * @coversClass \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression
 * @coversClass \Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression
 * @coversClass \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression
 * @group experience_builder
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
   * Combines the cases of all individual data providers, assigns clear labels.
   *
   * @return array<array{0: string, 1: FieldPropExpression|ReferenceFieldPropExpression}>
   */
  public static function provider(): array {
    $container = new ContainerBuilder();
    $prophet = new Prophet();
    $container->set('typed_data_manager', $prophet->prophesize(TypedDataManagerInterface::class)->reveal());
    \Drupal::setContainer($container);
    $generate_meaningful_case_label = function (string $prefix, array $cases) : array {
      return array_combine(
        array_map(fn (int|string $key) => sprintf("$prefix - %s", is_string($key) ? $key : "#$key"), array_keys($cases)),
        $cases
      );
    };

    return $generate_meaningful_case_label('FieldPropExpression', self::providerFieldPropExpression())
      + $generate_meaningful_case_label('FieldReferencePropExpression', self::providerReferenceFieldPropExpression())
      + $generate_meaningful_case_label('FieldObjectPropsExpression', self::providerFieldObjectPropsExpression())
      + $generate_meaningful_case_label('FieldTypePropExpression', self::providerFieldTypePropExpression())
      + $generate_meaningful_case_label('ReferenceFieldTypePropExpression', self::providerReferenceFieldTypePropExpression())
      + $generate_meaningful_case_label('FieldTypeObjectPropsExpression', self::providerFieldTypeObjectPropsExpression());
  }

  /**
   * @return array<array{0: string, 1: FieldPropExpression}>
   */
  public static function providerFieldPropExpression(): array {
    return [
      // Context: entity type, base field.
      ['ℹ︎␜entity:node␝title␞␟value', new FieldPropExpression(EntityDataDefinition::create('node'), 'title', NULL, 'value')],
      ['ℹ︎␜entity:node␝title␞0␟value', new FieldPropExpression(EntityDataDefinition::create('node'), 'title', 0, 'value')],
      ['ℹ︎␜entity:node␝title␞99␟value', new FieldPropExpression(EntityDataDefinition::create('node'), 'title', 99, 'value')],

      // Context: bundle of entity type, base field.
      ['ℹ︎␜entity:node:article␝title␞␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'title', NULL, 'value')],
      ['ℹ︎␜entity:node:article␝title␞0␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'title', 0, 'value')],
      ['ℹ︎␜entity:node:article␝title␞99␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'title', 99, 'value')],

      // Context: bundle of entity type, configurable field.
      ['ℹ︎␜entity:node:article␝field_image␞␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'value')],
      ['ℹ︎␜entity:node:article␝field_image␞0␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'field_image', 0, 'value')],
      ['ℹ︎␜entity:node:article␝field_image␞99␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'field_image', 99, 'value')],

      // Structured data expressions do NOT introspect the data model, they are
      // just stand-alone expressions with a string representation and a PHP
      // object representation. Hence nonsensical values are accepted for all
      // aspects:
      'invalid entity type' => ['ℹ︎␜entity:non_existent␝title␞␟value', new FieldPropExpression(EntityDataDefinition::create('non_existent'), 'title', NULL, 'value')],
      'invalid delta' => ['ℹ︎␜entity:node:article␝title␞-1␟value', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'title', -1, 'value')],
      'invalid prop name' => ['ℹ︎␜entity:node:article␝title␞␟non_existent', new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'title', NULL, 'non_existent')],
    ];
  }

  /**
   * @return array<array{0: string, 1: ReferenceFieldPropExpression}>
   */
  public static function providerReferenceFieldPropExpression(): array {
    $referencer_delta_null = new FieldPropExpression(EntityDataDefinition::create('node'), 'owner', NULL, 'value');
    $referencer_delta_zero = new FieldPropExpression(EntityDataDefinition::create('node'), 'owner', 0, 'value');
    $referencer_delta_high = new FieldPropExpression(EntityDataDefinition::create('node'), 'owner', 123, 'value');

    return [
      ['ℹ︎␜entity:node␝owner␞␟value␜␜entity:user␝name␞␟value', new ReferenceFieldPropExpression($referencer_delta_null, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', NULL, 'value'))],
      ['ℹ︎␜entity:node␝owner␞␟value␜␜entity:user␝name␞0␟value', new ReferenceFieldPropExpression($referencer_delta_null, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', 0, 'value'))],
      ['ℹ︎␜entity:node␝owner␞␟value␜␜entity:user␝name␞99␟value', new ReferenceFieldPropExpression($referencer_delta_null, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', 99, 'value'))],

      ['ℹ︎␜entity:node␝owner␞0␟value␜␜entity:user␝name␞␟value', new ReferenceFieldPropExpression($referencer_delta_zero, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', NULL, 'value'))],
      ['ℹ︎␜entity:node␝owner␞0␟value␜␜entity:user␝name␞0␟value', new ReferenceFieldPropExpression($referencer_delta_zero, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', 0, 'value'))],
      ['ℹ︎␜entity:node␝owner␞0␟value␜␜entity:user␝name␞99␟value', new ReferenceFieldPropExpression($referencer_delta_zero, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', 99, 'value'))],

      ['ℹ︎␜entity:node␝owner␞123␟value␜␜entity:user␝name␞␟value', new ReferenceFieldPropExpression($referencer_delta_high, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', NULL, 'value'))],
      ['ℹ︎␜entity:node␝owner␞123␟value␜␜entity:user␝name␞0␟value', new ReferenceFieldPropExpression($referencer_delta_high, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', 0, 'value'))],
      ['ℹ︎␜entity:node␝owner␞123␟value␜␜entity:user␝name␞99␟value', new ReferenceFieldPropExpression($referencer_delta_high, new FieldPropExpression(EntityDataDefinition::create('user'), 'name', 99, 'value'))],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldObjectPropsExpression}>
   */
  public static function providerFieldObjectPropsExpression(): array {
    return [
      // Context: entity type, base field.
      [
        'ℹ︎␜entity:node␝title␞0␟{label↠value}',
        new FieldObjectPropsExpression(EntityDataDefinition::create('node'), 'title', 0, [
          // SDC prop accepting an object, with a single mapped key-value pair.
          'label' => new FieldPropExpression(EntityDataDefinition::create('node'), 'title', 0, 'value'),
        ]),
      ],
      [
        'ℹ︎␜entity:node␝title␞␟{label↠value}',
        new FieldObjectPropsExpression(EntityDataDefinition::create('node'), 'title', NULL, [
          // SDC prop accepting an object, with a single mapped key-value pair.
          'label' => new FieldPropExpression(EntityDataDefinition::create('node'), 'title', NULL, 'value'),
        ]),
      ],

      // Context: bundle of entity type, configurable field.
      [
        'ℹ︎␜entity:node:article␝field_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,width↠width}',
        new FieldObjectPropsExpression(EntityDataDefinition::create('node', 'article'), 'field_image', NULL, [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (non-leaf) field property that follows an entity reference
          'src' => new ReferenceFieldPropExpression(
            new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'entity'),
            new FieldPropExpression(EntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
          // 2. one (leaf) field property
          'width' => new FieldPropExpression(EntityDataDefinition::create('node', 'article'), 'field_image', NULL, 'width'),
        ]),
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldTypePropExpression}>
   */
  public static function providerFieldTypePropExpression(): array {
    return [
      // Field type with single property.
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
      ['ℹ︎string␟value', new FieldTypePropExpression('string', 'value')],

      // Field type with >1 properties.
      // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
      ['ℹ︎image␟width', new FieldTypePropExpression('image', 'width')],
      ['ℹ︎image␟src', new FieldTypePropExpression('image', 'src')],

      // Structured data expressions do NOT introspect the data model, they are
      // just stand-alone expressions with a string representation and a PHP
      // object representation. Hence nonsensical values are accepted:
      'invalid prop name' => ['ℹ︎string␟non_existent', new FieldTypePropExpression('string', 'non_existent')],
    ];
  }

  /**
   * @return array<array{0: string, 1: ReferenceFieldTypePropExpression}>
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
            EntityDataDefinition::create('file'),
          'uri',
          0,
          'value'
          )
        ),
      ],

      // Field type with >1 properties.
      // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
      [
        'ℹ︎image␟entity␜␜entity:file␝uri␞0␟{stream_wrapper_uri↠value,public_url↠url}',
        new ReferenceFieldTypePropExpression(
          new FieldTypePropExpression('image', 'entity'),
          new FieldObjectPropsExpression(
            EntityDataDefinition::create('file'),
            'uri',
            0,
            [
              'stream_wrapper_uri' => new FieldPropExpression(
                EntityDataDefinition::create('file'),
                'uri',
                0,
                'value'
              ),
              'public_url' => new FieldPropExpression(
                EntityDataDefinition::create('file'),
                'uri',
                0,
                'url'
              ),
            ]
          ),
        ),
      ],
    ];
  }

  /**
   * @return array<array{0: string, 1: FieldTypeObjectPropsExpression}>
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
      ],

      // Context: bundle of entity type, configurable field.
      [
        'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,width↠width}',
        new FieldTypeObjectPropsExpression('image', [
          // SDC prop accepting an object, with >=1 mapped key-value pairs:
          // 1. one (non-leaf) field property that follows an entity reference
          'src' => new ReferenceFieldTypePropExpression(
            new FieldTypePropExpression('image', 'entity'),
            new FieldPropExpression(EntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
          // 2. one (leaf) field property
          'width' => new FieldTypePropExpression('image', 'width'),
        ]),
      ],
    ];
  }

  /**
   * @covers \Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct()
   */
  public function testInvalidFieldObjectPropsExpressionDueToPropName(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:node␝title␞0␟value` is not a valid expression, because it does not map the same field item (entity type `entity:node`, field name `field_image`, delta `0`).');
    new FieldObjectPropsExpression(EntityDataDefinition::create('node'), 'field_image', 0, [
      'label' => new FieldPropExpression(EntityDataDefinition::create('node'), 'title', 0, 'value'),
    ]);
  }

  /**
   * @covers \Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct()
   */
  public function testInvalidFieldObjectPropsExpressionDueToDelta(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:node␝title␞␟value` is not a valid expression, because it does not map the same field item (entity type `entity:node`, field name `title`, delta `0`).');
    new FieldObjectPropsExpression(EntityDataDefinition::create('node'), 'title', 0, [
      'label' => new FieldPropExpression(EntityDataDefinition::create('node'), 'title', NULL, 'value'),
    ]);
  }

  /**
   * @covers \Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression::__construct()
   */
  public function testInvalidFieldObjectPropsExpressionInsideReferenceFieldTypeExpression(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('`ℹ︎␜entity:file␝bytes␞0␟value` is not a valid expression, because it does not map the same field item (entity type `entity:file`, field name `uri`, delta `0`).');

    new ReferenceFieldTypePropExpression(
      new FieldTypePropExpression('image', 'entity'),
      new FieldObjectPropsExpression(
        EntityDataDefinition::create('file'),
        'uri',
        0,
        [
          'src' => new FieldPropExpression(EntityDataDefinition::create('file'), 'uri', 0, 'value'),
          'bytes' => new FieldPropExpression(EntityDataDefinition::create('file'), 'bytes', 0, 'value'),
        ]
      )
    );
  }

}
