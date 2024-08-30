<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Plugin\Component;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape;
use Drupal\experience_builder\SdcPropJsonSchemaType;
use Drupal\experience_builder\SdcPropToFieldTypePropMatcher;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * Tests matching SDC props against field type + field instance props.
 */
class SdcPropToFieldTypePropTest extends KernelTestBase {

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
    $components = $sdc_manager->getAllComponents();
    // Ensure the consistent sorting that ComponentPluginManager should have
    // already guaranteed.
    $components = array_combine(
      array_map(fn (Component $c) => $c->getPluginId(), $components),
      $components
    );
    ksort($components);
    // @todo Support matching `type: array` prop shapes in https://www.drupal.org/project/experience_builder/issues/3467870
    unset($components['experience_builder:shoe_tab_group']);
    foreach ($components as $component) {
      // Do not find a match for every unique component prop, but only for
      // unique prop shapes. This avoids a lot of meaningless test expectations.
      foreach (PropShape::getComponentProps($component) as $cpe_string => $prop_shape) {
        $cpe = ComponentPropExpression::fromString($cpe_string);
        // @see https://json-schema.org/understanding-json-schema/reference/object#required
        // @see https://json-schema.org/learn/getting-started-step-by-step#required
        $is_required = in_array($cpe->propName, $component->metadata->schema['required'] ?? [], TRUE);

        $unique_match_key = sprintf('%s, %s',
          $is_required ? 'REQUIRED' : 'optional',
          $prop_shape->uniquePropSchemaKey(),
        );

        $matches[$unique_match_key]['component props'][] = $cpe_string;

        if (isset($matches[$unique_match_key]['storage'])) {
          continue;
        }

        $schema = $prop_shape->resolvedSchema;

        $primitive_type = SdcPropJsonSchemaType::from($schema['type']);
        // phpcs:disable
        // From least to most restrictive matchmaking of structured data sources
        // to flow into component props:
        // 1. storage representation must match
        $sub_schema = $primitive_type->isScalar() ? NULL : $prop_shape->resolvedSchema;
        $storage_candidates = $matcher->findFieldTypeStorageCandidates($primitive_type, $is_required, $sub_schema);
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
        // phpcs:enable
        $instance_candidates = $matcher->findFieldInstanceFormatMatches($primitive_type, $is_required, $schema);
        // 4. adapters.
        $adapter_output_matches = $matcher->findAdaptersByMatchingOutput($schema);
        $adapter_matches_field_type = [];
        $adapter_matches_instance = [];
        foreach ($adapter_output_matches as $match) {
          foreach ($match->getInputs() as $input_name => $input_schema_ref) {

            $input_schema = $match->getInputSchema($input_name);
            $input_primitive_type = SdcPropJsonSchemaType::from(
              is_array($input_schema['type']) ? $input_schema['type'][0] : $input_schema['type']
            );

            $input_is_required = $match->inputIsRequired($input_name);
            $field_type_matches = $matcher->findFieldTypeFormatCandidates($input_primitive_type, $input_is_required, $input_schema, TRUE);
            $instance_matches = $matcher->findFieldInstanceFormatMatches($input_primitive_type, $input_is_required, $input_schema);

            $adapter_matches_field_type[$match->getPluginId()][$input_name] = array_map(fn (FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $field_type_matches);
            $adapter_matches_instance[$match->getPluginId()][$input_name] = array_map(fn (FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $e): string => (string) $e, $instance_matches);
          }
          ksort($adapter_matches_field_type);
          ksort($adapter_matches_instance);
        }

        // For each unique required/optional PropShape, store the string
        // representations of the discovered matches to compare against.
        $matches[$unique_match_key]['storage'] = array_map(fn (FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $storage_candidates);
        $matches[$unique_match_key]['format_any_prop'] = array_map(fn (FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $format_candidates_any_prop);
        $matches[$unique_match_key]['format_main_prop'] = array_map(fn (FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression $e): string => (string) $e, $format_candidates_main_prop);
        $matches[$unique_match_key]['instances'] = array_map(fn (FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $e): string => (string) $e, $instance_candidates);
        $matches[$unique_match_key]['adapter_matches_field_type'] = $adapter_matches_field_type;
        $matches[$unique_match_key]['adapter_matches_instance'] = $adapter_matches_instance;
      }
    }

    ksort($matches);
    $this->assertSame($expected, $matches);

    $module_installer->uninstall($modules);
  }

  /**
   * @return array<string, array{'modules': string[], 'expected': array<string, array<mixed>>}>
   */
  public static function provider(): array {
    $all_required_string_storage_props = [
      'ℹ︎daterange␟end_value',
      'ℹ︎daterange␟value',
      'ℹ︎datetime␟value',
      'ℹ︎decimal␟value',
      'ℹ︎email␟value',
      'ℹ︎file_uri␟url',
      'ℹ︎file_uri␟value',
      'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
      'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
      'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
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
    ];
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
      'ℹ︎file␟entity␜␜entity:file␝filemime␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝filename␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝langcode␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
      'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝uuid␞0␟value',
      'ℹ︎image␟alt',
      'ℹ︎image␟entity␜␜entity:file␝filemime␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝filename␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝langcode␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
      'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝uuid␞0␟value',
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
      'ℹ︎file␟entity␜␜entity:file␝changed␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝created␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝fid␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝filesize␞0␟value',
      'ℹ︎file␟entity␜␜entity:file␝uid␞0␟target_id',
      'ℹ︎file␟target_id',
      'ℹ︎image␟entity␜␜entity:file␝changed␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝created␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝fid␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝filesize␞0␟value',
      'ℹ︎image␟entity␜␜entity:file␝uid␞0␟target_id',
      'ℹ︎image␟height',
      'ℹ︎image␟target_id',
      'ℹ︎image␟width',
      'ℹ︎integer␟value',
      'ℹ︎list_integer␟value',
      'ℹ︎path␟pid',
      'ℹ︎timestamp␟value',
    ];

    $cases = [];
    $cases['XB example SDCs + all-props SDC, using ALL core-provided field types'] = [
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
        'REQUIRED, type=integer&$ref=json-schema-definitions://experience_builder.module/column-width' => [
          'component props' => [
            '⿲experience_builder:two_column␟width',
          ],
          'storage' => [
            'ℹ︎changed␟value',
            'ℹ︎comment␟status',
            'ℹ︎created␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎file␟target_id',
            'ℹ︎image␟target_id',
            'ℹ︎integer␟value',
            'ℹ︎list_integer␟value',
            'ℹ︎timestamp␟value',
          ],
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=object&$ref=json-schema-definitions://experience_builder.module/image' => [
          'component props' => [
            '⿲experience_builder:image␟image',
          ],
          'storage' => [
            'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'format_any_prop' => [
            'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'format_main_prop' => [
            'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
          ],
          'adapter_matches_field_type' => [
            'image_apply_style' => [
              'image' => ['ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}'],
              'imageStyle' => [],
              // @todo Figure out best way to describe config entity id via JSON schema.
              // 'imageStyle' => ['ℹ︎image_style_reference␟target_id'],
            ],
            'image_url_rel_to_abs' => [
              'image' => ['ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}'],
            ],
          ],
          'adapter_matches_instance' => [
            'image_apply_style' => [
              'image' => ['ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}'],
              'imageStyle' => [],
            ],
            'image_url_rel_to_abs' => [
              'image' => ['ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}'],
            ],
          ],
        ],
        'REQUIRED, type=string' => [
          'component props' => [
            '⿲experience_builder:heading␟text',
            '⿲experience_builder:shoe_details␟summary',
            '⿲experience_builder:shoe_tab␟label',
            '⿲experience_builder:shoe_tab␟panel',
            '⿲experience_builder:shoe_tab_panel␟name',
            '⿲sdc_test_all_props:all-props␟test_REQUIRED_string',
          ],
          'storage' => $all_required_string_storage_props,
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&$ref=json-schema-definitions://experience_builder.module/heading-element' => [
          'component props' => [
            '⿲experience_builder:heading␟element',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&enum[0]=default&enum[1]=primary&enum[2]=success&enum[3]=neutral&enum[4]=warning&enum[5]=danger&enum[6]=text' => [
          'component props' => [
            '⿲experience_builder:shoe_button␟variant',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&enum[0]=full&enum[1]=wide&enum[2]=normal&enum[3]=narrow' => [
          'component props' => [
            '⿲experience_builder:one_column␟width',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&enum[0]=moon-stars-fill&enum[1]=moon-stars&enum[2]=star-fill&enum[3]=star&enum[4]=stars&enum[5]=rocket-fill&enum[6]=rocket-takeoff-fill&enum[7]=rocket-takeoff&enum[8]=rocket' => [
          'component props' => [
            '⿲experience_builder:shoe_icon␟name',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&enum[0]=primary&enum[1]=success&enum[2]=neutral&enum[3]=warning&enum[4]=danger' => [
          'component props' => [
            '⿲experience_builder:shoe_badge␟variant',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&format=uri' => [
          'component props' => [
            '⿲experience_builder:my-hero␟cta1href',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file_uri␟url',
            'ℹ︎file_uri␟value',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎uri␟value',
          ],
          'format_main_prop' => [
            'ℹ︎file_uri␟value',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎uri␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:file␝uri␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&format=uri&pattern=\.(mp4|webm)(\?.*)?(#.*)?$' => [
          'component props' => [
            '⿲experience_builder:video␟src',
          ],
          'storage' => $all_required_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'REQUIRED, type=string&minLength=2' => [
          'component props' => [
            '⿲experience_builder:my-hero␟heading',
            '⿲experience_builder:my-section␟text',
          ],
          'storage' => $all_required_string_storage_props,
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=boolean' => [
          'component props' => [
            '⿲experience_builder:shoe_badge␟pill',
            '⿲experience_builder:shoe_badge␟pulse',
            '⿲experience_builder:shoe_button␟disabled',
            '⿲experience_builder:shoe_button␟loading',
            '⿲experience_builder:shoe_button␟outline',
            '⿲experience_builder:shoe_button␟pill',
            '⿲experience_builder:shoe_button␟circle',
            '⿲experience_builder:shoe_details␟open',
            '⿲experience_builder:shoe_details␟disabled',
            '⿲experience_builder:shoe_tab␟active',
            '⿲experience_builder:shoe_tab␟closable',
            '⿲experience_builder:shoe_tab␟disabled',
            '⿲experience_builder:shoe_tab_panel␟active',
            '⿲sdc_test_all_props:all-props␟test_bool',
          ],
          'storage' => [
            'ℹ︎boolean␟value',
            'ℹ︎file␟display',
            'ℹ︎file␟entity␜␜entity:file␝status␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝status␞0␟value',
            'ℹ︎password␟pre_hashed',
          ],
          'format_any_prop' => [
            'ℹ︎boolean␟value',
            'ℹ︎file␟display',
            'ℹ︎file␟entity␜␜entity:file␝status␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝status␞0␟value',
            'ℹ︎password␟pre_hashed',
          ],
          'format_main_prop' => [
            'ℹ︎boolean␟value',
            'ℹ︎file␟entity␜␜entity:file␝status␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝status␞0␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝status␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝pass␞␟pre_hashed',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝status␞␟value',
            'ℹ︎␜entity:node:foo␝default_langcode␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            'ℹ︎␜entity:node:foo␝promote␞␟value',
            'ℹ︎␜entity:node:foo␝revision_default␞␟value',
            'ℹ︎␜entity:node:foo␝revision_translation_affected␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝pass␞␟pre_hashed',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            'ℹ︎␜entity:node:foo␝status␞␟value',
            'ℹ︎␜entity:node:foo␝sticky␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝pass␞␟pre_hashed',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            'ℹ︎␜entity:path_alias␝revision_default␞␟value',
            'ℹ︎␜entity:path_alias␝status␞␟value',
            'ℹ︎␜entity:user␝default_langcode␞␟value',
            'ℹ︎␜entity:user␝pass␞␟pre_hashed',
            'ℹ︎␜entity:user␝status␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=integer' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_integer',
          ],
          'storage' => $all_integer_storage_props,
          'format_any_prop' => $all_integer_storage_props,
          'format_main_prop' => [
            'ℹ︎changed␟value',
            'ℹ︎comment␟status',
            'ℹ︎created␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎file␟entity␜␜entity:file␝changed␞0␟value',
            'ℹ︎file␟entity␜␜entity:file␝created␞0␟value',
            'ℹ︎file␟entity␜␜entity:file␝fid␞0␟value',
            'ℹ︎file␟entity␜␜entity:file␝filesize␞0␟value',
            'ℹ︎file␟entity␜␜entity:file␝uid␞0␟target_id',
            'ℹ︎file␟target_id',
            'ℹ︎image␟entity␜␜entity:file␝changed␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝created␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝fid␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝filesize␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uid␞0␟target_id',
            'ℹ︎image␟target_id',
            'ℹ︎integer␟value',
            'ℹ︎list_integer␟value',
            'ℹ︎timestamp␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝changed␞␟value',
            'ℹ︎␜entity:file␝created␞␟value',
            'ℹ︎␜entity:file␝fid␞␟value',
            'ℹ︎␜entity:file␝filesize␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝access␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝changed␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝created␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝login␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝uid␞␟value',
            'ℹ︎␜entity:file␝uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝changed␞␟value',
            'ℹ︎␜entity:node:foo␝created␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝changed␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝created␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝fid␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟target_id',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            'ℹ︎␜entity:node:foo␝nid␞␟value',
            'ℹ︎␜entity:node:foo␝path␞␟pid',
            'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝changed␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝created␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uid␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝changed␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝created␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uid␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟target_id',
            'ℹ︎␜entity:node:foo␝vid␞␟value',
            'ℹ︎␜entity:path_alias␝id␞␟value',
            'ℹ︎␜entity:path_alias␝revision_id␞␟value',
            'ℹ︎␜entity:user␝access␞␟value',
            'ℹ︎␜entity:user␝changed␞␟value',
            'ℹ︎␜entity:user␝created␞␟value',
            'ℹ︎␜entity:user␝login␞␟value',
            'ℹ︎␜entity:user␝uid␞␟value',
          ],
          'adapter_matches_field_type' => [
            'day_count' => [
              'oldest' => [
                'ℹ︎daterange␟value',
                'ℹ︎datetime␟value',
              ],
              'newest' => [
                'ℹ︎daterange␟value',
                'ℹ︎datetime␟value',
              ],
            ],
          ],
          'adapter_matches_instance' => [
            'day_count' => [
              'oldest' => [
                'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
                'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
              ],
              'newest' => [
                'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
                'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
              ],
            ],
          ],
        ],
        'optional, type=integer&maximum=2147483648&minimum=-2147483648' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_integer_range_minimum_maximum_timestamps',
          ],
          'storage' => $all_integer_storage_props,
          'format_any_prop' => [
            'ℹ︎timestamp␟value',
          ],
          'format_main_prop' => [
            'ℹ︎timestamp␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝access␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝login␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            'ℹ︎␜entity:user␝access␞␟value',
            'ℹ︎␜entity:user␝login␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=integer&minimum=0' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_integer_range_minimum',
          ],
          'storage' => $all_integer_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=object&$ref=json-schema-definitions://experience_builder.module/image' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_object_drupal_image',
          ],
          'storage' => [
            'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'format_any_prop' => [
            'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'format_main_prop' => [
            'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
          ],
          'adapter_matches_field_type' => [
            'image_apply_style' => [
              'image' => ['ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}'],
              'imageStyle' => [],
            ],
            'image_url_rel_to_abs' => [
              'image' => ['ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}'],
            ],
          ],
          'adapter_matches_instance' => [
            'image_apply_style' => [
              'image' => ['ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}'],
              'imageStyle' => [],
            ],
            'image_url_rel_to_abs' => [
              'image' => ['ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}'],
            ],
          ],
        ],
        'optional, type=object&$ref=json-schema-definitions://experience_builder.module/shoe-icon' => [
          'component props' => [
            '⿲experience_builder:shoe_button␟icon',
            '⿲experience_builder:shoe_details␟expand_icon',
            '⿲experience_builder:shoe_details␟collapse_icon',
          ],
          'storage' => [
            'ℹ︎file␟{label↠description}',
            'ℹ︎image␟{label↠alt,slot↠title}',
            'ℹ︎link␟{label↠title}',
            'ℹ︎string␟{label↠value}',
            'ℹ︎string_long␟{label↠value}',
          ],
          'format_any_prop' => [
            'ℹ︎file␟{label↠description}',
            'ℹ︎image␟{label↠alt,slot↠title}',
            'ℹ︎link␟{label↠title}',
            'ℹ︎string␟{label↠value}',
            'ℹ︎string_long␟{label↠value}',
          ],
          'format_main_prop' => [
            'ℹ︎file␟{label↠description}',
            'ℹ︎image␟{label↠alt,slot↠title}',
            'ℹ︎link␟{label↠title}',
            'ℹ︎string␟{label↠value}',
            'ℹ︎string_long␟{label↠value}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟{label↠alt,slot↠title}',
            'ℹ︎␜entity:node:foo␝revision_log␞␟{label↠value}',
            'ℹ︎␜entity:node:foo␝title␞␟{label↠value}',
            'ℹ︎␜entity:path_alias␝alias␞␟{label↠value}',
            'ℹ︎␜entity:path_alias␝path␞␟{label↠value}',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=object&$ref=json-schema-definitions://sdc_test_all_props.module/date-range' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_object_drupal_date_range',
          ],
          'storage' => [
            'ℹ︎daterange␟{from↠end_value,to↠value}',
          ],
          'format_any_prop' => [
            'ℹ︎daterange␟{from↠end_value,to↠value}',
          ],
          'format_main_prop' => [
            'ℹ︎daterange␟{from↠end_value,to↠value}',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string' => [
          'component props' => [
            '⿲experience_builder:deprecated␟text',
            '⿲experience_builder:experimental␟text',
            '⿲experience_builder:my-hero␟subheading',
            '⿲experience_builder:my-hero␟cta1',
            '⿲experience_builder:my-hero␟cta2',
            '⿲experience_builder:obsolete␟text',
            '⿲experience_builder:shoe_button␟label',
            '⿲experience_builder:shoe_button␟href',
            '⿲experience_builder:shoe_button␟rel',
            '⿲experience_builder:shoe_button␟download',
            '⿲experience_builder:shoe_icon␟label',
            '⿲experience_builder:shoe_icon␟slot',
            '⿲sdc_test_all_props:all-props␟test_string',
          ],
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&$ref=json-schema-definitions://experience_builder.module/image-uri' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::URI->value . '_image',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
          ],
          'format_main_prop' => [
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapter_matches_field_type' => [
            'image_extract_url' => [
              'imageUri' => [
                'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
                'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
              ],
            ],
          ],
          'adapter_matches_instance' => [
            'image_extract_url' => [
              'imageUri' => [
                'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
                'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
              ],
            ],
          ],
        ],
        'optional, type=string&enum[0]=&enum[1]=base&enum[2]=l&enum[3]=s&enum[4]=xs&enum[5]=xxs' => [
          'component props' => [
            '⿲experience_builder:shoe_icon␟size',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&enum[0]=&enum[1]=gray&enum[2]=primary&enum[3]=neutral-soft&enum[4]=neutral-medium&enum[5]=neutral-loud&enum[6]=primary-medium&enum[7]=primary-loud&enum[8]=black&enum[9]=white&enum[10]=red&enum[11]=gold&enum[12]=green' => [
          'component props' => [
            '⿲experience_builder:shoe_icon␟color',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&enum[0]=_blank&enum[1]=_parent&enum[2]=_self&enum[3]=_top' => [
          'component props' => [
            '⿲experience_builder:shoe_button␟target',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&enum[0]=foo&enum[1]=bar' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_enum',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&enum[0]=prefix&enum[1]=suffix' => [
          'component props' => [
            '⿲experience_builder:shoe_button␟icon_position',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&enum[0]=primary&enum[1]=secondary' => [
          'component props' => [
            '⿲experience_builder:heading␟style',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&enum[0]=small&enum[1]=medium&enum[2]=large' => [
          'component props' => [
            '⿲experience_builder:shoe_button␟size',
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=date' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::DATE->value,
          ],
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
          'adapter_matches_field_type' => [
            'unix_to_date' => [
              'unix' => [
                'ℹ︎changed␟value',
                'ℹ︎comment␟status',
                'ℹ︎created␟value',
                'ℹ︎entity_reference␟target_id',
                'ℹ︎file␟target_id',
                'ℹ︎image␟target_id',
                'ℹ︎integer␟value',
                'ℹ︎list_integer␟value',
                'ℹ︎timestamp␟value',
              ],
            ],
          ],
          'adapter_matches_instance' => [
            'unix_to_date' => [
              'unix' => [
                'ℹ︎␜entity:node:foo␝field_silly_image␞␟target_id',
              ],
            ],
          ],
        ],
        'optional, type=string&format=date-time' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::DATE_TIME->value),
          ],
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=duration' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::DURATION->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo No field type in Drupal core uses \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601.
          ],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=email' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::EMAIL->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎email␟value',
          ],
          'format_main_prop' => [
            'ℹ︎email␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝init␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            'ℹ︎␜entity:user␝init␞␟value',
            'ℹ︎␜entity:user␝mail␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=hostname' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::HOSTNAME->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo adapter from `type: string, format=uri`?
          ],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=idn-email' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::IDN_EMAIL->value),
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎email␟value',
          ],
          'format_main_prop' => [
            'ℹ︎email␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝init␞␟value',
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            'ℹ︎␜entity:user␝init␞␟value',
            'ℹ︎␜entity:user␝mail␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=idn-hostname' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::IDN_HOSTNAME->value),
          ],
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
        'optional, type=string&format=ipv4' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::IPV4->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
        'optional, type=string&format=ipv6' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::IPV6->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=iri' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::IRI->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file_uri␟url',
            'ℹ︎file_uri␟value',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
          ],
          'format_main_prop' => [
            'ℹ︎file_uri␟value',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:file␝uri␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=iri-reference' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::IRI_REFERENCE->value),
          ],
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
        'optional, type=string&format=json-pointer' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::JSON_POINTER->value),
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
        'optional, type=string&format=regex' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::REGEX->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
        'optional, type=string&format=relative-json-pointer' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::RELATIVE_JSON_POINTER->value),
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=time' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::TIME->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            // @todo Adapter for @FieldType=timestamp -> `type:string,format=time`, @FieldType=datetime -> `type:string,format=time`
          ],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=uri' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::URI->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file_uri␟url',
            'ℹ︎file_uri␟value',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
          ],
          'format_main_prop' => [
            'ℹ︎file_uri␟value',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟url',
            'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:file␝uri␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=uri-reference' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::URI_REFERENCE->value),
          ],
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
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
        'optional, type=string&format=uri-template' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . str_replace('-', '_', JsonSchemaStringFormat::URI_TEMPLATE->value),
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [],
          'format_main_prop' => [],
          'instances' => [],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
        'optional, type=string&format=uuid' => [
          'component props' => [
            '⿲sdc_test_all_props:all-props␟test_string_format_' . JsonSchemaStringFormat::UUID->value,
          ],
          'storage' => $all_string_storage_props,
          'format_any_prop' => [
            'ℹ︎file␟entity␜␜entity:file␝uuid␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uuid␞0␟value',
            'ℹ︎uuid␟value',
          ],
          'format_main_prop' => [
            'ℹ︎file␟entity␜␜entity:file␝uuid␞0␟value',
            'ℹ︎image␟entity␜␜entity:file␝uuid␞0␟value',
            'ℹ︎uuid␟value',
          ],
          'instances' => [
            'ℹ︎␜entity:file␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            'ℹ︎␜entity:file␝uuid␞␟value',
            'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uuid␞␟value',
            'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uuid␞␟value',
            'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            'ℹ︎␜entity:node:foo␝uuid␞␟value',
            'ℹ︎␜entity:path_alias␝uuid␞␟value',
            'ℹ︎␜entity:user␝uuid␞␟value',
          ],
          'adapter_matches_field_type' => [],
          'adapter_matches_instance' => [],
        ],
      ],
    ];

    // The Media Library module being installed does not affect the results of
    // the SdcPropToFieldTypePropMatcher; it only affects
    // PropShape::findFieldTypeStorage().
    // @see media_library_storage_prop_shape_alter()
    // @see \Drupal\experience_builder\PropShape::findFieldTypeStorage()
    // @see \Drupal\experience_builder\SdcPropToFieldTypePropMatcher
    // @todo Remove the field type matching functionality from SdcPropToFieldTypePropMatcher in https://www.drupal.org/project/experience_builder/issues/3450496
    $cases['XB example SDCs + all-props SDC, using ALL core-provided field types + media library'] = $cases['XB example SDCs + all-props SDC, using ALL core-provided field types'];
    $cases['XB example SDCs + all-props SDC, using ALL core-provided field types + media library']['modules'][] = 'media_library';

    return $cases;
  }

}
