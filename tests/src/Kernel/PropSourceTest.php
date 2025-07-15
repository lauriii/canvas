<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\BooleanCheckboxWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\File\FileExists;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDatelistWidget;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropSource\AdaptedPropSource;
use Drupal\experience_builder\PropSource\DefaultRelativeUrlPropSource;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\experience_builder\PropSource\PropSource
 * @group experience_builder
 */
class PropSourceTest extends KernelTestBase {

  private const IMAGE_MEDIA_UUID1 = '83b145bb-d8c3-4410-bbd6-fdcd06e27c29';
  private const IMAGE_MEDIA_UUID2 = '93b145bb-d8c3-4410-bbd6-fdcd06e27c29';

  use ContribStrictConfigSchemaTestTrait;
  use MediaTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'field',
    'file',
    'image',
    'node',
    'user',
    'datetime',
    'datetime_range',
    'media',
    'media_library',
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
    $this->installConfig('experience_builder');
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    $this->installEntitySchema('media');

    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('image', ['id' => 'anything_is_possible']);

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
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file1->save();
    $file_uri = 'public://image-3.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-3.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file2 = File::create([
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

    // Fixate the private key & hash salt to get predictable `itok`.
    $this->container->get('state')->set('system.private_key', 'dynamic_image_style_private_key');
    $settings_class = new \ReflectionClass(Settings::class);
    $instance_property = $settings_class->getProperty('instance');
    $settings = new Settings([
      'hash_salt' => 'dynamic_image_style_hash_salt',
    ]);
    $instance_property->setValue(NULL, $settings);
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\StaticPropSource
   * @dataProvider providerStaticPropSource
   */
  public function testStaticPropSource(
    string $sourceType,
    array|null $sourceTypeSettings,
    mixed $value,
    string $expression,
    string $expected_json_representation,
    array|null $field_widgets,
    mixed $expected_user_value,
    string $expected_prop_expression,
    array $expected_dependencies,
  ): void {
    // @phpstan-ignore-next-line
    $prop_source_example = StaticPropSource::parse([
      'sourceType' => $sourceType,
      'value' => $value,
      'expression' => $expression,
      'sourceTypeSettings' => $sourceTypeSettings,
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $prop_source_example;
    $this->assertSame($expected_json_representation, $json_representation);
    $decoded_representation = json_decode($json_representation, TRUE);
    $prop_source_example = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(StaticPropSource::class, $prop_source_example);
    // The contained information read back out.
    $this->assertSame($sourceType, $prop_source_example->getSourceType());
    /** @var class-string $expected_prop_expression */
    $this->assertInstanceOf($expected_prop_expression, StructuredDataPropExpression::fromString($prop_source_example->asChoice()));
    self::assertSame($expected_dependencies, $prop_source_example->calculateDependencies());
    // - generate a widget to edit the stored value — using the default widget
    //   or a specified widget.
    // @see \Drupal\experience_builder\Entity\Component::$defaults
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
    $this->assertSame($expected_user_value, $prop_source_example->evaluate(User::create([]), is_required: TRUE));
    // - the field type's item's raw value is minimized if it is single-property
    $this->assertSame($value, $prop_source_example->getValue());
  }

  public static function providerStaticPropSource(): \Generator {
    yield "scalar shape, field type=string, cardinality=1" => [
      'sourceType' => 'static:field_item:string',
      'sourceTypeSettings' => NULL,
      'value' => 'Hello, world!',
      'expression' => 'ℹ︎string␟value',
      'expected_json_representation' => '{"sourceType":"static:field_item:string","value":"Hello, world!","expression":"ℹ︎string␟value"}',
      'field_widgets' => [
        NULL => StringTextfieldWidget::class,
        'string_textfield' => StringTextfieldWidget::class,
        'string_textarea' => StringTextfieldWidget::class,
      ],
      'expected_user_value' => 'Hello, world!',
      'expected_prop_expression' => FieldTypePropExpression::class,
      'expected_dependencies' => [],
    ];
    yield "scalar shape, field type=uri, cardinality=1" => [
      'sourceType' => 'static:field_item:uri',
      'sourceTypeSettings' => NULL,
      'value' => 'https://drupal.org',
      'expression' => 'ℹ︎uri␟value',
      'expected_json_representation' => '{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}',
      'field_widgets' => [
        NULL => UriWidget::class,
        'uri' => UriWidget::class,
      ],
      'expected_user_value' => 'https://drupal.org',
      'expected_prop_expression' => FieldTypePropExpression::class,
      'expected_dependencies' => [],
    ];
    yield "scalar shape, field type=boolean, cardinality=1" => [
      'sourceType' => 'static:field_item:boolean',
      'sourceTypeSettings' => NULL,
      'value' => TRUE,
      'expression' => 'ℹ︎boolean␟value',
      'expected_json_representation' => '{"sourceType":"static:field_item:boolean","value":true,"expression":"ℹ︎boolean␟value"}',
      'field_widgets' => [
        NULL => BooleanCheckboxWidget::class,
        'boolean_checkbox' => BooleanCheckboxWidget::class,
      ],
      'expected_user_value' => TRUE,
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
      'expected_json_representation' => '{"sourceType":"static:field_item:integer","value":[20,6,1,88,92],"expression":"ℹ︎integer␟value","sourceTypeSettings":{"cardinality":5}}',
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
      'expected_json_representation' => '{"sourceType":"static:field_item:daterange","value":{"value":"2020-04-16T00:00","end_value":"2024-07-10T10:24"},"expression":"ℹ︎daterange␟{start↠value,stop↠end_value}"}',
      'field_widgets' => [
        NULL => DateRangeDefaultWidget::class,
        'daterange_default' => DateRangeDefaultWidget::class,
        'daterange_datelist' => DateRangeDatelistWidget::class,
      ],
      'expected_user_value' => [
        'start' => '2020-04-16T00:00',
        'stop' => '2024-07-10T10:24',
      ],
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
      'expected_json_representation' => '{"sourceType":"static:field_item:daterange","value":[{"value":"2020-04-16T00:00","end_value":"2024-07-10T10:24"},{"value":"2020-04-16T00:00","end_value":"2024-09-26T11:31"}],"expression":"ℹ︎daterange␟{start↠value,stop↠end_value}","sourceTypeSettings":{"cardinality":-1}}',
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
      'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
      'expected_json_representation' => '{"sourceType":"static:field_item:entity_reference","value":null,"expression":"ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}","sourceTypeSettings":{"storage":{"target_type":"media"},"instance":{"handler":"default:media","handler_settings":{"target_bundles":{"image":"image"}}}}}',
      'field_widgets' => [
        NULL => EntityReferenceAutocompleteWidget::class,
        'media_library_widget' => MediaLibraryWidget::class,
      ],
      'expected_user_value' => NULL,
      'expected_prop_expression' => FieldTypeObjectPropsExpression::class,
      'expected_dependencies' => [
        'config' => [
          'field.field.media.image.field_media_image',
          'image.style.xb_parametrized_width',
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
            ],
          ],
        ],
      ],
      'value' => [['target_id' => 2], ['target_id' => 1]],
      'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟alt,width↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟width,height↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟height}',
      'expected_json_representation' => '{"sourceType":"static:field_item:entity_reference","value":[{"target_id":2},{"target_id":1}],"expression":"ℹ︎entity_reference␟{src↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟alt,width↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟width,height↝entity␜␜entity:media:anything_is_possible|image␝field_media_image_1|field_media_image␞␟height}","sourceTypeSettings":{"storage":{"target_type":"media"},"instance":{"handler":"default:media","handler_settings":{"target_bundles":{"image":"image","anything_is_possible":"anything_is_possible"}}},"cardinality":5}}',
      'field_widgets' => [
        NULL => EntityReferenceAutocompleteWidget::class,
        'media_library_widget' => MediaLibraryWidget::class,
      ],
      'expected_user_value' => [
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/xb_parametrized_width--{width}/public/image-3.jpg.webp?itok=6Jb0oZWl'),
          'alt' => 'amazing',
          'width' => 80,
          'height' => 60,
        ],
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/xb_parametrized_width--{width}/public/image-2.jpg.webp?itok=dQpNrzPR'),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'width' => 80,
          'height' => 60,
        ],
      ],
      'expected_prop_expression' => FieldTypeObjectPropsExpression::class,
      'expected_dependencies' => [
        'config' => [
          'field.field.media.anything_is_possible.field_media_image_1',
          'field.field.media.image.field_media_image',
          'image.style.xb_parametrized_width',
          'media.type.anything_is_possible',
          'media.type.image',
        ],
        'content' => [
          'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
          'media:image:' . self::IMAGE_MEDIA_UUID1,
        ],
        'module' => [
          'file',
          'media',
        ],
      ],
    ];
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\DynamicPropSource
   */
  public function testDynamicPropSource(): void {
    $this->installEntitySchema('user');
    $user = User::create(['name' => 'John Doe']);
    $user->save();

    // A simple example: FieldPropExpression.
    $simple_example = DynamicPropSource::parse([
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:user␝name␞␟value',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $simple_example;
    $this->assertSame('{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝name␞␟value"}', $json_representation);
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(DynamicPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('dynamic', $simple_example->getSourceType());
    $this->assertInstanceOf(FieldPropExpression::class, StructuredDataPropExpression::fromString($simple_example->asChoice()));
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame('John Doe', $simple_example->evaluate($user, is_required: TRUE));
    // - calculate its dependencies
    $this->assertSame([
      'module' => [
        'user',
      ],
    ], $simple_example->calculateDependencies($user));

    // A reference example: ReferenceFieldPropExpression.
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'page'])->save();
    $node = $this->createNode(['uid' => $user->id()]);
    $object_example = DynamicPropSource::parse([
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $object_example;
    $this->assertSame('{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value"}', $json_representation);
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(DynamicPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('dynamic', $simple_example->getSourceType());
    $this->assertInstanceOf(ReferenceFieldPropExpression::class, StructuredDataPropExpression::fromString($object_example->asChoice()));
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    try {
      $simple_example->evaluate($user, is_required: TRUE);
      self::fail('Should throw an exception.');
    }
    catch (\DomainException $e) {
      self::assertSame('`ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value` is an expression for entity type `node`, but the provided entity is of type `user`.', $e->getMessage());
    }
    $this->assertSame('John Doe', $simple_example->evaluate($node, is_required: TRUE));
    // - calculate its dependencies
    $this->assertSame([
      'module' => ['node'],
      'config' => ['node.type.page'],
      'content' => ['user:user:' . $user->uuid()],
    ], $simple_example->calculateDependencies($node));

    // A complex object example: FieldObjectPropsExpression containing a
    // ReferenceFieldPropExpression.
    $object_example = DynamicPropSource::parse([
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}',
    ]);
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $object_example;
    $this->assertSame('{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}"}', $json_representation);
    $simple_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(DynamicPropSource::class, $simple_example);
    // The contained information read back out.
    $this->assertSame('dynamic', $simple_example->getSourceType());
    $this->assertInstanceOf(FieldObjectPropsExpression::class, StructuredDataPropExpression::fromString($object_example->asChoice()));
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    try {
      $simple_example->evaluate($user, is_required: TRUE);
      self::fail('Should throw an exception.');
    }
    catch (\DomainException $e) {
      self::assertSame('`ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}` is an expression for entity type `node`, but the provided entity is of type `user`.', $e->getMessage());
    }
    $this->assertSame([
      'human_id' => 'John Doe',
      'machine_id' => 1,
    ], $simple_example->evaluate($node, is_required: TRUE));
    // - calculate its dependencies
    $this->assertSame([
      'module' => [
        'node',
        'node',
      ],
      'config' => [
        'node.type.page',
        'node.type.page',
      ],
      'content' => ['user:user:' . $user->uuid()],
    ], $simple_example->calculateDependencies($node));
  }

  /**
   * @covers \Drupal\experience_builder\PropExpressions\StructuredData\Evaluator
   * @testWith ["ℹ︎␜entity:user␝name␞␟value", null, "John Doe"]
   *           ["ℹ︎␜entity:user␝name␞0␟value", null, "John Doe"]
   *           ["ℹ︎␜entity:user␝name␞-1␟value", "Requested delta -1, but deltas must be positive integers.", "💩"]
   *           ["ℹ︎␜entity:user␝name␞5␟value", "Requested delta 5 for single-cardinality field, must be either zero or omitted.", "💩"]
   *           ["ℹ︎␜entity:user␝roles␞␟target_id", null, ["test_role_a", "test_role_b"]]
   *           ["ℹ︎␜entity:user␝roles␞0␟target_id", null, "test_role_a"]
   *           ["ℹ︎␜entity:user␝roles␞1␟target_id", null, "test_role_b"]
   *           ["ℹ︎␜entity:user␝roles␞5␟target_id", null, null]
   *           ["ℹ︎␜entity:user␝roles␞-1␟target_id", "Requested delta -1, but deltas must be positive integers.", "💩"]
   */
  public function testInvalidDynamicPropSourceFieldPropExpressionDueToDelta(string $expression, ?string $expected_message, mixed $expected_value): void {
    Role::create(['id' => 'test_role_a', 'label' => 'Test role A'])->save();
    Role::create(['id' => 'test_role_b', 'label' => 'Test role B'])->save();
    $user = User::create([
      'name' => 'John Doe',
      'roles' => [
        'test_role_a',
        'test_role_b',
      ],
    ]);

    $dynamic_prop_source_delta_test = new DynamicPropSource(StructuredDataPropExpression::fromString($expression));

    if ($expected_message !== NULL) {
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage($expected_message);
    }

    self::assertSame($expected_value, $dynamic_prop_source_delta_test->evaluate($user, is_required: TRUE));
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\AdaptedPropSource
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
    $this->assertSame(1663, $simple_static_example->evaluate(User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713]), is_required: TRUE));
    self::assertSame([
      'module' => [
        'experience_builder',
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
    $user = User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713]);
    $this->assertSame(11874, $simple_dynamic_example->evaluate($user, is_required: TRUE));
    self::assertSame([
      'module' => [
        'experience_builder',
        'experience_builder',
        'user',
        'experience_builder',
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
    $this->assertSame('{"sourceType":"adapter:day_count","adapterInputs":{"oldest":{"sourceType":"static:field_item:datetime","value":{"value":"2020-04-16"},"expression":"ℹ︎datetime␟value","sourceTypeSettings":{"storage":{"datetime_type":"date"}}},"newest":{"sourceType":"adapter:unix_to_date","adapterInputs":{"unix":{"sourceType":"dynamic","expression":"ℹ︎␜entity:user␝access␞␟value"}}}}}', $json_representation);
    $complex_example = PropSource::parse(json_decode($json_representation, TRUE));
    $this->assertInstanceOf(AdaptedPropSource::class, $complex_example);
    // The contained information read back out.
    $this->assertSame('adapter:day_count', $complex_example->getSourceType());
    // Test the functionality of a DynamicPropSource:
    // - evaluate it to populate an SDC prop
    $this->assertSame(1546, $complex_example->evaluate(User::create(['name' => 'John Doe', 'created' => 694695600, 'access' => 1720602713]), is_required: TRUE));
    self::assertSame([
      'module' => [
        'experience_builder',
        'datetime',
        'experience_builder',
        'user',
      ],
    ], $complex_example->calculateDependencies($user));
  }

  /**
   * @coversClass \Drupal\experience_builder\PropSource\DefaultRelativeUrlPropSource
   */
  public function testDefaultRelativeUrlPropSource(): void {
    $this->enableModules(['xb_test_sdc', 'link', 'image', 'options']);
    // Force rebuilding of the definitions which will create the required
    // component.
    $plugin_manager = $this->container->get(ComponentPluginManager::class);
    $plugin_manager->clearCachedDefinitions();
    $plugin_manager->getDefinitions();
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
            'format' => 'uri-reference',
            'pattern' => '^(/|https?://)?.*\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\?.*)?(#.*)?$',
            'title' => 'Image URL',
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
      componentId: 'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
    );
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    // Note: title of properties have been omitted; only essential data is kept.
    $json_representation = (string) $source;
    self::assertSame('{"sourceType":"default-relative-url","value":{"src":"gracie.jpg","alt":"A good dog","width":601,"height":402},"jsonSchema":{"type":"object","properties":{"src":{"type":"string","format":"uri-reference","pattern":"^(\/|https?:\/\/)?.*\\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\\?.*)?(#.*)?$"},"alt":{"type":"string"},"width":{"type":"integer"},"height":{"type":"integer"}},"required":["src"]},"componentId":"sdc.xb_test_sdc.image-optional-with-example-and-additional-prop"}', $json_representation);
    $decoded = json_decode($json_representation, TRUE);
    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition it contains.
    $decoded['jsonSchema'] = array_reverse($decoded['jsonSchema']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());
    $path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'xb_test_sdc') . '/components/image-optional-with-example-and-additional-prop';
    // Prove that using a `$ref` results in the same JSON representation.
    $equivalent_source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        '$ref' => 'json-schema-definitions://experience_builder.module/image',
      ],
      componentId: 'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
    );
    self::assertSame((string) $equivalent_source, $json_representation);
    // Test that the URL resolves on evaluation.
    self::assertSame([
      'src' => Url::fromUri(\sprintf('base:%s/gracie.jpg', $path))->toString(),
      'alt' => 'A good dog',
      'width' => 601,
      'height' => 402,
    ], $source->evaluate(NULL, is_required: TRUE));
    self::assertSame([
      'config' => ['experience_builder.component.sdc.xb_test_sdc.image-optional-with-example-and-additional-prop'],
    ], $source->calculateDependencies());
    // This is never a choice presented to the end user; this is a purely internal prop source.
    $this->expectException(\LogicException::class);
    $source->asChoice();
  }

}
