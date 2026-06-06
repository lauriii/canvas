<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\PropShape;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\PropShape\PropShape;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(PropShape::class)]
#[Group('canvas')]
final class PropShapeNormalizeTest extends UnitTestCase {

  /**
   * Confirms that `JsonSchemaType::from()` would reject unknown types.
   */
  public function testJsonSchemaTypeFromRejectsMixedCase(): void {
    $this->expectException(\ValueError::class);
    JsonSchemaType::from('oBjEct');
  }

  /**
   * Tests that `type` values are lowercased on normalization.
   */
  public function testNormalizePropSchemaTolerantOfMixedCase(): void {
    $schema = [
      'type' => 'oBjEct',
      'properties' => [
        'nested' => ['type' => 'sTrInG'],
      ],
    ];

    $normalized = PropShape::normalizePropSchema($schema);

    // The mixed-case `type` is lowercased rather than rejected, and the
    // `properties` recursion still runs (which only happens when the lowercased
    // outer type matches `JsonSchemaType::Object`).
    $this->assertSame([
      'type' => 'object',
      'properties' => [
        'nested' => ['type' => 'string'],
      ],
    ], $normalized);
  }

  /**
   * Tests tolerance of `type` values that are not known `JsonSchemaType` cases.
   *
   * @param array<string, mixed> $schema
   * @param array<string, mixed> $expected
   */
  #[DataProvider('providerNormalizeTolerance')]
  public function testNormalizePropSchemaWithUnknownType(array $schema, array $expected): void {
    $this->assertSame($expected, PropShape::normalizePropSchema($schema));
  }

  /**
   * @return \Generator<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
   */
  public static function providerNormalizeTolerance(): \Generator {
    // A JSON Schema type that Canvas does not model (`null` is valid per the
    // JSON Schema spec but has no `JsonSchemaType` case).
    yield 'unmodeled JSON Schema type (null)' => [
      ['type' => 'null'],
      ['type' => 'null'],
    ];

    // cspell:ignore strign
    // A typo that bypasses validation — must not throw.
    yield 'typo in type' => [
      ['type' => 'strign', 'title' => 'A misspelled type'],
      ['type' => 'strign'],
    ];

    // SDC appends `'object'` to every prop's declared type (for deferring
    // rendering in Twig), so `type: 'string'` arrives here as
    // `['string', 'object']`. The first entry is the originally declared type.
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
    yield 'SDC-decorated string collapses to first type' => [
      ['type' => ['string', 'object']],
      ['type' => 'string'],
    ];

    // Same shape, but with an unknown leading type — must not throw.
    yield 'SDC-decorated unknown type collapses to first type' => [
      ['type' => ['mystery', 'object']],
      ['type' => 'mystery'],
    ];

    // Uppercase variants are normalized to lowercase rather than rejected.
    yield 'uppercase type is lowercased' => [
      ['type' => 'STRING'],
      ['type' => 'string'],
    ];
  }

  /**
   * Tests that the resolved `$ref` id keyword is stripped in either form.
   *
   * The justinrainbow/json-schema library injects the resolved `$ref` URI under
   * `id` (<= 6.8.x, and Draft-04) or `$id` (>= 6.9.0, Draft-06+). Either must be
   * removed so the URI does not make otherwise-equivalent prop shapes unique
   * and therefore non-storable.
   *
   * @see https://github.com/jsonrainbow/json-schema/issues/911
   */
  #[DataProvider('providerResolvedRefIdKeyword')]
  public function testNormalizePropSchemaStripsResolvedRefId(string $id_keyword): void {
    // An image-uri prop after its `$ref` to
    // json-schema-definitions://canvas.module/image-uri has been resolved: the
    // referenced definition is inlined and its URI stamped under the id keyword.
    // @see canvas/schema.json
    $resolved = [
      'title' => 'Image URL',
      'type' => 'string',
      'format' => 'uri-reference',
      'contentMediaType' => 'image/*',
      'x-allowed-schemes' => ['http', 'https'],
      $id_keyword => 'json-schema-definitions://canvas.module/image-uri',
    ];

    // Both keyword forms collapse to the same id-less shape, so equivalent
    // props match regardless of the json-schema version that resolved them.
    $this->assertSame(
      [
        'type' => 'string',
        'contentMediaType' => 'image/*',
        'format' => 'uri-reference',
        'x-allowed-schemes' => ['http', 'https'],
      ],
      PropShape::normalizePropSchema($resolved),
    );
  }

  /**
   * @return \Generator<string, array{0: string}>
   */
  public static function providerResolvedRefIdKeyword(): \Generator {
    yield 'Draft-04 / pre-6.9.0 `id`' => ['id'];
    yield 'Draft-07 / 6.9.0+ `$id`' => ['$id'];
  }

}
