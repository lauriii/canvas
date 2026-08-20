<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\field\Entity\FieldConfig;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Media Library Hook Storage Prop Alter.
 *
 * @legacy-covers \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter
 * @legacy-covers \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::mediaLibraryFieldWidgetInfoAlter
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
class MediaLibraryHookStoragePropAlterTest extends PropShapeRepositoryTest {

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

    // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::generateSampleValue()
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');

    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget
    $this->installEntitySchema('user');

    // Intentionally do NOT rely on the Standard install profile: the MediaTypes
    // using the Image MediaSource should work.
    // @see core/profiles/standard/config/optional/media.type.image.yml
    // @see \Drupal\media\Plugin\media\Source\Image
    $this->createMediaType('image', ['id' => 'baby_photos']);
    $this->createMediaType('image', ['id' => 'vacation_photos']);
    // Same for the VideoFile, oEmbed and File MediaSources.
    // @see \Drupal\media\Plugin\media\Source\VideoFile
    $this->createMediaType('video_file', ['id' => 'baby_videos']);
    $this->createMediaType('video_file', ['id' => 'vacation_videos']);
    // The `file` media source creates its source field with the default
    // `file_extensions` setting of 'txt doc docx pdf'. Three of those (doc,
    // docx, pdf) intersect the document shape's `x-allowed-file-extensions`
    // list, so this media type matches the document shape with no explicit
    // configuration — even though `txt` falls outside that list, because
    // matching requires intersection, not a subset.
    // @see \Drupal\media\Plugin\media\Source\File::createSourceField()
    // @see \Drupal\canvas\ShapeMatcher\MediaSourceObjectShapes::getMediaTypesForSchemaObject()
    $this->createMediaType('file', ['id' => 'baby_documents']);
    // Unlike `baby_documents`, this media type does not rely on the source
    // field defaults: its single allowed extension appears in the document
    // shape's `x-allowed-file-extensions` list, proving a one-element
    // intersection is enough to match. Contrast with `archives` and
    // `plain_text_notes` below, whose extensions never intersect the list
    // and therefore never match.
    $this->createFileMediaTypeWithExtensions('vacation_documents', 'pdf');
    // AudioFile extends File, but audio media types must not match the
    // document shape: audio file extensions do not intersect the document
    // shape's `x-allowed-file-extensions` list, so this media type must not
    // appear in any expectation.
    // @see \Drupal\media\Plugin\media\Source\AudioFile
    // @see \Drupal\canvas\ShapeMatcher\MediaSourceObjectShapes::getMediaTypesForSchemaObject()
    $this->createMediaType('audio_file', ['id' => 'podcasts']);
    // File media types whose source field extensions do not intersect the
    // document shape's `x-allowed-file-extensions` list must not match the
    // document shape: these media types must not appear in any expectation.
    // @see \Drupal\canvas\ShapeMatcher\MediaSourceObjectShapes::getMediaTypesForSchemaObject()
    $this->createFileMediaTypeWithExtensions('archives', 'zip tar gz');
    $this->createFileMediaTypeWithExtensions('plain_text_notes', 'txt');

    // A sample value is generated during the test, which needs this table.
    $this->installSchema('file', ['file_usage']);

