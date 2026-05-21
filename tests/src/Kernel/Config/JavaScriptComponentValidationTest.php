<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

// cspell:ignore sofie componente extraño

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\BetterConfigDependencyManagerTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of JavaScriptComponent entities.
 */
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[RunTestsInSeparateProcesses]
class JavaScriptComponentValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
    'node',
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore property.defaultValue
   */
  protected static array $propertiesWithRequiredKeys = [
    'css' => [
      "'original' is a required key.",
      "'compiled' is a required key.",
    ],
    'js' => [
      "'original' is a required key.",
      "'compiled' is a required key.",
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $javascript_component_base = [
      'name' => 'Test',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
      ],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'dataDependencies' => [],
    ];
    JavaScriptComponent::create([...$javascript_component_base, 'machineName' => 'other'])->save();
    $this->entity = JavaScriptComponent::create([
      ...$javascript_component_base,
      'machineName' => 'test',
      'dependencies' => [
        'enforced' => [
          'config' => [
            // @phpstan-ignore-next-line
            JavaScriptComponent::load('other')->getConfigDependencyName(),
          ],
        ],
      ],
    ]);
    $this->entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function testEntityIsValid(): void {
    parent::testEntityIsValid();

    // Beyond validity, validate config dependencies are computed correctly.
    $this->assertSame(
      [
        'config' => [
          'canvas.js_component.other',
        ],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'config' => [
        'canvas.js_component.other',
      ],
      'module' => [
        'canvas',
      ],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * @testWith [true, true, []]
   *           [true, false, {"": "Prop \"silly\" is required, but does not have example value"}]
   *           [false, true, []]
   *           [false, false, []]
   */
  public function testPropExample(bool $required, bool $has_example, array $expected_validation_errors): void {
    $test_prop_definition = [
      'type' => 'boolean',
      'title' => $this->randomMachineName(),
      'examples' => [TRUE],
    ];
    if (!$has_example) {
      unset($test_prop_definition['examples']);
    }
    $this->entity
      ->set('required', $required ? ['silly'] : [])
      ->set('props', ['silly' => $test_prop_definition]);
    $this->assertValidationErrors($expected_validation_errors);
  }

  public static function providerValidEnumsAndExamples(): \Generator {
    yield 'string' => [
      "string",
      ["the answer", "Wim", "Sofie", "Jack"],
      ["the answer" => "the answer", "Wim" => "Wim", "Sofie" => "Sofie", "Jack" => "Jack"],
      NULL,
    ];
    yield 'integer' => ["integer", [42, 1988, 1992, 2024], ["42" => "42", "1988" => "1988", "1992" => "1992", "2024" => "2024"], NULL];
  }

  #[DataProvider('providerValidEnumsAndExamples')]
  public function testValidEnumsAndExamples(string $json_schema_type, array $enum_and_examples_both, array $meta_enum, ?array $expected_typecasting): void {
    $this->entity->set('props', [
      'tested_enum_prop' => [
        'type' => $json_schema_type,
        'title' => "enum: $json_schema_type",
        'enum' => $enum_and_examples_both,
        'meta:enum' => $meta_enum,
        'examples' => $enum_and_examples_both,
      ],
    ]);
    $this->assertValidationErrors([]);
    $this->entity->save();

    // The expected output (i.e. after saving) is the input. But in a few cases,
    // typecasting may occur. For readability, the third parameter is only
    // required for those cases.
    $expected = $expected_typecasting ?? $enum_and_examples_both;

    $this->assertSame($expected, $this->entity->get('props')['tested_enum_prop']['enum']);
    $this->assertSame($meta_enum, $this->entity->get('props')['tested_enum_prop']['meta:enum']);
    $this->assertSame($expected, $this->entity->get('props')['tested_enum_prop']['examples']);
  }

  #[DataProvider('providerInvalidEnumsAndExamples')]
  public function testInvalidEnumsAndExamples(string $json_schema_type, array $enum_and_examples_both, ?array $meta_enum, array $indexed_validation_errors, array $expected_validation_errors): void {
    $this->entity->set('props', [
      'tested_enum_prop' => array_merge([
        'type' => $json_schema_type,
        'title' => "enum: $json_schema_type",
        'enum' => $enum_and_examples_both,
        'examples' => $enum_and_examples_both,
      ], $meta_enum ? ['meta:enum' => $meta_enum] : []),
    ]);

    // The expected validation errors are keyed by the index whose value in the
    // $enum_and_examples_both array is expected to trigger a validation error.
    // This is then expanded to expect an explicit validation error for that
    // same index in both `enum` and `examples`, hence ensuring consistent
    // validation for both.
    foreach ($indexed_validation_errors as $index => $validation_error) {
      $expected_validation_errors["props.tested_enum_prop.enum.$index"] = $validation_error;
      $expected_validation_errors["props.tested_enum_prop.examples.$index"] = $validation_error;
    }
    if ($meta_enum) {
      $this->assertSame($meta_enum, $this->entity->get('props')['tested_enum_prop']['meta:enum']);
    }
    $this->assertValidationErrors($expected_validation_errors);
  }

  /**
   * @testWith ["missing", "The JavaScript component with the machine name 'missing' does not exist."]
   *           ["", "The 'importedJsComponents' contains an invalid component name."]
   *           ["🚀", "The 'importedJsComponents' contains an invalid component name."]
   *           ["componente_extraño", "The 'importedJsComponents' contains an invalid component name."]
   *           [";", "The 'importedJsComponents' contains an invalid component name."]
   */
  public function testNonExistingJsDependencies(string $component_id, string $expected_exception_message): void {
    \assert($this->entity instanceof JavaScriptComponent);
    $this->expectException(ConstraintViolationException::class);
    $this->expectExceptionMessage($expected_exception_message);

    \assert($this->entity instanceof JavaScriptComponent);
    $client_values = $this->entity->normalizeForClientSide()->values;
    $client_values['importedJsComponents'] = [$component_id];
    $this->entity->updateFromClientSide($client_values);
  }

  public static function providerInvalidEnumsAndExamples(): array {
    return [
      'Invalid string' => [
        'string',
        ['string', 42, 3.14, NULL],
        NULL,
        ['3' => 'This value should not be null.'],
        [
          '' => [
            // If not meta:enums are specified, they are generated, but number ones
            // with decimals will be invalid.
            'The "meta:enum" keys for the "tested_enum_prop" prop enum cannot contain a dot. Offending key: "3.14"',
            'The values for the "tested_enum_prop" prop enum must be defined in "meta:enum". Missing keys: "3_14"',
          ],
        ],
      ],
      'Invalid integer' => [
        'integer',
        ['string', 42, 3.14, NULL],
        NULL,
        [
          '0' => 'This value should be of the correct primitive type.',
          '2' => 'This value should be of the correct primitive type.',
          '3' => 'This value should not be null.',
        ],
        [
          '' => [
            'Prop "tested_enum_prop" has invalid example value: [] String value found, but an integer or an object is required',
            'The "meta:enum" keys for the "tested_enum_prop" prop enum cannot contain a dot. Offending key: "3.14"',
            'The values for the "tested_enum_prop" prop enum must be defined in "meta:enum". Missing keys: "3_14"',
          ],
        ],
      ],
      // ⚠️ For now, Canvas does not support `enum` on `type: number` to match core and for better usability.
      // @see https://www.drupal.org/project/canvas/issues/3534758
      'Number' => [
        'number',
        [3.14, 1.0],
        NULL,
        [],
        [
          '' => [
            'The "meta:enum" keys for the "tested_enum_prop" prop enum cannot contain a dot. Offending key: "3.14"',
            'The values for the "tested_enum_prop" prop enum must be defined in "meta:enum". Missing keys: "3_14"',
          ],
          'props.tested_enum_prop' => "'enum' is an unknown key because props.tested_enum_prop.type is number (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.number).",
        ],
      ],
      'Invalid number' => [
        'number',
        ['string', 42, 3.14, NULL],
        NULL,
        [],
        [
          '' => [
            'Prop "tested_enum_prop" has invalid example value: [] String value found, but a number or an object is required',
            'The "meta:enum" keys for the "tested_enum_prop" prop enum cannot contain a dot. Offending key: "3.14"',
            'The values for the "tested_enum_prop" prop enum must be defined in "meta:enum". Missing keys: "3_14"',
          ],
          'props.tested_enum_prop' => "'enum' is an unknown key because props.tested_enum_prop.type is number (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.number).",
          'props.tested_enum_prop.examples.0' => 'This value should be of the correct primitive type.',
          'props.tested_enum_prop.examples.3' => 'This value should not be null.',
        ],
      ],
    ];
  }

  /**
   * Tests `type: boolean` validation and edge cases.
   *
   * (Cannot be tested generically, like `string`, `integer` and `number`.)
   */
  public function testBooleanPropDefinition(): void {
    // Try using `enum` on a boolean prop.
    $this->entity->set('props', [
      'some_boolean' => [
        'type' => 'boolean',
        'title' => 'either/or',
        'enum' => [TRUE, FALSE],
        'examples' => [TRUE, NULL, FALSE],
      ],
    ]);
    $this->assertValidationErrors([
      'props.some_boolean' => "'enum' is an unknown key because props.some_boolean.type is boolean (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.boolean).",
      'props.some_boolean.examples.1' => 'This value should not be null.',
    ]);
  }

  /**
   * Tests `type: string` `format: …` validation edge cases.
   *
   * @testWith [{"format": "uri-reference"}, "https://example.com", null]
   *           [{"format": "uri-reference"}, "ftp://example.com", null]
   *           [{"format": "uri-reference"}, "/node/1", null]
   *           [{"format": "uri-reference"}, "bunny.jpg", null]
   *           [{"format": "uri"}, "https://example.com", null]
   *           [{"format": "uri"}, "ftp://example.com", null]
   *           [{"format": "uri"}, "/node/1", "Invalid URL format"]
   *           [{"format": "uri"}, "bunny.jpg", "Invalid URL format"]
   *
   * @todo Expand this test coverage in https://www.drupal.org/project/canvas/issues/3542890 — this shows what is allowed by the two choices offered by the UI.
   */
  public function testStringFormatPropDefinition(array $string_definition, string $example, ?string $validation_error): void {
    $this->entity->set('props', [
      'beep' => [
        'type' => 'string',
        'title' => 'A meaningful title, but irrelevant in this test',
        ...$string_definition,
        'examples' => [$example],
      ],
    ]);
    $expected_validation_errors = \is_null($validation_error)
      ? []
      : ['' => 'Prop "beep" has invalid example value: [] ' . $validation_error];
    $this->assertValidationErrors($expected_validation_errors);
  }

  /**
   * Tests `type: array` validation and edge cases.
   */
  #[DataProvider('providerTestArrayPropDefinition')]
  public function testArrayPropDefinition(array $array_prop, array $expected_errors): void {
    $this->entity->set('props', ['array_prop_name' => $array_prop]);
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestArrayPropDefinition(): \Generator {
    yield 'Invalid: array with maxItems <2' => [
      [
        'type' => 'array',
        'title' => 'Weirdly Wrapped String',
        'items' => ['type' => 'string'],
        'maxItems' => 1,
        'examples' => [['o hai, I make zero sense']],
      ],
      [
        '' => 'The "maxItems" restriction on arrays (if set) must be at least 2, but got 1 on prop "array_prop_name". Use a non-array type for single-value props.',
        'props.array_prop_name.maxItems' => 'This value should be <em class="placeholder">2</em> or more.',
      ],
    ];
    yield 'Valid: string array with format' => [
      [
        'type' => 'array',
        'title' => 'Links',
        'items' => ['type' => 'string', 'format' => 'uri-reference'],
        'examples' => [['/foo', '/bar']],
      ],
      [],
    ];
    yield 'Valid: string array with enum' => [
      [
        'type' => 'array',
        'title' => 'Red or blue',
        'items' => [
          'type' => 'string',
          'enum' => ['red', 'blue'],
          'meta:enum' => [
            'red' => 'Red',
            'blue' => 'Blue',
          ],
        ],
        'examples' => [['red', 'red', 'blue']],
      ],
      [],
    ];
    yield 'Invalid: string array with format and an example violating the format' => [
      [
        'type' => 'array',
        'title' => 'Links',
        'items' => ['type' => 'string', 'format' => 'uri'],
        'examples' => [
          ['/foo', 'https://example.com/bar', 'baz', 'https://drupal.org/project/canvas'],
        ],
      ],
      [
        '' => "Prop \"array_prop_name\" has invalid example value: [[0]] Invalid URL format\n[[2]] Invalid URL format",
      ],
    ];
    yield 'Valid: string array with maxItems' => [
      [
        'type' => 'array',
        'title' => 'Tags',
        'items' => ['type' => 'string'],
        'maxItems' => 5,
        'examples' => [['Tag A', 'Tag B']],
      ],
      [],
    ];
    yield 'Valid: integer array without maxItems' => [
      [
        'type' => 'array',
        'title' => 'Scores',
        'items' => ['type' => 'integer'],
        'examples' => [[1, 2, 3]],
      ],
      [],
    ];
    yield 'Valid: HTML string array' => [
      [
        'type' => 'array',
        'title' => 'Rich Quotes',
        'items' => [
          'type' => 'string',
          'contentMediaType' => 'text/html',
          'x-formatting-context' => 'block',
        ],
        'examples' => [
          [
            '<p>This is a paragraph with <strong>bold</strong> text.</p><ul><li>List item 1</li><li>List item 2</li></ul>',
            '<p><strong>Hello</strong>, world!</p>',
          ],
        ],
      ],
      [],
    ];
    yield 'Valid: boolean array' => [
      [
        'type' => 'array',
        'title' => 'Flags',
        'items' => ['type' => 'boolean'],
        'examples' => [[TRUE, FALSE]],
      ],
      [],
    ];
    yield 'Valid: number array' => [
      [
        'type' => 'array',
        'title' => 'Prices',
        'items' => ['type' => 'number'],
        'examples' => [[1.99, 9.99]],
      ],
      [],
    ];
    yield 'Invalid: example exceeds maxItems — maxItems is validated against examples' => [
      [
        'type' => 'array',
        'title' => 'Scores',
        'items' => ['type' => 'integer'],
        'maxItems' => 3,
        'examples' => [[1, 2, 3, 4]],
      ],
      [
        '' => "Prop \"array_prop_name\" has invalid example value: [] There must be a maximum of 3 items in the array, 4 found",
      ],
    ];
    // `array` is a valid JSON Schema type but excluded from Canvas's items
    // Choice constraint (nested arrays are not supported by Drupal's Field
    // API). Using `array` here rather than a truly invalid type (e.g.
    // `unknown`) ensures only the config schema Choice violation fires and
    // not the SDC JSON Schema validator, which would also reject types that
    // are not valid JSON Schema at all.
    yield 'Invalid: nested array items not supported — config schema Choice + no storable prop shape' => [
      [
        'type' => 'array',
        'title' => 'Bad Items',
        'items' => ['type' => 'array'],
      ],
      [
        '' => 'Drupal Canvas does not know of a field type/widget to allow populating the <code>array_prop_name</code> prop, with the shape <code>{"type":"array","items":{"type":"array"}}</code>.',
        'props.array_prop_name.items' => "'items' is a required key because props.array_prop_name.items.type is array (see config schema type canvas.json_schema.prop_shape.array).",
        'props.array_prop_name.items.type' => 'The value you selected is not a valid choice.',
      ],
    ];

    yield 'Invalid: missing items schema' => [
      [
        'type' => 'array',
        'title' => 'Missing Items',
        // No examples - when items is missing, examples can't be validated
        // since the type resolution depends on items.type.
      ],
      [
        '' => 'Drupal Canvas does not know of a field type/widget to allow populating the <code>array_prop_name</code> prop, with the shape <code>{"type":"array"}</code>.',
        'props.array_prop_name' => "'items' is a required key because props.array_prop_name.type is array (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.array).",
      ],
    ];

    yield 'Invalid: integer array with string examples' => [
      [
        'type' => 'array',
        'title' => 'Scores',
        'items' => ['type' => 'integer'],
        'examples' => [['not', 'integers']],
      ],
      [
        '' => "Prop \"array_prop_name\" has invalid example value: [[0]] String value found, but an integer is required\n[[1]] String value found, but an integer is required",
        'props.array_prop_name.examples.0.0' => 'This value should be of the correct primitive type.',
        'props.array_prop_name.examples.0.1' => 'This value should be of the correct primitive type.',
      ],
    ];

    yield 'Invalid: number array with string examples' => [
      [
        'type' => 'array',
        'title' => 'Prices',
        'items' => ['type' => 'number'],
        'examples' => [['not', 'numbers']],
      ],
      [
        '' => "Prop \"array_prop_name\" has invalid example value: [[0]] String value found, but a number is required\n[[1]] String value found, but a number is required",
        'props.array_prop_name.examples.0.0' => 'This value should be of the correct primitive type.',
        'props.array_prop_name.examples.0.1' => 'This value should be of the correct primitive type.',
      ],
    ];

    yield 'Valid: object array prop' => [
      [
        'type' => 'array',
        'title' => 'Images',
        'items' => JsonSchemaObjectRef::Image->asPropShapeArray(),
        'examples' => [
          [
            [
              'src' => 'https://example.com/image1.png',
              'alt' => 'First image',
              'width' => 800,
              'height' => 600,
            ],
            [
              'src' => 'https://example.com/image2.png',
              'alt' => 'Second image',
              'width' => 1200,
              'height' => 900,
            ],
          ],
        ],
      ],
      [],
    ];

    yield 'Invalid: object array with wrong keys' => [
      [
        'type' => 'array',
        'title' => 'Images',
        'items' => JsonSchemaObjectRef::Image->asPropShapeArray(),
        'examples' => [
          [
            [
              // Missing required 'src', has invalid key 'url'.
              'url' => 'https://example.com/image.png',
              'alt' => 'Image',
            ],
          ],
        ],
      ],
      [
        '' => 'Prop "array_prop_name" has invalid example value: [[0].src] The property src is required',
        'props.array_prop_name.examples.0.0' => "'src' is a required key.",
        'props.array_prop_name.examples.0.0.url' => "'url' is not a supported key.",
      ],
    ];

    yield 'Invalid: object array with a relative image src example' => [
      [
        'type' => 'array',
        'title' => 'Images',
        'items' => [
          'type' => 'object',
          '$ref' => 'json-schema-definitions://canvas.module/image',
        ],
        'examples' => [
          [
            [
              'src' => 'https://example.com/cat.jpg',
              'alt' => 'A valid example.',
            ],
            [
              'src' => './hero.jpg',
              'alt' => 'A relative path that JsComponent cannot resolve.',
            ],
          ],
        ],
      ],
      [
        '' => 'Image prop "array_prop_name" example src "./hero.jpg" must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
      ],
    ];

    // `type: object` without `$ref` fails at the config schema level because
    // $ref is required in canvas.json_schema.item.object, matching the same
    // requirement as canvas.json_schema.prop_shape.object.
    yield 'Invalid: object items without $ref — config schema required key + no storable prop shape' => [
      [
        'type' => 'array',
        'title' => 'Objects',
        'items' => ['type' => 'object'],
      ],
      [
        '' => 'Drupal Canvas does not know of a field type/widget to allow populating the <code>array_prop_name</code> prop, with the shape <code>{"type":"array","items":{"type":"object"}}</code>.',
        'props.array_prop_name.items' => "'\$ref' is a required key because props.array_prop_name.items.type is object (see config schema type canvas.json_schema.prop_shape.object).",
      ],
    ];
  }

  /**
   * Tests `type: object` validation and edge cases.
   *
   * (Cannot be tested generically, like `string`, `integer` and `number`.)
   */
  public function testObjectPropDefinition(): void {
    $this->entity->set('props', [
      // A well-known object shape that is fully described by JSON Schema: the
      // code component developer knows exactly what to expect.
      'some_object' => JsonSchemaObjectRef::Image->asPropShapeArray() + [
        'title' => $this->randomString(),
        'enum' => [NULL],
        'meta:enum' => [NULL => 'Test'],
        'examples' => [
          [],
          NULL,
          [
            'src' => 'https://placehold.co/1200x900@2x.png',
            'width' => 1200,
            'height' => 900,
            'alt' => 'Example image placeholder',
          ],
          [
            // Only required props.
            'src' => 'https://placehold.co/1200x900@2x.png',
          ],
          [
            // Invalid pattern.
            'src' => 'hi mum, this is not a url',
          ],
          [
            // Missing required 'src'.
            'width' => 1200,
          ],
          [
            // Relative path: rejected because JsComponent cannot resolve them.
            'src' => 'path/to/image.png',
          ],
          [
            // Root-relative URL: rejected because it has no scheme/host.
            'src' => '/root/relative/path/to/image.png',
          ],
          [
            // Valid absolute URL, but using a disallowed scheme.
            'src' => 'public://cat.jpg',
          ],
        ],
      ],
      // A well-known object shape that is loosely described by JSON Schema: the
      // code component developer knows to expect an optional object, but the
      // exact shape depends on the entity field data they choose. Those choices
      // are stored in `dataDependencies.entityFields`.
      'article' => [
        'title' => 'Interesting article',
        'type' => 'object',
        '$ref' => 'json-schema-definitions://canvas.module/content-entity-reference',
      ],
      // The constraints chosen by the code component developer should not be
      // duplicated in the prop definition: it can be computed from
      // `dataDependencies.entityFields` and would be redundant to maintain in
      // two places. `examples` are also unsupported: the referenced entity is
      // resolved at runtime from `dataDependencies.entityFields`.
      'employee' => [
        'title' => 'Employee',
        'type' => 'object',
        '$ref' => 'json-schema-definitions://canvas.module/content-entity-reference',
        // Valid in JSON Schema, but not allowed in code component's prop
        // definitions.
        'x-allowed-entity-type-id' => 'user',
        'examples' => [],
      ],
    ]);
    $this->entity->set('required', ['employee']);
    $this->entity->set('dataDependencies', [
      'entityFields' => [
        'article' => ['ℹ︎␜entity:node:article␝title␞␟value'],
        'employee' => ['ℹ︎␜entity:user␝name␞␟value'],
      ],
    ]);
    $this->assertValidationErrors([
      '' => [
        'Prop "some_object" has invalid example value: [src] The property src is required',
        'Prop "employee" is a content-entity-reference prop and must not have examples.',
        'Prop "employee" is required, but content-entity-reference props must be optional.',
        'Image prop "some_object" example src "hi mum, this is not a url" must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
        'Image prop "some_object" example src "path/to/image.png" must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
        'Image prop "some_object" example src "/root/relative/path/to/image.png" must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
      ],
      'props.employee.x-allowed-entity-type-id' => "'x-allowed-entity-type-id' is not a supported key.",
      'props.some_object.enum.0' => 'This value should not be null.',
      'props.some_object.examples.0' => [
        "'src' is a required key.",
        'This value should not be blank.',
      ],
      'props.some_object.examples.1' => 'This value should not be null.',
      'props.some_object.examples.4.src' => 'This value should be a valid URI reference.',
      'props.some_object.examples.5' => "'src' is a required key.",
      'props.some_object.examples.8.src' => "'public' is not allowed, must be one of the allowed schemes: http, https.",
    ]);
    \assert($this->entity instanceof JavaScriptComponent);
    // Invalid props won't be returned, but no error should happen when calling `getContentEntityReferenceProps()`.
    $this->assertSame([
      'article' => [
        'title' => 'Interesting article',
        ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
      ],
    ], $this->entity->getContentEntityReferenceProps());
  }

  /**
   * Tests that an empty-string example for a string prop is rejected.
   *
   * @see https://www.drupal.org/i/3587211
   */
  public function testEmptyStringExampleRejected(): void {
    $this->entity->set('props', [
      'delta' => [
        'type' => 'string',
        'title' => 'Delta',
        'examples' => [''],
      ],
    ]);
    $this->assertValidationErrors([
      '' => 'Prop "delta" example value `""` cannot be used as a default.',
    ]);
  }

  /**
   * Tests different permutations of entity values.
   *
   * @param array $shape
   *   Array of entity values.
   * @param array $expected_errors
   *   Expected validation errors.
   */
  #[DataProvider('providerTestEntityShapes')]
  public function testEntityShapes(array $shape, array $expected_errors): void {
    $this->entity = JavaScriptComponent::create($shape);
    // Strip out the prefix added by https://www.drupal.org/node/3549909. This
    // can be removed when 11.3 is the minimum supported version of core.
    if (isset($expected_errors['']) && str_starts_with($expected_errors[''], 'In component canvas:test-unknown-prop-type:') && version_compare(\Drupal::VERSION, '11.3', '<')) {
      $expected_errors[''] = substr($expected_errors[''], 44);
    }
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestEntityShapes(): array {
    return [
      'Invalid: no JS' => [
        [
          'machineName' => 'test-no-slots-no-props',
          'name' => 'Test',
          'props' => [],
          'slots' => [],
          'js' => [
            'original' => NULL,
            'compiled' => NULL,
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          'js.compiled' => 'This value should not be null.',
          'js.original' => 'This value should not be null.',
        ],
      ],
      'Invalid: Unknown prop type' => [
        [
          'machineName' => 'test-unknown-prop-type',
          'name' => 'Test',
          'props' => [
            'mixed_up_prop' => [
              'type' => 'unknown',
              'title' => 'Title',
              'enum' => [
                'Press',
                'Click',
                'Submit',
              ],
              'meta:enum' => [
                'Press' => 'Press',
                'Click' => 'Click',
                'Submit' => 'Submit',
              ],
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          '' => "In component canvas:test-unknown-prop-type:\nUnable to find class/interface \"unknown\" specified in the prop \"mixed_up_prop\" for the component \"canvas:test-unknown-prop-type\".",
          'props.mixed_up_prop' => [
            "'enum' is an unknown key because props.mixed_up_prop.type is unknown (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.*).",
            "'meta:enum' is an unknown key because props.mixed_up_prop.type is unknown (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.*).",
          ],
          'props.mixed_up_prop.type' => 'The value you selected is not a valid choice.',
        ],
      ],
      'Valid: no props and no slots' => [
        [
          'machineName' => 'test-no-slots-no-props',
          'name' => 'Test',
          'props' => [],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Valid: props (of all supported types), of which two required and no slots' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'string' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
            'boolean' => [
              'type' => 'boolean',
              'title' => 'Truth',
              'examples' => [TRUE, FALSE],
            ],
            'integer' => [
              'type' => 'integer',
              'title' => 'Integer',
              'examples' => [23, 10, 2024],
            ],
            'number' => [
              'type' => 'number',
              'title' => 'Number',
              'examples' => [3.14],
            ],
            'journalist' => [
              'type' => 'object',
              'title' => 'Journalist',
              '$ref' => 'json-schema-definitions://canvas.module/content-entity-reference',
            ],
            'article' => [
              'type' => 'object',
              'title' => 'News article',
              '$ref' => 'json-schema-definitions://canvas.module/content-entity-reference',
            ],
          ],
          'required' => [
            'string',
            'integer',
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [
            'entityFields' => [
              'journalist' => ['ℹ︎␜entity:user␝name␞␟value'],
              'article' => [
                'ℹ︎␜entity:node:article␝title␞␟value',
                'ℹ︎␜entity:node:article␝body␞␟processed',
              ],
            ],
          ],
        ],
        [],
      ],
      'Invalid: a non-existent required prop' => [
        [
          'machineName' => 'test-non-existent-required-prop',
          'name' => 'Test',
          'props' => [
            'string' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'required' => [
            'does_not_exist',
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          // ⚠️ SDC does not complain about this!
          // @see \Drupal\Core\Theme\Component\ComponentValidator
          // @todo Update once https://www.drupal.org/project/drupal/issues/3493086 is fixed.
        ],
      ],
      'Valid: props, no slots set' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'text' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Valid: enum props' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'text' => [
              'type' => 'string',
              'title' => 'Title',
              'enum' => [
                'Press',
                'Click',
                'Submit',
              ],
              'meta:enum' => [
                'Press' => 'Press',
                'Click' => 'Click',
                'Submit' => 'Submit',
              ],
              'examples' => ['Press', 'Submit'],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Valid: slots (one with description+examples, one without), no props' => [
        [
          'machineName' => 'test-slots',
          'status' => TRUE,
          'name' => 'Test',
          'props' => [],
          'slots' => [
            'test-slot' => [
              'title' => 'test',
              'description' => 'Title',
              'examples' => [
                'Test 1',
                'Test 2',
              ],
            ],
            'test-slot-only-required' => [
              'title' => 'test',
            ],
          ],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Valid: empty JS and CSS, no props, and "disabled"' => [
        [
          'machineName' => 'test-no-js-no-css-no-props-nor-slots-and-disabled',
          'status' => FALSE,
          'name' => 'Test',
          'props' => [],
          'slots' => [],
          'js' => [
            'original' => '',
            'compiled' => '',
          ],
          'css' => [
            'original' => '',
            'compiled' => '',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Valid: image prop' => [
        [
          'machineName' => 'image-prop-no-slots',
          'name' => 'Test',
          'props' => [
            'image' => JsonSchemaObjectRef::Image->asPropShapeArray() + [
              'title' => 'Image title',
              'examples' => [
                [
                  'src' => 'https://example.com/image.png',
                  'alt' => 'Alternative text',
                  'width' => 800,
                  'height' => 600,
                ],
              ],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Invalid: required image prop missing examples' => [
        [
          'machineName' => 'image-prop-no-slots-no-examples',
          'name' => 'Test',
          'required' => [
            'image',
          ],
          'props' => [
            'image' => JsonSchemaObjectRef::Image->asPropShapeArray() + [
              'title' => 'Image title',
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          '' => 'Prop "image" is required, but does not have example value',
        ],
      ],
      'Valid: optional image prop missing examples' => [
        [
          'machineName' => 'image-prop-no-slots-no-examples',
          'name' => 'Test',
          'props' => [
            'image' => JsonSchemaObjectRef::Image->asPropShapeArray() + [
              'title' => 'Image title',
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Invalid: image prop $ref' => [
        [
          'machineName' => 'image-prop-no-slots-no-ref',
          'name' => 'Test',
          'props' => [
            'image' => [
              'title' => 'Image title',
              'type' => 'object',
              'examples' => [
                [
                  // @todo this is actually an invalid example, will be detected by https://www.drupal.org/i/3508725
                  'src' => 'https://example.com/image.png',
                  'alt' => 'Alternative text',
                  'width' => 800,
                  'height' => 600,
                ],
              ],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          '' => 'Drupal Canvas does not know of a field type/widget to allow populating the <code>image</code> prop, with the shape <code>{"type":"object"}</code>.',
          'props.image' => '\'$ref\' is a required key because props.image.type is object (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.object).',
          'props.image.examples.0.alt' => "'alt' is not a supported key.",
          'props.image.examples.0.height' => "'height' is not a supported key.",
          'props.image.examples.0.src' => "'src' is not a supported key.",
          'props.image.examples.0.width' => "'width' is not a supported key.",
        ],
      ],
      'Invalid: image prop with incorrect $ref' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'image' => [
              'title' => 'Image title',
              'type' => 'object',
              '$ref' => "json-schema-definitions://canvas.module/heading",
              'examples' => [
                [
                  'src' => 'https://example.com/image.png',
                  'alt' => 'Alternative text',
                  'width' => 800,
                  'height' => 600,
                ],
              ],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          '' => "Prop \"image\" has invalid example value: [text] The property text is required\n[element] The property element is required",
          'props.image.$ref' => 'The value you selected is not a valid choice.',
          'props.image.examples.0' => [
            "'text' is a required key.",
            "'element' is a required key.",
          ],
          'props.image.examples.0.alt' => "'alt' is not a supported key.",
          'props.image.examples.0.height' => "'height' is not a supported key.",
          'props.image.examples.0.src' => "'src' is not a supported key.",
          'props.image.examples.0.width' => "'width' is not a supported key.",
        ],
      ],
      'Valid: array prop (string items, with maxItems, required, with example)' => [
        [
          'machineName' => 'test-array-prop',
          'name' => 'Test',
          'props' => [
            'tags' => [
              'type' => 'array',
              'title' => 'Tags',
              'items' => ['type' => 'string'],
              'maxItems' => 10,
              'minItems' => 1,
              'examples' => [
                ['Tag A', 'Tag B'],
              ],
            ],
          ],
          'required' => ['tags'],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Valid: array prop (integer items, no maxItems, optional, no example)' => [
        [
          'machineName' => 'test-array-integer-prop',
          'name' => 'Test',
          'props' => [
            'scores' => [
              'type' => 'array',
              'title' => 'Scores',
              'items' => ['type' => 'integer'],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
      'Invalid: required array prop with no example' => [
        [
          'machineName' => 'test-required-array-no-example',
          'name' => 'Test',
          'props' => [
            'tags' => [
              'type' => 'array',
              'title' => 'Tags',
              'items' => ['type' => 'string'],
              'minItems' => 1,
            ],
          ],
          'required' => ['tags'],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [
          '' => 'Prop "tags" is required, but does not have example value',
        ],
      ],
      'Valid: markup prop' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'markup' => [
              'title' => 'Markup',
              'type' => 'string',
              'contentMediaType' => 'text/html',
              'x-formatting-context' => 'block',
              'examples' => [
                '<p>This is a paragraph with <strong>bold</strong> text.</p><ul><li>List item 1</li><li>List item 2</li></ul>',
              ],
            ],
          ],
          'slots' => [],
          'js' => [
            'original' => 'console.log("Test")',
            'compiled' => 'console.log("Test")',
          ],
          'css' => [
            'original' => '.test { display: none; }',
            'compiled' => '.test{display:none;}',
          ],
          'dataDependencies' => [],
        ],
        [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function providerInvalidMachineNameCharacters(): array {
    return [
      'INVALID: space separated' => ['space separated', FALSE],
      'INVALID: period separated' => ['period.separated', FALSE],
      'VALID: dash separated' => ['dash-separated', TRUE],
      'VALID: underscore separated' => ['underscore_separated', TRUE],
      'VALID: contains uppercase' => ['containsUppercase', TRUE],
      'INVALID: starts uppercase' => ['StartsUppercase', FALSE],
      'VALID: contains number' => ['number1', TRUE],
      'INVALID: starts with number' => ['10th_birthday', FALSE],
    ];
  }

  public function testInvalidSlotIdentifiedByConfigSchema(): void {
    $original_test_slot = $this->entity->get('slots')['test-slot'];
    $this->entity->set('slots', [
      '0-slot' => $original_test_slot,
    ]);
    // @todo This test case should have validation errors because '0-slot' is not a valid slot name.
    //   But currently we can not use the 'patternProperties' until
    //   https://www.drupal.org/i/3471064 is fixed.
    $this->assertValidationErrors([]);

    unset($original_test_slot['title']);
    $this->entity->set('slots', [
      'test-slot' => $original_test_slot,
    ]);
    $this->assertValidationErrors([
      '' => 'Slot "test-slot" must have title',
      'slots.test-slot' => "'title' is a required key.",
    ]);
  }

  public function testCollisionBetweenPropsAndSlots(): void {
    $prop_colliding_with_slot = [
      'test-slot' => [
        'title' => 'contrived example',
        'type' => 'string',
        'examples' => ['foo'],
      ],
    ];
    $this->entity->set('props', $prop_colliding_with_slot);
    $this->assertValidationErrors([
      '' => 'The component "canvas:test" declared [test-slot] both as a prop and as a slot. Make sure to use different names.',
    ]);

    // Verify that if there's a lower-level problem, that both the low-level and
    // this high-level consistency validation error appear.
    unset($prop_colliding_with_slot['test-slot']['examples']);
    $this->entity->set('props', $prop_colliding_with_slot);
    $this->assertValidationErrors([
      '' => 'The component "canvas:test" declared [test-slot] both as a prop and as a slot. Make sure to use different names.',
    ]);
  }

  /**
   * @testWith [{}, []]
   *           [{"something": []}, {"dataDependencies.something": "'something' is not a supported key."}]
   *           [{"drupalSettings": []}, {"dataDependencies.drupalSettings": "This value should not be blank."}]
   *           [{"drupalSettings": ["v0.pageTitle", "foo"]}, {"dataDependencies.drupalSettings.1": "The value you selected is not a valid choice."}]
   *           [{"drupalSettings": ["v0.pageTitle", "v0.branding"]}, []]
   *           [{"urls": []}, {"dataDependencies.urls": "This value should not be blank."}]
   *           [{"urls": ["https://www.drupal.org/jsonapi"]}, []]
   *           [{"drupalSettings": ["v0.pageTitle", "v0.branding"], "urls": ["https://www.drupal.org/jsonapi"], "entityFields": {"my_reference": ["ℹ︎␜entity:user␝name␞␟value"]}}, []]
   *           [{"drupalSettings": ["foo"], "entityFields": {"nonexistent_prop": ["ℹ︎␜entity:user␝name␞␟value"]}}, {"dataDependencies.drupalSettings.0": "The value you selected is not a valid choice.", "dataDependencies.entityFields.nonexistent_prop": "'nonexistent_prop' is not a supported key."}]
   *           [{"entityFields": {"text": ["ℹ︎␜entity:user␝name␞␟value"]}}, {"dataDependencies.entityFields.text": "'text' is not a supported key."}]
   */
  public function testDataDependencies(array $test, array $expected_errors): void {
    // Auto-inject a synthetic `my_reference` content-entity-reference prop
    // whenever the test data targets it via `entityFields.my_reference`. This
    // exercises the `SequenceKeysMustMatch` `conditions` scoping — only
    // content-entity-reference props are valid keys. Negative rows targeting a
    // non-content-entity-reference prop key (e.g. `text`) intentionally skip
    // injection so that the constraint sees zero content-entity-reference
    // props and flags the key as unsupported.
    if (\array_key_exists('entityFields', $test) && \array_key_exists('my_reference', $test['entityFields'])) {
      self::assertInstanceOf(JavaScriptComponent::class, $this->entity);
      $props = $this->entity->getProps();
      $props['my_reference'] = [
        'title' => $this->randomString(),
        ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
      ];
      $this->entity->setProps($props);
    }

    $this->entity->set('dataDependencies', $test);
    $this->assertValidationErrors($expected_errors);
  }

  /**
   * Tests entityFields within dataDependencies.
   */
  #[DataProvider('providerEntityFieldsDataDependencies')]
  public function testEntityFieldsDataDependencies(array $test, array $expected_errors, array $required): void {
    // Add `my_reference` as a content-entity-reference prop when the test row
    // targets it, so `entityFields` keys have a valid target.
    if (\array_key_exists('entityFields', $test) && \array_key_exists('my_reference', $test['entityFields'])) {
      \assert($this->entity instanceof JavaScriptComponent);
      $props = $this->entity->getProps() ?? [];
      $props['my_reference'] = [
        'title' => 'My reference',
        ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
      ];
      $this->entity->setProps($props);
    }

    $this->entity->set('dataDependencies', $test);
    if (!empty($required)) {
      $this->entity->set('required', $required);
    }
    $this->assertValidationErrors($expected_errors);
  }

  /**
   * Data provider for testEntityFieldsDataDependencies().
   */
  public static function providerEntityFieldsDataDependencies(): \Generator {
    yield 'empty entityFields' => [
      ['entityFields' => []],
      ['dataDependencies.entityFields' => "There must be >=1 content-entity-reference prop; otherwise the 'entityFields' key should be omitted."],
      [],
    ];

    yield 'entityFields key not in props' => [
      ['entityFields' => ['nonexistent_prop' => ['ℹ︎␜entity:user␝name␞␟value']]],
      ['dataDependencies.entityFields.nonexistent_prop' => "'nonexistent_prop' is not a supported key."],
      [],
    ];

    yield 'entityFields valid key but empty array' => [
      ['entityFields' => ['my_reference' => []]],
      [
        '' => 'Missing "x-allowed-entity-type-id" for content entity reference prop "my_reference".',
        'dataDependencies.entityFields.my_reference' => 'There must be >=1 entity field expression; otherwise the content-entity-reference prop should be deleted.',
      ],
      [],
    ];

    yield 'entityFields valid key with invalid expression' => [
      ['entityFields' => ['my_reference' => ['not-a-valid-expression']]],
      [
        '' => 'Missing "x-allowed-entity-type-id" for content entity reference prop "my_reference".',
        'dataDependencies.entityFields.my_reference.0' => '<em class="placeholder">not-a-valid-expression</em> is not a valid prop expression.',
      ],
      [],
    ];

    yield 'entityFields alongside drupalSettings' => [
      ['drupalSettings' => ['v0.pageTitle'], 'entityFields' => ['my_reference' => ['ℹ︎␜entity:user␝name␞␟value']]],
      [],
      [],
    ];

    // Valid expression types: FieldPropExpression, ReferenceFieldPropExpression, FieldObjectPropsExpression.
    yield 'entityFields valid FieldPropExpression' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:node:article␝title␞␟value']]],
      [],
      [],
    ];

    yield 'entityFields valid ReferenceFieldPropExpression' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value']]],
      [],
      [],
    ];

    yield 'entityFields valid FieldObjectPropsExpression' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:user␝user_picture␞␟{src↠url,alt↠alt}']]],
      [],
      [],
    ];

    // Same entity type+bundle constraint.
    yield 'entityFields mixed entity types in same prop' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:user␝name␞␟value', 'ℹ︎␜entity:node:article␝title␞␟value']]],
      ['dataDependencies.entityFields.my_reference' => 'All entity field expressions must target the same entity type and bundle, but found: entity:user, entity:node:article.'],
      [],
    ];

    yield 'entityFields same entity type in same prop' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:user␝name␞␟value', 'ℹ︎␜entity:user␝mail␞␟value']]],
      [],
      [],
    ];

    // Entity type/bundle existence validation.
    yield 'entityFields non-existent entity type' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:nonsense␝title␞␟value']]],
      [
        '' => 'Missing "x-allowed-entity-type-id" for content entity reference prop "my_reference".',
        'dataDependencies.entityFields.my_reference.0' => "The entity type 'nonsense' does not exist.",
      ],
      [],
    ];

    yield 'entityFields non-existent bundle' => [
      ['entityFields' => ['my_reference' => ['ℹ︎␜entity:node:nonsense␝title␞␟value']]],
      [
        '' => 'Invalid value "nonsense" for "x-allowed-bundle": not a known bundle of entity type "node".',
        'dataDependencies.entityFields.my_reference.0' => "The entity type 'node' does not have a 'nonsense' bundle.",
      ],
      [],
    ];
  }

  /**
   * Tests x-allowed-bundle validation for bundled entity types.
   *
   * @todo Implement this when the content-entity-reference prop type is added in #3573831.
   */
  public function testEntityFieldsMissingBundleForBundledEntityType(): void {
    $this->markTestSkipped('Requires the content-entity-reference prop type with x-allowed-bundle from #3573831.');
  }

  /**
   * Tests that `entityFields` expressions contribute to calculated dependencies.
   *
   * Validation-only coverage of `entityFields` lives in
   * ::testEntityFieldsDataDependencies(). This method complements it by saving
   * the entity and asserting the full `getDependencies()` output for each
   * allowed expression type and the regression case.
   *
   * @param array $data_dependencies
   *   The dataDependencies value to save on the entity.
   * @param array $expected_deps
   *   The exact expected output of $entity->getDependencies() after save.
   */
  #[DataProvider('providerEntityFieldsCalculatedDependencies')]
  public function testEntityFieldsCalculatedDependencies(array $data_dependencies, array $expected_deps): void {
    // Install a configurable field on the `user` entity so that
    // `FieldObjectPropsExpression` against `user_picture` contributes a
    // `field.field.user.user.user_picture` config dep.
    // @see core/profiles/standard/config/install/field.storage.user.user_picture.yml
    FieldStorageConfig::create([
      'entity_type' => 'user',
      'field_name' => 'user_picture',
      'type' => 'image',
      'translatable' => FALSE,
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'label' => 'Picture',
      'description' => '',
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
      'required' => FALSE,
    ])->save();

    // Create a `media:image` media type (with its image source field) and an
    // `entity_reference` field on `node:article` targeting it — the shape
    // produced by Canvas's media library integration.
    // @see config/install/image.style.canvas_parametrized_width.yml
    $this->installConfig(['canvas']);
    $this->installEntitySchema('media');
    $media_type = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
    ]);
    $media_type->save();
    $source_field = $media_type->getSource()->createSourceField($media_type);
    $source_field_storage = $source_field->getFieldStorageDefinition();
    \assert($source_field_storage instanceof FieldStorageConfigInterface);
    $source_field_storage->save();
    $source_field->save();
    $media_type->set('source_configuration', [
      'source_field' => $source_field->getName(),
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_media',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
      'translatable' => FALSE,
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'label' => 'Media',
      'description' => '',
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'bundle' => 'article',
      'required' => FALSE,
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => ['image' => 'image'],
        ],
      ],
    ])->save();

    // Extend the test entity with additional (non-required) props that mirror a
    // realistic multi-entity-reference component so that keys like
    // `suggested_by` and `highlighted_article` are valid `entityFields` keys.
    $this->entity->set('props', $this->entity->get('props') + [
      'suggested_by' => [
        'type' => 'string',
        'title' => 'Suggested by',
        'examples' => ['Alice', 'Bob'],
      ],
      'highlighted_article' => [
        'type' => 'string',
        'title' => 'Highlighted article',
        'examples' => ['Hello', 'World'],
      ],
    ]);

    $this->entity->set('dataDependencies', $data_dependencies);
    $this->entity->save();
    $this->assertSame($expected_deps, $this->entity->getDependencies());
  }

  /**
   * Data provider for ::testEntityFieldsCalculatedDependencies().
   */
  public static function providerEntityFieldsCalculatedDependencies(): \Generator {
    $enforced = 'canvas.js_component.other';

    // Note on key ordering: `getDependencies()` unsets the `enforced` key and
    // re-merges the enforced deps into the result AFTER the non-enforced ones,
    // so `module` (added during calculation) appears before `config` (which
    // only contains the enforced dep), and `canvas.js_component.other` is
    // appended to `config` after any freshly-computed config deps.
    // @see \Drupal\Core\Config\Entity\ConfigEntityBase::getDependencies()

    // Base field on a non-bundled entity type → only the entity type provider.
    yield 'FieldPropExpression on user.name (base field, no bundle)' => [
      ['entityFields' => ['text' => ['ℹ︎␜entity:user␝name␞␟value']]],
      [
        'module' => ['user'],
        'config' => [$enforced],
      ],
    ];

    // Base field on a bundled entity type → bundle config dep added.
    yield 'FieldPropExpression on node:article.title (base field, bundled)' => [
      ['entityFields' => ['text' => ['ℹ︎␜entity:node:article␝title␞␟value']]],
      [
        'config' => ['node.type.article', $enforced],
        'module' => ['node'],
      ],
    ];

    // ReferenceFieldPropExpression — deps from BOTH branches merged.
    yield 'ReferenceFieldPropExpression node:article.uid → user.name' => [
      ['entityFields' => ['text' => ['ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value']]],
      [
        'config' => ['node.type.article', $enforced],
        'module' => ['node', 'user'],
      ],
    ];

    // FieldObjectPropsExpression on a configurable field → field config dep.
    // `alt` and `title` are real ImageItem properties; ImageItem's storage
    // contributes a `file` module dep in addition to the `user` entity type.
    yield 'FieldObjectPropsExpression on user.user_picture' => [
      ['entityFields' => ['text' => ['ℹ︎␜entity:user␝user_picture␞␟{alt↠alt,title↠title}']]],
      [
        'config' => ['field.field.user.user.user_picture', $enforced],
        'module' => ['file', 'user'],
      ],
    ];

    // `drupalSettings` alongside `entityFields` — entityFields deps still added.
    yield 'entityFields alongside drupalSettings' => [
      ['drupalSettings' => ['v0.pageTitle'], 'entityFields' => ['text' => ['ℹ︎␜entity:user␝name␞␟value']]],
      [
        'module' => ['user'],
        'config' => [$enforced],
      ],
    ];

    // Multiple content-entity-reference props in one component, with one prop
    // using a `FieldObjectPropsExpression` that follows an entity reference
    // (`src↝entity…`) into the referenced `file` entity.
    yield 'multiple entityFields props with follow-reference FieldObjectPropsExpression' => [
      [
        'entityFields' => [
          'suggested_by' => [
            "ℹ︎␜entity:user␝name␞␟value",
          ],
          'highlighted_article' => [
            "ℹ︎␜entity:node:article␝title␞␟value",
            "ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝field_media_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,srcset↠srcset_candidate_uri_template,width↠width}",
          ],
        ],
      ],
      [
        'config' => [
          'field.field.media.image.field_media_image',
          'field.field.node.article.field_media',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'node.type.article',
          $enforced,
        ],
        'module' => [
          'file',
          'media',
          'node',
          'user',
        ],
      ],
    ];

    // Regression: no `entityFields` → nothing beyond the enforced dep.
    yield 'no entityFields (regression)' => [
      [],
      ['config' => [$enforced]],
    ];
  }

  protected function assertValidationErrors(array $expected_messages): void {
    // JsComponentHasValidAndSupportedSdcMetadata adds additional validation, but
    // \Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase::testInvalidMachineNameCharacters()
    // does not provide a way to add additional errors when the machine name is
    // invalid.
    $invalid_id_messages = [
      'machineName' => 'The <em class="placeholder">&quot;' . $this->entity->id() . '&quot;</em> machine name is not valid.',
      '' => "The 'machineName' property cannot be changed.",
    ];
    // 'dash-separated' is valid machine name for component but not for config
    // entity.
    if ($this->entity->id() !== 'dash-separated' && $expected_messages === $invalid_id_messages) {
      $expected_messages[''] = [
        "In component canvas:{$this->entity->id()}:\n[id] Does not match the regex pattern ^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*:[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$\n[machineName] Does not match the regex pattern ^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$",
        $expected_messages[''],
      ];
      // Strip out the prefix added by https://www.drupal.org/node/3549909. This
      // can be removed when 11.3 is the minimum supported version of core.
      if (version_compare(\Drupal::VERSION, '11.3', '<')) {
        $expected_messages[''][0] = substr($expected_messages[''][0], strlen("In component canvas:{$this->entity->id()}:\n"));
      }
    }
    parent::assertValidationErrors($expected_messages);
  }

}
