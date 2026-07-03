<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

// cspell:ignore Bienvenue savoir Identité visuelle Gitane Découvrez Charte graphique

use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputTranslatability;

/**
 * Canonical all-edges component tree fixture for translation tests.
 *
 * Defines the same component tree used by
 * ConfigWithComponentTreeTranslationTestBase, shared so that both config- and
 * content-entity translation tests exercise identical edge cases.
 *
 * Exercises:
 * - multiple-cardinality string array (tags)
 * - optional unpopulated string prop (my-hero subheading)
 * - plain string props (my-hero heading, cta2; banner heading)
 * - URI-reference string prop (my-hero cta1href)
 * - non-static prop source props (my-hero cta1, cta1href) — parametrized:
 *   ContentTemplate allows EntityField + HostEntityUrl; Page does not.
 * - link field with uri + options (my-cta href)
 * - rich prose: value + format (banner text)
 * - non-translatable boolean props (branding block)
 * - untranslatable prop shapes (untranslatable-prop-shapes): `date`,
 *   `email`, `integer` and `boolean`. No prop shape is translatable, so none
 *   is offered for translation — locking in that the TMGMT extractor and the
 *   config schema generator agree (only plain/rich prose and URI-esque strings
 *   are translatable).
 *
 * @see \Drupal\Tests\canvas\Functional\ConfigWithComponentTreeTranslationTestBase
 * @see \Drupal\Tests\canvas\Functional\ContentWithComponentTreeTmgmtUiTest
 */
trait ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait {

  /**
   * UUID: tags component (multiple-cardinality). Delta: 0.
   */
  protected const UUID_TAGS = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

  /**
   * UUID: my-hero component (optional subheading, mixed prop sources). Delta: 1.
   */
  protected const UUID_MY_HERO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';

  /**
   * UUID: my-cta component (link field: uri + options). Delta: 2.
   */
  protected const UUID_MY_CTA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

  /**
   * UUID: banner component (rich prose). Delta: 3.
   */
  protected const UUID_BANNER = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

  /**
   * UUID: branding block component (non-translatable boolean props). Delta: 4.
   */
  protected const UUID_BRANDING = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3';

  /**
   * UUID: block component with a deeply nested translatable. Delta: 5.
   *
   * @see \Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputTranslatability
   */
  protected const UUID_BLOCK_DEEP_TRANSLATABLE = 'ffffffff-ffff-6fff-8fff-ffffffffffff';

  /**
   * UUID: block component with an empty translatable input. Delta: 6.
   *
   * The branding block's only translatable input (`label`) is present but empty
   * in the default translation. A translatable input that is empty in the
   * source language must still be offered for translation (it may be given a
   * value in the target language) rather than crash extraction.
   *
   * @see https://git.drupalcode.org/project/canvas/-/issues/3591734
   */
  protected const UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT = '0a0a0a0a-0a0a-40a0-80a0-0a0a0a0a0a0a';

  /**
   * UUID: untranslatable-prop-shapes component. Delta: 7.
   *
   * None of its prop shapes is translatable, so none is offered for
   * translation: `date` (datetime field, date-only), `email`, `count`
   * (integer) and `flag` (boolean).
   */
  protected const UUID_UNTRANSLATABLE_PROP_SHAPES = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

  /**
   * Delta of each component in the component tree, keyed by UUID constant name.
   *
   * Tmgmt_content uses delta-based field keys in its TMGMT review form
   * (e.g. `components|1|heading[translation]`), not UUID-based paths like
   * tmgmt_config. Both share the same config-schema-accurate sub-keys for
   * complex field types (e.g. `components|2|href|uri[translation]`).
   * Use these constants to avoid hardcoding magic numbers.
   */
  protected const COMPONENT_DELTA = [
    self::UUID_TAGS => 0,
    self::UUID_MY_HERO => 1,
    self::UUID_MY_CTA => 2,
    self::UUID_BANNER => 3,
    self::UUID_BRANDING => 4,
    self::UUID_BLOCK_DEEP_TRANSLATABLE => 5,
    self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT => 6,
    self::UUID_UNTRANSLATABLE_PROP_SHAPES => 7,
  ];

