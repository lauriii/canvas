<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\PropShape\EphemeralPropShapeRepository;
use Drupal\canvas\PropShape\ObjectPropsStorablePropShape;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\canvas\PropSource\ObjectPropsSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the composite prop source for custom object props ("groups").
 *
 * @see docs/adr/0021-object-props-in-code-components.md
 */
#[CoversClass(ObjectPropsSource::class)]
#[CoversClass(ObjectPropsStorablePropShape::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class ObjectPropsSourceTest extends PropSourceTestBase {

  private const INGREDIENT_SCHEMA = [
    'type' => 'object',
    'properties' => [
      'name' => ['type' => 'string'],
      'amount' => ['type' => 'number'],
      'unit' => ['type' => 'string', 'enum' => ['g', 'ml']],
    ],
    'required' => ['name'],
  ];

  private const AUTHOR_SCHEMA = [
    'type' => 'object',
    'properties' => [
      'name' => ['type' => 'string'],
      'image' => ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image'],
      'link' => ['type' => 'string', 'format' => 'uri'],
    ],
    'required' => ['name'],
  ];

  private function getStorablePropShape(array $schema): ObjectPropsStorablePropShape {
    $repository = $this->container->get(EphemeralPropShapeRepository::class);
    $shape = PropShape::normalize($schema);
    $storable = $repository->getStorablePropShape($shape);
    $this->assertInstanceOf(ObjectPropsStorablePropShape::class, $storable);
    return $storable;
  }

  /**
   * A group of scalars saves and evaluates to one composed object.
   */
  public function testSingleValueGroup(): void {
    $storable = $this->getStorablePropShape(self::INGREDIENT_SCHEMA);
    $this->assertNull($storable->cardinality);
    $this->assertSame(['name', 'amount', 'unit'], \array_keys($storable->subShapes));
    // Each sub-property resolved through the existing shape-to-field-type
    // mapping.
    $this->assertSame('string', $storable->subShapes['name']->fieldTypeProp->getFieldType());
    $this->assertSame('float', $storable->subShapes['amount']->fieldTypeProp->getFieldType());
    $this->assertSame('list_string', $storable->subShapes['unit']->fieldTypeProp->getFieldType());
    $this->assertSame('options_select', $storable->subShapes['unit']->fieldWidget);

    $source = $storable->toObjectPropsSource();
    $this->assertTrue($source->isEmpty());
    $this->assertSame(1, $source->getCardinality());

    $populated = $source->withValue(['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g']);
    // Evaluation produces one object.
    $this->assertSame(['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'], $populated->evaluate(User::create(), is_required: TRUE)->value);
    $this->assertSame(['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'], $populated->getValue());

    // The wire format round-trips.
    $json_representation = (string) $populated;
    $decoded_representation = \json_decode($json_representation, TRUE, flags: \JSON_THROW_ON_ERROR);
    $this->assertSame([
      'sourceType' => 'object-props',
      'value' => ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
      'sources' => [
        'name' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'amount' => [
          'sourceType' => 'static:field_item:float',
          'expression' => 'ℹ︎float␟value',
        ],
        'unit' => [
          'sourceType' => 'static:field_item:list_string',
          'expression' => 'ℹ︎list_string␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
          ],
        ],
      ],
    ], $decoded_representation);
    $parsed = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(ObjectPropsSource::class, $parsed);
    $this->assertSame('object-props', $parsed->getSourceType());
    $this->assertSame(['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'], $parsed->evaluate(User::create(), is_required: TRUE)->value);

    // Partially populated groups omit the empty sub-properties.
    $partial = $source->withValue(['name' => 'Flour', 'amount' => NULL], allow_empty: TRUE);
    $this->assertSame(['name' => 'Flour'], $partial->evaluate(User::create(), is_required: TRUE)->value);

    // A fully empty group evaluates to NULL.
    $empty = $source->withValue(NULL, allow_empty: TRUE);
    $this->assertTrue($empty->isEmpty());
    $this->assertNull($empty->evaluate(User::create(), is_required: FALSE)->value);
    $this->assertNull($empty->getValue());

    // Values for undeclared sub-properties are rejected.
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("'nonexistent' is not a sub-property of this group.");
    $source->withValue(['nonexistent' => 'x']);
  }

  /**
   * A group with an image `$ref` sub-property evaluates a media reference.
   */
  public function testImageSubProperty(): void {
    $this->setUpCurrentUser([], ['view media', 'access content']);
    $storable = $this->getStorablePropShape(self::AUTHOR_SCHEMA);
    // The image sub-property went through the candidate/alter flow: the media
    // library integration applies.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
    $this->assertSame('entity_reference', $storable->subShapes['image']->fieldTypeProp->getFieldType());
    $this->assertSame('media_library_widget', $storable->subShapes['image']->fieldWidget);

    $source = $storable->toObjectPropsSource();
    $populated = $source->withValue([
      'name' => 'Ada',
      'image' => ['target_id' => 1],
      'link' => ['uri' => 'https://example.com/ada'],
    ]);
    $result = $populated->evaluate(User::create(), is_required: TRUE);
    $value = $this->allowSimplifiedExpectations($result)->value;
    $this->assertIsArray($value);
    $this->assertSame('Ada', $value['name']);
    $this->assertSame('https://example.com/ada', $value['link']);
    $this->assertIsArray($value['image']);
    $this->assertSame('An image so amazing that to gaze upon it would melt your face', $value['image']['alt']);
    $this->assertIsString($value['image']['src']);
    $this->assertStringContainsString('image-2.jpg', $value['image']['src']);
    // The referenced entities' cacheability bubbles up.
    $this->assertContains('media:1', $result->getCacheTags());
    $this->assertContains('file:1', $result->getCacheTags());

    // The dependencies are composed from all sub-properties.
    $dependencies = $populated->calculateDependencies();
    $this->assertContains('field.field.media.image.field_media_image', $dependencies['config']);
    $this->assertContains('media:image:' . self::IMAGE_MEDIA_UUID1, $dependencies['content']);
  }

  /**
   * A multi-value group evaluates to an ordered array of objects.
   */
  public function testMultiValueGroup(): void {
    $repository = $this->container->get(EphemeralPropShapeRepository::class);
    $shape = PropShape::normalize([
      'type' => 'array',
      'items' => self::INGREDIENT_SCHEMA,
    ]);
    $storable = $repository->getStorablePropShape($shape);
    $this->assertInstanceOf(ObjectPropsStorablePropShape::class, $storable);
    $this->assertSame(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $storable->cardinality);
    $this->assertSame(['name', 'amount', 'unit'], \array_keys($storable->subShapes));

    // `maxItems` maps to the cardinality.
    $limited = $repository->getStorablePropShape(PropShape::normalize([
      'type' => 'array',
      'items' => self::INGREDIENT_SCHEMA,
      'maxItems' => 3,
    ]));
    $this->assertInstanceOf(ObjectPropsStorablePropShape::class, $limited);
    $this->assertSame(3, $limited->cardinality);

    $source = $storable->toObjectPropsSource();
    $this->assertSame(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $source->getCardinality());
    $this->assertSame([], $source->getValue());

    $populated = $source->withValue([
      ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
      ['name' => 'Milk', 'unit' => 'ml'],
    ]);
    // The authored order is preserved.
    $this->assertSame([
      ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
      ['name' => 'Milk', 'unit' => 'ml'],
    ], $populated->evaluate(User::create(), is_required: TRUE)->value);

    // The wire format round-trips, and carries the cardinality.
    $decoded_representation = \json_decode((string) $populated, TRUE, flags: \JSON_THROW_ON_ERROR);
    $this->assertSame(['cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED], $decoded_representation['sourceTypeSettings']);
    $parsed = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(ObjectPropsSource::class, $parsed);
    $this->assertSame([
      ['name' => 'Flour', 'amount' => 500.5, 'unit' => 'g'],
      ['name' => 'Milk', 'unit' => 'ml'],
    ], $parsed->evaluate(User::create(), is_required: TRUE)->value);

    // A fully empty item is valid, but is dropped from evaluation and storage.
    $with_empty_item = $source->withValue([
      ['name' => 'Flour'],
      [],
      ['name' => 'Milk'],
    ], allow_empty: TRUE);
    $this->assertSame([
      ['name' => 'Flour'],
      ['name' => 'Milk'],
    ], $with_empty_item->evaluate(User::create(), is_required: TRUE)->value);
    $this->assertSame([
      ['name' => 'Flour'],
      ['name' => 'Milk'],
    ], $with_empty_item->getValue());

    // A multi-value group must be populated with a list.
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('A multi-value group must be populated with a list of object values.');
    $source->withValue(['name' => 'Flour']);
  }

  /**
   * Groups inside groups have no storable shape: the 1-level depth limit.
   */
  public function testNestedGroupHasNoStorableShape(): void {
    $repository = $this->container->get(EphemeralPropShapeRepository::class);
    $this->assertNull($repository->getStorablePropShape(PropShape::normalize([
      'type' => 'object',
      'properties' => [
        'inner' => [
          'type' => 'object',
          'properties' => ['x' => ['type' => 'string']],
        ],
      ],
    ])));
    // Formatted text sub-properties are not supported either.
    $this->assertNull($repository->getStorablePropShape(PropShape::normalize([
      'type' => 'object',
      'properties' => [
        'body' => ['type' => 'string', 'contentMediaType' => 'text/html'],
      ],
    ])));
    // A sub-property that is itself storable as a multi-value scalar is fine.
    $storable = $repository->getStorablePropShape(PropShape::normalize([
      'type' => 'object',
      'properties' => [
        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
      ],
    ]));
    $this->assertInstanceOf(ObjectPropsStorablePropShape::class, $storable);
    $this->assertInstanceOf(StorablePropShape::class, $storable->subShapes['tags']);
    $this->assertSame(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $storable->subShapes['tags']->cardinality);
    $source = $storable->toObjectPropsSource();
    $populated = $source->withValue(['tags' => ['a', 'b']]);
    $this->assertSame(['tags' => ['a', 'b']], $populated->evaluate(User::create(), is_required: TRUE)->value);
  }

  /**
   * The object branch composes; unrelated shapes are unaffected.
   */
  public function testComputeStorablePropShapeDirectly(): void {
    $repository = $this->container->get(EphemeralPropShapeRepository::class);
    $shape = PropShape::normalize(self::INGREDIENT_SCHEMA);
    $storable = JsonSchemaType::Object->computeStorablePropShape($shape, $repository);
    $this->assertInstanceOf(ObjectPropsStorablePropShape::class, $storable);
    // Additional keywords on the object shape are not supported.
    $this->assertNull(JsonSchemaType::Object->computeStorablePropShape(PropShape::normalize(
      self::INGREDIENT_SCHEMA + ['additionalProperties' => FALSE],
    ), $repository));
  }

}
