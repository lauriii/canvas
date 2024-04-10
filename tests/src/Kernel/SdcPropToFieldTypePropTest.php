<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\experience_builder\ComponentPropExpression;
use Drupal\experience_builder\FieldTypePropExpression;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\SdcPropJsonSchemaType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sdc\ComponentPluginManager;
use Drupal\experience_builder\SdcPropToFieldTypePropMatcher;

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
   * @dataProvider provider
   */
  public function test(array $modules, array $expected) {
    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install($modules);

    $sdc_manager = \Drupal::service('plugin.manager.sdc');
    assert($sdc_manager instanceof ComponentPluginManager);

    $matcher = \Drupal::service(SdcPropToFieldTypePropMatcher::class);
    assert($matcher instanceof SdcPropToFieldTypePropMatcher);

    foreach ($sdc_manager->getAllComponents() as $component) {
      $component_name = $component->getPluginId();

      // SDCs are forbidden from having additional properties beyond the
      // explicitly listed ones.
      // @see \Drupal\sdc\Component\ComponentValidator::validateProps()
      // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      $prop_names = array_keys($component->metadata->schema['properties'] ?? []);
      foreach ($prop_names as $prop_name) {
        $cpe = new ComponentPropExpression($component_name, $prop_name);
        $schema = $component->metadata->schema['properties'][$prop_name];

        // TRICKY: `attributes` is a special case — it is kind of a reserved prop.
        // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
        if ($prop_name === 'attributes') {
          assert($schema['type'][0] === Attribute::class);
          continue;
        }

        $primitive_type = SdcPropJsonSchemaType::from(
          // TRICKY: SDC always allowed `object` for Twig integration reasons.
          // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
          is_array($schema['type']) ? $schema['type'][0] : $schema['type']
        );

        // @see https://json-schema.org/understanding-json-schema/reference/object#required
        // @see https://json-schema.org/learn/getting-started-step-by-step#required
        $is_required = in_array($prop_name, $component->metadata->schema['required'] ?? [], TRUE);

        // From least to most restrictive matchmaking of structured data sources
        // to flow into component props:
        // 1. storage representation must match
        $storage_candidates = $matcher->findFieldTypeStorageCandidates($primitive_type, $is_required);
        // 2. format must match
        //    👉 UX need: when the BUILDER is creating a content type's template
        //       and they declare the intent to not statically assign a value to
        //       a component prop, then these are the available choices to
        //       create a new field!
        //    🎉 Component placement at a structural level (content
        //       template) encourages EXPANDING the data model IF needs are met!
        //    ❓ UX need: when the CREATOR is placing a component and they want
        //       to statically assign a value.
        $format_candidates = $matcher->findFieldTypeFormatCandidates($primitive_type, $is_required, $schema);
        // 3. a field instance of this type must exist.
        //    👉 UX need: when the BUILDER is creating a content type's template
        //       OR the creator is placing a component in a slot, and they
        //       declare the intent to not statically assign a value to a
        //       component prop, then these are the available choices
        //    🎉 Component placement at a structural level (content
        //       template) encourages USING the data model IF needs are not met!
        // @todo Load all `FieldConfig` instances (optionally limited by entity type + bundle), to find actually viable choices. Next up:
        $instance_candidates = [];
        // 4. adapters.
        // @todo Make adapters a reality; but how to not overwhelm? 🤔 Probably we should only generate these for SDC props with a `format` that otherwise has zero matches? Because we could cast any `int` to a `string`, but that'd just result in terrible UX.
        //$adapted_candidates = [];

        // For each component prop ($cpe), store the string representations of
        // the discovered
        $matches[(string) $cpe]['storage'] = array_map(fn (FieldTypePropExpression $e): string => (string) $e, $storage_candidates);
        $matches[(string) $cpe]['format'] = array_map(fn (FieldTypePropExpression $e): string => (string) $e, $format_candidates);
        //$matches[(string) $cpe]['instance'] = array_map(fn (FieldPropExpression $e): string => (string) $e, $instance_candidates);
      }
    }

    $this->assertSame($expected, $matches);

    $module_installer->uninstall($modules);
  }

  public function provider() {
    $all_string_storage_props  = [
      'ℹ︎comment␟last_comment_name',
      'ℹ︎datetime␟value',
      'ℹ︎daterange␟value',
      'ℹ︎daterange␟end_value',
      'ℹ︎file_uri␟value',
      'ℹ︎file␟description',
      'ℹ︎image␟alt',
      'ℹ︎image␟title',
      'ℹ︎link␟uri',
      'ℹ︎link␟title',
      'ℹ︎list_string␟value',
      'ℹ︎path␟alias',
      'ℹ︎path␟langcode',
      'ℹ︎telephone␟value',
      'ℹ︎text_with_summary␟value',
      'ℹ︎text_with_summary␟format',
      'ℹ︎text_with_summary␟summary',
      'ℹ︎text␟value',
      'ℹ︎text␟format',
      'ℹ︎text_long␟value',
      'ℹ︎text_long␟format',
      'ℹ︎uri␟value',
      'ℹ︎uuid␟value',
      'ℹ︎email␟value',
      'ℹ︎string␟value',
      'ℹ︎language␟value',
      'ℹ︎string_long␟value',
      'ℹ︎password␟value',
      'ℹ︎password␟existing',
      'ℹ︎decimal␟value',
    ];
    $all_string_required_storage_props  = [
      'ℹ︎datetime␟value',
      'ℹ︎daterange␟value',
      'ℹ︎daterange␟end_value',
      'ℹ︎file_uri␟value',
      'ℹ︎list_string␟value',
      'ℹ︎telephone␟value',
      'ℹ︎text_with_summary␟value',
      'ℹ︎text␟value',
      'ℹ︎text_long␟value',
      'ℹ︎uri␟value',
      'ℹ︎uuid␟value',
      'ℹ︎email␟value',
      'ℹ︎string␟value',
      'ℹ︎language␟value',
      'ℹ︎string_long␟value',
      'ℹ︎decimal␟value',
    ];
    $all_integer_storage_props  = [
      'ℹ︎comment␟status',
      'ℹ︎comment␟cid',
      'ℹ︎comment␟last_comment_timestamp',
      'ℹ︎comment␟last_comment_uid',
      'ℹ︎comment␟comment_count',
      'ℹ︎file␟target_id',
      'ℹ︎image␟target_id',
      'ℹ︎image␟width',
      'ℹ︎image␟height',
      'ℹ︎list_integer␟value',
      'ℹ︎path␟pid',
      'ℹ︎integer␟value',
      'ℹ︎entity_reference␟target_id',
      'ℹ︎timestamp␟value',
      'ℹ︎created␟value',
      'ℹ︎changed␟value',
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
      ],
      'expected matches' => [
        '⿲sdc_test_all_props:all-props␟test-string' => [
          'storage' => $all_string_storage_props,
          'format' => $all_string_storage_props,
        ],
        '⿲sdc_test_all_props:all-props␟test-REQUIRED-string' => [
          'storage' => $all_string_required_storage_props,
          'format' => $all_string_required_storage_props,
        ],
        '⿲sdc_test_all_props:all-props␟test-string-enum' => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::DATE_TIME->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎datetime␟value',
            'ℹ︎daterange␟value',
            'ℹ︎daterange␟end_value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::DATE->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎datetime␟value',
            'ℹ︎daterange␟value',
            'ℹ︎daterange␟end_value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::TIME->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Adapter for @FieldType=timestamp -> `type:string,format=time`, @FieldType=datetime -> `type:string,format=time`
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::DURATION->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo No field type in Drupal core uses \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601.
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::EMAIL->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎email␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IDN_EMAIL->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎email␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::HOSTNAME->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo adapter from `type: string, format=uri`?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IDN_HOSTNAME->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo adapter from `type: string, format=uri`?
            // @todo To generate a match for this JSON schema type:
            // - generate an adapter?! -> but we cannot just adapt arbitrary data to generate a IP
            // - follow entity references in the actual data model, i.e. this will find matches at the instance level? -> but does not allow the BUILDER persona to create instances
            // - create an instance with the necessary requirement?! => `@FieldType=string` + `Ip` constraint … but no field type allows configuring this?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IPV4->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IPV6->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::UUID->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎uuid␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎file_uri␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI_REFERENCE->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎path␟alias',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IRI->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎file_uri␟value',
            'ℹ︎link␟uri',
            'ℹ︎uri␟value',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::IRI_REFERENCE->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            'ℹ︎path␟alias',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::URI_TEMPLATE->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::JSON_POINTER->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::RELATIVE_JSON_POINTER->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-' . JsonSchemaStringFormat::REGEX->value => [
          'storage' => $all_string_storage_props,
          'format' => [
            // @todo Update \Drupal\sdc\Component\ComponentValidator to disallow this — does not make sense for presenting information?
          ],
        ],

        // Integers.
        '⿲sdc_test_all_props:all-props␟test-integer' => [
          'storage' => $all_integer_storage_props,
          'format' => $all_integer_storage_props,
        ],
        '⿲sdc_test_all_props:all-props␟test-integer-range-minimum' => [
          'storage' => $all_integer_storage_props,
          'format' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-integer-range-minimum-maximum-timestamps' => [
          'storage' => $all_integer_storage_props,
          'format' => [
            'ℹ︎timestamp␟value',
          ],
        ],
      ],
    ];

    yield 'real-world SDCs, using only always-provided field types' => [
      'modules' => [
        // The modules providing sample SDCs.
        'cl_editorial',
        'sdc_examples',
        'sdc_test',
      ],
      'expected matches' => [
        '⿲cl_editorial:component-card␟name' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲cl_editorial:component-card␟machineName' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲cl_editorial:component-card␟id' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲cl_editorial:component-card␟description' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲cl_editorial:component-card␟status' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲cl_editorial:component-card␟thumbnailHref' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲cl_editorial:component-card␟group' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-cta␟text' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-cta␟href' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
          ],
        ],
        '⿲sdc_examples:my-cta␟target' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_examples:my-button--primary␟text' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-button--primary␟iconType' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_examples:my-button␟text' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-button␟iconType' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_examples:my-marquee␟text' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-marquee␟scrollAmount' => [
          'storage' => [
            'ℹ︎integer␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎float␟value',
            'ℹ︎timestamp␟value',
            'ℹ︎created␟value',
            'ℹ︎changed␟value',
          ],
          'format' => [
            'ℹ︎integer␟value',
            'ℹ︎entity_reference␟target_id',
            'ℹ︎float␟value',
            'ℹ︎timestamp␟value',
            'ℹ︎created␟value',
            'ℹ︎changed␟value',
          ],
        ],
        '⿲sdc_examples:my-banner␟heading' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner␟ctaText' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner␟ctaHref' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner␟ctaTarget' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_examples:my-banner␟image' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-linked-media␟image' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-linked-media␟href' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner--tall␟heading' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner--tall␟ctaText' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner--tall␟ctaHref' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-banner--tall␟ctaTarget' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_examples:my-banner--tall␟image' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-card--light␟header' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_examples:my-card␟header' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_test:my-cta␟text' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_test:my-cta␟href' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
          ],
        ],
        '⿲sdc_test:my-cta␟target' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_test:array-to-object␟testProp' => [
          'storage' => [],
          'format' => [],
        ],
        '⿲sdc_test:my-button␟text' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_test:my-button␟iconType' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_test:my-banner␟heading' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_test:my-banner␟ctaText' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_test:my-banner␟ctaHref' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
        '⿲sdc_test:my-banner␟ctaTarget' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [],
        ],
        '⿲sdc_test:my-banner␟image' => [
          'storage' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
          'format' => [
            'ℹ︎uri␟value',
            'ℹ︎uuid␟value',
            'ℹ︎email␟value',
            'ℹ︎string␟value',
            'ℹ︎language␟value',
            'ℹ︎string_long␟value',
            'ℹ︎password␟value',
            'ℹ︎password␟existing',
            'ℹ︎decimal␟value',
          ],
        ],
      ],
    ];

    yield 'real-world SDCs, using ALL core-provided field types' => [
      'modules' => [
        // The modules providing sample SDCs.
        'cl_editorial',
        // @todo Expand test coverage with these.
//        'sdc_test',
//        'sdc_examples',
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
      'expected matches' => [
        '⿲cl_editorial:component-card␟name' => [
          'storage' => $all_string_storage_props,
          'format' => $all_string_storage_props,
        ],
        '⿲cl_editorial:component-card␟machineName' => [
          'storage' => $all_string_storage_props,
          'format' => [],
        ],
        '⿲cl_editorial:component-card␟id' => [
          'storage' => $all_string_storage_props,
          'format' => [],
        ],
        '⿲cl_editorial:component-card␟description' => [
          'storage' => $all_string_storage_props,
          'format' => $all_string_storage_props,
        ],
        '⿲cl_editorial:component-card␟status' => [
          'storage' => $all_string_storage_props,
          'format' => [],
        ],
        '⿲cl_editorial:component-card␟thumbnailHref' => [
          'storage' => $all_string_storage_props,
          'format' => $all_string_storage_props,
        ],
        '⿲cl_editorial:component-card␟group' => [
          'storage' => $all_string_storage_props,
          'format' => $all_string_storage_props,
        ],
      ],
    ];
  }
}
