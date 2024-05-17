<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\SdcPropJsonSchemaType;
use Drupal\experience_builder\SdcPropToFieldTypePropMatcher;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;

/**
 * Tests matching SDC props against field type + field instance props.
 */
class SdcPropToFieldTypePropTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Necessary for uninstalling modules.
    $this->installSchema('user', ['users_data']);
  }

  /**
   * Tests matches for component props.
   *
   * @param string[] $modules
   * @param array{'modules': string[], 'expected': array<string, array<mixed>>} $expected
   *
   * @dataProvider provider
   */
  public function test(array $modules, array $expected): void {
    $missing_test_modules = array_diff($modules, array_keys(\Drupal::service('extension.list.module')->getList()));
    if (!empty($missing_test_modules)) {
      $this->markTestSkipped(sprintf('The %s test modules are missing.', implode(',', $missing_test_modules)));
      // @phpstan-ignore-next-line
      return;
    }

    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install($modules);

    // Create configurable fields for certain combinations of modules.
    if (empty(array_diff(['node', 'field', 'image'], $modules))) {
      $this->installEntitySchema('node');
      $this->installEntitySchema('field_storage_config');
      $this->installEntitySchema('field_config');
      // Create a "Foo" node type.
      NodeType::create([
        'name' => 'Foo',
        'type' => 'foo',
      ])->save();
      // Create a "silly image" field on the "Foo" node type.
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_silly_image',
        'type' => 'image',
      ])->save();
      FieldConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_silly_image',
        'bundle' => 'foo',
        'required' => TRUE,
      ])->save();
      // Create a "event duration" field on the "Foo" node type.
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_event_duration',
        'type' => 'daterange',
      ])->save();
      FieldConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_event_duration',
        'bundle' => 'foo',
        'required' => TRUE,
      ])->save();
    }

    $sdc_manager = \Drupal::service('plugin.manager.sdc');
    $matcher = \Drupal::service(SdcPropToFieldTypePropMatcher::class);
    assert($matcher instanceof SdcPropToFieldTypePropMatcher);

    $matches = [];
    foreach ($sdc_manager->getAllComponents() as $component) {
      $component_name = $component->getPluginId();

      // Retrieve the full JSON schema definition from the SDC's metadata.
      // @see \Drupal\sdc\Component\ComponentValidator::validateProps()
      // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      /** @var array<string, mixed> $schema */
      $schema = $component->metadata->schema;
      foreach ($matcher->iterateJsonSchema($schema) as $prop_name => [
        'required' => $is_required,
        'schema' => $schema,
      ]) {
        $cpe = new ComponentPropExpression($component_name, $prop_name);

        // TRICKY: `attributes` is a special case — it is kind of a reserved
        // prop.
        // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
        // @see https://www.drupal.org/project/drupal/issues/3352063#comment-15277820
        if ($prop_name === 'attributes') {
          assert($schema['type'][0] === Attribute::class);
          continue;
        }

        if (isset($schema['$ref'])) {
          // Prove a $ref URL can be transformed into a publicly accessible URL.
          $stream_wrapper = \Drupal::service(StreamWrapperManagerInterface::class)->getViaUri($schema['$ref']);
          $this->assertInstanceOf(StreamWrapperInterface::class, $stream_wrapper);
          $public_ref_url = $stream_wrapper->getExternalUrl();
          $this->assertStringEndsWith('/experience_builder/schema.json#defs/' . basename($schema['$ref']), $public_ref_url);
        }

        $primitive_type = SdcPropJsonSchemaType::from(
          // TRICKY: SDC always allowed `object` for Twig integration reasons.
          // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
          is_array($schema['type']) ? $schema['type'][0] : $schema['type']
        );

        // phpcs:disable
        // From least to most restrictive matchmaking of structured data sources
        // to flow into component props:
        // 1. storage representation must match
        $subschema = $primitive_type->isScalar() ? NULL : $schema;
        $storage_candidates = $matcher->findFieldTypeStorageCandidates($primitive_type, $is_required, $subschema);
        // 2. format must match
        //    👉 UX need: when the BUILDER is creating a content type's template
        //       and they declare the intent to not statically assign a value to
        //       a component prop, then the "main" property of a field type must
        //       match semantically. These are the available choices to create a
        //       new field!
        //    🎉 Component placement at a structural level (content
        //       template) encourages EXPANDING the data model IF needs are met!
        //    ❓ UX need: when the CREATOR is placing a component and they want
        //       to statically assign a value; then it's also preferable to use
        //       a field type's "main" property (for the best semantical match),
        //       but using a non-main property is fine too, especially when
        //       reusing structured data.
        $format_candidates_main_prop = $matcher->findFieldTypeFormatCandidates($primitive_type, $is_required, $schema, TRUE);
        $format_candidates_any_prop = $matcher->findFieldTypeFormatCandidates($primitive_type, $is_required, $schema, FALSE);
        // 3. a field instance of this type must exist.
        //    👉 UX need: when the BUILDER is creating a content type's template
        //       OR the creator is placing a component in a slot, and they
        //       declare the intent to not statically assign a value to a
        //       component prop, then these are the available choices
        //    🎉 Component placement at a structural level (content
        //       template) encourages USING the data model IF needs are not met!
        $instance_candidates = $matcher->findFieldInstanceFormatMatches($primitive_type, $is_required, $schema);
        // 4. adapters.
        // @todo Make adapters a reality; but how to not overwhelm? 🤔 Probably we should only generate these for SDC props with a `format` that otherwise has zero matches? Because we could cast any `int` to a `string`, but that'd just result in terrible UX.
        //$adapted_candidates = [];
        // phpcs:enable

        // For each component prop ($cpe), store the string representations of
        // the discovered matches to compare against.
        $matches[(string) $cpe]['storage'] = array_map(fn (FieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $storage_candidates);
        $matches[(string) $cpe]['format_any_prop'] = array_map(fn (FieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $format_candidates_any_prop);
        $matches[(string) $cpe]['format_main_prop'] = array_map(fn (FieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $format_candidates_main_prop);
        $matches[(string) $cpe]['instances'] = array_map(fn (FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $e): string => (string) $e, $instance_candidates);
      }
    }

    $this->assertSame($expected, $matches);

    $module_installer->uninstall($modules);
  }

  /**
   * @return \Generator<string, array{'modules': string[], 'expected': array<string, array<mixed>>}>
   */
  public static function provider() {
    $all_string_storage_props = [
      'ℹ︎comment␟last_comment_name',
      'ℹ︎daterange␟end_value',
      'ℹ︎daterange␟value',
      'ℹ︎datetime␟value',
      'ℹ︎decimal␟value',
      'ℹ︎email␟value',
      'ℹ︎file_uri␟url',
      'ℹ︎file_uri␟value',
      'ℹ︎file␟description',
      'ℹ︎image␟alt',
      'ℹ︎image␟title',
      'ℹ︎language␟value',
      'ℹ︎link␟title',
      'ℹ︎link␟uri',
      'ℹ︎list_string␟value',
      'ℹ︎password␟existing',
      'ℹ︎password␟value',
      'ℹ︎path␟alias',
      'ℹ︎path␟langcode',
      'ℹ︎string_long␟value',
      'ℹ︎string␟value',
      'ℹ︎telephone␟value',
      'ℹ︎text_long␟format',
      'ℹ︎text_long␟value',
      'ℹ︎text_with_summary␟format',
      'ℹ︎text_with_summary␟summary',
      'ℹ︎text_with_summary␟value',
      'ℹ︎text␟format',
      'ℹ︎text␟processed',
      'ℹ︎text␟value',
      'ℹ︎uri␟value',
      'ℹ︎uuid␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝filemime␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝filename␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝langcode␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟url',
      'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝uuid␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝filemime␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝filename␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝langcode␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
      'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝uuid␞0␟value',
    ];
    $all_integer_storage_props = [
      'ℹ︎changed␟value',
      'ℹ︎comment␟cid',
      'ℹ︎comment␟comment_count',
      'ℹ︎comment␟last_comment_timestamp',
      'ℹ︎comment␟last_comment_uid',
      'ℹ︎comment␟status',
      'ℹ︎created␟value',
      'ℹ︎entity_reference␟target_id',
      'ℹ︎file␟target_id',
      'ℹ︎image␟height',
      'ℹ︎image␟target_id',
      'ℹ︎image␟width',
      'ℹ︎integer␟value',
      'ℹ︎list_integer␟value',
      'ℹ︎path␟pid',
      'ℹ︎timestamp␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝changed␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝created␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝fid␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝filesize␞0␟value',
      'ℹ︎︎file␟entity␜︎␜entity:file␝uid␞0␟target_id',
      'ℹ︎︎image␟entity␜︎␜entity:file␝changed␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝created␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝fid␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝filesize␞0␟value',
      'ℹ︎︎image␟entity␜︎␜entity:file␝uid␞0␟target_id',
    ];

    yield 'test SDC, using ALL core-provided field types' => [
      'modules' => [
        // The module providing the sample SDC to test all JSON schema types.
        'sdc_test_all_props',
        // All other core modules providing field types.
        'comment',
        'datetime',
        'datetime_range',
        'file',
        'image',
        'link',
        'options',
        'path',
        'telephone',
        'text',
        // Create sample configurable fields on the `node` entity type.
        'node',
        'field',
      ],
      'expected' => [
        '⿲experience_builder:image␟image' => [
          'storage' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'format_any_prop' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'format_main_prop' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜ℹ︎␜entity:file␝uri␞␟value, alt↠alt, width↠width, height↠height}',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file␟description',
            'ℹ︎image␟alt',
            'ℹ︎image␟title',
            'ℹ︎link␟title',
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            'ℹ︎␜entity:node:foo␝revision_log␞␟value',
            'ℹ︎␜entity:node:foo␝title␞␟value',
            'ℹ︎␜entity:path_alias␝alias␞␟value',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-REQUIRED-string' => [
          'storage' => [
            'ℹ︎daterange␟end_value',
            'ℹ︎daterange␟value',
            'ℹ︎datetime␟value',
            'ℹ︎decimal␟value',
            'ℹ︎email␟value',
            'ℹ︎file_uri␟url',
            'ℹ︎file_uri␟value',
            'ℹ︎language␟value',
            'ℹ︎list_string␟value',
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
            'ℹ︎telephone␟value',
            'ℹ︎text_long␟value',
            'ℹ︎text_with_summary␟value',
            'ℹ︎text␟value',
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝title␞␟value',
            'ℹ︎␜entity:path_alias␝alias␞␟value',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-enum' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Make this work using the `list_string` field type
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::DATE_TIME->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎daterange␟end_value',
            'ℹ︎daterange␟value',
            'ℹ︎datetime␟value',
          ],
          'format_main_prop' => [
            'ℹ︎daterange␟value',
            'ℹ︎datetime␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::DATE->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎daterange␟end_value',
            'ℹ︎daterange␟value',
            'ℹ︎datetime␟value',
          ],
          'format_main_prop' => [
            'ℹ︎daterange␟value',
            'ℹ︎datetime␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::TIME->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Adapter for @FieldType=timestamp -> `type:string,format=time`, @FieldType=datetime -> `type:string,format=time`
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::DURATION->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo No field type in Drupal core uses \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601.
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::EMAIL->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎email␟value',
          ],
          'format_main_prop' => [
            'ℹ︎email␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:user␝init␞␟value',
            'ℹ︎␜entity:user␝mail␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝init␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝mail␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝init␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝mail␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝init␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝mail␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IDN_EMAIL->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎email␟value',
          ],
          'format_main_prop' => [
            'ℹ︎email␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:user␝init␞␟value',
            'ℹ︎␜entity:user␝mail␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝init␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝mail␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝init␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝mail␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝init␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝mail␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::HOSTNAME->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo adapter from `type: string, format=uri`?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IDN_HOSTNAME->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // phpcs:disable
            // @todo adapter from `type: string, format=uri`?
            // @todo To generate a match for this JSON schema type:
            // - generate an adapter?! -> but we cannot just adapt arbitrary data to generate a IP
            // - follow entity references in the actual data model, i.e. this will find matches at the instance level? -> but does not allow the BUILDER persona to create instances
            // - create an instance with the necessary requirement?! => `@FieldType=string` + `Ip` constraint … but no field type allows configuring this?
            // phpcs:enable
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IPV4->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IPV6->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::UUID->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎uuid␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uuid␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uuid␞0␟value',
          ],
          'format_main_prop' => [
            'ℹ︎uuid␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uuid␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uuid␞0␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uuid␞␟value',
            'ℹ︎␜entity:node:foo␝uuid␞␟value',
            'ℹ︎␜entity:path_alias␝uuid␞␟value',
            'ℹ︎␜entity:user␝uuid␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝uuid␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uuid␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝uuid␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝uuid␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file_uri␟url',
            'ℹ︎file_uri␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'format_main_prop' => [
            'ℹ︎file_uri␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:file␝uri␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uri␞␟url',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uri␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI->value . '-image' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'format_main_prop' => [
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'instances' => [
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uri␞␟url',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uri␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI_REFERENCE->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎path␟alias',
          ],
          'format_main_prop' => [
            'ℹ︎path␟alias',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝path␞␟alias',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IRI->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file_uri␟url',
            'ℹ︎file_uri␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'format_main_prop' => [
            'ℹ︎file_uri␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uri␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟url',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uri␞0␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:file␝uri␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uri␞␟url',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uri␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IRI_REFERENCE->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎path␟alias',
          ],
          'format_main_prop' => [
            'ℹ︎path␟alias',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝path␞␟alias',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI_TEMPLATE->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::JSON_POINTER->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::RELATIVE_JSON_POINTER->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::REGEX->value => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
          'format_main_prop' => [],
          'instances' => [],
        ],

        // Integers.
        '⿲sdc_test_all_props:all-props␟test-integer' => [
          'storage' => $all_integer_storage_props,
          'format_any_prop' => $all_integer_storage_props,
          'format_main_prop' => [
            'ℹ︎changed␟value',
            'ℹ︎comment␟status',
            'ℹ︎created␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎file␟target_id',
            'ℹ︎image␟target_id',
            'ℹ︎integer␟value',
            'ℹ︎list_integer␟value',
            'ℹ︎timestamp␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝changed␞0␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝created␞0␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝fid␞0␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝filesize␞0␟value',
            'ℹ︎︎file␟entity␜︎␜entity:file␝uid␞0␟target_id',
            'ℹ︎︎image␟entity␜︎␜entity:file␝changed␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝created␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝fid␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝filesize␞0␟value',
            'ℹ︎︎image␟entity␜︎␜entity:file␝uid␞0␟target_id',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝changed␞␟value',
            'ℹ︎␜entity:file␝created␞␟value',
            'ℹ︎␜entity:file␝fid␞␟value',
            'ℹ︎␜entity:file␝filesize␞␟value',
            'ℹ︎␜entity:file␝uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝changed␞␟value',
            'ℹ︎␜entity:node:foo␝created␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟target_id',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            'ℹ︎␜entity:node:foo␝nid␞␟value',
            'ℹ︎␜entity:node:foo␝path␞␟pid',
            'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝vid␞␟value',
            'ℹ︎␜entity:path_alias␝id␞␟value',
            'ℹ︎␜entity:path_alias␝revision_id␞␟value',
            'ℹ︎␜entity:user␝access␞␟value',
            'ℹ︎␜entity:user␝changed␞␟value',
            'ℹ︎␜entity:user␝created␞␟value',
            'ℹ︎␜entity:user␝login␞␟value',
            'ℹ︎␜entity:user␝uid␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝access␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝changed␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝created␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝login␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝uid␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝changed␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝created␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝fid␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝filesize␞␟value',
            'ℹ︎︎␜entity:node:foo␝field_silly_image␞␟entity␜︎␜entity:file␝uid␞␟target_id',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝access␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝changed␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝created␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝login␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝uid␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝access␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝changed␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝created␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝login␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝uid␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-integer-range-minimum' => [
          'storage' => $all_integer_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-integer-range-minimum-maximum-timestamps' => [
          'storage' => $all_integer_storage_props,
          'format_any_prop' => [
            'ℹ︎timestamp␟value',
          ],
          'format_main_prop' => [
            'ℹ︎timestamp␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:user␝access␞␟value',
            'ℹ︎␜entity:user␝login␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝access␞␟value',
            'ℹ︎︎␜entity:file␝uid␞␟entity␜︎␜entity:user␝login␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝access␞␟value',
            'ℹ︎︎␜entity:node:foo␝revision_uid␞␟entity␜︎␜entity:user␝login␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝access␞␟value',
            'ℹ︎︎␜entity:node:foo␝uid␞␟entity␜︎␜entity:user␝login␞␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-object-drupal-image' => [
          'storage' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'format_any_prop' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'format_main_prop' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜ℹ︎␜entity:file␝uri␞␟value, alt↠alt, width↠width, height↠height}',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-object-drupal-date-range' => [
          'storage' => [
            'ℹ︎daterange␟{from↠end_value, to↠value}',
          ],
          'format_any_prop' => [
            'ℹ︎daterange␟{from↠end_value, to↠value}',
          ],
          'format_main_prop' => [
            'ℹ︎daterange␟{from↠end_value, to↠value}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value, to↠end_value}',
          ],
        ],
      ],
    ];

    $core_only_string_storage_props = [
      'ℹ︎decimal␟value',
      'ℹ︎email␟value',
      'ℹ︎language␟value',
      'ℹ︎password␟existing',
      'ℹ︎password␟value',
      'ℹ︎string_long␟value',
      'ℹ︎string␟value',
      'ℹ︎uri␟value',
      'ℹ︎uuid␟value',
    ];
    $core_only_string_storage_props_without_password_for_tbd_reason = [
      'ℹ︎decimal␟value',
      'ℹ︎email␟value',
      'ℹ︎language␟value',
      'ℹ︎string_long␟value',
      'ℹ︎string␟value',
      'ℹ︎uri␟value',
      'ℹ︎uuid␟value',
    ];
    yield 'real-world SDCs, using only always-provided field types' => [
      'modules' => [
        // The modules providing sample SDCs.
        'cl_editorial',
        'sdc_examples',
        'sdc_test',
      ],
      'expected' => [
        '⿲cl_editorial:component-card␟name' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟machineName' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟id' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟description' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟status' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟thumbnailHref' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟group' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲experience_builder:image␟image' => [
          'storage' => [],
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_examples:my-cta␟text' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-cta␟href' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎uri␟value',
          ],
          'format_main_prop' => [
            'ℹ︎uri␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-cta␟target' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_examples:my-button--primary␟text' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-button--primary␟iconType' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_examples:my-button␟text' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-button␟iconType' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_examples:my-marquee␟text' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-marquee␟scrollAmount' => [
          'storage' => [
            'ℹ︎changed␟value',
            'ℹ︎created␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎float␟value',
            'ℹ︎integer␟value',
            'ℹ︎timestamp␟value',
          ],
          'format_any_prop' => [
            'ℹ︎changed␟value',
            'ℹ︎created␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎float␟value',
            'ℹ︎integer␟value',
            'ℹ︎timestamp␟value',
          ],
          'format_main_prop' => [
            'ℹ︎changed␟value',
            'ℹ︎created␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎float␟value',
            'ℹ︎integer␟value',
            'ℹ︎timestamp␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:user␝access␞␟value',
            'ℹ︎␜entity:user␝changed␞␟value',
            'ℹ︎␜entity:user␝created␞␟value',
            'ℹ︎␜entity:user␝login␞␟value',
            'ℹ︎␜entity:user␝uid␞␟value',
          ],
        ],
        '⿲sdc_examples:my-banner␟heading' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner␟ctaText' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner␟ctaHref' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner␟ctaTarget' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner␟image' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-linked-media␟image' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-linked-media␟href' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner--tall␟heading' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner--tall␟ctaText' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner--tall␟ctaHref' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner--tall␟ctaTarget' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_examples:my-banner--tall␟image' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-card--light␟header' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_examples:my-card␟header' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-cta␟text' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-cta␟href' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎uri␟value',
          ],
          'format_main_prop' => [
            'ℹ︎uri␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-cta␟target' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test:array-to-object␟testProp' => [
          'storage' => [],
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test:my-button␟text' => [
          'storage' => $core_only_string_storage_props_without_password_for_tbd_reason,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-button␟iconType' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test:my-banner␟heading' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-banner␟ctaText' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-banner␟ctaHref' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
        '⿲sdc_test:my-banner␟ctaTarget' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲sdc_test:my-banner␟image' => [
          'storage' => $core_only_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [],
        ],
      ],
    ];
    yield 'real-world SDCs, using ALL core-provided field types' => [
      'modules' => [
        // The modules providing sample SDCs.
        'cl_editorial',
        // @todo Expand test coverage with `sdc_test` and `sdc_examples`?
        // All other core modules providing field types.
        'comment',
        'datetime',
        'datetime_range',
        'file',
        'image',
        'link',
        'options',
        'path',
        'telephone',
        'text',
      ],
      'expected' => [
        '⿲cl_editorial:component-card␟name' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file␟description',
            'ℹ︎image␟alt',
            'ℹ︎image␟title',
            'ℹ︎link␟title',
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:path_alias␝alias␞␟value',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲cl_editorial:component-card␟machineName' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟id' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟description' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file␟description',
            'ℹ︎image␟alt',
            'ℹ︎image␟title',
            'ℹ︎link␟title',
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:path_alias␝alias␞␟value',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲cl_editorial:component-card␟status' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
        ],
        '⿲cl_editorial:component-card␟thumbnailHref' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎file␟description',
            'ℹ︎image␟alt',
            'ℹ︎image␟title',
            'ℹ︎link␟title',
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [
            // @todo wrong matches because wrong SDC prop type definition
            'ℹ︎␜entity:path_alias␝alias␞␟value',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲cl_editorial:component-card␟group' => [
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file␟description',
            'ℹ︎image␟alt',
            'ℹ︎image␟title',
            'ℹ︎link␟title',
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'format_main_prop' => [
            'ℹ︎string_long␟value',
            'ℹ︎string␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:path_alias␝alias␞␟value',
            'ℹ︎␜entity:path_alias␝path␞␟value',
          ],
        ],
        '⿲experience_builder:image␟image' => [
          'storage' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'format_any_prop' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'format_main_prop' => [
            'ℹ︎image␟{src↝entity␜ℹ︎␜entity:file␝uri␞0␟value, alt↠alt, width↠width, height↠height}',
          ],
          'instances' => [],
        ],
      ],
    ];
  }

}
