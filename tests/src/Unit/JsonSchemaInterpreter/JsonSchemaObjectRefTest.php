<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\JsonSchemaInterpreter;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
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

}
