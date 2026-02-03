<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\Plugin\Adapter\UnixTimestampToDateAdapter;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\BooleanCheckboxWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\File\FileExists;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDatelistWidget;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\AdaptedPropSource;
use Drupal\canvas\PropSource\DefaultRelativeUrlPropSource;
use Drupal\canvas\PropSource\DynamicPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * @coversDefaultClass \Drupal\canvas\PropSource\PropSource
 * @group canvas
 * @group canvas_component_sources
 * @group canvas_data_model
 * @group canvas_data_model__prop_expressions
 */
class PropSourceTest extends KernelTestBase {

  private const FILE_UUID1 = 'a461c159-039a-4de2-96e5-07d1112105df';
  private const FILE_UUID2 = '792ea357-71d6-45fa-a12b-78d029edbe4c';
  private const IMAGE_MEDIA_UUID1 = '83b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  private const IMAGE_MEDIA_UUID2 = '93b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  private const TEST_MEDIA = '43b145bb-d8c3-4410-bbd6-fdcd06e27c29';

  use ContentTypeCreationTrait;
  use ContribStrictConfigSchemaTestTrait;
  use EntityReferenceFieldCreationTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;
  use NodeCreationTrait;
  use UserCreationTrait;
  use TestFileCreationTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'field',
    'file',
    'image',
    'node',
    'user',
    'datetime',
    'datetime_range',
    'media',
    'media_library',
    'media_test_source',
    'system',
    'media',
    'views',
    'filter',
    'ckeditor5',
    'editor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('canvas');
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    $this->installEntitySchema('media');

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

