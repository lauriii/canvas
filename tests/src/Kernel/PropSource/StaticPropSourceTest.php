<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

// cspell:ignore Qqzr

use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\BooleanCheckboxWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\Language\LanguageInterface;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDatelistWidget;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(StaticPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
#[RunTestsInSeparateProcesses]
class StaticPropSourceTest extends PropSourceTestBase {

  #[DataProvider('providerTest')]
  public function test(
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
    array $permissions,
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
    $decoded_representation = \json_decode($json_representation, TRUE, flags: \JSON_THROW_ON_ERROR);
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
    \assert(\is_array($field_widgets));
    // Ensure we always test the default widget.
    \assert(isset($field_widgets[NULL]));
    // Ensure an unknown widget type is handled gracefully.
    $field_widgets['not_real'] = $field_widgets[NULL];
    foreach ($field_widgets as $widget_type => $expected_widget_class) {
      $this->assertInstanceOf($expected_widget_class, $prop_source_example->getWidget('irrelevant-for-test', 'irrelevant-for-test', 'irrelevant-for-test', $this->randomString(), $widget_type));
    }

    try {
      // @phpstan-ignore argument.type
      StaticPropSource::isMinimalRepresentation($decoded_representation);
    }
    catch (\LogicException) {
      $this->fail("Not a minimal representation: $json_representation.");
    }

    if (NULL === $value) {
      $this->assertNull($expected_user_value);
      // Do not continue testing if there is no values.
      return;
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
    if (\is_array($expected_user_value) && array_is_list($expected_user_value)) {
      foreach (\array_keys($expected_user_value) as $i) {
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

  public static function providerTest(): \Generator {
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
      'permissions' => [],
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
      'permissions' => [],
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
      'permissions' => [],
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
      'permissions' => [],
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
      'permissions' => [],
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
      'permissions' => [],
    ];
    yield "complex empty example with entity_reference, user has explicitly removed input (value is NULL)" => [
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
      'permissions' => [],
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
          'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-3.jpg.avif?itok=X5Qqzr53'),
          'alt' => 'amazing',
          'width' => 80,
          'height' => 60,
        ],
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=IeQvQSDi'),
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
          'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=IeQvQSDi'),
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
   * Tests that a static entity reference resolves to the correct translation.
   *
   * Verifies that when a StaticPropSource contains an entity reference to a
   * translated Media image entity, evaluating the prop expression returns the
   * alt text matching the active content language. The Evaluator calls
   * getTranslationFromContext() on the referenced entity, which picks the
   * translation based on the current content language.
   *
   * Also verifies language fallback: evaluating in a language for which the
   * entity has no translation falls back to the default language.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::doEvaluate()
   */
  public function testTranslatedEntityReference(): void {
    $this->setupContentTranslation();
    $this->setUpCurrentUser(permissions: ['access content', 'view media']);
    $media = $this->createTranslatedMediaFixture();

    // Expression: entity_reference → entity → media:image →
    // field_media_image → alt.
    $prop_source_array = [
      'sourceType' => 'static:field_item:entity_reference',
      'value' => ['target_id' => $media->id()],
      'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟alt',
      'sourceTypeSettings' => [
        'storage' => ['target_type' => 'media'],
        'instance' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => ['image' => 'image'],
          ],
        ],
      ],
    ];

    foreach (self::providerTranslatedReferencedMedia() as $scenario_name => ['langcode' => $langcode, 'expected_alt' => $expected_alt]) {
      // The null-host evaluate() path mutates the loaded entity object by adding
      // languages:language_content to it via addCacheableDependency(). Without
      // this reset, the next scenario's host-entity path gets that mutated
      // object from the static cache, leaking the cache context into its result.
      \Drupal::entityTypeManager()->getStorage('media')->resetCache();

      // ⚠️ When evaluating with a given host entity, that host entity language
      // is used and the active negotiated content language has no impact.
      // Note:
      // - host_entity IS set
      // - the content language cache context is NEVER present
      foreach (self::langcodes() as $active_content_langcode) {
        $this->switchContentLanguage($active_content_langcode);
        $result = StaticPropSource::parse($prop_source_array)->evaluate(
          host_entity: $this->createEntityWithLangcode($langcode),
          is_required: TRUE,
        );
        self::assertSame($expected_alt, $result->value, "$active_content_langcode active, $scenario_name");
        self::assertNotContains('languages:' . LanguageInterface::TYPE_CONTENT, $result->getCacheContexts(), "$active_content_langcode active, $scenario_name");
      }

      // ⚠️ When evaluating without a host entity, the active negotiated content
      // language is used to decide which translation of the referenced Media
      // entity to load.
      // Note:
      // - host_entity IS NOT set
      // - the content language cache context is ALWAYS present
      // @todo Change this expectation in https://git.drupalcode.org/project/canvas/-/work_items/3571785: it should no longer be adding the language cache context then
      $this->switchContentLanguage($langcode);
      $result = StaticPropSource::parse($prop_source_array)->evaluate(
        host_entity: NULL,
        is_required: TRUE,
      );
      self::assertSame($expected_alt, $result->value, $scenario_name);
      self::assertContains('languages:' . LanguageInterface::TYPE_CONTENT, $result->getCacheContexts(), $scenario_name);
    }
  }

  /**
   * Tests that a static image prop source is language-independent.
   *
   * A StaticPropSource's FieldItemList is conjured without a host entity, so it
   * carries `und`. Evaluating the full image expression (inline scalars + the
   * non-translatable File reference) must yield an identical result for every
   * host translation: the `und` langcode never leaks in as the negotiated
   * language, and static values do not vary by language.
   *
   * @see \Drupal\canvas\PropSource\StaticPropSource::conjureFieldItemList()
   * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::resolveTranslation()
   * @todo Refactor or remove this test coverage in https://git.drupalcode.org/project/canvas/-/work_items/3571785
   */
  public function testStaticImageIsLanguageIndependent(): void {
    $this->setupContentTranslation();
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'page'])->save();
    \Drupal::service('content_translation.manager')
      ->setEnabled('node', 'page', TRUE);
    $this->setUpCurrentUser(permissions: ['access content']);

    $prop_source = StaticPropSource::parse([
      'sourceType' => 'static:field_item:image',
      'value' => ['target_id' => 1, 'alt' => 'Inline alt text', 'width' => 80, 'height' => 60],
      'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
    ]);

    // The conjured list has no host entity, so the langcode is `und`.
    self::assertSame(
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      $prop_source->fieldItemList->getLangcode(),
    );

    // A translatable host node with a non-default translation.
    $node = $this->createNode(['type' => 'page', 'langcode' => 'en']);
    $node->addTranslation('es', ['title' => 'Spanish title']);

    // Evaluate against the English host (active content language: en).
    $en_result = $prop_source->evaluate(clone $node->getTranslation('en'), is_required: TRUE);
    // Evaluate against the Spanish host, with the active content language also
    // switched to Spanish — neither must change the static result.
    $this->switchContentLanguage('es');
    $es_result = $prop_source->evaluate(clone $node->getTranslation('es'), is_required: TRUE);
    // And even with no host entity, the prop should still unchanged
    $no_host_result = $prop_source->evaluate(NULL, is_required: TRUE);

    // The File reference resolved through the `und` conjured list: `src` is a
    // non-empty URL string. Inline scalars are returned verbatim.
    self::assertSame([
      'src' => str_replace(
        ['::SITE_DIR_BASE_URL::', UrlHelper::encodePath('::SITE_DIR_BASE_URL::')],
        [\base_path() . $this->siteDirectory, UrlHelper::encodePath(\base_path() . $this->siteDirectory)],
        '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=' . UrlHelper::encodePath('::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=IeQvQSDi'),
      ),
      'alt' => 'Inline alt text',
      'width' => 80,
      'height' => 60,
    ], $en_result->value);

    // Check that the evaluation result is the same in all languages.
    self::assertSame($en_result->value, $es_result->value);
    self::assertSame($en_result->value, $no_host_result->value);

    // The content language cache context is never added.
    self::assertNotContains('languages:' . LanguageInterface::TYPE_CONTENT, $en_result->getCacheContexts());
    self::assertNotContains('languages:' . LanguageInterface::TYPE_CONTENT, $es_result->getCacheContexts());
    self::assertNotContains('languages:' . LanguageInterface::TYPE_CONTENT, $no_host_result->getCacheContexts());
  }

}