    // @see \Drupal\media_library\MediaLibraryEditorOpener::__construct()
    $this->installEntitySchema('filter_format');
  }

  /**
   * Creates a File media type whose source field allows the given extensions.
   */
  private function createFileMediaTypeWithExtensions(string $id, string $file_extensions): void {
    $media_type = $this->createMediaType('file', ['id' => $id]);
    $source_field_definition = $media_type->getSource()->getSourceFieldDefinition($media_type);
    \assert($source_field_definition instanceof FieldConfig);
    $source_field_definition->setSetting('file_extensions', $file_extensions);
    $source_field_definition->save();
  }

  public static function getExpectedUnstorablePropShapes(): array {
    $unstorable_prop_shapes = parent::getExpectedUnstorablePropShapes();
    unset(
      $unstorable_prop_shapes['type=object&$ref=' . JsonSchemaObjectRef::Video->value],
    );
    return $unstorable_prop_shapes;
  }

  /**
   * @return \Drupal\canvas\PropShape\StorablePropShape[]
   */
  public static function getExpectedStorablePropShapes(): array {
    $storable_prop_shapes = parent::getExpectedStorablePropShapes();
    $image_shapes = array_intersect_key(
      $storable_prop_shapes,
      array_flip([
        'type=object&$ref=' . JsonSchemaObjectRef::Image->value,
        'type=array&items[$ref]=' . JsonSchemaObjectRef::Image->value . '&items[type]=object&minItems=1',
        'type=array&items[$ref]=' . JsonSchemaObjectRef::Image->value . '&items[type]=object&maxItems=2',
      ]),
    );
    foreach ($image_shapes as $k => $image_shape) {
      $storable_prop_shapes[$k] = new StorablePropShape(
        shape: $image_shape->shape,
        cardinality: $image_shape->cardinality,
        fieldWidget: 'media_library_widget',
        // @phpstan-ignore-next-line
        fieldTypeProp: StructuredDataPropExpression::fromString("ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:vacation_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}]"),
        fieldStorageSettings: [
          'target_type' => 'media',
        ],
        fieldInstanceSettings: [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'baby_photos' => 'baby_photos',
              'vacation_photos' => 'vacation_photos',
            ],
          ],
        ],
      );
    }

    $storable_prop_shapes['type=object&$ref=' . JsonSchemaObjectRef::Video->value] = new StorablePropShape(
      shape: JsonSchemaObjectRef::Video->asPropShape(),
      // @phpstan-ignore-next-line
      fieldTypeProp: StructuredDataPropExpression::fromString('ℹ︎entity_reference␟entity␜[␜entity:media:baby_videos␝field_media_video_file␞␟{src↝entity␜␜entity:file␝uri␞␟url}][␜entity:media:vacation_videos␝field_media_video_file_1␞␟{src↝entity␜␜entity:file␝uri␞␟url}]'),
      fieldWidget: 'media_library_widget',
      fieldStorageSettings: [
        'target_type' => 'media',
      ],
      fieldInstanceSettings: [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [
            'baby_videos' => 'baby_videos',
            'vacation_videos' => 'vacation_videos',
          ],
        ],
      ],
    );

    $storable_prop_shapes['type=object&$ref=' . JsonSchemaObjectRef::Document->value] = new StorablePropShape(
      shape: JsonSchemaObjectRef::Document->asPropShape(),
      // @phpstan-ignore-next-line
      fieldTypeProp: StructuredDataPropExpression::fromString('ℹ︎entity_reference␟entity␜[␜entity:media:baby_documents␝field_media_file␞␟{src↝entity␜␜entity:file␝uri␞␟url,filename↝entity␜␜entity:file␝filename␞␟value,filesize↝entity␜␜entity:file␝filesize␞␟value,mimetype↝entity␜␜entity:file␝filemime␞␟value}][␜entity:media:vacation_documents␝field_media_file_1␞␟{src↝entity␜␜entity:file␝uri␞␟url,filename↝entity␜␜entity:file␝filename␞␟value,filesize↝entity␜␜entity:file␝filesize␞␟value,mimetype↝entity␜␜entity:file␝filemime␞␟value}]'),
      fieldWidget: 'media_library_widget',
      fieldStorageSettings: [
        'target_type' => 'media',
      ],
      fieldInstanceSettings: [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [
            'baby_documents' => 'baby_documents',
            'vacation_documents' => 'vacation_documents',
          ],
        ],
      ],
    );

    $storable_prop_shapes['type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri'] = new StorablePropShape(
      shape: new PropShape(['type' => 'string', 'contentMediaType' => 'image/*', 'format' => 'uri', 'x-allowed-schemes' => ['public']]),
      // @phpstan-ignore-next-line
      fieldTypeProp: StructuredDataPropExpression::fromString('ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:vacation_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value]'),
      fieldWidget: 'media_library_widget',
      fieldStorageSettings: [
        'target_type' => 'media',
      ],
      fieldInstanceSettings: [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [
            'baby_photos' => 'baby_photos',
            'vacation_photos' => 'vacation_photos',
          ],
        ],
      ],
    );

    return $storable_prop_shapes;
  }

  /**
   * {@inheritdoc}
   *
   * This test proves an `image`-typed baseline transitions to
   * `entity_reference` once an image MediaType is created. This subclass'
   * ::setUp() already creates image MediaTypes, so that baseline never exists
   * here — the parent class already exercises the behavior in full.
   */
  public function testArrayPropShapeInheritsItemPropShapeCacheTags(): void {
    $this->markTestSkipped('The image MediaTypes created in ::setUp() invalidate this test\'s `image`-typed baseline; the parent class covers it.');
  }

  /**
   * Tests prop shapes yield working static prop sources.
   *
   * @param \Drupal\canvas\PropShape\StorablePropShape[] $storable_prop_shapes
   */
  #[Depends('testStorablePropShapes')]
  public function testPropShapesYieldWorkingStaticPropSources(array $storable_prop_shapes): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);
    parent::testPropShapesYieldWorkingStaticPropSources($storable_prop_shapes);
  }

}
