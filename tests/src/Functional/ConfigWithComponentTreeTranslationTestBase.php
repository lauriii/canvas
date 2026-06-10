<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;

/**
 * Base class for translation UI tests for config-defined component trees.
 *
 * Provides a shared ContentTemplate fixture used by both the
 * config_translation and TMGMT UI test subclasses, so both can run in
 * parallel as separate test processes rather than sequentially within a
 * single class.
 *
 * Exercises all edge cases for JsonSchemaPropsComponentSourceBase-powered
 * ComponentSource plugins:
 * - plain prose, single-cardinality
 * - rich prose (value + format)
 * - URI-esque (uri + options)
 * - multiple-cardinality (array of strings)
 * - optional unpopulated prop (present in schema, absent in source data)
 * - non-static prop sources (excluded from translation)
 *
 * NOTE: PageRegions are not tested because ContentTemplate allows a superset of
 * prop sources (it allows EntityFieldPropSources etc which PageRegion config
 * entities' component trees do not).
 *
 * @see \Drupal\Tests\canvas\Kernel\Config\ConfigWithComponentTreeTestBase
 * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTranslationLifeCycleInDepth()
 * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::testGetTranslatableInputKeys()
 * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::providerSymmetricallyTranslatableComponentInstanceScenarios()
 */
abstract class ConfigWithComponentTreeTranslationTestBase extends FunctionalTestBase {

  use ConstraintViolationsTestTrait;
  use DataProviderWithComponentTreeTrait;

  /**
   * UUID: tags component (multi-cardinality).
   */
  protected const UUID_TAGS = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

  /**
   * UUID: my-hero component (optional subheading, mixed prop sources).
   */
  protected const UUID_MY_HERO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';

  /**
   * UUID: my-cta component (URI-esque href).
   */
  protected const UUID_MY_CTA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

  /**
   * UUID: banner component (rich prose).
   */
  protected const UUID_BANNER = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

  /**
   * UUID: branding block component (non-translatable booleans).
   */
  protected const UUID_BRANDING = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3';

  protected const CONFIG_NAME = 'canvas.content_template.node.article.full';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * Asserts the expected French LanguageConfigOverride.
   *
   * Used to ensure that regardless of translation UI, an identical
   * LanguageConfigOverride is produced.
   */
  protected function assertTranslatedConfigComponentTree(): void {
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $override = $language_manager->getLanguageConfigOverride('fr', self::CONFIG_NAME);
    self::assertFalse($override->isNew());
    self::assertSame(
      [
        'component_tree' => [
          // Multiple-cardinality: tags translated as indexed array.
          self::UUID_TAGS => [
            'inputs' => [
              'tags' => ['fr: baz', 'fr: bar', 'fr: foo'],
            ],
          ],
          self::UUID_MY_HERO => [
            'inputs' => [
              'heading' => 'Bienvenue à Canvas',
              'subheading' => 'Découvrez Canvas',
              'cta2' => 'En savoir plus',
            ],
          ],
          self::UUID_MY_CTA => [
            'inputs' => [
              // Plain prose: `text` translated.
              'text' => 'fr: Press',
              // URI-esque: only `href`'s `uri` field property translated.
              'href' => [
                'uri' => 'https://fr.drupal.org',
              ],
            ],
          ],
          self::UUID_BANNER => [
            'inputs' => [
              // Plain prose: `heading` translated.
              'heading' => 'fr: A heading element! :)',
              // Rich prose: only `value` stored in override, `format` excluded.
              'text' => [
                'value' => 'fr: <p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
              ],
            ],
          ],
          // A Block component.
          self::UUID_BRANDING => [
            'inputs' => [
              'label' => 'Identité visuelle',
            ],
          ],
        ],
      ],
      $override->getRawData(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // In 11.2 and above we install modules in groups, which means this module
    // cannot be installed in the same group as canvas.
    \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);

    $page = $this->getSession()->getPage();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('admin/config/regional/language');
    $this->clickLink('Add language');
    $page->selectFieldOption('predefined_langcode', 'fr');
    $page->pressButton('Add language');
    $this->assertSession()->pageTextContains('The language French has been created and can now be used.');
    $this->rebuildContainer();

    $existing_template = ContentTemplate::load('node.article.full');
    if ($existing_template instanceof ContentTemplate) {
      $existing_template->delete();
    }

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::populateActiveComponentVersionPlaceholders([
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
            'cta1' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'cta1href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
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
            'label' => 'Branding',
            'label_display' => 'visible',
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => FALSE,
          ],
        ],
      ]),
    ]);
    $violations = $template->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations), $template->getConfigTarget());
    $template->save();
  }

}
