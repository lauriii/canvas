<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Depends;
use Drupal\canvas\Form\ComponentInstanceForm;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\Core\Form\FormState;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\system\Functional\Form\StubForm;
use Drupal\user\Entity\User;
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

    // A sample value is generated during the test, which needs this table.
    $this->installSchema('file', ['file_usage']);

    // @see \Drupal\media_library\MediaLibraryEditorOpener::__construct()
    $this->installEntitySchema('filter_format');
  }

  public static function getExpectedUnstorablePropShapes(): array {
    $unstorable_prop_shapes = parent::getExpectedUnstorablePropShapes();
    unset(
      $unstorable_prop_shapes['type=object&$ref=json-schema-definitions://canvas.module/video'],
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
        'type=object&$ref=json-schema-definitions://canvas.module/image',
        'type=array&items[$ref]=json-schema-definitions://canvas.module/image&items[type]=object',
        'type=array&items[$ref]=json-schema-definitions://canvas.module/image&items[type]=object&maxItems=2',
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

    $storable_prop_shapes['type=object&$ref=json-schema-definitions://canvas.module/video'] = new StorablePropShape(
      shape: new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/video']),
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
   * Tests prop shapes yield working static prop sources.
   *
   * @param \Drupal\canvas\PropShape\StorablePropShape[] $storable_prop_shapes
   */
  #[Depends('testStorablePropShapes')]
  public function testPropShapesYieldWorkingStaticPropSources(array $storable_prop_shapes): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);
    parent::testPropShapesYieldWorkingStaticPropSources($storable_prop_shapes);
  }

  /**
   * Tests media_library_widget component prop name behavior.
   *
   * @param \Drupal\canvas\PropShape\StorablePropShape[] $storable_prop_shapes
   *
   * @legacy-covers \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetCompleteFormAlter
   */
  #[Depends('testStorablePropShapes')]
  public function testMediaLibraryWidgetComponentPropName(array $storable_prop_shapes): void {
    $this->setUpCurrentUser(permissions: ['access content', 'administer media']);
    $this->assertNotEmpty($storable_prop_shapes);
    foreach ($storable_prop_shapes as $key => $storable_prop_shape) {
      if ($storable_prop_shape->fieldWidget !== 'media_library_widget') {
        continue;
      }

      $prop_source = $storable_prop_shape->toStaticPropSource();
      $widget = $prop_source->getWidget('irrelevant-for-this-test', 'irrelevant-for-this-test', $key, $this->randomString(), $storable_prop_shape->fieldWidget);

      // When the widget is rendered outside the ComponentInstanceForm (e.g.
      // in this test), #component_prop_name must NOT be set.
      // @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetCompleteFormAlter()
      $form = ['#parents' => [$this->randomMachineName()]];
      $form_state = new FormState();
      $form_object = new StubForm('some_id', $form);
      $form_state->setFormObject($form_object);
      $form = $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'some-prop-name', FALSE, User::create([]), $form, $form_state);
      $this->assertArrayNotHasKey('#component_prop_name', $form);

      // When the same widget is rendered inside the ComponentInstanceForm,
      // #component_prop_name MUST be set.
      // Get a widget whose field definition name is 'some-prop-name', so the
      // hook sets #component_prop_name to 'some-prop-name'.
      $widget_for_ci = $prop_source->getWidget('irrelevant-for-this-test', 'irrelevant-for-this-test', 'some-prop-name', $this->randomString(), $storable_prop_shape->fieldWidget);
      $form_ci = ['#parents' => [$this->randomMachineName()]];
      $form_state_ci = new FormState();
      $form_state_ci->setFormObject(new StubForm(ComponentInstanceForm::FORM_ID, $form_ci));
      $form_with_prop_name = $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget_for_ci, 'some-prop-name', FALSE, User::create([]), $form_ci, $form_state_ci);
      $this->assertSame('some-prop-name', $form_with_prop_name['#component_prop_name']);
    }
  }

}