    // Fixate the private key & hash salt to get predictable `itok`.
    $this->container->get('state')->set('system.private_key', 'dynamic_image_style_private_key');
    $settings_class = new \ReflectionClass(Settings::class);
    $instance_property = $settings_class->getProperty('instance');
    $settings = new Settings([
      'hash_salt' => 'dynamic_image_style_hash_salt',
    ]);
    $instance_property->setValue(NULL, $settings);
  }

  private function allowSimplifiedExpectations(EvaluationResult $actual_result): EvaluationResult {
    return new EvaluationResult(
      // Simplified result to allow simplified test expectations.
      value: $this->recursivelyReplaceStrings($actual_result->value, [
        \base_path() . $this->siteDirectory => '::SITE_DIR_BASE_URL::',
      ]),
      // Unchanged cacheability.
      cacheability: $actual_result,
    );
  }

  private function recursivelyReplaceStrings(mixed $value, array $string_replacements): mixed {
    // Recurse.
    if (is_array($value)) {
      return array_map(
        fn (mixed $v) => $this->recursivelyReplaceStrings($v, $string_replacements),
        $value,
      );
    }
    // Nothing to do.
    if (!is_string($value)) {
      return $value;
    }
    return str_replace(
      array_keys($string_replacements),
      array_values($string_replacements),
      $value
    );
  }

  /**
   * @coversClass \Drupal\canvas\PropSource\StaticPropSource
   * @dataProvider providerStaticPropSource
   */
  public function testStaticPropSource(
    string $sourceType,
    array|null $sourceTypeSettings,
    mixed $value,
    string $expression,
    array $expected_array_representation,
    array|null $field_widgets,
    mixed $expected_user_value,
    CacheableMetadata $expected_cacheability,
    string $expected_prop_expression,
    array $expected_dependencies,
    array $permissions = [],
  ): void {
    $this->setUpCurrentUser([], $permissions);
    $prop_source_example = StaticPropSource::parse([
      'sourceType' => $sourceType,
      'value' => $value,
      'expression' => $expression,
      'sourceTypeSettings' => $sourceTypeSettings,
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $prop_source_example;
    $decoded_representation = json_decode($json_representation, TRUE);
    $this->assertSame($expected_array_representation, $decoded_representation);
    // @phpstan-ignore argument.type
    $prop_source_example = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(StaticPropSource::class, $prop_source_example);
    // The contained information read back out.
    $this->assertSame($sourceType, $prop_source_example->getSourceType());
    /** @var class-string $expected_prop_expression */
    $this->assertInstanceOf($expected_prop_expression, StructuredDataPropExpression::fromString($prop_source_example->asChoice()));
    self::assertSame($expected_dependencies, $prop_source_example->calculateDependencies());
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\canvas\Entity\Component::$defaults
    \assert(is_array($field_widgets));
    // Ensure we always test the default widget.
    \assert(isset($field_widgets[NULL]));
    // Ensure an unknown widget type is handled gracefully.
    $field_widgets['not_real'] = $field_widgets[NULL];
    foreach ($field_widgets as $widget_type => $expected_widget_class) {
      $this->assertInstanceOf($expected_widget_class, $prop_source_example->getWidget('irrelevant-for-test', 'irrelevant-for-test', 'irrelevant-for-test', $this->randomString(), $widget_type));
    }
    if (NULL === $value) {
      $this->assertNull($expected_user_value);
      // Do not continue testing if there is no values.
      return;
    }

    try {
      // @phpstan-ignore argument.type
      StaticPropSource::isMinimalRepresentation($decoded_representation);
    }
    catch (\LogicException) {
      $this->fail("Not a minimal representation: $json_representation.");
    }
    $this->assertSame($value, $prop_source_example->getValue());
    // Test the functionality of a StaticPropSource:
    // - evaluate it to populate an SDC prop
    if (isset($expected_user_value['src'])) {
      // Make it easier to write expectations containing root-relative URLs
      // pointing somewhere into the site-specific directory.
      $expected_user_value['src'] = str_replace('::SITE_DIR_BASE_URL::', \base_path() . $this->siteDirectory, $expected_user_value['src']);
      $expected_user_value['src'] = str_replace(UrlHelper::encodePath('::SITE_DIR_BASE_URL::'), UrlHelper::encodePath(\base_path() . $this->siteDirectory), $expected_user_value['src']);
    }
    if (is_array($expected_user_value) && array_is_list($expected_user_value)) {
      foreach (array_keys($expected_user_value) as $i) {
        if (isset($expected_user_value[$i]['src'])) {
          // Make it easier to write expectations containing root-relative URLs
          // pointing somewhere into the site-specific directory.
          $expected_user_value[$i]['src'] = str_replace('::SITE_DIR_BASE_URL::', \base_path() . $this->siteDirectory, $expected_user_value[$i]['src']);
          $expected_user_value[$i]['src'] = str_replace(UrlHelper::encodePath('::SITE_DIR_BASE_URL::'), UrlHelper::encodePath(\base_path() . $this->siteDirectory), $expected_user_value[$i]['src']);
        }
      }
    }
    $evaluation_result = $prop_source_example->evaluate(User::create(), is_required: TRUE);
    self::assertSame($expected_user_value, $evaluation_result->value);
    self::assertEqualsCanonicalizing($expected_cacheability->getCacheTags(), $evaluation_result->getCacheTags());
    self::assertEqualsCanonicalizing($expected_cacheability->getCacheContexts(), $evaluation_result->getCacheContexts());
    self::assertSame($expected_cacheability->getCacheMaxAge(), $evaluation_result->getCacheMaxAge());
    // - the field type's item's raw value is minimized if it is single-property
    $this->assertSame($value, $prop_source_example->getValue());
  }

  public static function providerStaticPropSource(): \Generator {
    $permanent_cacheability = new CacheableMetadata();
    yield "scalar shape, field type=string, cardinality=1" => [
      'sourceType' => 'static:field_item:string',
      'sourceTypeSettings' => NULL,
      'value' => 'Hello, world!',
      'expression' => 'ℹ︎string␟value',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:string',
        'value' => 'Hello, world!',
        'expression' => 'ℹ︎string␟value',
      ],
      'field_widgets' => [
        NULL => StringTextfieldWidget::class,
        'string_textfield' => StringTextfieldWidget::class,
        'string_textarea' => StringTextfieldWidget::class,
      ],
      'expected_user_value' => 'Hello, world!',
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => FieldTypePropExpression::class,
      'expected_dependencies' => [],
    ];
    yield "scalar shape, field type=uri, cardinality=1" => [
      'sourceType' => 'static:field_item:uri',
      'sourceTypeSettings' => NULL,
      'value' => 'https://drupal.org',
      'expression' => 'ℹ︎uri␟value',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:uri',
        'value' => 'https://drupal.org',
        'expression' => 'ℹ︎uri␟value',
      ],
      'field_widgets' => [
        NULL => UriWidget::class,
        'uri' => UriWidget::class,
      ],
      'expected_user_value' => 'https://drupal.org',
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => FieldTypePropExpression::class,
      'expected_dependencies' => [],
    ];
    yield "scalar shape, field type=boolean, cardinality=1" => [
      'sourceType' => 'static:field_item:boolean',
      'sourceTypeSettings' => NULL,
      'value' => TRUE,
      'expression' => 'ℹ︎boolean␟value',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:boolean',
        'value' => TRUE,
        'expression' => 'ℹ︎boolean␟value',
      ],
      'field_widgets' => [
        NULL => BooleanCheckboxWidget::class,
        'boolean_checkbox' => BooleanCheckboxWidget::class,
      ],
      'expected_user_value' => TRUE,
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => FieldTypePropExpression::class,
      'expected_dependencies' => [],
    ];
    // A simple (expression targeting a simple prop) array example (with
    // cardinality specified, rather than the default of `cardinality=1`).
    yield "scalar shape, field type=integer, cardinality=5" => [
      'sourceType' => 'static:field_item:integer',
      'sourceTypeSettings' => [
        'cardinality' => 5,
      ],
      'value' => [
        20,
        06,
        1,
        88,
        92,
      ],
      'expression' => 'ℹ︎integer␟value',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:integer',
        'value' => [20, 6, 1, 88, 92],
        'expression' => 'ℹ︎integer␟value',
        'sourceTypeSettings' => ['cardinality' => 5],
      ],
      'field_widgets' => [
        NULL => NumberWidget::class,
        'number' => NumberWidget::class,
      ],
      'expected_user_value' => [
        20,
        06,
        1,
        88,
        92,
      ],
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => FieldTypePropExpression::class,
      'expected_dependencies' => [],
    ];
    yield "object shape, daterange field, cardinality=1" => [
      'sourceType' => 'static:field_item:daterange',
      'sourceTypeSettings' => NULL,
      'value' => [
        'value' => '2020-04-16T00:00',
        'end_value' => '2024-07-10T10:24',
      ],
      'expression' => 'ℹ︎daterange␟{start↠value,stop↠end_value}',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:daterange',
        'value' => [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-07-10T10:24',
        ],
        'expression' => 'ℹ︎daterange␟{start↠value,stop↠end_value}',
      ],
      'field_widgets' => [
        NULL => DateRangeDefaultWidget::class,
        'daterange_default' => DateRangeDefaultWidget::class,
        'daterange_datelist' => DateRangeDatelistWidget::class,
      ],
      'expected_user_value' => [
        'start' => '2020-04-16T00:00',
        'stop' => '2024-07-10T10:24',
      ],
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => FieldTypeObjectPropsExpression::class,
      'expected_dependencies' => [
        'module' => [
          'datetime_range',
        ],
      ],
    ];
    // A complex (expression targeting multiple props) array example (with
    // cardinality specified, rather than the default of `cardinality=1`).
    yield "object shape, daterange field, cardinality=UNLIMITED" => [
      'sourceType' => 'static:field_item:daterange',
      'sourceTypeSettings' => [
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      'value' => [
        [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-07-10T10:24',
        ],
        [
          'value' => '2020-04-16T00:00',
          'end_value' => '2024-09-26T11:31',
        ],
      ],
      'expression' => 'ℹ︎daterange␟{start↠value,stop↠end_value}',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:daterange',
        'value' => [
          [
            'value' => '2020-04-16T00:00',
            'end_value' => '2024-07-10T10:24',
          ],
          [
            'value' => '2020-04-16T00:00',
            'end_value' => '2024-09-26T11:31',
          ],
        ],
        'expression' => 'ℹ︎daterange␟{start↠value,stop↠end_value}',
        'sourceTypeSettings' => [
          'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        ],
      ],
      'field_widgets' => [
        NULL => DateRangeDefaultWidget::class,
        'daterange_default' => DateRangeDefaultWidget::class,
        'daterange_datelist' => DateRangeDatelistWidget::class,
      ],
      'expected_user_value' => [
        [
          'start' => '2020-04-16T00:00',
          'stop' => '2024-07-10T10:24',
        ],
        [
          'start' => '2020-04-16T00:00',
          'stop' => '2024-09-26T11:31',
        ],
      ],
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => FieldTypeObjectPropsExpression::class,
      'expected_dependencies' => [
        'module' => [
          'datetime_range',
        ],
      ],
    ];
    yield "complex empty example with entity_reference" => [
      'sourceType' => 'static:field_item:entity_reference',
      'sourceTypeSettings' => [
        'storage' => ['target_type' => 'media'],
        'instance' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => ['image' => 'image'],
          ],
        ],
      ],
      'value' => NULL,
      // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
      'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:entity_reference',
        'value' => NULL,
        'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
        'sourceTypeSettings' => [
          'storage' => ['target_type' => 'media'],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => ['image' => 'image'],
            ],
          ],
        ],
      ],
      'field_widgets' => [
        NULL => EntityReferenceAutocompleteWidget::class,
        'media_library_widget' => MediaLibraryWidget::class,
      ],
      'expected_user_value' => NULL,
      // A (dangling) reference field that doesn't reference anything never
      // becomes stale.
      'expected_cacheability' => $permanent_cacheability,
      'expected_prop_expression' => ReferenceFieldTypePropExpression::class,
      'expected_dependencies' => [
        'config' => [
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
        ],
        'content' => [],
        'module' => [
          'file',
          'media',
        ],
      ],
    ];

    yield "complex non-empty example with entity_reference and multiple target bundles but same field name" => [
      'sourceType' => 'static:field_item:entity_reference',
      'sourceTypeSettings' => [
        'cardinality' => 5,
        'storage' => ['target_type' => 'media'],
        'instance' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'image' => 'image',
              'anything_is_possible' => 'anything_is_possible',
              'image_but_not_image_media_source' => 'image_but_not_image_media_source',
            ],
          ],
        ],
      ],
      'value' => [['target_id' => 2], ['target_id' => 1], ['target_id' => 3]],
      'expression' => 'ℹ︎entity_reference␟entity␜[␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟{src↠value}]',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:entity_reference',
        'value' => [
          ['target_id' => 2],
          ['target_id' => 1],
          ['target_id' => 3],
        ],
        'expression' => 'ℹ︎entity_reference␟entity␜[␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟{src↠value}]',
        'sourceTypeSettings' => [
          'storage' => ['target_type' => 'media'],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => [
                'image' => 'image',
                'anything_is_possible' => 'anything_is_possible',
                'image_but_not_image_media_source' => 'image_but_not_image_media_source',
              ],
            ],
          ],
          'cardinality' => 5,
        ],
      ],
      'field_widgets' => [
        NULL => EntityReferenceAutocompleteWidget::class,
        'media_library_widget' => MediaLibraryWidget::class,
      ],
      'expected_user_value' => [
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-3.jpg.avif?itok=ZZaCKDGa'),
          'alt' => 'amazing',
          'width' => 80,
          'height' => 60,
        ],
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=XYZlDjzC'),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'width' => 80,
          'height' => 60,
        ],
        [
          'src' => 'Jack is awesome!',
        ],
      ],
      'expected_cacheability' => (new CacheableMetadata())
        ->setCacheTags([
          'media:1', 'media:2', 'media:3',
          'file:1', 'file:2',
          'config:image.style.canvas_parametrized_width',
        ])
        // Cache contexts added by referenced entity access checking.
        // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
        ->setCacheContexts(['user.permissions']),
      'expected_prop_expression' => ReferenceFieldTypePropExpression::class,
      'expected_dependencies' => [
        'config' => [
          'field.field.media.anything_is_possible.field_media_image_1',
          'field.field.media.image.field_media_image',
          'field.field.media.image_but_not_image_media_source.field_media_test',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'media.type.image',
          'media.type.image_but_not_image_media_source',
        ],
        'content' => [
          'file:file:' . self::FILE_UUID2,
          'file:file:' . self::FILE_UUID1,
          'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
          'media:image:' . self::IMAGE_MEDIA_UUID1,
          'media:image_but_not_image_media_source:' . self::TEST_MEDIA,
        ],
        'module' => [
          'file',
          'media',
        ],
      ],
      'permissions' => ['view media', 'access content'],
    ];

    // Complex entity_reference example using multiple branches, where each
    // branch uses different bundle and field name to get the final value.
    // Resolved values are strings.
    yield "complex non-empty example with entity_reference containing multiple branches but not an object" => [
      'sourceType' => 'static:field_item:entity_reference',
      'sourceTypeSettings' => [
        'cardinality' => 5,
        'storage' => ['target_type' => 'media'],
        'instance' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'anything_is_possible' => 'anything_is_possible',
              'image' => 'image',
              'image_but_not_image_media_source' => 'image_but_not_image_media_source',
            ],
          ],
        ],
      ],
      'value' => [['target_id' => 2], ['target_id' => 1], ['target_id' => 3]],
      'expression' => 'ℹ︎entity_reference␟entity␜[␜entity:media:anything_is_possible␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟value]',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:entity_reference',
        'value' => [
          ['target_id' => 2],
          ['target_id' => 1],
          ['target_id' => 3],
        ],
        'expression' => 'ℹ︎entity_reference␟entity␜[␜entity:media:anything_is_possible␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟value]',
        'sourceTypeSettings' => [
          'storage' => [
            'target_type' => 'media',
          ],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => [
                'anything_is_possible' => 'anything_is_possible',
                'image' => 'image',
                'image_but_not_image_media_source' => 'image_but_not_image_media_source',
              ],
            ],
          ],
          'cardinality' => 5,
        ],
      ],
      'field_widgets' => [
        NULL => EntityReferenceAutocompleteWidget::class,
        'media_library_widget' => MediaLibraryWidget::class,
      ],
      'expected_user_value' => [
        'public://image-3.jpg',
        'public://image-2.jpg',
        'Jack is awesome!',
      ],
      'expected_cacheability' => (new CacheableMetadata())
        ->setCacheTags([
          'media:1', 'media:2', 'media:3',
          'file:1', 'file:2',
        ])
        // Cache contexts added by referenced entity access checking.
        // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
        ->setCacheContexts(['user.permissions']),
      'expected_prop_expression' => ReferenceFieldTypePropExpression::class,
      'expected_dependencies' => [
        'config' => [
          'field.field.media.anything_is_possible.field_media_image_1',
          'field.field.media.image.field_media_image',
          'field.field.media.image_but_not_image_media_source.field_media_test',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'media.type.image',
          'media.type.image_but_not_image_media_source',
        ],
        'content' => [
          'file:file:' . self::FILE_UUID2,
          'file:file:' . self::FILE_UUID1,
          'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
          'media:image:' . self::IMAGE_MEDIA_UUID1,
          'media:image_but_not_image_media_source:' . self::TEST_MEDIA,
        ],
        'module' => [
          'file',
          'media',
        ],
      ],
      'permissions' => ['view media', 'access content'],
    ];

    // Complex entity_reference example using multiple branches where resolved
    // value is an object with multiple props. Each branch maps its set of
    // props to different combination of bundles, fields and props.
    // Resolved values are objects containing multiple props.
    yield "complex non-empty example with entity_reference containing multiple branches" => [
      'sourceType' => 'static:field_item:entity_reference',
      'sourceTypeSettings' => [
        'cardinality' => 5,
        'storage' => ['target_type' => 'media'],
        'instance' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'anything_is_possible' => 'anything_is_possible',
              'image' => 'image',
              'image_but_not_image_media_source' => 'image_but_not_image_media_source',
            ],
          ],
        ],
      ],
      'value' => [['target_id' => 2], ['target_id' => 1], ['target_id' => 3]],
      'expression' => 'ℹ︎entity_reference␟entity␜[␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟{src↠value,alt↠value}]',
      'expected_array_representation' => [
        'sourceType' => PropSource::Static->value . ':field_item:entity_reference',
        'value' => [
          ['target_id' => 2],
          ['target_id' => 1],
          ['target_id' => 3],
        ],
        'expression' => 'ℹ︎entity_reference␟entity␜[␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟{src↠value,alt↠value}]',
        'sourceTypeSettings' => [
          'storage' => [
            'target_type' => 'media',
          ],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => [
                'anything_is_possible' => 'anything_is_possible',
                'image' => 'image',
                'image_but_not_image_media_source' => 'image_but_not_image_media_source',
              ],
            ],
          ],
          'cardinality' => 5,
        ],
      ],
      'field_widgets' => [
        NULL => EntityReferenceAutocompleteWidget::class,
        'media_library_widget' => MediaLibraryWidget::class,
      ],
      'expected_user_value' => [
        [
          'src' => 'public://image-3.jpg',
          'alt' => 'amazing',
        ],
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=XYZlDjzC'),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'width' => 80,
          'height' => 60,
        ],
        [
          'src' => 'Jack is awesome!',
          'alt' => 'Jack is awesome!',
        ],
      ],
      'expected_cacheability' => (new CacheableMetadata())
        ->setCacheTags([
          'media:1', 'media:2', 'media:3',
          'file:1', 'file:2',
          'config:image.style.canvas_parametrized_width',
        ])
        // Cache contexts added by referenced entity access checking.
        // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
        ->setCacheContexts(['user.permissions']),
      'expected_prop_expression' => ReferenceFieldTypePropExpression::class,
      'expected_dependencies' => [
        'config' => [
          'field.field.media.anything_is_possible.field_media_image_1',
          'field.field.media.image.field_media_image',
          'field.field.media.image_but_not_image_media_source.field_media_test',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'media.type.image',
          'media.type.image_but_not_image_media_source',
        ],
        'content' => [
          'file:file:' . self::FILE_UUID2,
          'file:file:' . self::FILE_UUID1,
          'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
          'media:image:' . self::IMAGE_MEDIA_UUID1,
          'media:image_but_not_image_media_source:' . self::TEST_MEDIA,
        ],
        'module' => [
          'file',
          'media',
        ],
      ],
      'permissions' => ['view media', 'access content'],
    ];
  }

  /**
   * @coversClass \Drupal\canvas\PropSource\DynamicPropSource
   * @dataProvider providerDynamicPropSource
   */
  public function testDynamicPropSource(
    array $permissions,
    string $expression,
    ?string $adapter_plugin_id,
    bool $is_required,
    array $expected_array_representation,
    string $expected_expression_class,
    ?EvaluationResult $expected_evaluation_with_user_host_entity,
    ?array $expected_user_access_denied_message,
    ?EvaluationResult $expected_evaluation_with_node_host_entity,
    ?array $expected_node_access_denied_message,
    array $expected_dependencies_expression_only,
    array $expected_dependencies_with_host_entity,
  ): void {
    // Evaluating dynamic props requires entity and field access of the data
    // being accessed.

    // For testing expressions relying on users.
    $this->installEntitySchema('user');
    $user = User::create([
      'uuid' => '881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
      'name' => 'John Doe',
      'status' => 1,
      'created' => 694695600,
      'access' => 1720602713,
    ]);
    $user->save();

    // For testing expressions relying on nodes.
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'page'])->save();
    $this->createImageField('field_image', 'node', 'page');
    FieldStorageConfig::create([
      'field_name' => 'a_timestamp_maybe',
      'entity_type' => 'node',
      'type' => 'timestamp',
      'settings' => [],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'a_timestamp_maybe',
      'label' => 'A timestamp, maybe',
      'entity_type' => 'node',
      'bundle' => 'page',
      // Optional, to be able to test how DynamicPropSource' adapter support
      // handles missing optional values (i.e. NULL).
      'required' => FALSE,
      'settings' => [],
    ])->save();
    $this->createEntityReferenceField('node', 'page', 'field_photos', 'Photos', 'media',
      selection_handler_settings: [
        'target_bundles' => [
          'anything_is_possible',
          'image',
          'image_but_not_image_media_source',
        ],
      ],
      cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    );
    $node = $this->createNode([
      'type' => 'page',
      'uid' => $user->id(),
      'field_image' => ['target_id' => 1],
      'field_photos' => [['target_id' => 2], ['target_id' => 1], ['target_id' => 3]],
    ]);

    $original = DynamicPropSource::parse(match ($adapter_plugin_id) {
      NULL => ['sourceType' => 'dynamic', 'expression' => $expression],
      default => ['sourceType' => 'dynamic', 'expression' => $expression, 'adapter' => $adapter_plugin_id],
    });
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $original;
    $decoded_representation = json_decode($json_representation, TRUE);
    $this->assertSame($expected_array_representation, $decoded_representation);
    // @phpstan-ignore argument.type
    $parsed = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(DynamicPropSource::class, $parsed);
    // The contained information read back out.
    $this->assertSame('dynamic', $parsed->getSourceType());
    // @phpstan-ignore-next-line argument.type
    $this->assertInstanceOf($expected_expression_class, StructuredDataPropExpression::fromString($parsed->asChoice()));

    // Test the functionality of a DynamicPropSource:
    $parsed_expression = StructuredDataPropExpression::fromString($expression);
    $correct_host_entity_type = match (get_class($parsed_expression)) {
      FieldPropExpression::class, FieldObjectPropsExpression::class => $parsed_expression->entityType->getEntityTypeId(),
      ReferenceFieldPropExpression::class => $parsed_expression->referencer->entityType->getEntityTypeId(),
      default => throw new \LogicException(),
    };
    // - evaluate it to populate an SDC prop using a `user` host entity
    // First try without the correct permissions.
    if ($expected_evaluation_with_user_host_entity instanceof EvaluationResult) {
      self::assertNotNull($expected_user_access_denied_message);
      \assert(count($permissions) === count($expected_user_access_denied_message));
      for ($i = 0; $i < count($expected_user_access_denied_message); $i++) {
        // First try without the correct permissions; then grant each permission
        // one-by-one, to observe what the effect is on the evaluation result.
        if ($i >= 1) {
          $this->setUpCurrentUser(permissions: array_slice($permissions, 0, $i));
        }
        try {
          $parsed->evaluate(clone $user, $is_required);
          $this->fail('Should throw an access exception.');
        }
        catch (CacheableAccessDeniedHttpException $e) {
          self::assertSame($expected_user_access_denied_message[$i], $e->getMessage());
        }
      }
    }
    // Grant all permissions, now it should succeed.
    $this->setUpCurrentUser(permissions: $permissions);
    try {
      $result = $parsed->evaluate(clone $user, $is_required);
      if (!$expected_evaluation_with_user_host_entity instanceof EvaluationResult) {
        self::fail('Should throw an exception.');
      }
      else {
        self::assertSame($expected_evaluation_with_user_host_entity->value, $result->value);
        self::assertEqualsCanonicalizing($expected_evaluation_with_user_host_entity->getCacheTags(), $result->getCacheTags());
        self::assertEqualsCanonicalizing($expected_evaluation_with_user_host_entity->getCacheContexts(), $result->getCacheContexts());
        self::assertSame($expected_evaluation_with_user_host_entity->getCacheMaxAge(), $result->getCacheMaxAge());
      }
    }
    catch (\DomainException $e) {
      self::assertSame(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `user`.", (string) $parsed_expression, $correct_host_entity_type), $e->getMessage());
    }

    // - evaluate it to populate an SDC prop using a `node` host entity
    // First try without the correct permissions.
    $this->setUpCurrentUser();
    if ($expected_evaluation_with_node_host_entity instanceof EvaluationResult) {
      self::assertNotNull($expected_node_access_denied_message);
      \assert(count($permissions) === count($expected_node_access_denied_message));
      for ($i = 0; $i < count($expected_node_access_denied_message); $i++) {
        // First try without the correct permissions; then grant each permission
        // one-by-one, to observe what the effect is on the evaluation result.
        if ($i >= 1) {
          $this->setUpCurrentUser(permissions: array_slice($permissions, 0, $i));
        }
        try {
          $parsed->evaluate(clone $node, $is_required);
          $this->fail('Should throw an access exception.');
        }
        catch (CacheableAccessDeniedHttpException $e) {
          self::assertSame($expected_node_access_denied_message[$i], $e->getMessage());
        }
      }
    }
    // Grant all permissions, now it should succeed.
    $this->setUpCurrentUser(permissions: $permissions);
    try {
      $result = $parsed->evaluate(clone $node, $is_required);
      if (!$expected_evaluation_with_node_host_entity instanceof EvaluationResult) {
        self::fail('Should throw an exception.');
      }
      else {
        self::assertEqualsCanonicalizing($expected_evaluation_with_node_host_entity->getCacheTags(), $result->getCacheTags());
        self::assertEqualsCanonicalizing($expected_evaluation_with_node_host_entity->getCacheContexts(), $result->getCacheContexts());
        self::assertSame($expected_evaluation_with_node_host_entity->getCacheMaxAge(), $result->getCacheMaxAge());
        self::assertSame($expected_evaluation_with_node_host_entity->value, $this->allowSimplifiedExpectations($result)->value);
      }
    }
    catch (\DomainException $e) {
      self::assertSame(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `node`.", (string) $parsed_expression, $correct_host_entity_type), $e->getMessage());
    }

    // - calculate its dependencies
    $this->assertSame($expected_dependencies_expression_only, $parsed->calculateDependencies());
    $correct_host_entity = match ($correct_host_entity_type) {
      'user' => $user,
      'node' => $node,
      default => throw new \LogicException(),
    };
    $this->assertSame($expected_dependencies_with_host_entity, $parsed->calculateDependencies($correct_host_entity));
  }

  public static function providerDynamicPropSource(): \Generator {
    yield "simple: FieldPropExpression" => [
      'permissions' => ['access user profiles'],
      'expression' => 'ℹ︎␜entity:user␝name␞␟value',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:user␝name␞␟value',
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => new EvaluationResult(
        'John Doe',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'user:1',
          ])
          // Cache contexts added by host entity access checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_user_access_denied_message' => ["Access denied to entity while evaluating expression, ℹ︎␜entity:user␝name␞␟value, reason: The 'access user profiles' permission is required."],
      'expected_evaluation_with_node_host_entity' => NULL,
      'expected_node_access_denied_message' => NULL,
      'expected_dependencies_expression_only' => ['module' => ['user']],
      'expected_dependencies_with_host_entity' => ['module' => ['user']],
    ];

    yield "simple, with adapter: FieldPropExpression" => [
      'permissions' => ['access user profiles'],
      'expression' => 'ℹ︎␜entity:user␝created␞␟value',
      'adapter_plugin_id' => 'unix_to_date',
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:user␝created␞␟value',
        'adapter' => UnixTimestampToDateAdapter::PLUGIN_ID,
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => new EvaluationResult(
        '1992-01-06',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'user:1',
          ])
          // Cache contexts added by host entity access checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_user_access_denied_message' => ["Access denied to entity while evaluating expression, ℹ︎␜entity:user␝created␞␟value, reason: The 'access user profiles' permission is required."],
      'expected_evaluation_with_node_host_entity' => NULL,
      'expected_node_access_denied_message' => NULL,
      'expected_dependencies_expression_only' => [
        'module' => [
          'user',
          'canvas',
        ],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => [
          'user',
          'canvas',
        ],
      ],
    ];

    yield "simple, with adapter for optional (NULL) value: FieldPropExpression" => [
      'permissions' => ['access content'],
      'expression' => 'ℹ︎␜entity:node:page␝a_timestamp_maybe␞␟value',
      'adapter_plugin_id' => 'unix_to_date',
      'is_required' => FALSE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:node:page␝a_timestamp_maybe␞␟value',
        'adapter' => UnixTimestampToDateAdapter::PLUGIN_ID,
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        NULL,
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
          ])
          // Cache contexts added by host entity access checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => ["Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝a_timestamp_maybe␞␟value, reason: The 'access content' permission is required."],
      'expected_dependencies_expression_only' => [
        'module' => [
          'node',
          'canvas',
        ],
        'config' => [
          'node.type.page',
          'field.field.node.page.a_timestamp_maybe',
        ],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => [
          'node',
          'canvas',
        ],
        'config' => [
          'node.type.page',
          'field.field.node.page.a_timestamp_maybe',
        ],
      ],
    ];

    yield "entity reference: FieldPropExpression using the `url` property, for a REQUIRED component prop" => [
      'permissions' => [
        // Grant access to the host entity.
        'access content',
        // Grant access to the referenced entity.
        'access user profiles',
      ],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        '/user/1',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The referenced entity.
            'user:1',
          ])
          // Cache contexts added by host entity access checking AND access
          // checks in the computed field property.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          // @see \Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        // Exception due to host entity being inaccessible.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟url, reason: The 'access content' permission is required.",
        // Exception due to referenced entity being inaccessible.
        "Required field property empty due to entity or field access while evaluating expression ℹ︎␜entity:node:page␝uid␞␟url, reason: The 'access user profiles' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    // In contrast with the above test case:
    // - the `access user profiles` permission is NOT granted, to simulate the
    //   referenced entity not being accessible to the current user
    // - the expected evaluation result is `NULL`, which is acceptable for an
    //   optional component prop
    yield "entity reference: FieldPropExpression using the `url` property, for an OPTIONAL component prop" => [
      'permissions' => [
        // Grant access to the host entity.
        'access content',
      ],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      'adapter_plugin_id' => NULL,
      'is_required' => FALSE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        NULL,
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // TRICKY: the tag for the referenced entity (`user:1`) is ABSENT
            // because it played no role in denying access.
            // @see \Drupal\user\UserAccessControlHandler::checkAccess()
          ])
          // Cache contexts added by host entity access checking AND access
          // checks in the computed field property.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          // @see \Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl
          // Cache contexts added by access checking.
          // @see \Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl
          ->setCacheContexts([
            'user',
            'user.permissions',
          ]),
      ),
      'expected_node_access_denied_message' => [
        // Exception due to host entity being inaccessible.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟url, reason: The 'access content' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    yield "entity reference: ReferenceFieldPropExpression following the `entity` property" => [
      'permissions' => ['access content', 'access user profiles'],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      'expected_expression_class' => ReferenceFieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        'John Doe',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The referenced entity.
            'user:1',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value, reason: The 'access content' permission is required.",
        "Access denied to entity while evaluating expression, ℹ︎␜entity:user␝name␞␟value, reason: The 'access user profiles' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node', 'user'],
        'config' => ['node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node', 'user'],
        'config' => ['node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    yield "complex object: FieldObjectPropsExpression containing a ReferenceFieldPropExpression" => [
      'permissions' => ['access content', 'access user profiles'],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}',
      ],
      'expected_expression_class' => FieldObjectPropsExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        [
          'human_id' => 'John Doe',
          'machine_id' => 1,
        ],
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The referenced entity.
            'user:1',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}, reason: The 'access content' permission is required.",
        "Access denied to entity while evaluating expression, ℹ︎␜entity:user␝name␞␟value, reason: The 'access user profiles' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node', 'user', 'node'],
        'config' => ['node.type.page', 'node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node', 'user', 'node'],
        'config' => ['node.type.page', 'node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    $expected_dependencies_expression = [
      'module' => [
        'node',
        'media',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
      ],
      'config' => [
        'node.type.page',
        'field.field.node.page.field_photos',
        'media.type.anything_is_possible',
        'media.type.image',
        'media.type.image_but_not_image_media_source',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'image.style.canvas_parametrized_width',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'image.style.canvas_parametrized_width',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'image.style.canvas_parametrized_width',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'image.style.canvas_parametrized_width',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image_but_not_image_media_source',
        'field.field.media.image_but_not_image_media_source.field_media_test',
      ],
    ];
    // The expression in the context of the `page` node, which surfaces content
    // dependencies because the `src_with_alternate_widths` property DOES
    // provide such dependencies.
    // Module dependencies are different from those for the expression, because
    // this includes those surfaced during evaluation of node 1.
    // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
    $expected_node_1_expression_dependencies = [
      'module' => [
        'node',
        'media',
        'media',
        'file',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
      ],
      'config' => $expected_dependencies_expression['config'],
      'content' => [
        'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
        'file:file:' . self::FILE_UUID2,
      ],
    ];

    $per_media_type_specific_expression_branches = '[␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟{src↠value}]';
    yield "complex object: ReferenceFieldPropExpression with per-target bundle branches, for single delta (similar for single-cardinality field)" => [
      'permissions' => ['access content', 'view media'],
      'expression' => "ℹ︎␜entity:node:page␝field_photos␞0␟entity␜$per_media_type_specific_expression_branches",
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => "ℹ︎␜entity:node:page␝field_photos␞0␟entity␜$per_media_type_specific_expression_branches",
      ],
      'expected_expression_class' => ReferenceFieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=::SITE_DIR_BASE_URL::' . UrlHelper::encodePath('/files/styles/canvas_parametrized_width--{width}/public/image-3.jpg.avif?itok=ZZaCKDGa'),
          'alt' => 'amazing',
          'width' => 80,
          'height' => 60,
        ],
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The media entity being referenced by delta 0: of the media type
            // `anything_is_possible`.
            'media:2',
            // The entity used by the computed `src_with_alternate_widths` field
            // property.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
            'file:2',
            // The parametrized image style used by the computed
            // `srcset_candidate_uri_template` field property, which is in turn
            // used by the above `src_with_alternate_widths` field property.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\TypedData\ImageDerivativeWithParametrizedWidth
            'config:image.style.canvas_parametrized_width',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝field_photos␞0␟entity␜$per_media_type_specific_expression_branches, reason: The 'access content' permission is required.",
        // 💡 This illustrates which one of the three branches is evaluated.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}, reason: The 'view media' permission is required when the media item is published.",
      ],
      'expected_dependencies_expression_only' => $expected_dependencies_expression,
      'expected_dependencies_with_host_entity' => $expected_node_1_expression_dependencies,
    ];
    yield "complex object: ReferenceFieldPropExpression with per-target bundle branches, for all deltas" => [
      'permissions' => ['access content', 'view media'],
      'expression' => "ℹ︎␜entity:node:page␝field_photos␞␟entity␜$per_media_type_specific_expression_branches",
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::Dynamic->value,
        'expression' => "ℹ︎␜entity:node:page␝field_photos␞␟entity␜$per_media_type_specific_expression_branches",
      ],
      'expected_expression_class' => ReferenceFieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        [
          [
            'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=::SITE_DIR_BASE_URL::' . UrlHelper::encodePath('/files/styles/canvas_parametrized_width--{width}/public/image-3.jpg.avif?itok=ZZaCKDGa'),
            'alt' => 'amazing',
            'width' => 80,
            'height' => 60,
          ],
          [
            'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=::SITE_DIR_BASE_URL::' . UrlHelper::encodePath('/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=XYZlDjzC'),
            'alt' => 'An image so amazing that to gaze upon it would melt your face',
            'width' => 80,
            'height' => 60,
          ],
          [
            'src' => 'Jack is awesome!',
          ],
        ],
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // All referenced media entities.
            'media:2',
            'media:1',
            'media:3',
            // The entities used by the 2 computed `src_with_alternate_widths`
            // field properties: those for the `image` Media and the
            // `anything_is_possible` Media.
            // The `image_but_not_image_media_source` Media type does not use
            // File entities.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
            'file:2',
            'file:1',
            // The parametrized image style used by the computed
            // `srcset_candidate_uri_template` field property, which is in turn
            // used by the above `src_with_alternate_widths` field property.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\TypedData\ImageDerivativeWithParametrizedWidth
            'config:image.style.canvas_parametrized_width',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝field_photos␞␟entity␜$per_media_type_specific_expression_branches, reason: The 'access content' permission is required.",
        // 💡 This illustrates which one of the three branches is evaluated
        // FIRST: the first referenced entity. Once the `view media` permission
        // is granted, the subsequent 2 references can be resolved, too.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}, reason: The 'view media' permission is required when the media item is published.",
      ],
      'expected_dependencies_expression_only' => $expected_dependencies_expression,
      // Unlike the above test case, the one below will evaluate ALL deltas in the
      // given entity field, so these additional dependencies arise.
      'expected_dependencies_with_host_entity' => [
        'module' => [
          ...$expected_node_1_expression_dependencies['module'],
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
        ],
        'config' => [
          ...$expected_node_1_expression_dependencies['config'],
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image_but_not_image_media_source',
          'field.field.media.image_but_not_image_media_source.field_media_test',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image_but_not_image_media_source',
          'field.field.media.image_but_not_image_media_source.field_media_test',
        ],
        'content' => [
          'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
          'media:image:' . self::IMAGE_MEDIA_UUID1,
          'media:image_but_not_image_media_source:' . self::TEST_MEDIA,
          'file:file:' . self::FILE_UUID2,
          'file:file:' . self::FILE_UUID1,
        ],
      ],
    ];
  }

  public static function providerInvalidDynamicPropSourceFieldPropExpressionDueToDelta(): iterable {
    yield [
      "ℹ︎␜entity:user␝name␞␟value",
      NULL,
      "John Doe",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝name␞0␟value",
      NULL,
      "John Doe",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝name␞-1␟value",
      "Requested delta -1, but deltas must be positive integers.",
      "💩",
      (new CacheableMetadata()),
    ];
    yield [
      "ℹ︎␜entity:user␝name␞5␟value",
      "Requested delta 5 for single-cardinality field, must be either zero or omitted.",
      "💩",
      (new CacheableMetadata()),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞␟target_id",
      NULL,
      ["test_role_a", "test_role_b"],
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞0␟target_id",
      NULL,
      "test_role_a",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞1␟target_id",
      NULL,
      "test_role_b",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞5␟target_id",
      "Requested delta 5 for unlimited cardinality field, but only deltas [0, 1] exist.",
      "💩",
      (new CacheableMetadata()),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞-1␟target_id",
      "Requested delta -1, but deltas must be positive integers.",
      "💩",
      (new CacheableMetadata()),
    ];
  }

  /**
   * @covers \Drupal\canvas\PropExpressions\StructuredData\Evaluator
   */
  #[DataProvider('providerInvalidDynamicPropSourceFieldPropExpressionDueToDelta')]
  public function testInvalidDynamicPropSourceFieldPropExpressionDueToDelta(string $expression, ?string $expected_message, mixed $expected_value, CacheableMetadata $expected_cacheability): void {
    $this->setUpCurrentUser(permissions: ['administer permissions', 'access user profiles', 'administer users']);
    Role::create(['id' => 'test_role_a', 'label' => 'Test role A'])->save();
    Role::create(['id' => 'test_role_b', 'label' => 'Test role B'])->save();
    $user = User::create([
      'name' => 'John Doe',
      'roles' => [
        'test_role_a',
        'test_role_b',
      ],
    ])->activate();

    // @phpstan-ignore-next-line argument.type
    $dynamic_prop_source_delta_test = new DynamicPropSource(StructuredDataPropExpression::fromString($expression));

    if ($expected_message !== NULL) {
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage($expected_message);
    }

    $evaluation_result = $dynamic_prop_source_delta_test->evaluate($user, is_required: TRUE);
    self::assertSame($expected_value, $evaluation_result->value);
    self::assertSame($expected_cacheability->getCacheTags(), $evaluation_result->getCacheTags());
    self::assertSame($expected_cacheability->getCacheContexts(), $evaluation_result->getCacheContexts());
    self::assertSame($expected_cacheability->getCacheMaxAge(), $evaluation_result->getCacheMaxAge());
  }

  /**
   * @covers \Drupal\canvas\PropSource\DynamicPropSource::withAdapter()
   * @covers \Drupal\canvas\PropSource\DynamicPropSource::parse())
   */
  public function testInvalidDynamicPropSourceDueToMissingAdapter(): void {
    $this->expectException(PluginNotFoundException::class);
    $this->expectExceptionMessage('The "unix_to_date_oops_I_have_been_renamed" plugin does not exist.');

    DynamicPropSource::parse([
      'sourceType' => PropSource::Dynamic->value,
      'expression' => 'ℹ︎␜entity:user␝created␞␟value',
      'adapter' => 'unix_to_date_oops_I_have_been_renamed',
    ]);
  }

  /**
   * @coversClass \Drupal\canvas\PropSource\AdaptedPropSource
   */
  public function testAdaptedPropSource(): void {
    // 2. user created access

    // 1. daterange
    // A simple static example.
    $simple_static_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:daterange',
          'value' => [
            'value' => '2020-04-16',
            'end_value' => '2024-11-04',
          ],
          'expression' => 'ℹ︎daterange␟value',
        ],
        'newest' => [
          'sourceType' => 'static:field_item:daterange',
          'value' => [
            'value' => '2020-04-16',
            'end_value' => '2024-11-04',
          ],
          'expression' => 'ℹ︎daterange␟end_value',
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_static_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:daterange","value":{"value":"2020-04-16","end_value":"2024-11-04"},"expression":"ℹ︎daterange␟value"},"newest":{"sourceType":"static:field_item:daterange","value":{"value":"2020-04-16","end_value":"2024-11-04"},"expression":"ℹ︎daterange␟end_value"}}}', $json_representation);
    $simple_static_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $simple_static_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $simple_static_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $user = User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713]);
    // TRICKY: entities must be saved for them to have cache tags.
    $user->save();
    self::assertEquals(
      new EvaluationResult(
        1663,
        (new CacheableMetadata())->setCacheTags(['user:1']),
      ),
      $simple_static_example->evaluate($user, is_required: TRUE),
    );
    self::assertSame([
      'module' => [
        'canvas',
        'datetime_range',
        'datetime_range',
      ],
    ], $simple_static_example->calculateDependencies());

    // A simple dynamic example.
    $simple_dynamic_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝created␞␟value',
            ],
          ],
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_dynamic_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝created␞␟value"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $simple_dynamic_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $simple_dynamic_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $simple_dynamic_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->setUpCurrentUser(permissions: ['access user profiles', 'administer users']);
    self::assertEquals(
      new EvaluationResult(
        11874,
        (new CacheableMetadata())
          ->setCacheTags(['user:1'])
          ->setCacheContexts(['user.permissions'])),
      $simple_dynamic_example->evaluate($user, is_required: TRUE)
    );
    self::assertSame([
      'module' => [
        'canvas',
        'canvas',
        'user',
        'canvas',
        'user',
      ],
    ], $simple_dynamic_example->calculateDependencies($user));

    // A complex example.
    $complex_example = AdaptedPropSource::parse([
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:datetime',
          'sourceTypeSettings' => [
            'storage' => [
              'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
            ],
          ],
          'value' => '2020-04-16',
          'expression' => 'ℹ︎datetime␟value',
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $complex_example;
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:datetime","value":"2020-04-16","expression":"ℹ︎datetime␟value","sourceTypeSettings":{"storage":{"datetime_type":"date"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $complex_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    self::assertEquals(
      new EvaluationResult(
        1546,
        (new CacheableMetadata())
          ->setCacheTags(['user:1'])
          ->setCacheContexts(['user.permissions']),
      ),
      $complex_example->evaluate($user, is_required: TRUE)
    );
    self::assertSame([
      'module' => [
        'canvas',
        'datetime',
        'canvas',
        'user',
      ],
    ], $complex_example->calculateDependencies($user));

    // Since #3548749, multi-property fields with only a single stored property
    // are serialized differently. Test backward compatibility with the old
    // format.
    $array_representation_prior_to_3548749 = [
      'sourceType' => 'adapter:day_count',
      'adapterInputs' => [
        'oldest' => [
          'sourceType' => 'static:field_item:datetime',
          'value' => [
            'value' => '2020-04-16',
          ],
          'expression' => 'ℹ︎datetime␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
            ],
          ],
        ],
        'newest' => [
          'sourceType' => 'adapter:unix_to_date',
          'adapterInputs' => [
            'unix' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝access␞␟value',
            ],
          ],
        ],
      ],
    ];
    $complex_example_bc = PropSource::parse($array_representation_prior_to_3548749);
    // Original state: the value is an array, which explicitly lists the main
    // property (also "value") as the sole key-value pair.
    // @phpstan-ignore staticMethod.alreadyNarrowedType
    self::assertSame(['value' => '2020-04-16'], $array_representation_prior_to_3548749['adapterInputs']['oldest']['value']);
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example_bc);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example_bc->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    self::assertEquals(
      new EvaluationResult(
        1546,
        (new CacheableMetadata())
          ->setCacheTags(['user:1'])
          ->setCacheContexts(['user.permissions']),
      ),
      $complex_example_bc->evaluate($user, is_required: TRUE)
    );
    self::assertSame([
      'module' => [
        'canvas',
        'datetime',
        'canvas',
        'user',
      ],
    ], $complex_example_bc->calculateDependencies($user));
    // Updated state: the value is no longer an array, but a single value: the
    // value of the main property.
    // This proves that editing a StaticPropSource automatically updates it.
    self::assertSame('2020-04-16', $complex_example_bc->toArray()['adapterInputs']['oldest']['value']);
  }

  /**
   * @coversClass \Drupal\canvas\PropSource\DefaultRelativeUrlPropSource
   */
  public function testDefaultRelativeUrlPropSource(): void {
    $this->enableModules(['canvas_test_sdc', 'link', 'image', 'options', 'text']);
    self::assertNull(Component::load('sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop'));
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    self::assertNotNull(Component::load('sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop'));

    $source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        'title' => 'image',
        'type' => 'object',
        'required' => ['src'],
        'properties' => [
          'src' => [
            'type' => 'string',
            'contentMediaType' => 'image/*',
            'format' => 'uri-reference',
            'title' => 'Image URL',
            'x-allowed-schemes' => ['http', 'https'],
          ],
          'alt' => [
            'type' => 'string',
            'title' => 'Alternate text',
          ],
          'width' => [
            'type' => 'integer',
            'title' => 'Image width',
          ],
          'height' => [
            'type' => 'integer',
            'title' => 'Image height',
          ],
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    // Note: title of properties have been omitted; only essential data is kept.
    $json_representation = (string) $source;
    self::assertSame('{"sourceType":"default-relative-url","value":{"src":"gracie.jpg","alt":"A good dog","width":601,"height":402},"jsonSchema":{"type":"object","properties":{"src":{"type":"string","contentMediaType":"image\/*","format":"uri-reference","x-allowed-schemes":["http","https"]},"alt":{"type":"string"},"width":{"type":"integer"},"height":{"type":"integer"}},"required":["src"]},"componentId":"sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop"}', $json_representation);
    $decoded = json_decode($json_representation, TRUE);
    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition it contains.
    $decoded['jsonSchema'] = array_reverse($decoded['jsonSchema']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());
    $path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/image-optional-with-example-and-additional-prop';
    // Prove that using a `$ref` results in the same JSON representation.
    $equivalent_source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        '$ref' => 'json-schema-definitions://canvas.module/image',
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );
    self::assertSame((string) $equivalent_source, $json_representation);
    // Test that the URL resolves on evaluation.
    $evaluation_result = $source->evaluate(NULL, is_required: TRUE);
    self::assertSame([
      'src' => Url::fromUri(\sprintf('base:%s/gracie.jpg', $path))->toString(),
      'alt' => 'A good dog',
      'width' => 601,
      'height' => 402,
    ], $evaluation_result->value);
    self::assertEqualsCanonicalizing(['component_plugins'], $evaluation_result->getCacheTags());
    self::assertEqualsCanonicalizing([], $evaluation_result->getCacheContexts());
    self::assertSame(Cache::PERMANENT, $evaluation_result->getCacheMaxAge());
    self::assertSame([
      'config' => ['canvas.component.sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop'],
    ], $source->calculateDependencies());

    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition properties it contains.
    $decoded['jsonSchema']['properties'] = array_reverse($decoded['jsonSchema']['properties']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());

    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition properties attributes it contains.
    $decoded['jsonSchema']['properties']['src'] = array_reverse($decoded['jsonSchema']['properties']['src']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());

    // This is never a choice presented to the end user; this is a purely internal prop source.
    $this->expectException(\LogicException::class);
    $source->asChoice();
  }

  /**
   * @param array{sourceType: string, absolute?: boolean} $what_to_parse
   * @param array $expected_array_representation
   * @param string $entity_type_id
   * @param string $entity_uuid
   * @param string|null $expected_url
   * @param class-string<\Throwable>|null $expected_exception
   */
  #[TestWith([
    ['sourceType' => 'host-entity-url'],
    ['sourceType' => 'host-entity-url', 'absolute' => TRUE],
    'media',
    self::IMAGE_MEDIA_UUID1,
    '/media/1/edit',
    NULL,
  ])]
  #[TestWith([
    ['sourceType' => 'host-entity-url'],
    ['sourceType' => 'host-entity-url', 'absolute' => TRUE],
    'file',
    self::FILE_UUID1,
    NULL,
    UndefinedLinkTemplateException::class,
  ])]
  #[TestWith([
    ['sourceType' => 'host-entity-url'],
    ['sourceType' => 'host-entity-url', 'absolute' => TRUE],
    'media',
    'not-a-real-uuid',
    NULL,
    MissingHostEntityException::class,
  ])]
  #[TestWith([
    ['sourceType' => 'host-entity-url'],
    ['sourceType' => 'host-entity-url', 'absolute' => TRUE],
    'node',
    'with-alias',
    '/awesome-page',
    NULL,
  ])]
  #[TestWith([
    ['sourceType' => 'host-entity-url'],
    ['sourceType' => 'host-entity-url', 'absolute' => TRUE],
    'node',
    'without-alias',
    '/node/1',
    NULL,
  ])]
  #[TestWith([
    ['sourceType' => 'host-entity-url', 'absolute' => FALSE],
    ['sourceType' => 'host-entity-url', 'absolute' => FALSE],
    'node',
    'with-alias',
    '/awesome-page',
    NULL,
  ])]
  public function testHostEntityUrlPropSource(array $what_to_parse, array $expected_array_representation, string $entity_type_id, string $entity_uuid, ?string $expected_url, ?string $expected_exception): void {
    $source = HostEntityUrlPropSource::parse($what_to_parse);
    // Unless otherwise specified, $source->absolute should default to TRUE.
    self::assertSame($what_to_parse['absolute'] ?? TRUE, $source->absolute);

    self::assertArrayHasKey('absolute', $expected_array_representation);
    self::assertSame($expected_array_representation, $source->toArray());
    $expected_json_representation = Json::encode($expected_array_representation);
    self::assertSame($expected_json_representation, (string) $source);

    // Confirm that the array representation can be parsed back.
    $source = PropSource::parse($expected_array_representation);
    self::assertInstanceOf(HostEntityUrlPropSource::class, $source);
    self::assertSame('host-entity-url', $source->getSourceType());
    self::assertSame($expected_array_representation['absolute'], $source->absolute);
    self::assertSame([], $source->calculateDependencies());
    self::assertSame(
      \sprintf('host-entity-url:%s:canonical', $source->absolute ? 'absolute' : 'relative'),
      $source->asChoice(),
    );
    self::assertSame(
      $source->absolute ? 'Absolute URL' : 'Relative URL',
      (string) $source->label(),
    );

    $this->enableModules(['path', 'path_alias', 'text']);
    $this->installConfig('node');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->createContentType(['type' => 'page']);
    $this->createNode([
      'type' => 'page',
      'uuid' => 'without-alias',
    ]);
    $this->createNode([
      'type' => 'page',
      'uuid' => 'with-alias',
      'path' => ['alias' => '/awesome-page'],
    ]);

    $entity = $this->container->get(EntityRepositoryInterface::class)
      ->loadEntityByUuid($entity_type_id, $entity_uuid);

    if ($source->absolute) {
      $expected_url = $GLOBALS['base_url'] . $expected_url;
    }
    if ($expected_exception) {
      $this->expectException($expected_exception);
    }
    self::assertSame($expected_url, $source->evaluate($entity, TRUE)->value);
  }

}
