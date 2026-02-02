<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;
use Drupal\media\Entity\MediaType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * @covers \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
 * @covers \Drupal\canvas\CanvasConfigUpdater::expressionUsesDeprecatedReference()
 * @covers \Drupal\canvas\CanvasConfigUpdater::needsMultiBundleReferencePropExpressionUpdate()
 * @covers \Drupal\canvas\CanvasConfigUpdater::updateMultiBundleReferencePropExpressionToMultiBranch()
 * @group canvas
 * @group canvas_data_model__prop_expressions
 * @group legacy
 */
final class ComponentMultiBundleReferencePropExpressionsUpdateTest extends CanvasUpdatePathTestBase {

  use MediaTypeCreationTrait;

  public const string EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_EXPRESSION = 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}';
  public const string EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_VERSION = 'cc9b97c9370aabdf';
  public const string EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_EXPRESSION = 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:baby_photos|image␝field_media_image_1|field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:baby_photos|image␝field_media_image_1|field_media_image␞␟alt,width↝entity␜␜entity:media:baby_photos|image␝field_media_image_1|field_media_image␞␟width,height↝entity␜␜entity:media:baby_photos|image␝field_media_image_1|field_media_image␞␟height}';
  public const string EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_VERSION = '730a286bcf800bb8';

  public const string EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_EXPRESSION = 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value';
  public const string EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_VERSION = '706b9870ca19466e';
  public const string EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_EXPRESSION = 'ℹ︎entity_reference␟entity␜␜entity:media:baby_photos|image␝field_media_image_1|field_media_image␞␟entity␜␜entity:file␝uri␞␟value';
  public const string EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_VERSION = '62229f4bf5a5c039';

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  private function assertExpectedVersionsExpression(string $component_id, string $prop_name, string $expected_expression): void {
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    foreach ($component->getVersions() as $version) {
      $component->loadVersion($version);
      self::assertSame($expected_expression, $component->getSettings()['prop_field_definitions'][$prop_name]['expression'], \sprintf("Expected expression not found for version %s", $version));
    }
  }

  private function assertExpectedVersions(string $component_id, array $versions): void {
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    self::assertSame($versions, $component->getVersions(), $component_id);
  }

  /**
   * The scenario where an "image" shape is populated by a single media type.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   *
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::needsLiftedReferencePropExpressionUpdate()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::liftReferenceAndCreateBranchesIfNeeded()
   *
   * Tests the 3rd case described in
   *
   * @see \Drupal\canvas\CanvasConfigUpdater::expressionUsesDeprecatedReference()
   */
  public function testSingleImageMediaType(): void {
    $this->doTest([
      // Updated.
      'sdc.canvas_test_sdc.image' => [
        'props' => [
          'image' => [
            'before' => self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_EXPRESSION,
            'after' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
        'versions' => [
          'before' => [
            self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_VERSION,
          ],
          'after' => [
            // New versions: one for each upgrade path that runs.
            'fb40be57bd7e0973',
            'abadf2538ecfdecc',
            // Same as before.
            self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_VERSION,
          ],
        ],
      ],
      // Unchanged, because single-bundle reference.
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'props' => [
          'src' => [
            'before' => self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_EXPRESSION,
            'after' => self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_EXPRESSION,
          ],
        ],
        'versions' => [
          'before' => [
            self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_VERSION,
          ],
          'after' => [
            // New versions: one for each upgrade path that runs.
            '723c5fa9bf0bb82a',
            '901a231a79aad2cd',
            // Same as before.
            self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_VERSION,
          ],
        ],
      ],
    ]);
  }

  /**
   * The scenario where an "image" shape is populated by a single media type.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   *
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::needsMultiBundleReferencePropExpressionUpdate()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::liftReferenceAndCreateBranchesIfNeeded()
   *
   * Tests the 1st and 2nd case described in
   *
   * @see \Drupal\canvas\CanvasConfigUpdater::expressionUsesDeprecatedReference()
   *
   * TRICKY: testing with a non-image media type to allow testing the
   * obsoleteness of SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP is not possible,
   * because the Media Library module's hook_canvas_storable_prop_shape_alter()
   * implementation would undo it. Hence that must be tested in a unit test.
   *
   * @see \Drupal\Tests\canvas\Unit\PropExpressionTest::testUpdatePathFor3563451
   */
  public function testMultipleImageMediaTypes(): void {
    // The database test fixture can only simulate one reality. It simulates the
    // reality where only a single "image" media type exists.
    // @see ::testSingleBundle()

    // Verify initial state: single-bundle image media type expressions.
    $sdc_with_image_object_prop_shape = Component::load('sdc.canvas_test_sdc.image');
    self::assertNotNull($sdc_with_image_object_prop_shape);
    self::assertSame([self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_VERSION], $sdc_with_image_object_prop_shape->getVersions());
    self::assertSame(self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_EXPRESSION, $sdc_with_image_object_prop_shape->loadVersion(self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_VERSION)->getSettings()['prop_field_definitions']['image']['expression']);
    $sdc_with_image_uri_shape = Component::load('sdc.canvas_test_sdc.card-with-stream-wrapper-image');
    self::assertNotNull($sdc_with_image_uri_shape);
    self::assertSame([self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_VERSION], $sdc_with_image_uri_shape->getVersions());
    self::assertSame(self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_EXPRESSION, $sdc_with_image_uri_shape->loadVersion(self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_VERSION)->getSettings()['prop_field_definitions']['src']['expression']);

    // To test a multi-bundle scenario, create a second "image" media type, and
    // update both test SDCs to use multi-bundle expressions as they would have
    // been created up to and including Canvas 1.0.3.
    // @see https://www.drupal.org/project/canvas/releases/1.0.3
    // @see ::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_EXPRESSION
    // @see ::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_EXPRESSION
    self::assertSame(['image'], array_keys(MediaType::loadMultiple()));
    $this->createMediaType('image', ['id' => 'baby_photos']);
    self::assertSame(['baby_photos', 'image'], array_keys(MediaType::loadMultiple()));
    $target_bundles_setting = [
      'baby_photos' => 'baby_photos',
      'image' => 'image',
    ];
    // Generate new version of the image object component that is multi-bundle.
    $settings = $sdc_with_image_object_prop_shape->getSettings();
    $settings['prop_field_definitions']['image']['field_instance_settings']['handler_settings']['target_bundles'] = $target_bundles_setting;
    $settings['prop_field_definitions']['image']['expression'] = self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_EXPRESSION;
    $sdc_with_image_object_prop_shape->createVersion(self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_VERSION)
      ->setSettings($settings)
      // Pretend the single-bundle version never existed, to avoid making the
      // update path test unnecessarily complicated.
      ->deleteVersion(self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_OBJECT_VERSION)
      // Update Component config entity without executing update path, to allow
      // testing the update path.
      ->setSyncing(TRUE)
      ->save();
    self::assertActiveVersionIsValid($sdc_with_image_object_prop_shape);

    // Generate new version of the image URI component that is multi-bundle.
    $settings = $sdc_with_image_uri_shape->getSettings();
    $settings['prop_field_definitions']['src']['field_instance_settings']['handler_settings']['target_bundles'] = $target_bundles_setting;
    $settings['prop_field_definitions']['src']['expression'] = self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_EXPRESSION;
    $sdc_with_image_uri_shape->createVersion(self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_VERSION)
      ->setSettings($settings)
      // Pretend the single-bundle version never existed, to avoid making the
      // update path test unnecessarily complicated.
      ->deleteVersion(self::EXPECTED_ORIGINAL_SINGLE_BUNDLE_IMAGE_URI_VERSION)
      // Update Component config entity without executing update path, to allow
      // testing the update path.
      ->setSyncing(TRUE)
      ->save();
    self::assertActiveVersionIsValid($sdc_with_image_uri_shape);

    // Verify before-update-path state: multi-bundle expressions.
    $sdc_with_image_object_prop_shape = Component::load('sdc.canvas_test_sdc.image');
    self::assertNotNull($sdc_with_image_object_prop_shape);
    self::assertSame([self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_VERSION], $sdc_with_image_object_prop_shape->getVersions());
    self::assertSame(self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_EXPRESSION, $sdc_with_image_object_prop_shape->loadVersion(self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_VERSION)->getSettings()['prop_field_definitions']['image']['expression']);
    $sdc_with_image_uri_shape = Component::load('sdc.canvas_test_sdc.card-with-stream-wrapper-image');
    self::assertNotNull($sdc_with_image_uri_shape);
    self::assertSame([self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_VERSION], $sdc_with_image_uri_shape->getVersions());
    self::assertSame(self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_EXPRESSION, $sdc_with_image_uri_shape->loadVersion(self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_VERSION)->getSettings()['prop_field_definitions']['src']['expression']);

    $this->doTest([
      // Updated, because multi-bundle.
      'sdc.canvas_test_sdc.image' => [
        'props' => [
          'image' => [
            'before' => self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_EXPRESSION,
            'after' => 'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}]',
          ],
        ],
        'versions' => [
          'before' => [
            self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_VERSION,
          ],
          'after' => [
            // New versions: one for each upgrade path that runs.
            '12f14a4fa751351b',
            '7a75ed7de2f24655',
            // Same as before.
            self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_OBJECT_VERSION,
          ],
        ],
      ],
      // Updated, because multi-bundle.
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'props' => [
          'src' => [
            'before' => self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_EXPRESSION,
            'after' => 'ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value]',
          ],
        ],
        'versions' => [
          'before' => [
            self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_VERSION,
          ],
          'after' => [
            // New versions: one for each upgrade path that runs.
            'd3e09133670673f4',
            '7d918190525c0c69',
            // Same as before.
            self::EXPECTED_ORIGINAL_MULTI_BUNDLE_IMAGE_URI_VERSION,
          ],
        ],
      ],
    ]);
  }

  /**
   * @param non-empty-array $component_ids
   *   The before vs after update path expectations.
   */
  private function doTest(array $component_ids): void {
    foreach ($component_ids as $component_id => $component_data) {
      self::assertExpectedVersions($component_id, $component_data['versions']['before']);
      foreach ($component_data['props'] as $prop_name => $expressions) {
        self::assertExpectedVersionsExpression($component_id, $prop_name, $expressions['before']);
      }
    }

    $this->runUpdates();

    foreach ($component_ids as $component_id => $component_data) {
      self::assertExpectedVersions($component_id, $component_data['versions']['after']);
      foreach ($component_data['props'] as $prop_name => $expressions) {
        self::assertExpectedVersionsExpression($component_id, $prop_name, $expressions['after']);
      }
      $updated_component = Component::load($component_id);
      self::assertNotNull($updated_component);
      self::assertEntityIsValid($updated_component);
    }
  }

  private static function assertActiveVersionIsValid(Component $component): void {
    $violations = iterator_to_array($component->getTypedData()->validate());
    $property_paths = array_map(fn (ConstraintViolationInterface $v) => $v->getPropertyPath(), $violations);
    $violations_by_property_path = array_combine($property_paths, $violations);
    if (array_key_exists('active_version', $violations_by_property_path)) {
      self::fail(\sprintf('The active version `%s` is invalid: %s',
        $component->getActiveVersion(),
        $violations_by_property_path['active_version']->getMessage(),
      ));
    }
    self::assertArrayNotHasKey('active_version', $violations_by_property_path);
  }

}
