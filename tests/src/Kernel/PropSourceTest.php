<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDatelistWidget;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropSource\AdaptedPropSource;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\experience_builder\PropSource\PropSource
 * @group experience_builder
 */
class PropSourceTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'user',
    'datetime',
    'datetime_range',
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
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(StaticPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('static:field_item:string', $simple_example->getSourceType());
    $this->assertInstanceOf(FieldTypePropExpression::class, StructuredDataPropExpression::fromString($simple_example->asChoice()));
    $this->assertSame('Hello, world!', $simple_example->getValue());
    // Test the functionality of a StaticPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame('Hello, world!', $simple_example->evaluate(User::create([])));
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
    $this->assertInstanceOf(StringTextfieldWidget::class, $simple_example->getWidget('irrelevant-for-test'));
    $this->assertInstanceOf(StringTextfieldWidget::class, $simple_example->getWidget('irrelevant-for-test', 'string_textfield'));
    // The widget plugin manager ignores any request for another widget type and
    // falls back to the default widget if
    // @see \Drupal\Core\Field\WidgetPluginManager::getInstance()
    $this->assertInstanceOf(StringTextfieldWidget::class, $simple_example->getWidget('irrelevant-for-test', 'string_textarea'));

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
    $complex_example = PropSource::parse(json_decode($json_representation, TRUE));
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
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
    $this->assertInstanceOf(DateRangeDefaultWidget::class, $complex_example->getWidget('irrelevant-for-test'));
    $this->assertInstanceOf(DateRangeDefaultWidget::class, $complex_example->getWidget('irrelevant-for-test', 'daterange_default'));
    $this->assertInstanceOf(DateRangeDatelistWidget::class, $complex_example->getWidget('irrelevant-for-test', 'daterange_datelist'));
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\DynamicPropSource
   */
  public function testDynamicPropSource(): void {
    // A simple example.
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
    $this->assertSame('John Doe', $simple_example->evaluate(User::create(['name' => 'John Doe'])));
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
    $this->assertSame(11874, $simple_dynamic_example->evaluate(User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713])));

    // A complex example.
    $complex_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:datetime',
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
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:datetime","value":{"value":"2020-04-16"},"expression":"ℹ︎datetime␟value"},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $complex_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame(1546, $complex_example->evaluate(User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713])));
  }

}
