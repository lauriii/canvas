<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\PropShape\StorablePropShape;

/**
 * @covers media_library_storage_prop_shape_alter()
 * @covers media_library_field_widget_info_alter()
 */
class MediaLibraryHookStoragePropAlterTest extends PropShapeRepositoryTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // @see \Drupal\media\Entity\Media
    'media',
    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget
    'media_library',
    // Without this module installed, the `field_media_image` field won't be
    // created, because the FieldConfig entity type would not exist.
    'field',
    // The Media Library widget uses Views.
    'views',
    // @see \Drupal\media_library\MediaLibraryEditorOpener::__construct()
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::generateSampleValue()
    $this->installEntitySchema('media');

    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget
    $this->installEntitySchema('user');

    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->setInstallProfile('standard');
    $this->installConfig(['media']);

    // A sample value is generated during the test, which needs this table.
    $this->installSchema('file', ['file_usage']);

    // @see \Drupal\media_library\MediaLibraryEditorOpener::__construct()
    $this->installEntitySchema('filter_format');
  }

  /**
   * @return \Drupal\experience_builder\PropShape\StorablePropShape[]
   */
  public static function getExpectedStorablePropShapes(): array {
    $storable_prop_shapes = parent::getExpectedStorablePropShapes();
    $storable_prop_shapes['type=object&$ref=json-schema-definitions://experience_builder.module/image'] = new StorablePropShape(
      shape: new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image']),
      fieldWidget: 'media_library_widget',
      // @phpstan-ignore-next-line
      fieldTypeProp: StructuredDataPropExpression::fromString('ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}'),
      fieldStorageSettings: [
        'target_type' => 'media',
      ],
      fieldInstanceSettings: [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [
            'image' => 'image',
          ],
        ],
      ],
    );
    return $storable_prop_shapes;
  }

}
