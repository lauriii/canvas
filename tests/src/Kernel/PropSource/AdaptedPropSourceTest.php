<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropSource\AdaptedPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(AdaptedPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
#[RunTestsInSeparateProcesses]
class AdaptedPropSourceTest extends PropSourceTestBase {

  public function test(): void {
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
    // Test the functionality of a EntityFieldPropSource:
    // - evaluate it to populate an SDC prop
    $user = User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713]);
    // TRICKY: entities must be saved for them to have cache tags.
    $user->save();
    self::assertEquals(
      new EvaluationResult(
        1663,
        (new CacheableMetadata())->setCacheTags(['user:1']),
      ),
      $simple_static_example->evaluate($user, is_required: TRUE),
    );
    self::assertSame([
      'module' => [
        'canvas',
        'datetime_range',
        'datetime_range',
      ],
    ], $simple_static_example->calculateDependencies());

    // A simple entity field example.
    $simple_entity_field_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:user␝created␞␟value',
            ],
          ],
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_entity_field_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"entity-field","expression":"ℹ︎␜entity:user␝created␞␟value"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"entity-field","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $simple_entity_field_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $simple_entity_field_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $simple_entity_field_example->getSourceType());
    // Test the functionality of a EntityFieldPropSource:
    // - evaluate it to populate an SDC prop
    $this->setUpCurrentUser(permissions: ['access user profiles', 'administer users']);
    self::assertEquals(
      new EvaluationResult(
        11874,
        (new CacheableMetadata())
          ->setCacheTags(['user:1'])
          ->setCacheContexts(['user.permissions'])),
      $simple_entity_field_example->evaluate($user, is_required: TRUE)
    );
    self::assertSame([
      'module' => [
        'canvas',
        'canvas',
        'user',
        'canvas',
        'user',
      ],
    ], $simple_entity_field_example->calculateDependencies($user));

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
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $complex_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:datetime","value":"2020-04-16","expression":"ℹ︎datetime␟value","sourceTypeSettings":{"storage":{"datetime_type":"date"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"entity-field","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $complex_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example->getSourceType());
    // Test the functionality of a EntityFieldPropSource:
    // - evaluate it to populate an SDC prop
    self::assertEquals(
      new EvaluationResult(
        1546,
        (new CacheableMetadata())
          ->setCacheTags(['user:1'])
          ->setCacheContexts(['user.permissions']),
      ),
      $complex_example->evaluate($user, is_required: TRUE)
    );
    self::assertSame([
      'module' => [
        'canvas',
        'datetime',
        'canvas',
        'user',
      ],
    ], $complex_example->calculateDependencies($user));

    // Since #3548749, multi-property fields with only a single stored property
    // are serialized differently. Test backward compatibility with the old
    // format.
    $array_representation_prior_to_3548749 = [
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:datetime',
          'value' => [
            'value' => '2020-04-16',
          ],
          'expression' => 'ℹ︎datetime␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
            ],
          ],
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ];
    $complex_example_bc = PropSource::parse($array_representation_prior_to_3548749);
    // Original state: the value is an array, which explicitly lists the main
    // property (also "value") as the sole key-value pair.
    // @phpstan-ignore staticMethod.alreadyNarrowedType
    self::assertSame(['value' => '2020-04-16'], $array_representation_prior_to_3548749['adapterInputs']['oldest']['value']);
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example_bc);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example_bc->getSourceType());
    // Test the functionality of a EntityFieldPropSource:
    // - evaluate it to populate an SDC prop
    self::assertEquals(
      new EvaluationResult(
        1546,
        (new CacheableMetadata())
          ->setCacheTags(['user:1'])
          ->setCacheContexts(['user.permissions']),
      ),
      $complex_example_bc->evaluate($user, is_required: TRUE)
    );
    self::assertSame([
      'module' => [
        'canvas',
        'datetime',
        'canvas',
        'user',
      ],
    ], $complex_example_bc->calculateDependencies($user));
    // Updated state: the value is no longer an array, but a single value: the
    // value of the main property.
    // This proves that editing a StaticPropSource automatically updates it.
    self::assertSame('2020-04-16', $complex_example_bc->toArray()['adapterInputs']['oldest']['value']);
  }

}
