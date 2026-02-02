<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\PropShape\PersistentPropShapeRepository;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas\PropShape\EphemeralPropShapeRepository;
use Drupal\canvas\Validation\JsonSchema\CustomConstraintError;
use Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks;
use Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType\MultipleOfItem;
use Drupal\Core\Cache\CacheCollectorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormState;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\link\LinkItemInterface;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @coversDefaultClass \Drupal\canvas\PropShape\PersistentPropShapeRepository
 * @covers \Drupal\canvas\PropShape\EphemeralPropShapeRepository
 * @group canvas
 * @group canvas_data_model
 * @group canvas_data_model__prop_expressions
 */
class PropShapeRepositoryTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use UserCreationTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'canvas',
    // The dependent modules.
    'sdc',
    // Modules providing additional SDCs.
    'sdc_test',
    'sdc_test_all_props',
    'canvas_test_sdc',
    // Modules providing field types and widgets that the PropShapes are using.
    'ckeditor5',
    'datetime',
    'editor',
    'image',
    'file',
    'filter',
    'link',
    'media',
    'options',
    'text',
    'datetime',
  ];

  protected static $configSchemaCheckerExclusions = [
    // The "all-props" test-only SDC is used to assess also prop shapes that are
    // not yet storable, and hence do not meet the requirements.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::checkRequirements()
    // @see /ui/tests/e2e/prop-types.cy.js
    'canvas.' . ComponentEntity::ENTITY_TYPE_ID . '.' . SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.sdc_test_all_props.all-props',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install([
      // Needed by Canvas.
      'stark',
      // To test $ref handling in themes.
      // @see \Drupal\canvas\JsonSchemaDefinitionsStreamwrapper
      'test_theme_base',
      'test_theme_child',
      'test_theme_without_ref',
    ]);
    // @see core/modules/system/config/install/core.date_format.html_date.yml
    // @see core/modules/system/config/install/core.date_format.html_datetime.yml
    // @see \Drupal\datetime\Plugin\Field\FieldWidget\DateTimeDefaultWidget::formElement()
    $this->installConfig(['system']);
    // @see config/install/image.style.canvas_parametrized_width.yml
    $this->installConfig(['canvas']);
    // @see \Drupal\file\Plugin\Field\FieldType\FileItem::generateSampleValue()
    $this->installEntitySchema('file');
  }

  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register(PropShapeRepositoryInterface::class, PersistentPropShapeRepositoryTestHelper::class)
      ->addArgument(new Reference(EphemeralPropShapeRepository::class))
      ->addArgument(new Reference('cache.discovery'))
      ->addArgument(new Reference('lock'))
      ->addArgument(new Reference(ComponentSourceManager::class))
      ->addArgument(new Reference('kernel'));
  }

  /**
   * Tests finding all unique prop shapes.
   */
  public function testUniquePropShapeDiscovery(): array {
    // The ephemeral prop shape repository will contain all prop shapes, because
    // it is called by \Drupal\canvas\ComponentMetadataRequirementsChecker.
    $ephemeral_prop_shape_repository = $this->container->get(EphemeralPropShapeRepository::class);

    // The persistent prop shape repository will contain all prop shapes for
    // components that are eligible.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::checkRequirements()
    $persistent_prop_shape_repository = $this->container->get(PropShapeRepositoryInterface::class);
    self::assertInstanceOf(PersistentPropShapeRepository::class, $persistent_prop_shape_repository);

    // Empty prop shape repositories at the start. And no Components.
    self::assertEmpty($ephemeral_prop_shape_repository->getUniquePropShapes());
    self::assertEmpty($persistent_prop_shape_repository->getUniquePropShapes());
    self::assertEmpty(Component::loadMultiple());

    // Discover all Components, which will cause the prop shape repositories to
    // get populated.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotEmpty($ephemeral_prop_shape_repository->getUniquePropShapes());
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotEmpty($persistent_prop_shape_repository->getUniquePropShapes());
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotEmpty(Component::loadMultiple());

    // EphemeralPropShapeRepository must contain a superset, because the
    // persistent prop shape repository contains only the shapes that actually
    // qualified to be used to turn into actual Components.
    self::assertTrue(
      count($ephemeral_prop_shape_repository->getUniquePropShapes())
      >
      count($persistent_prop_shape_repository->getUniquePropShapes())
    );
    self::assertEmpty(array_diff_key($persistent_prop_shape_repository->getUniquePropShapes(), $ephemeral_prop_shape_repository->getUniquePropShapes()));
    self::assertNotEmpty(array_diff_key($ephemeral_prop_shape_repository->getUniquePropShapes(), $persistent_prop_shape_repository->getUniquePropShapes()));

    $unique_prop_shapes = array_values($ephemeral_prop_shape_repository->getUniquePropShapes());
    $this->assertEquals([
      new PropShape(['type' => 'array', 'items' => ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image']]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image'], 'maxItems' => 2]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer']]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer', 'maximum' => 100, 'minimum' => -100], 'maxItems' => 100]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer', 'maximum' => 100, 'minimum' => -100], 'maxItems' => 100, 'minItems' => 2]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 20, 'minItems' => 1]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2]),
      new PropShape(['type' => 'array', 'items' => ['type' => 'string']]),
      new PropShape(['type' => 'boolean']),
      new PropShape(['type' => 'integer']),
      new PropShape(['type' => 'integer', '$ref' => 'json-schema-definitions://canvas.module/column-width']),
      new PropShape(['type' => 'integer', 'enum' => [1, 2]]),
      new PropShape(['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]]),
      new PropShape(['type' => 'integer', 'maximum' => 100, 'minimum' => -100]),
      new PropShape(['type' => 'integer', 'maximum' => 2147483648, 'minimum' => -2147483648]),
      new PropShape(['type' => 'integer', 'minimum' => 0]),
      new PropShape(['type' => 'integer', 'minimum' => 1]),
      new PropShape(['type' => 'integer', 'multipleOf' => 12]),
      new PropShape(['type' => 'number']),
      new PropShape(['type' => 'object']),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/date-range']),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image']),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/shoe-icon']),
      new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/video']),
      new PropShape(['type' => 'string']),
      new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://canvas.module/heading-element']),
      new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://canvas.module/image-uri']),
      new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://canvas.module/stream-wrapper-image-uri']),
      new PropShape(['type' => 'string', '$ref' => 'json-schema-definitions://test_theme_base.theme/organization-logo-url']),
      new PropShape(['type' => 'string', 'contentMediaType' => 'text/html']),
      new PropShape(['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'block']),
      new PropShape(['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline']),
      new PropShape(['type' => 'string', 'enum' => ['7', '3.14']]),
      new PropShape(['type' => 'string', 'enum' => ['_blank', '_parent', '_self', '_top']]),
      new PropShape(['type' => 'string', 'enum' => ['_self', '_blank']]),
      new PropShape(['type' => 'string', 'enum' => ['auto', 'manual']]),
      new PropShape(['type' => 'string', 'enum' => ['default', 'primary', 'success', 'neutral', 'warning', 'danger', 'text']]),
      new PropShape(['type' => 'string', 'enum' => ['foo', 'bar']]),
      new PropShape(['type' => 'string', 'enum' => ['full', 'wide', 'normal', 'narrow']]),
      new PropShape(['type' => 'string', 'enum' => ['horizontal', 'vertical']]),
      new PropShape(['type' => 'string', 'enum' => ['lazy', 'eager']]),
      new PropShape(['type' => 'string', 'enum' => ['power', 'like', 'external']]),
      new PropShape(['type' => 'string', 'enum' => ['prefix', 'suffix']]),
      new PropShape(['type' => 'string', 'enum' => ['primary', 'secondary']]),
      new PropShape(['type' => 'string', 'enum' => ['primary', 'success', 'neutral', 'warning', 'danger']]),
      new PropShape(['type' => 'string', 'enum' => ['small', 'big', 'huge']]),
      new PropShape(['type' => 'string', 'enum' => ['small', 'big', 'huge', 'contains.dots']]),
      new PropShape(['type' => 'string', 'enum' => ['small', 'medium', 'large']]),
      new PropShape(['type' => 'string', 'enum' => ['top', 'bottom', 'start', 'end']]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Date->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DateTime->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Duration->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Email->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Hostname->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IdnEmail->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IdnHostname->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Ipv4->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Ipv6->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Iri->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IriReference->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::JsonPointer->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Regex->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::RelativeJsonPointer->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Time->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Uri->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriReference->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriReference->value, CustomConstraintError::X_ALLOWED_SCHEMES => ['http', 'https']]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriTemplate->value]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriTemplate->value, 'x-required-variables' => ['width']]),
      new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Uuid->value]),
      new PropShape(['type' => 'string', 'minLength' => 2]),
      new PropShape(['type' => 'string', 'pattern' => '(.|\r?\n)*']),
    ], $unique_prop_shapes);

    return $unique_prop_shapes;
  }

  /**
   * @return \Drupal\canvas\PropShape\StorablePropShape[]
   */
  public static function getExpectedStorablePropShapes(): array {
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
      'type=integer&$ref=json-schema-definitions://canvas.module/column-width' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'enum' => [25, 33, 50, 66, 75]]),
        fieldTypeProp: new FieldTypePropExpression('list_integer', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=integer&maximum=100&minimum=-100' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'maximum' => 100, 'minimum' => -100]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
        fieldInstanceSettings: ['min' => -100, 'max' => 100],
      ),
      'type=integer&maximum=2147483648&minimum=-2147483648' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'maximum' => 2147483648, 'minimum' => -2147483648]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
        fieldInstanceSettings: ['min' => -2147483648, 'max' => 2147483648],
      ),
      'type=integer&minimum=0' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'minimum' => 0]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
        fieldInstanceSettings: ['min' => 0, 'max' => ''],
      ),
      'type=integer&minimum=1' => new StorablePropShape(
        shape: new PropShape(['type' => 'integer', 'minimum' => 1]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        fieldWidget: 'number',
        fieldInstanceSettings: ['max' => '', 'min' => 1],
      ),
      'type=number' => new StorablePropShape(
        shape: new PropShape(['type' => 'number']),
        fieldTypeProp: new FieldTypePropExpression('float', 'value'),
        fieldWidget: 'number',
      ),
      'type=string' => new StorablePropShape(
        shape: new PropShape(['type' => 'string']),
        fieldTypeProp: new FieldTypePropExpression('string', 'value'),
        fieldWidget: 'string_textfield',
      ),
      'type=string&$ref=json-schema-definitions://canvas.module/image-uri' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'contentMediaType' => 'image/*', 'format' => 'uri-reference', 'x-allowed-schemes' => ['http', 'https']]),
        fieldTypeProp: new FieldTypePropExpression('image', 'src_with_alternate_widths'),
        fieldWidget: 'image_image',
      ),
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'contentMediaType' => 'image/*', 'format' => 'uri', 'x-allowed-schemes' => ['public']]),
        fieldTypeProp: new ReferenceFieldTypePropExpression(
          referencer: new FieldTypePropExpression('image', 'entity'),
          referenced: new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'value'),
        ),
        fieldWidget: 'image_image',
      ),
      'type=string&$ref=json-schema-definitions://test_theme_base.theme/organization-logo-url' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'contentMediaType' => 'image/*',
          'enum' => [
            "https://example.com/drop.svg",
            "https://example.com/drop-greater.svg",
            "https://example.com/drop-community.svg",
            "https://example.com/drop-individual.svg",
            "https://example.com/drop-stacked.svg",
            "https://example.com/drop-horizontal.svg",
          ],
          'format' => 'uri-reference',
          'x-allowed-schemes' => ['https'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
        fieldWidget: 'options_select',
      ),
      'type=object&$ref=json-schema-definitions://canvas.module/image' => new StorablePropShape(
        shape: new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image']),
        fieldTypeProp: new FieldTypeObjectPropsExpression('image', [
          'src' => new FieldTypePropExpression('image', 'src_with_alternate_widths'),
          'alt' => new FieldTypePropExpression('image', 'alt'),
          'width' => new FieldTypePropExpression('image', 'width'),
          'height' => new FieldTypePropExpression('image', 'height'),
        ]),
        fieldWidget: 'image_image',
      ),
      'type=object&$ref=json-schema-definitions://canvas.module/video' => new StorablePropShape(
        new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/video']),
        fieldTypeProp: new FieldTypeObjectPropsExpression('file', [
          'src' => new ReferenceFieldTypePropExpression(
            new FieldTypePropExpression('file', 'entity'),
            new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', NULL, 'url'),
          ),
        ]),
        fieldInstanceSettings: ['file_extensions' => 'mp4'],
        fieldWidget: 'file_generic',
      ),
      'type=string&$ref=json-schema-definitions://canvas.module/heading-element' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=foo&enum[1]=bar' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['foo', 'bar'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=_blank&enum[1]=_parent&enum[2]=_self&enum[3]=_top' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['_blank', '_parent', '_self', '_top'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=auto&enum[1]=manual' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['auto', 'manual'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=default&enum[1]=primary&enum[2]=success&enum[3]=neutral&enum[4]=warning&enum[5]=danger&enum[6]=text' => new StorablePropShape(
        new PropShape([
          'type' => 'string',
          'enum' => ['default', 'primary', 'success', 'neutral', 'warning', 'danger', 'text'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=full&enum[1]=wide&enum[2]=normal&enum[3]=narrow' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['full', 'wide', 'normal', 'narrow'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=lazy&enum[1]=eager' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['lazy', 'eager'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=prefix&enum[1]=suffix' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['prefix', 'suffix'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=primary&enum[1]=secondary' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['primary', 'secondary'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=primary&enum[1]=success&enum[2]=neutral&enum[3]=warning&enum[4]=danger' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['primary', 'success', 'neutral', 'warning', 'danger'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=small&enum[1]=medium&enum[2]=large' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['small', 'medium', 'large'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=top&enum[1]=bottom&enum[2]=start&enum[3]=end' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['top', 'bottom', 'start', 'end'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=power&enum[1]=like&enum[2]=external' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'string',
          'enum' => ['power', 'like', 'external'],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&format=uri' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => 'uri']),
        fieldTypeProp: new FieldTypePropExpression('link', 'url'),
        fieldInstanceSettings: [
          'title' => DRUPAL_DISABLED,
          'link_type' => LinkItemInterface::LINK_EXTERNAL,
        ],
        fieldWidget: 'link_default',
      ),
      'type=string&minLength=2' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'minLength' => 2]),
        fieldTypeProp: new FieldTypePropExpression('string', 'value'),
        fieldWidget: 'string_textfield',
      ),
      'type=string&format=date' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Date->value]),
        fieldTypeProp: new FieldTypePropExpression('datetime', 'value'),
        fieldWidget: 'datetime_default',
        fieldStorageSettings: [
          'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
        ],
      ),
      'type=string&format=date-time' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::DateTime->value]),
        fieldTypeProp: new FieldTypePropExpression('datetime', 'value'),
        fieldWidget: 'datetime_default',
        fieldStorageSettings: [
          'datetime_type' => DateTimeItem::DATETIME_TYPE_DATETIME,
        ],
      ),
      'type=string&format=email' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Email->value]),
        fieldTypeProp: new FieldTypePropExpression('email', 'value'),
        fieldWidget: 'email_default',
      ),
      'type=string&format=idn-email' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IdnEmail->value]),
        fieldTypeProp: new FieldTypePropExpression('email', 'value'),
        fieldWidget: 'email_default',
      ),
      'type=string&format=iri' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Iri->value]),
        fieldTypeProp: new FieldTypePropExpression('link', 'url'),
        fieldInstanceSettings: [
          'title' => DRUPAL_DISABLED,
          'link_type' => LinkItemInterface::LINK_EXTERNAL,
        ],
        fieldWidget: 'link_default',
      ),
      'type=string&format=iri-reference' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IriReference->value]),
        fieldTypeProp: new FieldTypePropExpression('link', 'url'),
        fieldInstanceSettings: [
          'title' => DRUPAL_DISABLED,
          'link_type' => LinkItemInterface::LINK_GENERIC,
        ],
        fieldWidget: 'link_default',
      ),
      'type=string&format=uri-reference' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriReference->value]),
        fieldTypeProp: new FieldTypePropExpression('link', 'url'),
        fieldInstanceSettings: [
          'title' => DRUPAL_DISABLED,
          'link_type' => LinkItemInterface::LINK_GENERIC,
        ],
        fieldWidget: 'link_default',
      ),
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriReference->value, CustomConstraintError::X_ALLOWED_SCHEMES => ['http', 'https']]),
        fieldTypeProp: new FieldTypePropExpression('link', 'url'),
        fieldInstanceSettings: [
          'title' => DRUPAL_DISABLED,
          'link_type' => LinkItemInterface::LINK_GENERIC,
        ],
        fieldWidget: 'link_default',
      ),
      'type=string&contentMediaType=text/html' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'contentMediaType' => 'text/html']),
        fieldTypeProp: new FieldTypePropExpression('text_long', 'processed'),
        fieldWidget: 'text_textarea',
        fieldInstanceSettings: [
          'allowed_formats' => [
            'canvas_html_block',
          ],
        ],
      ),
      'type=string&contentMediaType=text/html&x-formatting-context=block' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'block']),
        fieldTypeProp: new FieldTypePropExpression('text_long', 'processed'),
        fieldWidget: 'text_textarea',
        fieldInstanceSettings: [
          'allowed_formats' => [
            'canvas_html_block',
          ],
        ],
      ),
      'type=string&contentMediaType=text/html&x-formatting-context=inline' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline']),
        fieldTypeProp: new FieldTypePropExpression('text', 'processed'),
        fieldWidget: 'text_textfield',
        fieldInstanceSettings: [
          'allowed_formats' => [
            'canvas_html_inline',
          ],
        ],
      ),
      'type=integer&enum[0]=1&enum[1]=2' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'integer',
          'enum' => [1, 2],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_integer', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=integer&enum[0]=1&enum[1]=2&enum[2]=3&enum[3]=4&enum[4]=5&enum[5]=6' => new StorablePropShape(
        shape: new PropShape([
          'type' => 'integer',
          'enum' => [1, 2, 3, 4, 5, 6],
        ]),
        fieldTypeProp: new FieldTypePropExpression('list_integer', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=array&items[type]=integer' => new StorablePropShape(
        shape: new PropShape(['type' => 'array', 'items' => ['type' => 'integer']]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        fieldWidget: 'number',
      ),
      'type=array&items[type]=integer&maxItems=2' => new StorablePropShape(
        shape: new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        cardinality: 2,
        fieldWidget: 'number',
      ),
      'type=array&items[$ref]=json-schema-definitions://canvas.module/image&items[type]=object&maxItems=2' => new StorablePropShape(
        shape: new PropShape(['type' => 'array', 'items' => ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image'], 'maxItems' => 2]),
        fieldTypeProp: new FieldTypeObjectPropsExpression('image', [
          'src' => new FieldTypePropExpression('image', 'src_with_alternate_widths'),
          'alt' => new FieldTypePropExpression('image', 'alt'),
          'width' => new FieldTypePropExpression('image', 'width'),
          'height' => new FieldTypePropExpression('image', 'height'),
        ]),
        cardinality: 2,
        fieldWidget: 'image_image',
      ),
      'type=array&items[$ref]=json-schema-definitions://canvas.module/image&items[type]=object' => new StorablePropShape(
        shape: new PropShape(['type' => 'array', 'items' => ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image']]),
        fieldTypeProp: new FieldTypeObjectPropsExpression('image', [
          'src' => new FieldTypePropExpression('image', 'src_with_alternate_widths'),
          'alt' => new FieldTypePropExpression('image', 'alt'),
          'width' => new FieldTypePropExpression('image', 'width'),
          'height' => new FieldTypePropExpression('image', 'height'),
        ]),
        cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        fieldWidget: 'image_image',
      ),
      'type=array&items[type]=integer&items[minimum]=-100&items[maximum]=100&maxItems=100' => new StorablePropShape(
        shape: new PropShape(['type' => 'array', 'items' => ['type' => 'integer', 'maximum' => 100, 'minimum' => -100], 'maxItems' => 100]),
        fieldTypeProp: new FieldTypePropExpression('integer', 'value'),
        cardinality: 100,
        fieldWidget: 'number',
        fieldStorageSettings: NULL,
        fieldInstanceSettings: [
          'min' => -100,
          'max' => 100,
        ],
      ),
      'type=array&items[type]=string' => new StorablePropShape(
        shape: new PropShape(['type' => 'array', 'items' => ['type' => 'string']]),
        fieldTypeProp: new FieldTypePropExpression('string', 'value'),
        cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        fieldWidget: 'string_textfield',
      ),
      'type=string&enum[0]=7&enum[1]=3.14' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['7', '3.14']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=_self&enum[1]=_blank' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['_self', '_blank']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=horizontal&enum[1]=vertical' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['horizontal', 'vertical']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=small&enum[1]=big&enum[2]=huge' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['small', 'big', 'huge']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&enum[0]=small&enum[1]=big&enum[2]=huge&enum[3]=contains.dots' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'enum' => ['small', 'big', 'huge', 'contains.dots']]),
        fieldTypeProp: new FieldTypePropExpression('list_string', 'value'),
        fieldWidget: 'options_select',
        fieldStorageSettings: [
          'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
        ],
      ),
      'type=string&pattern=(.|\r?\n)*' => new StorablePropShape(
        shape: new PropShape(['type' => 'string', 'pattern' => '(.|\r?\n)*']),
        fieldTypeProp: new FieldTypePropExpression('string_long', 'value'),
        fieldWidget: 'string_textarea',
      ),
    ];
  }

  /**
   * @return \Drupal\canvas\PropShape\PropShape[]
   */
  public static function getExpectedUnstorablePropShapes(): array {
    return [
      'type=array&items[type]=integer&maxItems=20&minItems=1' => new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 20, 'minItems' => 1]),
      'type=array&items[type]=integer&minItems=1' => new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1]),
      'type=array&items[type]=integer&minItems=2' => new PropShape(['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2]),
      'type=object&$ref=json-schema-definitions://canvas.module/date-range' => new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/date-range']),
      'type=object&$ref=json-schema-definitions://canvas.module/shoe-icon' => new PropShape(['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/shoe-icon']),
      'type=integer&multipleOf=12' => new PropShape(['type' => 'integer', 'multipleOf' => 12]),
      'type=string&format=duration' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Duration->value]),
      'type=string&format=hostname' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Hostname->value]),
      'type=string&format=idn-hostname' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::IdnHostname->value]),
      'type=string&format=ipv4' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Ipv4->value]),
      'type=string&format=ipv6' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Ipv6->value]),
      'type=string&format=json-pointer' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::JsonPointer->value]),
      'type=string&format=regex' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Regex->value]),
      'type=string&format=relative-json-pointer' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::RelativeJsonPointer->value]),
      'type=string&format=time' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Time->value]),
      'type=string&format=uri-template' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::UriTemplate->value]),
      'type=string&format=uuid' => new PropShape(['type' => 'string', 'format' => JsonSchemaStringFormat::Uuid->value]),
      'type=array&items[type]=integer&items[minimum]=-100&items[maximum]=100&maxItems=100&minItems=2' => new PropShape([
        'type' => 'array',
        'items' => ['type' => 'integer', 'maximum' => 100, 'minimum' => -100],
        'maxItems' => 100,
        'minItems' => 2,
      ]),
      'type=string&format=uri-template&x-required-variables[0]=width' => new PropShape([
        'type' => 'string',
        'format' => JsonSchemaStringFormat::UriTemplate->value,
        'x-required-variables' => ['width'],
      ]),
      'type=object' => new PropShape([
        'type' => 'object',
      ]),
    ];
  }

  /**
   * @depends testUniquePropShapeDiscovery
   */
  public function testStorablePropShapes(array $unique_prop_shapes): array {
    $this->assertNotEmpty($unique_prop_shapes);

    /** @var \Drupal\canvas\PropShape\PropShapeRepositoryInterface $prop_shape_repository */
    $prop_shape_repository = \Drupal::service(PropShapeRepositoryInterface::class);
    $unique_storable_prop_shapes = [];
    foreach ($unique_prop_shapes as $prop_shape) {
      \assert($prop_shape instanceof PropShape);
      // If this prop shape is not storable, then fall back to the PropShape
      // object, to make it easy to assert which shapes are storable vs not.
      $unique_storable_prop_shapes[$prop_shape->uniquePropSchemaKey()] = $prop_shape_repository->getStorablePropShape($prop_shape) ?? $prop_shape;
    }

    $unstorable_prop_shapes = array_filter($unique_storable_prop_shapes, fn ($s) => $s instanceof PropShape);
    $unique_storable_prop_shapes = array_filter($unique_storable_prop_shapes, fn ($s) => $s instanceof StorablePropShape);

    $this->assertEquals(static::getExpectedStorablePropShapes(), $unique_storable_prop_shapes);

    // ⚠️ No field type + widget yet for these! For some that is fine though.
    $this->assertEquals(static::getExpectedUnstorablePropShapes(), $unstorable_prop_shapes);

    return $unique_storable_prop_shapes;
  }

  /**
   * @depends testStorablePropShapes
   * @param \Drupal\canvas\PropShape\StorablePropShape[] $storable_prop_shapes
   */
  public function testPropShapesYieldWorkingStaticPropSources(array $storable_prop_shapes): void {
    // If a test method extending this one has already set up a user with
    // permissions, we do not need to do it again.
    // @see \Drupal\Tests\canvas\Kernel\MediaLibraryHookStoragePropAlterTest::testPropShapesYieldWorkingStaticPropSources
    if (\Drupal::currentUser()->isAnonymous()) {
      $this->setUpCurrentUser(permissions: ['access content']);
    }

    $this->assertNotEmpty($storable_prop_shapes);
    foreach ($storable_prop_shapes as $key => $storable_prop_shape) {
      // A static prop source can be generated.
      $prop_source = $storable_prop_shape->toStaticPropSource();

      // A widget can be generated.
      $widget = $prop_source->getWidget('irrelevant-for-this-test', 'irrelevant-for-this-test', $key, $this->randomString(), $storable_prop_shape->fieldWidget);
      $this->assertSame($storable_prop_shape->fieldWidget, $widget->getPluginId());

      // A widget form can be generated.
      // @see \Drupal\Core\Entity\Entity\EntityFormDisplay::buildForm()
      // @see \Drupal\Core\Field\WidgetBase::form()
      $form = ['#parents' => [$this->randomMachineName()]];
      $form_state = new FormState();
      $form = $prop_source->formTemporaryRemoveThisExclamationExclamationExclamation($widget, 'some-prop-name', FALSE, User::create([]), $form, $form_state);

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

      // Some core SDCs have enums without meta:enums, which we aren't
      // supporting. So instead of option_list we are getting a textfield.
      // So we would need to ignore those or just use one of the
      // valid values for now. This should not be needed after requiring 11.2.x
      // which will include https://drupal.org/i/3493070.
      if (isset($storable_prop_shape->shape->schema['enum'])) {
        $randomized_prop_source = $prop_source->withValue($storable_prop_shape->shape->schema['enum'][0]);
      }

      $random_value = $randomized_prop_source->getValue();
      $stored_randomized_prop_source = (string) $randomized_prop_source;
      $reloaded_randomized_prop_source = StaticPropSource::parse(json_decode($stored_randomized_prop_source, TRUE));
      $this->assertSame($random_value, $reloaded_randomized_prop_source->getValue());
      // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
      $some_prop_name = $this->randomMachineName();
      $schema = Validator::arrayToObjectRecursive([
        'type' => 'object',
        'required' => [$some_prop_name],
        'properties' => [$some_prop_name => $storable_prop_shape->shape->schema],
        'additionalProperties' => FALSE,
      ]);
      $props = Validator::arrayToObjectRecursive([
        $some_prop_name => $reloaded_randomized_prop_source->evaluate(NULL, is_required: TRUE)->value,
      ]);
      $validator = new Validator();
      $validator->validate($props, $schema, Constraint::CHECK_MODE_TYPE_CAST);
      $this->assertSame(
        [],
        $validator->getErrors(),
        \sprintf("Sample value %s generated by field type %s for %s is invalid!",
          json_encode($random_value),
          $storable_prop_shape->fieldTypeProp->getFieldType(),
          $storable_prop_shape->shape->uniquePropSchemaKey()
        )
      );
    }
  }

  /**
   * @depends testStorablePropShapes
   * @param \Drupal\canvas\PropShape\StorablePropShape[] $storable_prop_shapes
   *
   * @covers \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetInfoAlter()
   */
  public function testAllWidgetsForPropShapesHaveTransforms(array $storable_prop_shapes): void {
    self::assertNotEmpty($storable_prop_shapes);
    $widget_manager = $this->container->get('plugin.manager.field.widget');
    \assert($widget_manager instanceof WidgetPluginManager);
    $definitions = $widget_manager->getDefinitions();
    foreach ($storable_prop_shapes as $storable_prop_shape) {
      // A static prop source can be generated.
      $storable_prop_shape->toStaticPropSource();

      $widget_plugin_id = $storable_prop_shape->fieldWidget;
      self::assertArrayHasKey($widget_plugin_id, $definitions);
      $definition = $definitions[$widget_plugin_id];
      self::assertArrayHasKey('canvas', $definition, \sprintf('Found transform for %s', $widget_plugin_id));
      self::assertArrayHasKey('transforms', $definition['canvas']);
    }
  }

  /**
   * @covers \Drupal\canvas\PropShape\PersistentPropShapeRepository::resolveCacheMiss()
   * @covers \Drupal\canvas\PropShape\PersistentPropShapeRepository::invalidateTags()
   *
   * @see ::getExpectedUnstorablePropShapes()
   * @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
   * @see \Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType\MultipleOfItem
   *
   * This using Component config entities too, but only to help prove the alter
   * hooks are invoked when necessary.
   */
  public function testStorablePropShapeAlter(): void {
    // If the module is already installed during ::setUp(), then this test is
    // still worth running, but only needs to test the "not resolving" part.
    $module_to_install = 'canvas_test_storable_prop_shape_alter';
    $module_is_already_installed = in_array($module_to_install, static::$modules, TRUE);

    \Drupal::service(ComponentSourceManager::class)->generateComponents();

    $component_id = SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.sdc_test_all_props.all-props';
    $prop_name = 'test_integer_by_the_dozen';

    $component = \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->loadUnchanged($component_id);
    \assert($component instanceof Component);
    self::assertCount(1, $component->getVersions());
    $settings = $component->getSettings();
    if ($module_is_already_installed) {
      self::assertArrayHasKey($prop_name, $settings['prop_field_definitions']);
    }
    else {
      self::assertArrayNotHasKey($prop_name, $settings['prop_field_definitions']);

      // Now enable the 'canvas_test_storable_prop_shape_alter' module.
      // @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
      \Drupal::service(ModuleInstallerInterface::class)
        ->install([$module_to_install]);
      // Note that we don't need to call destruct() here. The
      // invalidation is triggering that as expected!

      $component = \Drupal::entityTypeManager()
        ->getStorage(Component::ENTITY_TYPE_ID)
        ->loadUnchanged($component_id);
      \assert($component instanceof Component);
      self::assertCount(2, $component->getVersions());
      $settings = $component->getSettings();
    }
    self::assertArrayHasKey($prop_name, $settings['prop_field_definitions']);
    self::assertSame(MultipleOfItem::PLUGIN_ID, $settings['prop_field_definitions'][$prop_name]['field_type']);
    self::assertSame('number', $settings['prop_field_definitions'][$prop_name]['field_widget']);

    \Drupal::state()->set(CanvasTestStorablePropShapeAlterHooks::STATE_KEY_AND_CACHE_TAG, TRUE);
    $prop_shape_repository = \Drupal::service(PropShapeRepositoryInterface::class);
    \assert($prop_shape_repository instanceof CacheCollectorInterface);
    \assert($prop_shape_repository instanceof PersistentPropShapeRepositoryTestHelper);
    $prop_shape_repository->invalidateTags([CanvasTestStorablePropShapeAlterHooks::STATE_KEY_AND_CACHE_TAG]);
    // The invalidation would happen immediately, but we are preventing any
    // unneeded calls to regenerate Components in the same request.
    // So we need a helper class that can simulate that, and call destruct()
    // manually.
    // Sadly, even without the fix for supporting disappearing prop shapes, this
    // test would still pass, as that flag work-around is actually forcing some
    // extra updates.
    // Being honest, the asserts below are here mostly for documenting purposes.
    $prop_shape_repository->setCacheCreated(\time());
    $prop_shape_repository->destruct();

    $component = \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->loadUnchanged($component_id);
    \assert($component instanceof Component);
    self::assertCount($module_is_already_installed ? 2 : 3, $component->getVersions());
    $settings = $component->getSettings();
    self::assertArrayNotHasKey($prop_name, $settings['prop_field_definitions']);
  }

}
