<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\experience_builder\ComponentPropExpression;
use Drupal\experience_builder\FieldPropExpression;
use Drupal\experience_builder\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressionInterface;
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
    // The modules providing sample SDCs.
    'cl_editorial',
    'sdc_test',
    'sdc_examples',
  ];

  public function test() {
    $sdc_manager = \Drupal::service('plugin.manager.sdc');
    assert($sdc_manager instanceof ComponentPluginManager);

    $matcher = \Drupal::service(SdcPropToFieldTypePropMatcher::class);
    assert($matcher instanceof SdcPropToFieldTypePropMatcher);

    foreach ($sdc_manager->getAllComponents() as $component) {
      $component_name = $component->getPluginId();

      // SDCs are forbidden from having additional properties beyond the
      // explicitly listed ones.
      // @see \Drupal\sdc\Component\ComponentValidator::validateProps()
      // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo
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
        //    👉 UX need: when the builder is creating a content type's template
        //       and they declare the intent to not statically assign a value to
        //       a component prop, then these are the available choices to
        //       create a new field!
        //    🎉 Component placement at a structural level (content
        //       template) encourages EXPANDING the data model IF needs are met!
        $format_candidates = $matcher->findFieldTypeFormatCandidates($primitive_type, $is_required, $schema);
        // 3. a field instance of this type must exist.
        //    👉 UX need: when the builder is creating a content type's template
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

    $this->assertSame([
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
    ],
      $matches
    );
  }

}
