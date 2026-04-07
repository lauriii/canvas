<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Base class for PropSource tests.
 *
 * @see \Drupal\canvas\PropSource\PropSource
 */
abstract class PropSourceTestBase extends CanvasKernelTestBase {

  protected const FILE_UUID1 = 'a461c159-039a-4de2-96e5-07d1112105df';
  protected const FILE_UUID2 = '792ea357-71d6-45fa-a12b-78d029edbe4c';
  protected const IMAGE_MEDIA_UUID1 = '83b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  protected const IMAGE_MEDIA_UUID2 = '93b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  protected const TEST_MEDIA = '43b145bb-d8c3-4410-bbd6-fdcd06e27c29';

  use ContentTypeCreationTrait;
  use EntityReferenceFieldCreationTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;
  use NodeCreationTrait;
  use PredictableImageStyleItokTestTrait;
  use UserCreationTrait;
  use TestFileCreationTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'datetime_range',
    'media_test_source',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');

    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('image', ['id' => 'anything_is_possible']);
    // @see \Drupal\media_test_source\Plugin\media\Source\Test
    $this->createMediaType('test', ['id' => 'image_but_not_image_media_source']);

    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $file_uri = 'public://image-2.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file1 = File::create([
      'uuid' => self::FILE_UUID1,
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file1->save();
    $file_uri = 'public://image-3.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-3.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file2 = File::create([
      'uuid' => self::FILE_UUID2,
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file2->save();
    $this->installEntitySchema('media');
    $image1 = Media::create([
      'uuid' => self::IMAGE_MEDIA_UUID1,
      'bundle' => 'image',
      'name' => 'Amazing image',
      'field_media_image' => [
        [
          'target_id' => $file1->id(),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'title' => 'This is an amazing image, just look at it and you will be amazed',
        ],
      ],
    ]);
    $image1->save();
    $image2 = Media::create([
      'uuid' => self::IMAGE_MEDIA_UUID2,
      'bundle' => 'anything_is_possible',
      'name' => 'amazing',
      'field_media_image_1' => [
        [
          'target_id' => $file2->id(),
          'alt' => 'amazing',
          'title' => 'amazing',
        ],
      ],
    ]);
    $image2->save();
    $test_media = Media::create([
      'uuid' => self::TEST_MEDIA,
      'bundle' => 'image_but_not_image_media_source',
      'name' => 'contrived example',
      'field_media_test' => [
        'value' => 'Jack is awesome!',
      ],
    ]);
    $test_media->save();
    $this->setupPredictableItok();
  }

  protected function allowSimplifiedExpectations(EvaluationResult $actual_result): EvaluationResult {
    return new EvaluationResult(
      // Simplified result to allow simplified test expectations.
      value: $this->recursivelyReplaceStrings($actual_result->value, [
        \base_path() . $this->siteDirectory => '::SITE_DIR_BASE_URL::',
      ]),
      // Unchanged cacheability.
      cacheability: $actual_result,
    );
  }

  protected function recursivelyReplaceStrings(mixed $value, array $string_replacements): mixed {
    // Recurse.
    if (\is_array($value)) {
      return \array_map(
        fn (mixed $v) => $this->recursivelyReplaceStrings($v, $string_replacements),
        $value,
      );
    }
    // Nothing to do.
    if (!\is_string($value)) {
      return $value;
    }
    return str_replace(
      \array_keys($string_replacements),
      array_values($string_replacements),
      $value
    );
  }

}
