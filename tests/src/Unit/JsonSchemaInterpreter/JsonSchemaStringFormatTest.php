<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\JsonSchemaInterpreter;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\Plugin\Validation\Constraint\UriConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriTargetFileExtensionsConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriTargetMediaTypeConstraint;
use Drupal\canvas\ShapeMatcher\DataTypeShapeRequirement;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shape requirements JsonSchemaStringFormat::Uri derives.
 *
 * A `format: uri` schema with a wildcard `contentMediaType` (for example,
 * `application/*`) must yield a `UriTargetMediaType` requirement, plus a
 * `UriTargetFileExtensions` requirement when the schema declares
 * `x-allowed-file-extensions`, and a `UriScheme` requirement when it declares
 * `x-allowed-schemes`. A concrete media type such as `application/pdf` takes
 * the default branch, which emits neither target requirement.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat::toDataTypeShapeRequirements()
 */
#[CoversClass(JsonSchemaStringFormat::class)]
#[Group('canvas')]
final class JsonSchemaStringFormatTest extends UnitTestCase {

  /**
   * @param array<string, mixed> $schema
   * @param string[] $expected_constraints
   */
  #[DataProvider('providerUriTargetMediaType')]
  public function testUriTargetMediaType(array $schema, array $expected_constraints): void {
    $requirements = JsonSchemaStringFormat::Uri->toDataTypeShapeRequirements($schema);
    $constraints = \array_map(
      static fn (DataTypeShapeRequirement $requirement): string => $requirement->constraint,
      \iterator_to_array($requirements),
    );
    $this->assertSame($expected_constraints, $constraints);
  }

  public static function providerUriTargetMediaType(): \Generator {
    yield 'wildcard contentMediaType with x-allowed-schemes' => [
      [
        'type' => 'string',
        'format' => 'uri',
        'contentMediaType' => 'application/*',
        'x-allowed-schemes' => ['http', 'https'],
      ],
      [
        UriTargetMediaTypeConstraint::PLUGIN_ID,
        UriConstraint::PLUGIN_ID,
        UriSchemeConstraint::PLUGIN_ID,
        'PrimitiveType',
      ],
    ];
    yield 'wildcard contentMediaType without x-allowed-schemes keeps the media type requirement' => [
      [
        'type' => 'string',
        'format' => 'uri',
        'contentMediaType' => 'image/*',
      ],
      [
        UriTargetMediaTypeConstraint::PLUGIN_ID,
        UriConstraint::PLUGIN_ID,
        'PrimitiveType',
      ],
    ];
    yield 'wildcard contentMediaType with x-allowed-file-extensions adds the extension requirement' => [
      [
        'type' => 'string',
        'format' => 'uri',
        'contentMediaType' => 'application/*',
        'x-allowed-schemes' => ['http', 'https'],
        'x-allowed-file-extensions' => ['pdf', 'doc', 'docx'],
      ],
      [
        UriTargetMediaTypeConstraint::PLUGIN_ID,
        UriTargetFileExtensionsConstraint::PLUGIN_ID,
        UriConstraint::PLUGIN_ID,
        UriSchemeConstraint::PLUGIN_ID,
        'PrimitiveType',
      ],
    ];
    yield 'non-wildcard contentMediaType uses the default branch' => [
      [
        'type' => 'string',
        'format' => 'uri',
        'contentMediaType' => 'application/pdf',
        'x-allowed-schemes' => ['http', 'https'],
      ],
      [
        'PrimitiveType',
        UriConstraint::PLUGIN_ID,
        UriSchemeConstraint::PLUGIN_ID,
      ],
    ];
  }

}
