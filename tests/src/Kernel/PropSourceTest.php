<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDatelistWidget;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropSource\AdaptedPropSource;
use Drupal\experience_builder\PropSource\DefaultRelativeUrlPropSource;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\experience_builder\PropSource\PropSource
 * @group experience_builder
 */
class PropSourceTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'node',
    'user',
    'datetime',
    'datetime_range',
    'system',
  ];

  /**
   * @coversClass \Drupal\experience_builder\PropSource\StaticPropSource
   */
  public function testStaticPropSource(): void {
    // A simple example.
    $simple_example = StaticPropSource::parse([
      'sourceType' => 'static:field_item:string',
      'value' => 'Hello, world!',
      'expression' => 'ℹ︎string␟value',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_example;
    $this->assertSame('{"sourceType":"static:field_item:string","value":"Hello, world!","expression":"ℹ︎string␟value"}', $json_representation);
    $decoded_representation = json_decode($json_representation, TRUE);
    try {
      StaticPropSource::isMinimalRepresentation($decoded_representation);
    }
    catch (\LogicException) {
      $this->fail("Not a minimal representation: $json_representation.");
    }
    $simple_example = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(StaticPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('static:field_item:string', $simple_example->getSourceType());
    $this->assertInstanceOf(FieldTypePropExpression::class, StructuredDataPropExpression::fromString($simple_example->asChoice()));
    $this->assertSame('Hello, world!', $simple_example->getValue());
    // Test the functionality of a StaticPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame('Hello, world!', $simple_example->evaluate(User::create([])));
    // - the field type's item's raw value is minimized if it is single-property
    $this->assertSame('Hello, world!', $simple_example->getValue());
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
    $this->assertInstanceOf(StringTextfieldWidget::class, $simple_example->getWidget('irrelevant-for-test', $this->randomString(), NULL));
    $this->assertInstanceOf(StringTextfieldWidget::class, $simple_example->getWidget('irrelevant-for-test', $this->randomString(), 'string_textfield'));
    // The widget plugin manager ignores any request for another widget type and
    // falls back to the default widget if
    // @see \Drupal\Core\Field\WidgetPluginManager::getInstance()
    $this->assertInstanceOf(StringTextfieldWidget::class, $simple_example->getWidget('irrelevant-for-test', $this->randomString(), 'string_textarea'));
    self::assertSame([
      'plugin' => [
        'field_type:string',
      ],
    ], $simple_example->calculateDependencies());

    // A complex example.
    $complex_example = StaticPropSource::parse([
      'sourceType' => 'static:field_item:daterange',
      'value' => [
        'value' => '2020-04-16T00:00',
        'end_value' => '2024-07-10T10:24',
      ],
      'expression' => 'ℹ︎daterange␟{start↠value,stop↠end_value}',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $complex_example;
    $this->assertSame('{"sourceType":"static:field_item:daterange","value":{"value":"2020-04-16T00:00","end_value":"2024-07-10T10:24"},"expression":"ℹ︎daterange␟{start↠value,stop↠end_value}"}', $json_representation);
    $decoded_representation = json_decode($json_representation, TRUE);
    try {
      StaticPropSource::isMinimalRepresentation($decoded_representation);
    }
    catch (\LogicException) {
      $this->fail("Not a minimal representation: $json_representation.");
    }
    $complex_example = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(StaticPropSource::class, $complex_example);
    // The contained information read back out.
    $this->assertSame('static:field_item:daterange', $complex_example->getSourceType());
    $this->assertInstanceOf(FieldTypeObjectPropsExpression::class, StructuredDataPropExpression::fromString($complex_example->asChoice()));
    $this->assertSame([
      'value' => '2020-04-16T00:00',
      'end_value' => '2024-07-10T10:24',
    ], $complex_example->getValue());
    // Test the functionality of a StaticPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame([
      'start' => '2020-04-16T00:00',
      'stop' => '2024-07-10T10:24',
    ], $complex_example->evaluate(User::create([])));
    // - the field type's item's raw value is minimized if it is single-property
    $this->assertSame(
      [
        'value' => '2020-04-16T00:00',
        'end_value' => '2024-07-10T10:24',
      ],
      $complex_example->getValue()
    );
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
    $this->assertInstanceOf(DateRangeDefaultWidget::class, $complex_example->getWidget('irrelevant-for-test', $this->randomString(), NULL));
    $this->assertInstanceOf(DateRangeDefaultWidget::class, $complex_example->getWidget('irrelevant-for-test', $this->randomString(), 'daterange_default'));
    $this->assertInstanceOf(DateRangeDatelistWidget::class, $complex_example->getWidget('irrelevant-for-test', $this->randomString(), 'daterange_datelist'));
    self::assertSame([
      'module' => [
        'datetime_range',
        'datetime_range',
      ],
      'plugin' => [
        'field_type:daterange',
        'field_type:daterange',
      ],
    ], $complex_example->calculateDependencies());

    // A simple (expression targeting a simple prop) array example (with
    // cardinality specified, rather than the default of `cardinality=1`).
    $simple_array_example = StaticPropSource::parse([
      'sourceType' => 'static:field_item:integer',
      'sourceTypeSettings' => [
        'cardinality' => 5,
      ],
      'value' => [
        20,
        06,
        1,
        88,
        92,
      ],
      'expression' => 'ℹ︎integer␟value',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_array_example;
    $this->assertSame('{"sourceType":"static:field_item:integer","value":[20,6,1,88,92],"expression":"ℹ︎integer␟value","sourceTypeSettings":{"cardinality":5}}', $json_representation);
    $decoded_representation = json_decode($json_representation, TRUE);
    try {
      StaticPropSource::isMinimalRepresentation($decoded_representation);
    }
    catch (\LogicException) {
      $this->fail("Not a minimal representation: $json_representation.");
    }
    $simple_array_example = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(StaticPropSource::class, $simple_array_example);
    // The contained information read back out.
    $this->assertSame('static:field_item:integer', $simple_array_example->getSourceType());
    $this->assertInstanceOf(FieldTypePropExpression::class, StructuredDataPropExpression::fromString($simple_array_example->asChoice()));
    $this->assertSame([20, 06, 1, 88, 92], $simple_array_example->getValue());
    // Test the functionality of a StaticPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame([20, 06, 1, 88, 92], $simple_array_example->evaluate(User::create([])));
    // - the field type's item's raw value is minimized if it is single-property
    $this->assertSame([20, 06, 1, 88, 92], $simple_array_example->getValue());
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
    $this->assertInstanceOf(NumberWidget::class, $simple_array_example->getWidget('irrelevant-for-test', $this->randomString(), NULL));
    $this->assertInstanceOf(NumberWidget::class, $simple_array_example->getWidget('irrelevant-for-test', $this->randomString(), 'number'));
    // The widget plugin manager ignores any request for another widget type and
    // falls back to the default widget if
    // @see \Drupal\Core\Field\WidgetPluginManager::getInstance()
    $this->assertInstanceOf(NumberWidget::class, $simple_array_example->getWidget('irrelevant-for-test', $this->randomString(), 'number'));

    // A complex (expression targeting multiple props) array example (with
    // cardinality specified, rather than the default of `cardinality=1`).
    $complex_array_example = StaticPropSource::parse([
      'sourceType' => 'static:field_item:daterange',
      'sourceTypeSettings' => [
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      'value' => [
        [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-07-10T10:24',
        ],
        [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-09-26T11:31',
        ],
      ],
      'expression' => 'ℹ︎daterange␟{start↠value,stop↠end_value}',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $complex_array_example;
    $this->assertSame('{"sourceType":"static:field_item:daterange","value":[{"value":"2020-04-16T00:00","end_value":"2024-07-10T10:24"},{"value":"2020-04-16T00:00","end_value":"2024-09-26T11:31"}],"expression":"ℹ︎daterange␟{start↠value,stop↠end_value}","sourceTypeSettings":{"cardinality":-1}}', $json_representation);
    $decoded_representation = json_decode($json_representation, TRUE);
    try {
      StaticPropSource::isMinimalRepresentation($decoded_representation);
    }
    catch (\LogicException) {
      $this->fail("Not a minimal representation: $json_representation.");
    }
    $complex_array_example = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(StaticPropSource::class, $complex_array_example);
    // The contained information read back out.
    $this->assertSame('static:field_item:daterange', $complex_array_example->getSourceType());
    $this->assertInstanceOf(FieldTypeObjectPropsExpression::class, StructuredDataPropExpression::fromString($complex_array_example->asChoice()));
    $this->assertSame([
      [
        'value' => '2020-04-16T00:00',
        'end_value' => '2024-07-10T10:24',
      ],
      [
        'value' => '2020-04-16T00:00',
        'end_value' => '2024-09-26T11:31',
      ],
    ], $complex_array_example->getValue());
    // Test the functionality of a StaticPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame([
      [
        'start' => '2020-04-16T00:00',
        'stop' => '2024-07-10T10:24',
      ],
      [
        'start' => '2020-04-16T00:00',
        'stop' => '2024-09-26T11:31',
      ],
    ], $complex_array_example->evaluate(User::create([])));
    // - the field type's item's raw value is minimized if it is single-property
    $this->assertSame(
      [
        [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-07-10T10:24',
        ],
        [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-09-26T11:31',
        ],
      ],
      $complex_array_example->getValue()
    );
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
    $this->assertInstanceOf(DateRangeDefaultWidget::class, $complex_array_example->getWidget('irrelevant-for-test', $this->randomString(), NULL));
    $this->assertInstanceOf(DateRangeDefaultWidget::class, $complex_array_example->getWidget('irrelevant-for-test', $this->randomString(), 'daterange_default'));
    $this->assertInstanceOf(DateRangeDatelistWidget::class, $complex_array_example->getWidget('irrelevant-for-test', $this->randomString(), 'daterange_datelist'));
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\DynamicPropSource
   */
  public function testDynamicPropSource(): void {
    $this->installEntitySchema('user');
    $user = User::create(['name' => 'John Doe']);
    $user->save();

    // A simple example: FieldPropExpression.
    $simple_example = DynamicPropSource::parse([
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:user␝name␞␟value',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_example;
    $this->assertSame('{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝name␞␟value"}', $json_representation);
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(DynamicPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('dynamic', $simple_example->getSourceType());
    $this->assertInstanceOf(FieldPropExpression::class, StructuredDataPropExpression::fromString($simple_example->asChoice()));
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame('John Doe', $simple_example->evaluate($user));
    // - calculate its dependencies
    $this->assertSame([
      'module' => [
        'user',
      ],
      'plugin' => [
        'entity_type:user',
      ],
    ], $simple_example->calculateDependencies($user));

    // A reference example: ReferenceFieldPropExpression.
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'page'])->save();
    $node = $this->createNode(['uid' => $user->id()]);
    $object_example = DynamicPropSource::parse([
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $object_example;
    $this->assertSame('{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value"}', $json_representation);
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(DynamicPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('dynamic', $simple_example->getSourceType());
    $this->assertInstanceOf(ReferenceFieldPropExpression::class, StructuredDataPropExpression::fromString($object_example->asChoice()));
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    try {
      $simple_example->evaluate($user);
      self::fail('Should throw an exception.');
    }
    catch (\DomainException $e) {
      self::assertSame('`ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value` is an expression for entity type `node`, but the provided entity is of type `user`.', $e->getMessage());
    }
    $this->assertSame('John Doe', $simple_example->evaluate($node));
    // - calculate its dependencies
    $this->assertSame([
      'module' => ['node'],
      'plugin' => ['entity_type:node'],
      'config' => ['node.type.page'],
      'content' => ['user:user:' . $user->uuid()],
    ], $simple_example->calculateDependencies($node));

    // A complex object example: FieldObjectPropsExpression containing a
    // ReferenceFieldPropExpression.
    $object_example = DynamicPropSource::parse([
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $object_example;
    $this->assertSame('{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}"}', $json_representation);
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(DynamicPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('dynamic', $simple_example->getSourceType());
    $this->assertInstanceOf(FieldObjectPropsExpression::class, StructuredDataPropExpression::fromString($object_example->asChoice()));
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    try {
      $simple_example->evaluate($user);
      self::fail('Should throw an exception.');
    }
    catch (\DomainException $e) {
      self::assertSame('`ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}` is an expression for entity type `node`, but the provided entity is of type `user`.', $e->getMessage());
    }
    $this->assertSame([
      'human_id' => 'John Doe',
      'machine_id' => 1,
    ], $simple_example->evaluate($node));
    // - calculate its dependencies
    $this->assertSame([
      'module' => [
        'node',
        'node',
      ],
      'plugin' => [
        'entity_type:node',
        'entity_type:node',
      ],
      'config' => [
        'node.type.page',
        'node.type.page',
      ],
      'content' => ['user:user:' . $user->uuid()],
    ], $simple_example->calculateDependencies($node));
  }

  /**
   * @covers \Drupal\experience_builder\PropExpressions\StructuredData\Evaluator
   * @testWith ["ℹ︎␜entity:user␝name␞␟value", null, "John Doe"]
   *           ["ℹ︎␜entity:user␝name␞0␟value", null, "John Doe"]
   *           ["ℹ︎␜entity:user␝name␞-1␟value", "Requested delta -1, but deltas must be positive integers.", "💩"]
   *           ["ℹ︎␜entity:user␝name␞5␟value", "Requested delta 5 for single-cardinality field, must be either zero or omitted.", "💩"]
   *           ["ℹ︎␜entity:user␝roles␞␟target_id", null, ["test_role_a", "test_role_b"]]
   *           ["ℹ︎␜entity:user␝roles␞0␟target_id", null, "test_role_a"]
   *           ["ℹ︎␜entity:user␝roles␞1␟target_id", null, "test_role_b"]
   *           ["ℹ︎␜entity:user␝roles␞5␟target_id", null, null]
   *           ["ℹ︎␜entity:user␝roles␞-1␟target_id", "Requested delta -1, but deltas must be positive integers.", "💩"]
   */
  public function testInvalidDynamicPropSourceFieldPropExpressionDueToDelta(string $expression, ?string $expected_message, mixed $expected_value): void {
    Role::create(['id' => 'test_role_a', 'label' => 'Test role A'])->save();
    Role::create(['id' => 'test_role_b', 'label' => 'Test role B'])->save();
    $user = User::create([
      'name' => 'John Doe',
      'roles' => [
        'test_role_a',
        'test_role_b',
      ],
    ]);

    $dynamic_prop_source_delta_test = new DynamicPropSource(StructuredDataPropExpression::fromString($expression));

    if ($expected_message !== NULL) {
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage($expected_message);
    }

    self::assertSame($expected_value, $dynamic_prop_source_delta_test->evaluate($user));
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\AdaptedPropSource
   */
  public function testAdaptedPropSource(): void {
    // 2. user created access

    // 1. daterange
    // A simple static example.
    $simple_static_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:daterange',
          'value' => [
            'value' => '2020-04-16',
            'end_value' => '2024-11-04',
          ],
          'expression' => 'ℹ︎daterange␟value',
        ],
        'newest' => [
          'sourceType' => 'static:field_item:daterange',
          'value' => [
            'value' => '2020-04-16',
            'end_value' => '2024-11-04',
          ],
          'expression' => 'ℹ︎daterange␟end_value',
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_static_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:daterange","value":{"value":"2020-04-16","end_value":"2024-11-04"},"expression":"ℹ︎daterange␟value"},"newest":{"sourceType":"static:field_item:daterange","value":{"value":"2020-04-16","end_value":"2024-11-04"},"expression":"ℹ︎daterange␟end_value"}}}', $json_representation);
    $simple_static_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $simple_static_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $simple_static_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame(1663, $simple_static_example->evaluate(User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713])));
    self::assertSame([
      'module' => [
        'experience_builder',
        'datetime_range',
        'datetime_range',
      ],
      'plugin' => [
        'adapter:day_count',
        'field_type:daterange',
        'field_type:daterange',
      ],
    ], $simple_static_example->calculateDependencies());

    // A simple dynamic example.
    $simple_dynamic_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝created␞␟value',
            ],
          ],
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_dynamic_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝created␞␟value"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $simple_dynamic_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $simple_dynamic_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $simple_dynamic_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $user = User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713]);
    $this->assertSame(11874, $simple_dynamic_example->evaluate($user));
    self::assertSame([
      'module' => [
        'experience_builder',
        'experience_builder',
        'user',
        'experience_builder',
        'user',
      ],
      'plugin' => [
        'adapter:day_count',
        'adapter:unix_to_date',
        'entity_type:user',
        'adapter:unix_to_date',
        'entity_type:user',
      ],
    ], $simple_dynamic_example->calculateDependencies($user));

    // A complex example.
    $complex_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:datetime',
          'sourceTypeSettings' => [
            'storage' => [
              'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
            ],
          ],
          'value' => '2020-04-16',
          'expression' => 'ℹ︎datetime␟value',
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $complex_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:datetime","value":{"value":"2020-04-16"},"expression":"ℹ︎datetime␟value","sourceTypeSettings":{"storage":{"datetime_type":"date"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $complex_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame(1546, $complex_example->evaluate(User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713])));
    self::assertSame([
      'module' => [
        'experience_builder',
        'datetime',
        'experience_builder',
        'user',
      ],
      'plugin' => [
        'adapter:day_count',
        'field_type:datetime',
        'adapter:unix_to_date',
        'entity_type:user',
      ],
    ], $complex_example->calculateDependencies($user));
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\DefaultRelativeUrlPropSource
   */
  public function testDefaultRelativeUrlPropSource(): void {
    $this->enableModules(['xb_test_sdc', 'link', 'image', 'file', 'options']);
    // Force rebuilding of the definitions which will create the required
    // component.
    $plugin_manager = $this->container->get(ComponentPluginManager::class);
    $plugin_manager->clearCachedDefinitions();
    $plugin_manager->getDefinitions();
    $source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        'title' => 'image',
        'type' => 'object',
        'required' => ['src'],
        'properties' => [
          'src' => [
            'type' => 'string',
            'format' => 'uri-reference',
            'pattern' => '^(/|https?://)?.*\.(png|gif|jpg|jpeg|webp)(\?.*)?(#.*)?$',
            'title' => 'Image URL',
          ],
          'alt' => [
            'type' => 'string',
            'title' => 'Alternate text',
          ],
          'width' => [
            'type' => 'integer',
            'title' => 'Image width',
          ],
          'height' => [
            'type' => 'integer',
            'title' => 'Image height',
          ],
        ],
      ],
      componentId: 'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
    );
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    // Note: title of properties have been omitted; only essential data is kept.
    $json_representation = (string) $source;
    self::assertSame('{"sourceType":"default-relative-url","value":{"src":"gracie.jpg","alt":"A good dog","width":601,"height":402},"jsonSchema":{"type":"object","properties":{"src":{"type":"string","format":"uri-reference","pattern":"^(\/|https?:\/\/)?.*\\\.(png|gif|jpg|jpeg|webp)(\\\?.*)?(#.*)?$"},"alt":{"type":"string"},"width":{"type":"integer"},"height":{"type":"integer"}},"required":["src"]},"componentId":"sdc.xb_test_sdc.image-optional-with-example-and-additional-prop"}', $json_representation);
    $decoded = json_decode($json_representation, TRUE);
    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition it contains.
    $decoded['jsonSchema'] = array_reverse($decoded['jsonSchema']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());
    $path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'xb_test_sdc') . '/components/image-optional-with-example-and-additional-prop';
    // Prove that using a `$ref` results in the same JSON representation.
    $equivalent_source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        '$ref' => 'json-schema-definitions://experience_builder.module/image',
      ],
      componentId: 'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
    );
    self::assertSame((string) $equivalent_source, $json_representation);
    // Test that the URL resolves on evaluation.
    self::assertSame([
      'src' => Url::fromUri(\sprintf('base:%s/gracie.jpg', $path))->toString(),
      'alt' => 'A good dog',
      'width' => 601,
      'height' => 402,
    ], $source->evaluate(NULL));
    self::assertSame([
      'config' => ['experience_builder.component.sdc.xb_test_sdc.image-optional-with-example-and-additional-prop'],
    ], $source->calculateDependencies());
    // This is never a choice presented to the end user; this is a purely internal prop source.
    $this->expectException(\LogicException::class);
    $source->asChoice();
  }

}
