<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\DataType;

use Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString;
use Drupal\canvas\PropSource\FieldStorageDefinition;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\Entity\File;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(ComputedUrlWithQueryString::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class ComputedUrlWithQueryStringTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
  }

  /**
   * A deleted File entity degrades gracefully and bubbles cacheability.
   */
  public function testHandlesBrokenReference(): void {
    $file = File::create(['uri' => 'public://image-2.jpg', 'status' => 1]);
    $file->save();
    $file_cache_tags = $file->getCacheTags();
    $file->delete();

    $field_item_list = \Drupal::typedDataManager()->create(
      FieldStorageDefinition::create('image'),
    );
    \assert($field_item_list instanceof FieldItemListInterface);
    $field_item_list->setValue([['target_id' => $file->id()]]);

    $image_item = $field_item_list->get(0);
    self::assertNotNull($image_item);

    // Accessing the computed property must NOT throw an AssertionError. The
    // returned GeneratedUrl should be empty (no URL to generate) rather than
    // causing a fatal.
    /** @var \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString $computed */
    $computed = $image_item->get('src_with_alternate_widths');
    self::assertInstanceOf(ComputedUrlWithQueryString::class, $computed);
    self::assertSame('', $computed->getValue()->getGeneratedUrl());
    self::assertSame($file_cache_tags, $computed->getValue()->getCacheTags());
  }

}