  /**
   * Gets expected French translated `inputs` for the test component tree.
   *
   * Both the config- and content-entity translation tests submit the same
   * French translations (auto-prefixed "fr: " for most, manually overridden
   * for heading/cta2/href). This method encodes the expected state of each
   * component instance's inputs after accepting the translation, so callers can
   * assert entire input arrays rather than individual keys.
   *
   * @param string|null $my_hero_cta1
   *   Expected translated value for my-hero's `cta1` prop, or NULL to assert
   *   the key is absent (config entity: non-static prop source excluded).
   * @param array|string|null $my_hero_cta1href
   *   Expected translated value for my-hero's `cta1href` prop, or NULL to
   *   assert the key is absent. For content entity tests this is an array
   *   (uri-reference link field storage: ['uri' => ..., 'options' => []]).
   * @param bool $expect_overrides_only
   *   Whether to expect only overrides (TRUE; typical for config translation's
   *   "config override" approach) or values for all input keys (FALSE;
   *   including non-translatable input keys).
   *
   * @return array<string, array>
   *   Expected inputs per component UUID, keyed by the UUID constants defined
   *   on this trait.
   */
  protected static function expectedTranslatedInputs(
    string|null $my_hero_cta1,
    array|string|null $my_hero_cta1href,
    bool $expect_overrides_only,
  ): array {
    // Build my-hero inputs in schema definition order so assertSame() on the
    // whole array works correctly for both config and content entity callers.
    $my_hero = [
      'heading' => 'Bienvenue à Canvas',
      'subheading' => 'Découvrez Canvas',
    ];
    if ($my_hero_cta1 !== NULL) {
      $my_hero['cta1'] = $my_hero_cta1;
    }
    if ($my_hero_cta1href !== NULL) {
      $my_hero['cta1href'] = $my_hero_cta1href;
    }
    $my_hero['cta2'] = 'En savoir plus';

    $result = [
      self::UUID_TAGS => [
        'tags' => ['fr: baz', 'fr: bar', 'fr: foo'],
      ],
      self::UUID_MY_HERO => $my_hero,
      self::UUID_MY_CTA => [
        'text' => 'fr: Press',
        'href' => [
          'uri' => 'https://fr.drupal.org',
          'options' => [],
        ],
      ],
      self::UUID_BANNER => [
        'heading' => 'fr: A heading element! :)',
        'text' => [
          'value' => 'fr: <p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
          'format' => 'canvas_html_block',
        ],
      ],
      self::UUID_BRANDING => [
        'label' => 'Identité visuelle',
        'label_display' => '0',
        'use_site_logo' => TRUE,
        'use_site_name' => TRUE,
        'use_site_slogan' => FALSE,
      ],
      self::UUID_BLOCK_DEEP_TRANSLATABLE => [
        'label' => 'fr: Canvas Test Block for testing input translatability',
        'label_display' => '0',
        'top_level_translatable_regardless_of_type' => CanvasTestBlockInputTranslatability::DEFAULT_CONFIGURATION['top_level_translatable_regardless_of_type'],
        'deeply_nested_translatable' => [0 => ['foo' => 'Huh?', 'bar' => 'fr: Gitane']],
      ],
      // `name` is empty in the source but translated to a real value in French,
      // proving an empty-source translatable input is offered and can receive a
      // target-language value.
      self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT => [
        'label' => 'fr: Test block with settings',
        'label_display' => '0',
        'name' => 'Charte graphique',
      ],
      // The untranslatable-prop-shapes component: no prop shape is
      // translatable. `date` (datetime field, date-only), `email`, `count`
      // (integer) and `flag` (boolean) all keep their source values.
      self::UUID_UNTRANSLATABLE_PROP_SHAPES => [
        'date' => '2024-01-15',
        'email' => 'person@example.com',
        'count' => 42,
        'flag' => TRUE,
      ],
    ];
    if ($expect_overrides_only) {
      // Omit `href.options`.
      unset($result[self::UUID_MY_CTA]['href']['options']);
      // Omit `text.format`.
      unset($result[self::UUID_BANNER]['text']['format']);
      // Keep only `label`.
      unset($result[self::UUID_BRANDING]['label_display']);
      unset($result[self::UUID_BRANDING]['use_site_logo']);
      unset($result[self::UUID_BRANDING]['use_site_name']);
      unset($result[self::UUID_BRANDING]['use_site_slogan']);
      // Keep only `label` + `deeply_nested_translatable.0.bar`.
      unset($result[self::UUID_BLOCK_DEEP_TRANSLATABLE]['label_display']);
      unset($result[self::UUID_BLOCK_DEEP_TRANSLATABLE]['top_level_translatable_regardless_of_type']);
      unset($result[self::UUID_BLOCK_DEEP_TRANSLATABLE]['deeply_nested_translatable'][0]['foo']);
      // Keep only the translatable `label` and `name`.
      unset($result[self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT]['label_display']);
      // No prop shape is translatable, so the override holds nothing for this
      // component instance.
      unset($result[self::UUID_UNTRANSLATABLE_PROP_SHAPES]);
    }
    return $result;
  }

