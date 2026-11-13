<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\JsonSchemaInterpreter;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(JsonSchemaObjectRef::class)]
#[Group('canvas')]
final class JsonSchemaObjectRefTest extends UnitTestCase {

  /**
   * The literal `$ref` URIs in the config schema YAML must match the enum.
   *
   * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef
   * @see config/schema/canvas.json_schema.yml
   */
  public function testChoicesMatchEnumCases(): void {
    $schema = Yaml::parseFile(\dirname(__DIR__, 4) . '/config/schema/canvas.json_schema.yml');
    \assert(\is_array($schema));

    $choices = $schema['canvas.json_schema.prop_shape.object']['mapping']['$ref']['constraints']['Choice']['choices'] ?? NULL;
    $this->assertIsArray($choices, 'The `$ref` Choice constraint exists in canvas.json_schema.prop_shape.object.');

    $enum_values = \array_map(static fn (JsonSchemaObjectRef $case): string => $case->value, JsonSchemaObjectRef::cases());

    sort($choices);
    sort($enum_values);
    $this->assertSame(
      $enum_values,
      $choices,
      'The literal `$ref` URIs in canvas.json_schema.yml must be kept in sync with JsonSchemaObjectRef enum cases.',
    );
  }

  /**
   * Tests both cardinalities of content-entity-reference prop schemas.
   *
   * @param array $prop_schema
   *   The JSON schema for a component prop.
   * @param bool $expected_object
   *   The expected result of ::isContentEntityReferenceObject().
   * @param bool $expected_object_list
   *   The expected result of ::isContentEntityReferenceObjectList().
   */
  #[DataProvider('providerContentEntityReferenceCardinality')]
  public function testContentEntityReferenceCardinality(array $prop_schema, bool $expected_object, bool $expected_object_list): void {
    self::assertSame($expected_object, JsonSchemaObjectRef::isContentEntityReferenceObject($prop_schema));
    self::assertSame($expected_object_list, JsonSchemaObjectRef::isContentEntityReferenceObjectList($prop_schema));
    self::assertSame($expected_object || $expected_object_list, JsonSchemaObjectRef::isContentEntityReference($prop_schema));
  }

  /**
   * Data provider for ::testContentEntityReferenceCardinality().
   */
  public static function providerContentEntityReferenceCardinality(): \Generator {
    yield 'single reference' => [
      ['title' => 'Article', ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray()],
      TRUE,
      FALSE,
    ];
    yield 'list of references' => [
      [
        'title' => 'Articles',
        'type' => 'array',
        'items' => JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
      ],
      FALSE,
      TRUE,
    ];
    yield 'list of references, with maxItems and x-allowed-* on items' => [
      [
        'type' => 'array',
        'items' => JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray() + [
          'x-allowed-entity-type-id' => 'node',
          'x-allowed-bundle' => 'article',
        ],
        'maxItems' => 3,
      ],
      FALSE,
      TRUE,
    ];
    yield 'list of images' => [
      ['type' => 'array', 'items' => JsonSchemaObjectRef::Image->asPropShapeArray()],
      FALSE,
      FALSE,
    ];
    yield 'list without items' => [
      ['type' => 'array'],
      FALSE,
      FALSE,
    ];
    yield 'list with items without type' => [
      ['type' => 'array', 'items' => ['$ref' => JsonSchemaObjectRef::ContentEntityReference->value]],
      FALSE,
      FALSE,
    ];
    yield 'list with one schema per item' => [
      ['type' => 'array', 'items' => [JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray()]],
      FALSE,
      FALSE,
    ];
  }

}
