<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormState;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\experience_builder\SdcPropToFieldTypePropMatcher;
use Drupal\experience_builder\StorablePropShape;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\user\Entity\User;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

class PropShapeRepositoryTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'experience_builder',
    // The dependent modules.
    'sdc',
    // Modules providing additional SDCs.
    'sdc_test',
    'sdc_test_all_props',
    // Modules providing field types that the PropShapes are using.
    'datetime',
    'image',
    'file',
    'options',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // @see core/modules/system/config/install/core.date_format.html_date.yml
    // @see core/modules/system/config/install/core.date_format.html_datetime.yml
    // @see \Drupal\datetime\Plugin\Field\FieldWidget\DateTimeDefaultWidget::formElement()
    $this->installConfig(['system']);
    // @see \Drupal\file\Plugin\Field\FieldType\FileItem::generateSampleValue()
    $this->installEntitySchema('file');
  }

  /**
   * Tests finding all unique prop schemas.
   */
  public function testUniquePropSchemaDiscovery(): array {
    $sdc_manager = \Drupal::service('plugin.manager.sdc');
    $matcher = \Drupal::service(SdcPropToFieldTypePropMatcher::class);
    assert($matcher instanceof SdcPropToFieldTypePropMatcher);

    $components = $sdc_manager->getAllComponents();
    $unique_prop_shapes = [];
    foreach ($components as $component) {
      foreach (PropShape::getComponentProps($component) as $prop_shape) {
        // A `type: object` without `properties` and without `$ref` does not
        // make sense.
        if ($prop_shape->schema['type'] === 'object' && !array_key_exists('$ref', $prop_shape->schema) && empty($prop_shape->schema['properties'] ?? [])) {
          // @see core/modules/system/tests/modules/sdc_test/components/array-to-object/array-to-object.component.yml
          assert($component->getPluginId() === 'sdc_test:array-to-object');
          continue;
        }

        $unique_prop_shapes[$prop_shape->uniquePropSchemaKey()] = $prop_shape;
      }
    }
    ksort($unique_prop_shapes);
    $unique_prop_shapes = array_values($unique_prop_shapes);
    $this->assertEquals([
      new PropShape(['type' => 'boolean']),
      new PropShape(['type' => 'integer']),
      new PropShape(['type' => 'integer', '$ref' => 'json-schema-definitions://experience_builder.module/column-width']),
      new PropShape(['type' => 'integer', 'maximum' => 2147483648, 'minimum' => -2147483648]),
      new PropShape(['type' => 'integer', 'minimum' => 0]),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image']),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/shoe-icon']),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://sdc_test_all_props.module/date-range']),
      new PropShape(['type' => 'string']),
      new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://experience_builder.module/heading-element']),
      new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://experience_builder.module/image-uri']),
      new PropShape(['type' => 'string', 'enum' => ['', '_blank']]),
      new PropShape(['type' => 'string', 'enum' => ['', 'base', 'l', 's', 'xs', 'xxs']]),
      new PropShape(['type' => 'string', 'enum' => ['', 'gray', 'primary', 'neutral-soft', 'neutral-medium', 'neutral-loud', 'primary-medium', 'primary-loud', 'black', 'white', 'red', 'gold', 'green']]),
      new PropShape(['type' => 'string', 'enum' => ['_blank', '_parent', '_self', '_top']]),
      new PropShape(['type' => 'string', 'enum' => ['default', 'primary', 'success', 'neutral', 'warning', 'danger', 'text']]),
      new PropShape(['type' => 'string', 'enum' => ['foo', 'bar']]),
      new PropShape(['type' => 'string', 'enum' => ['full', 'wide', 'normal', 'narrow']]),
      new PropShape(['type' => 'string', 'enum' => ['moon-stars-fill', 'moon-stars', 'star-fill', 'star', 'stars', 'rocket-fill', 'rocket-takeoff-fill', 'rocket-takeoff', 'rocket']]),
      new PropShape(['type' => 'string', 'enum' => ['power', 'like', 'external']]),
      new PropShape(['type' => 'string', 'enum' => ['prefix', 'suffix']]),
      new PropShape(['type' => 'string', 'enum' => ['primary', 'secondary']]),
      new PropShape(['type' => 'string', 'enum' => ['primary', 'success', 'neutral', 'warning', 'danger']]),
      new PropShape(['type' => 'string', 'enum' => ['small', 'medium', 'large']]),
      new PropShape(['type' => 'string', 'enum' => ['top', 'bottom', 'start', 'end']]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DATE->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DATE_TIME->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DURATION->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::EMAIL->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::HOSTNAME->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IDN_EMAIL->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IDN_HOSTNAME->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IPV4->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IPV6->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IRI->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IRI_REFERENCE->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::JSON_POINTER->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::REGEX->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::RELATIVE_JSON_POINTER->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::TIME->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI->value, 'pattern' => '\.(mp4|webm)(\?.*)?(#.*)?$']),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI_REFERENCE->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI_TEMPLATE->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UUID->value]),
      new PropShape(['type' => 'string', 'minLength' => 2]),
    ], $unique_prop_shapes);

    return $unique_prop_shapes;
  }

  /**
   * @return \Drupal\experience_builder\StorablePropShape[]
   */
  public static function getExpectedStorablePropShapes(): array {
    $generate_allowed_values_setting = function (array $allowed_values): array {
      return array_map(
        fn ($allowed_value) => ['value' => $allowed_value, 'label' => (string) $allowed_value],
        $allowed_values
      );
    };
    return [
      'type=boolean' => new StorablePropShape(
        shape: new PropShape(['type' => 'boolean']),
        fieldTypeProp: new FieldTypePropExpression('boolean', 'value'),
        fieldWidget: 'boolean_checkbox',
      ),
      'type=integer' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer']),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
      ),
      'type=integer&$ref=json-schema-definitions://experience_builder.module/column-width' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'enum' => [25, 33, 50, 66, 75]]),
        fieldTypeProp: new FieldTypePropExpression('list_integer', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => [
            ['value' => 25, 'label' => '25'],
            ['value' => 33, 'label' => '33'],
            ['value' => 50, 'label' => '50'],
            ['value' => 66, 'label' => '66'],
            ['value' => 75, 'label' => '75'],
          ],
        ],
      ),
      'type=integer&maximum=2147483648&minimum=-2147483648' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'maximum' => 2147483648, 'minimum' => -2147483648]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
        fieldStorageSettings: ['min' => -2147483648, 'max' => 2147483648],
      ),
      'type=integer&minimum=0' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'minimum' => 0]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
        fieldStorageSettings: ['min' => 0, 'max' => ''],
      ),
      'type=string' => new StorablePropShape(
        shape: new PropShape(['type' => 'string']),
        fieldTypeProp: new FieldTypePropExpression('string', 'value'),
        fieldWidget: 'string_textfield',
      ),
      'type=object&$ref=json-schema-definitions://experience_builder.module/image' => new StorablePropShape(
        shape: new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/image']),
        fieldTypeProp: new FieldTypeObjectPropsExpression('image', [
          'src' => new ReferenceFieldTypePropExpression(
            new FieldTypePropExpression('image', 'entity'),
            new FieldPropExpression(EntityDataDefinition::create('file'), 'uri', NULL, 'value'),
          ),
          'alt' => new FieldTypePropExpression('image', 'alt'),
          'width' => new FieldTypePropExpression('image', 'width'),
          'height' => new FieldTypePropExpression('image', 'height'),
        ]),
        fieldWidget: 'image_image',
      ),
      'type=string&$ref=json-schema-definitions://experience_builder.module/heading-element' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => [
            ['value' => 'div', 'label' => 'div'],
            ['value' => 'h1', 'label' => 'h1'],
            ['value' => 'h2', 'label' => 'h2'],
            ['value' => 'h3', 'label' => 'h3'],
            ['value' => 'h4', 'label' => 'h4'],
            ['value' => 'h5', 'label' => 'h5'],
            ['value' => 'h6', 'label' => 'h6'],
          ],
        ],
      ),
      'type=string&enum[0]=foo&enum[1]=bar' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['foo', 'bar']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => [
            ['value' => 'foo', 'label' => 'foo'],
            ['value' => 'bar', 'label' => 'bar'],
          ],
        ],
      ),
      'type=string&enum[0]=&enum[1]=_blank' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['', '_blank']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => [
            ['value' => '', 'label' => ''],
            ['value' => '_blank', 'label' => '_blank'],
          ],
        ],
      ),
      'type=string&enum[0]=&enum[1]=base&enum[2]=l&enum[3]=s&enum[4]=xs&enum[5]=xxs' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['', 'base', 'l', 's', 'xs', 'xxs']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['', 'base', 'l', 's', 'xs', 'xxs']),
        ],
      ),
      'type=string&enum[0]=&enum[1]=gray&enum[2]=primary&enum[3]=neutral-soft&enum[4]=neutral-medium&enum[5]=neutral-loud&enum[6]=primary-medium&enum[7]=primary-loud&enum[8]=black&enum[9]=white&enum[10]=red&enum[11]=gold&enum[12]=green' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['', 'gray', 'primary', 'neutral-soft', 'neutral-medium', 'neutral-loud', 'primary-medium', 'primary-loud', 'black', 'white', 'red', 'gold', 'green']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['', 'gray', 'primary', 'neutral-soft', 'neutral-medium', 'neutral-loud', 'primary-medium', 'primary-loud', 'black', 'white', 'red', 'gold', 'green']),
        ],
      ),
      'type=string&enum[0]=_blank&enum[1]=_parent&enum[2]=_self&enum[3]=_top' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['_blank', '_parent', '_self', '_top']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['_blank', '_parent', '_self', '_top']),
        ],
      ),
      'type=string&enum[0]=default&enum[1]=primary&enum[2]=success&enum[3]=neutral&enum[4]=warning&enum[5]=danger&enum[6]=text' => new StorablePropShape(
        new PropShape(['type' => 'string', 'enum' => ['default', 'primary', 'success', 'neutral', 'warning', 'danger', 'text']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['default', 'primary', 'success', 'neutral', 'warning', 'danger', 'text']),
        ],
      ),
      'type=string&enum[0]=full&enum[1]=wide&enum[2]=normal&enum[3]=narrow' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['full', 'wide', 'normal', 'narrow']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['full', 'wide', 'normal', 'narrow']),
        ],
      ),
      'type=string&enum[0]=moon-stars-fill&enum[1]=moon-stars&enum[2]=star-fill&enum[3]=star&enum[4]=stars&enum[5]=rocket-fill&enum[6]=rocket-takeoff-fill&enum[7]=rocket-takeoff&enum[8]=rocket' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['moon-stars-fill', 'moon-stars', 'star-fill', 'star', 'stars', 'rocket-fill', 'rocket-takeoff-fill', 'rocket-takeoff', 'rocket']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['moon-stars-fill', 'moon-stars', 'star-fill', 'star', 'stars', 'rocket-fill', 'rocket-takeoff-fill', 'rocket-takeoff', 'rocket']),
        ],
      ),
      'type=string&enum[0]=prefix&enum[1]=suffix' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['prefix', 'suffix']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['prefix', 'suffix']),
        ],
      ),
      'type=string&enum[0]=primary&enum[1]=secondary' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['primary', 'secondary']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['primary', 'secondary']),
        ],
      ),
      'type=string&enum[0]=primary&enum[1]=success&enum[2]=neutral&enum[3]=warning&enum[4]=danger' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['primary', 'success', 'neutral', 'warning', 'danger']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['primary', 'success', 'neutral', 'warning', 'danger']),
        ],
      ),
      'type=string&enum[0]=small&enum[1]=medium&enum[2]=large' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['small', 'medium', 'large']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['small', 'medium', 'large']),
        ],
      ),
      'type=string&enum[0]=top&enum[1]=bottom&enum[2]=start&enum[3]=end' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['top', 'bottom', 'start', 'end']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => $generate_allowed_values_setting(['top', 'bottom', 'start', 'end']),
        ],
      ),
      'type=string&enum[0]=power&enum[1]=like&enum[2]=external' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['power', 'like', 'external']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values' => [
            ['value' => 'power', 'label' => 'power'],
            ['value' => 'like', 'label' => 'like'],
            ['value' => 'external', 'label' => 'external'],
          ],
        ],
      ),
      'type=string&format=uri' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => 'uri']),
        fieldTypeProp: new FieldTypePropExpression('uri', 'value'),
        fieldWidget: 'uri',
      ),
      'type=string&minLength=2' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'minLength' => 2]),
        fieldTypeProp: new FieldTypePropExpression('string', 'value'),
        fieldWidget: 'string_textfield',
      ),
      'type=string&format=date' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DATE->value]),
        fieldTypeProp: new FieldTypePropExpression('datetime', 'value'),
        fieldWidget: 'datetime_default',
        fieldStorageSettings: [
          'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
        ],
      ),
      'type=string&format=date-time' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DATE_TIME->value]),
        fieldTypeProp: new FieldTypePropExpression('datetime', 'value'),
        fieldWidget: 'datetime_default',
        fieldStorageSettings: [
          'datetime_type' => DateTimeItem::DATETIME_TYPE_DATETIME,
        ],
      ),
      'type=string&format=email' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::EMAIL->value]),
        fieldTypeProp: new FieldTypePropExpression('email', 'value'),
        fieldWidget: 'email_default',
      ),
      'type=string&format=idn-email' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IDN_EMAIL->value]),
        fieldTypeProp: new FieldTypePropExpression('email', 'value'),
        fieldWidget: 'email_default',
      ),
      'type=string&format=iri' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IRI->value]),
        fieldTypeProp: new FieldTypePropExpression('uri', 'value'),
        fieldWidget: 'uri',
      ),
    ];
  }

  /**
   * @return \Drupal\experience_builder\PropShape[]
   */
  public static function getExpectedUnstorablePropShapes(): array {
    return [
      'type=object&$ref=json-schema-definitions://sdc_test_all_props.module/date-range' => new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://sdc_test_all_props.module/date-range']),
      'type=string&$ref=json-schema-definitions://experience_builder.module/image-uri' => new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://experience_builder.module/image-uri']),
      'type=object&$ref=json-schema-definitions://experience_builder.module/shoe-icon' => new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://experience_builder.module/shoe-icon']),
      'type=string&format=duration' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DURATION->value]),
      'type=string&format=hostname' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::HOSTNAME->value]),
      'type=string&format=idn-hostname' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IDN_HOSTNAME->value]),
      'type=string&format=ipv4' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IPV4->value]),
      'type=string&format=ipv6' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IPV6->value]),
      'type=string&format=json-pointer' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::JSON_POINTER->value]),
      'type=string&format=regex' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::REGEX->value]),
      'type=string&format=relative-json-pointer' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::RELATIVE_JSON_POINTER->value]),
      'type=string&format=time' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::TIME->value]),
      'type=string&format=uri&pattern=\.(mp4|webm)(\?.*)?(#.*)?$' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI->value, 'pattern' => '\.(mp4|webm)(\?.*)?(#.*)?$']),
      'type=string&format=uri-template' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI_TEMPLATE->value]),
      'type=string&format=uuid' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UUID->value]),
      'type=string&format=iri-reference' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IRI_REFERENCE->value]),
      'type=string&format=uri-reference' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::URI_REFERENCE->value]),
    ];
  }

  /**
   * @depends testUniquePropSchemaDiscovery
   */
  public function testStorablePropShapes(array $unique_prop_shapes): array {
    $this->assertNotEmpty($unique_prop_shapes);

    $unique_storable_prop_shapes = [];
    foreach ($unique_prop_shapes as $prop_shape) {
      assert($prop_shape instanceof PropShape);
      // If this prop shape is not storable, then fall back to the PropShape
      // object, to make it easy to assert which shapes are storable vs not.
      $unique_storable_prop_shapes[$prop_shape->uniquePropSchemaKey()] = $prop_shape->getStorage() ?? $prop_shape;
    }

    $unstorable_prop_shapes = array_filter($unique_storable_prop_shapes, fn ($s) => $s instanceof PropShape);
    $unique_storable_prop_shapes = array_filter($unique_storable_prop_shapes, fn ($s) => $s instanceof StorablePropShape);

    $this->assertEquals(static::getExpectedStorablePropShapes(), $unique_storable_prop_shapes);

    // ⚠️ No field type + widget yet for these! For some that is fine though.
    $this->assertEquals(static::getExpectedUnstorablePropShapes(), $unstorable_prop_shapes);

    return array_filter($unique_storable_prop_shapes, fn ($prop_shape) => $prop_shape instanceof StorablePropShape);
  }

  /**
   * @depends testStorablePropShapes
   * @param \Drupal\experience_builder\StorablePropShape[] $storable_prop_shapes
   */
  public function testPropShapesYieldWorkingStaticPropSources(array $storable_prop_shapes): void {
    $this->assertNotEmpty($storable_prop_shapes);
    foreach ($storable_prop_shapes as $storable_prop_shape) {
      // A static prop source can be generated.
      $prop_source = $storable_prop_shape->toStaticPropSource();
      $this->assertInstanceOf(StaticPropSource::class, $prop_source);

      // A widget can be generated.
      $widget = $prop_source->getWidget('irrelevant-in-this-test', $this->randomString(), $storable_prop_shape->fieldWidget);
      $this->assertInstanceof(WidgetInterface::class, $widget);
      $this->assertSame($storable_prop_shape->fieldWidget, $widget->getPluginId());

      // A widget form can be generated.
      // @see \Drupal\Core\Entity\Entity\EntityFormDisplay::buildForm()
      // @see \Drupal\Core\Field\WidgetBase::form()
      $form = ['#parents' => [$this->randomMachineName()]];
      $form_state = new FormState();
      $form = $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($storable_prop_shape->fieldWidget, 'nonsensical-uuid', 'some-prop-name', $this->randomString(), User::create([]), $form, $form_state);

      // Finally, prove the total compatibility of the StaticPropSource
      // generated by the StorablePropShape:
      // - generate a random value using the field type
      // - store the StaticPropSource that contains this random value
      // - (this simulated the user entering a value)
      // - verify it is present after loading from storage
      // - finally: verify that evaluating the StaticPropSource returns the
      //   parts of the generated value using the stored expression in such a
      //   way that the SDC component validator reports no errors.
      $randomized_prop_source = $prop_source->randomizeValue();
      $random_value = $randomized_prop_source->getValue();
      $stored_randomized_prop_source = (string) $randomized_prop_source;
      $reloaded_randomized_prop_source = StaticPropSource::parse(json_decode($stored_randomized_prop_source, TRUE));
      $this->assertInstanceOf(StaticPropSource::class, $reloaded_randomized_prop_source);
      $this->assertSame($random_value, $reloaded_randomized_prop_source->getValue());
      // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
      $some_prop_name = $this->randomMachineName();
      $schema = Validator::arrayToObjectRecursive([
        'type' => 'object',
        'required' => [$some_prop_name],
        'properties' => [$some_prop_name => $storable_prop_shape->shape->schema],
        'additionalProperties' => FALSE,
      ]);
      $props = Validator::arrayToObjectRecursive([$some_prop_name => $reloaded_randomized_prop_source->evaluate(NULL)]);
      $validator = new Validator();
      $validator->validate($props, $schema, Constraint::CHECK_MODE_TYPE_CAST);
      $this->assertSame(
        [],
        $validator->getErrors(),
        sprintf("Sample value %s generated by field type %s for %s is invalid!",
          json_encode($random_value),
          $storable_prop_shape->fieldTypeProp->fieldType,
          $storable_prop_shape->shape->uniquePropSchemaKey()
        )
      );

      // @todo Remove this, find better solution.
      drupal_static_reset('options_allowed_values');
    }
  }

}
