<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Update;

use Drupal\canvas\Entity\ParametrizedImageStyle;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves that newly created image media source fields do not depend on Canvas.
 */
#[Group('canvas')]
final class ImageMediaSourceFieldConfigDependenciesTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas', 'field']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
  }

  public function test(): void {
    $canvas_image_style = ParametrizedImageStyle::load('canvas_parametrized_width');
    self::assertNotNull($canvas_image_style);
    $image_media_source_field = FieldConfig::load('media.image.field_media_image');
    self::assertNotNull($image_media_source_field);
    self::assertNotContains($canvas_image_style->getConfigDependencyName(), $image_media_source_field->getDependencies()['config']);
  }

}
