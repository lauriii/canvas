<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\Labeler;
use Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Unit\PropExpressionTest;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests PropExpression functionality that cannot be tested in a unit test.
 *
 * Gets its test cases from the unit test though, to guarantee completeness of
 * test coverage.
 *
 * @see \Drupal\Tests\canvas\Unit\PropExpressionTest
 * @group canvas
 * @group canvas_data_model
 * @group canvas_data_model__prop_expressions
 */
class PropExpressionKernelTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use EntityReferenceFieldCreationTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  public const NODE_1_UUID = '406ff859-f31b-4247-8b76-56cda80c06b9';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'system',
    'taxonomy',
    'editor',
    'ckeditor5',
    'text',
    'filter',
    'user',
    'file',
    'image',
    'media',
    'media_library',
    'media_test_source',
    'views',
    // Ensure field type overrides are installed and hence testable.
    'canvas',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $configSchemaCheckerExclusions = [
    // @todo Core bug: this is missing config schema: `type: field.storage_settings.file_uri` does not exist! This is being fixed in https://www.drupal.org/project/drupal/issues/3324140.
    'field.storage.node.bar',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');

    $this->installConfig('canvas');

    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->createMediaType('image', ['id' => 'baby_photos', 'label' => 'Baby photos']);
    $this->createMediaType('image', ['id' => 'vacation_photos', 'label' => 'Vacation photos']);
    $this->createMediaType('test', ['id' => 'remote_image', 'label' => 'Remote image']);

    // `article` node type.
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();
    $this->createEntityReferenceField(
      'node',
      'article',
      'field_tags',
      'Tags',
      'taxonomy_term',
      'default',
      ['target_bundles' => ['tags']],
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    );
    FieldStorageConfig::create([
      'field_name' => 'body',
      'type' => 'text',
      'entity_type' => 'node',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
    ])->save();
    $this->createImageField('field_image', 'node', 'article');
    $this->createEntityReferenceField('node', 'article', 'yo_ho', 'Yo Ho', 'media', selection_handler_settings: [
      // @see \Drupal\Tests\canvas\Unit\PropExpressionTest::EXPECTED_YO_HO_FIELD_CONFIG_DEPENDENCIES
      'target_bundles' => [
        'baby_photos',
        'image',
        'remote_image',
        'vacation_photos',
      ],
    ]);

    // `foo` node type.
    NodeType::create([
      'type' => 'foo',
      'name' => 'Foo',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'bar',
      'type' => 'file_uri',
      'entity_type' => 'node',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'bar',
      'entity_type' => 'node',
      'bundle' => 'foo',
      'label' => 'The bar file URI field',
    ])->save();

    // `news` node type.
    NodeType::create([
      'type' => 'news',
      'name' => 'News',
    ])->save();
    $this->createImageField('field_photo', 'node', 'news');

    // `product` node type.
    NodeType::create([
      'type' => 'product',
      'name' => 'Product',
    ])->save();
    // ⚠️ This cannot use ::createImageField(), because that core trait blindly
    // creates a new `FieldStorageConfig`, whereas this one explicitly needs to
    // create multiple field instances (`FieldConfig` config entities) tied to
    // the same field storage (`FieldStorageConfig` config entity).
    FieldConfig::create([
      'field_name' => 'field_photo',
      'label' => 'field_photo',
      'entity_type' => 'node',
      'bundle' => 'product',
      'required' => FALSE,
      'settings' => [],
      'description' => '',
    ])->save();
    $this->createImageField('field_product_packaging_photo', 'node', 'product');

    // Optional, single-cardinality user profile picture field.
    // @see core/profiles/standard/config/install/field.storage.user.user_picture.yml
    FieldStorageConfig::create([
      'entity_type' => 'user',
      'field_name' => 'user_picture',
      'type' => 'image',
      'translatable' => FALSE,
      'cardinality' => 1,
    ])->save();
    // @see core/profiles/standard/config/install/field.field.user.user.user_picture.yml
    FieldConfig::create([
      'label' => 'Picture',
      'description' => '',
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
      'required' => FALSE,
    ])->save();

    User::create([
      'uuid' => 'some-user-uuid',
      'name' => 'user1',
      'mail' => 'user@localhost',
    ])
      ->activate()
      ->save();
    Vocabulary::create(['name' => 'Tags', 'vid' => 'tags'])->save();
    Term::create([
      'uuid' => 'some-term-uuid',
      'name' => 'term1',
      'vid' => 'tags',
    ])->save();
    Term::create([
      'uuid' => 'another-term-uuid',
      'name' => 'term2',
      'vid' => 'tags',
    ])->save();
    $image_file = File::create([
      'uuid' => 'some-image-uuid',
      'uri' => 'public://example.png',
      'filename' => 'example.png',
    ]);
    $image_file->save();
    $another_image_file = File::create([
      'uuid' => 'photo-baby-jack-uuid',
      'uri' => 'public://jack.jpg',
      'filename' => 'jack.jpg',
    ]);
    $another_image_file->save();
    $image_media = Media::create([
      'name' => 'Example image',
      'bundle' => 'image',
      'field_media_image' => $image_file,
      'uuid' => 'some-media-uuid',
    ]);
    $image_media->save();
    $baby_photos_media = Media::create([
      'name' => 'Baby Jack',
      'bundle' => 'baby_photos',
      'field_media_image_1' => $another_image_file,
      'uuid' => 'baby-photos-media-uuid',
    ]);
    $baby_photos_media->save();
    Node::create([
      'uuid' => self::NODE_1_UUID,
      'title' => 'dummy_title',
      'type' => 'article',
      'uid' => 1,
      'body' => [
        'format' => 'plain_text',
        'value' => $this->randomString(),
      ],
      'field_tags' => [
        ['target_id' => 1],
        ['target_id' => 2],
      ],
      'field_image' => [
        [
          'target_id' => 1,
          'alt' => 'test alt',
          'title' => 'test title',
          'width' => 10,
          'height' => 11,
        ],
      ],
      'yo_ho' => [
        'target_id' => $image_media->id(),
      ],
    ])->save();

    // `xyz` node type.
    NodeType::create([
      'type' => 'xyz',
      'name' => 'XYZ',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'abc',
      'type' => 'map',
      'entity_type' => 'node',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'abc',
      'entity_type' => 'node',
      'bundle' => 'xyz',
      'label' => 'The XYZ map field',
    ])->save();

    $this->setUpCurrentUser(permissions: ['access content', 'view media', 'access user profiles']);
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\Labeler
   */
  #[IgnoreDeprecations]
  public function testLabel(): void {
    $labeler = \Drupal::service(Labeler::class);

    $deprecations_for_3563451 = [PropExpressionTest::EXPECT_DEPRECATION_3563451, PropExpressionTest::EXPECT_DEPRECATION_3563451_REFERENCE, PropExpressionTest::EXPECT_DEPRECATION_3563451_OBJECT];

    foreach (PropExpressionTest::provider() as $test_case_label => $case) {
      // Merely the unit test suffices for this expression: testing dependency
      // calculation is pointless, because the update path would have updated
      // this expression.
      if (!empty(array_intersect((array) $case[2], $deprecations_for_3563451))) {
        self::assertCount(3, $case, \sprintf("Test case `%s` tests a deprecated expression. The update path \canvas_post_update_0011_multi_bundle_reference_prop_expressions() guarantees it does not occur in any Component config entity anymore, so drop the additional expectations.", $test_case_label));
        continue;
      }

      $expression = $case[1];
      $test_case_precise_label = \sprintf("%s (%s)", $test_case_label, (string) $expression);
      $expected_expression_label = $case[3];

      try {
        // @phpstan-ignore-next-line argument.type
        $label = $labeler->label($expression, EntityDataDefinition::create('node', 'article'));
        // If a non-existent entity type/bundle/field/field property: not even a
        // label can be generated. An invalid delta is not a problem.
        if ($expected_expression_label instanceof \Throwable) {
          self::fail('Exception expected.');
        }
      }
      catch (\Throwable $e) {
        if ($expected_expression_label instanceof \Throwable) {
          self::assertSame(get_class($expected_expression_label), get_class($e));
          if ($expected_expression_label instanceof \Exception) {
            self::assertSame($expected_expression_label->getMessage(), $e->getMessage(), $test_case_precise_label);
          }
          elseif ($expected_expression_label instanceof \TypeError) {
            // TypeError thrown by Labeler contains line number and file path
            // making this very fragile to test, so we have to check the error
            // message partially.
            self::assertStringContainsString($expected_expression_label->getMessage(), $e->getMessage(), $test_case_precise_label);
          }
          continue;
        }
        self::fail(\sprintf('Unexpected exception `%s` with message `%s for case `%s`.', get_class($e), $e->getMessage(), $test_case_precise_label));
      }
      self::assertSame($expected_expression_label, (string) $label, $test_case_precise_label);
    }
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::calculateDependencies()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression::calculateDependencies()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression::calculateDependencies()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression::calculateDependencies()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::calculateDependencies()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::calculateDependencies()
   */
  #[IgnoreDeprecations]
  public function testCalculateDependencies(): void {
    $host_entity = Node::load(1);

    $deprecations_for_3563451 = [PropExpressionTest::EXPECT_DEPRECATION_3563451, PropExpressionTest::EXPECT_DEPRECATION_3563451_REFERENCE, PropExpressionTest::EXPECT_DEPRECATION_3563451_OBJECT];

    foreach (PropExpressionTest::provider() as $test_case_label => $case) {
      // Merely the unit test suffices for this expression: testing dependency
      // calculation is pointless, because the update path would have updated
      // this expression.
      if (!empty(array_intersect((array) $case[2], $deprecations_for_3563451))) {
        self::assertCount(3, $case, \sprintf("Test case `%s` tests a deprecated expression. The update path \canvas_post_update_0011_multi_bundle_reference_prop_expressions() guarantees it does not occur in any Component config entity anymore, so drop the additional expectations.", $test_case_label));
        continue;
      }

      $expression = $case[1];
      \assert($expression instanceof EntityFieldBasedPropExpressionInterface || $expression instanceof FieldTypeBasedPropExpressionInterface);
      $expected_dependencies = $case[4];
      // Almost always, the content-aware dependencies are the same as the
      // content-unaware ones, just with the `content` key-value pair omitted,
      // if any.
      $expected_content_unaware_dependencies = $case[5] ?? (
        is_array($expected_dependencies)
          ? array_diff_key($expected_dependencies, array_flip(['content']))
          : NULL
      );

      $test_case_precise_label = \sprintf("%s (%s)", $test_case_label, (string) $expression);

      $entity_or_field = match(TRUE) {
        $expression instanceof EntityFieldBasedPropExpressionInterface => $host_entity,
        $expression instanceof FieldTypeBasedPropExpressionInterface => (function () use ($expression) {
          // For reference fields, ::randomizeValue() will point to incorrect
          // entities (defaulting to the `Node` entity type!) unless the storage
          // and instance settings passed to StaticPropSource are correct too.
          $storage_settings = [];
          $instance_settings = [];
          $target_entity_data_definition = NULL;
          if ($expression instanceof ReferencePropExpressionInterface) {
            if (!$expression->targetsMultipleBundles()) {
              \assert($expression->referenced instanceof EntityFieldBasedPropExpressionInterface);
              $target_entity_data_definition = $expression->referenced->getHostEntityDataDefinition();
            }
            else {
              \assert($expression->referenced instanceof ReferencedBundleSpecificBranches);
              $first_branch = array_keys($expression->referenced->bundleSpecificReferencedExpressions)[0];
              // TRICKY: the exact dependencies depend on the bundle of the
              // entity that is referenced. To be able to test this with a
              // single expectation rather than many, this test hardcodes the
              // first branch. In the current test cases, this is always the
              // "baby_photos" MediaType branch.
              \assert($first_branch === 'entity:media:baby_photos');
              $target_entity_data_definition = $expression->referenced
                ->getBranch('media', 'baby_photos')
                ->getHostEntityDataDefinition();
            }
          }
          if ($expression instanceof ObjectPropExpressionInterface && $expression->getFieldType() === 'entity_reference') {
            \assert($expression->objectPropsToFieldTypeProps['src'] instanceof ReferenceFieldTypePropExpression);
            \assert(!$expression->objectPropsToFieldTypeProps['src']->referenced instanceof ReferencedBundleSpecificBranches);
            $target_entity_data_definition = $expression->objectPropsToFieldTypeProps['src']->referenced->getHostEntityDataDefinition();
            \assert($target_entity_data_definition instanceof BetterEntityDataDefinition);
          }

          if ($target_entity_data_definition !== NULL) {
            \assert($target_entity_data_definition instanceof BetterEntityDataDefinition);
            $storage_settings['target_type'] = $target_entity_data_definition->getEntityTypeId();
            $target_bundles = $target_entity_data_definition->getBundles();
            if ($target_bundles) {
              $instance_settings = [
                'handler_settings' => [
                  'target_bundles' => array_combine($target_bundles, $target_bundles),
                ],
              ];
            }
          }

          // 🪄 Conjure a randomly populated prop source to evaluate this
          // expression.
          $field_item_list = StaticPropSource::generate($expression, 1, $storage_settings, $instance_settings)
            ->randomizeValue()->fieldItemList;
          if ($field_item_list instanceof FileFieldItemList) {
            // Ensure that expected content dependencies always use the hardcoded
            // file entity UUID.
            // @see ::setUp()
            \assert($field_item_list[0] instanceof FieldItemInterface);
            $field_item_list[0]->get('target_id')->setValue(1);
          }
          return $field_item_list;
        })(),
      };

      // If a non-existent delta: fails during evaluation, which occurs when
      // calculating dependencies.
      if ($expected_dependencies instanceof \Exception) {
        try {
          $expression->calculateDependencies($entity_or_field);
          self::fail('Exception expected.');
        }
        catch (\Exception $e) {
          self::assertSame(get_class($expected_dependencies), get_class($e));
          self::assertSame($expected_dependencies->getMessage(), $e->getMessage(), $test_case_precise_label);
        }
        continue;
      }

      // When calculating dependencies for a prop expression *with* a valid
      // entity or field item list, all expected dependencies should be present.
      self::assertSame($expected_dependencies, $expression->calculateDependencies($entity_or_field), $test_case_precise_label);

      // When calculating dependencies for a prop expression *without* that, no
      // `content` dependencies (if any) should be present, because it is
      // impossible for just an expression to reference content entities.
      // (This is the case when evaluating for example a prop expression used in
      // a DynamicPropSource in a ContentTemplate: the content template applies
      // to many possible host entities, not any single one, so its
      // DynamicPropSources cannot possibly depend on any content entities.)
      self::assertSame($expected_content_unaware_dependencies, $expression->calculateDependencies(NULL), $test_case_precise_label);
    }
  }

  /**
   * Tests an impossible-to-unit test ReferencedBundleSpecificBranches aspect.
   *
   * (Impossible because checking field cardinality requires services to be
   * available and config entities to be saved. Neither is possible in a unit
   * test, except through mocking. But mocking is brittle, and quickly ends up
   * being stale.)
   *
   * Note this covers both the ReferenceFieldPropExpression and
   * ReferenceFieldTypePropExpression prop expression classes' multi-bundle
   * support, because they both use ReferencedBundleSpecificBranches in exactly
   * the same way.
   *
   * @covers \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches::__construct()
   * @covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression
   * @covers \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression
   * @see \Drupal\Tests\canvas\Unit\PropExpressionTest::testInvalidReferencePropExpressionDueToMismatchedLeafExpressionCardinality()
   */
  public function testInvalidReferencePropExpressionDueToMismatchedLeafExpressionCardinality(): void {
    // @phpstan-ignore method.nonObject
    self::assertSame(1, FieldStorageConfig::load('media.field_media_test')->getCardinality());
    // @phpstan-ignore method.nonObject
    self::assertSame(\SAVED_UPDATED, FieldStorageConfig::load("media.field_media_test")->setCardinality(5)->save());
    // @phpstan-ignore staticMethod.impossibleType
    self::assertSame(5, FieldStorageConfig::load('media.field_media_test')?->getCardinality());

    // @phpstan-ignore method.notFound
    self::assertSame(1, EntityDataDefinition::createFromDataType('entity:file')->getPropertyDefinition('uri')?->getCardinality());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Bundle-specific expressions have inconsistent leaf expressions: they must all must target fields of the same cardinality.');
    new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression('entity_reference', 'entity'),
      referenced: new ReferencedBundleSpecificBranches([
        // Returns a FieldPropExpression with unlimited cardinality.
        'entity:media:baby_photos' => new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'baby_photos'), 'field_media_image_1', NULL, 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
        // Returns a FieldPropExpression with single cardinality.
        'entity:media:remote_image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', ['remote_image']), 'field_media_test', NULL, 'non_existent_computed_property'),
      ]),
    );
  }

}