  /**
   * Returns the canonical component tree items.
   *
   * @param mixed $cta1
   *   Value for my-hero's `cta1` prop. Pass a static string for content entity
   *   tests (Page); pass a non-static prop source array for config entity tests
   *   (ContentTemplate) to exercise that non-static props are excluded.
   * @param mixed $cta1href
   *   Value for my-hero's `cta1href` prop. Same parametrization as $cta1.
   *
   * @see \Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait::populateActiveComponentVersionPlaceholders()
   */
  protected static function componentTreeItems(mixed $cta1, mixed $cta1href): array {
    return [
      [
        'uuid' => self::UUID_TAGS,
        'component_id' => 'sdc.canvas_test_sdc.tags',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'tags' => ['baz', 'bar', 'foo'],
        ],
      ],
      [
        'uuid' => self::UUID_MY_HERO,
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'heading' => 'Welcome to Canvas',
          // ⚠️ `subheading` is optional and not populated, but should still
          // be translatable.
          // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement
          // @see \Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor
          // @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor
          'cta1' => $cta1,
          'cta1href' => $cta1href,
          'cta2' => 'Learn more',
        ],
      ],
      [
        'uuid' => self::UUID_MY_CTA,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'text' => 'Press',
          'href' => [
            'uri' => 'https://www.drupal.org',
            'options' => [],
          ],
        ],
      ],
      [
        'uuid' => self::UUID_BANNER,
        'component_id' => 'sdc.canvas_test_sdc.banner',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'heading' => 'A heading element! :)',
          'text' => [
            'value' => '<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
            'format' => 'canvas_html_block',
          ],
        ],
      ],
      [
        'uuid' => self::UUID_BRANDING,
        'component_id' => 'block.system_branding_block',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          // ⚠️ `label`/`label_display` are not editable for blocks in Canvas, so
          // they are empty/hidden. The empty `label` is still offered for
          // translation (empty source → `∅` placeholder).
          // @todo `label` should only be offered for translation when `label_display` is 'visible', once `label` becomes editable for blocks; refine in https://www.drupal.org/project/canvas/issues/3572850
          'label' => '',
          'label_display' => '0',
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => FALSE,
        ],
      ],
      [
        'uuid' => self::UUID_BLOCK_DEEP_TRANSLATABLE,
        'component_id' => 'block.' . CanvasTestBlockInputTranslatability::PLUGIN_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
          ...CanvasTestBlockInputTranslatability::DEFAULT_CONFIGURATION,
        ],
      ],
      [
        'uuid' => self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT,
        'component_id' => 'block.canvas_test_block_input_validatable',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
          // ⚠️ `name` is a translatable content setting (`type: label`) that is
          // present but empty in the default translation. It must still be
          // offered for translation — empty in the source, translatable to a
          // value in the target — rather than crash extraction.
          // @see https://git.drupalcode.org/project/canvas/-/issues/3591734
          'name' => '',
        ],
      ],
      [
        'uuid' => self::UUID_UNTRANSLATABLE_PROP_SHAPES,
        'component_id' => 'sdc.canvas_test_translation.untranslatable-prop-shapes',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'date' => '2024-01-15',
          'email' => 'person@example.com',
          'count' => 42,
          'flag' => TRUE,
        ],
      ],
    ];
  }

}
