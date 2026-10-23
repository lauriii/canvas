<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Field\FieldType;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\Plugin\Field\FieldType\FocalPointItem;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * @see \Drupal\canvas\Plugin\Field\FieldType\FocalPointItem
 */
#[CoversClass(FocalPointItem::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
final class FocalPointItemTest extends CanvasKernelTestBase {

  /**
   * Tests that the focal point shape is stored in the focal point field type.
   */
  public function testShapeMatching(): void {
    $repository = $this->container->get(PropShapeRepositoryInterface::class);

    $single = $repository->getStorablePropShape(JsonSchemaObjectRef::FocalPoint->asPropShape());
    self::assertNotNull($single);
    self::assertSame('canvas_focal_point', $single->fieldWidget);
    self::assertSame('ℹ︎canvas_focal_point␟{x↠x,y↠y}', (string) $single->fieldTypeProp);
    self::assertNull($single->cardinality);

    $list = $repository->getStorablePropShape(new PropShape([
      'type' => 'array',
      'items' => JsonSchemaObjectRef::FocalPoint->asPropShapeArray(),
      'maxItems' => 3,
    ]));
    self::assertNotNull($list);
    self::assertSame('canvas_focal_point', $list->fieldWidget);
    self::assertSame(3, $list->cardinality);

  }

  /**
   * Tests validation and emptiness.
   *
   * @param array<string, mixed> $value
   *   The field item value.
   * @param bool $is_empty
   *   Whether the item is empty.
   * @param list<string> $expected_violation_paths
   *   The property paths of the expected violations.
   */
  #[TestWith([['x' => 25, 'y' => 75], FALSE, []])]
  #[TestWith([['x' => 0, 'y' => 100], FALSE, []])]
  #[TestWith([['x' => 100.5, 'y' => -1], FALSE, ['0.x', '0.y']])]
  #[TestWith([['x' => 25, 'y' => NULL], TRUE, []])]
  #[TestWith([['x' => '', 'y' => ''], TRUE, []])]
  public function testValidation(array $value, bool $is_empty, array $expected_violation_paths): void {
    $definition = BaseFieldDefinition::create('canvas_focal_point')
      ->setName('focal_point')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $items = $this->container->get(TypedDataManagerInterface::class)->create($definition, [$value]);
    \assert($items instanceof FieldItemListInterface);

    self::assertSame($is_empty, $items->first()?->isEmpty());
    $violations = $items->validate();
    $paths = [];
    foreach ($violations as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    self::assertSame($expected_violation_paths, $paths);
  }

}
