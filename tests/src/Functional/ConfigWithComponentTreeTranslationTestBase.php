<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
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
 * NOTE: Page variants are not tested because ContentTemplate allows a superset
 * of prop sources (it allows EntityFieldPropSources etc which page variant
 * config entities' component trees do not).
 *
 * @see \Drupal\Tests\canvas\Kernel\Config\ConfigWithComponentTreeTestBase
 * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTranslationLifeCycleInDepth()
 * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::testGetTranslatableInputKeys()
 * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::providerSymmetricallyTranslatableComponentInstanceScenarios()
 */
abstract class ConfigWithComponentTreeTranslationTestBase extends FunctionalTestBase {

  use ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
  use ConstraintViolationsTestTrait;
  use DataProviderWithComponentTreeTrait;

  protected const CONFIG_NAME = 'canvas.content_template.node.article.full';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_block',
    'canvas_test_sdc',
    'canvas_test_translation',
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

    $expected_inputs = self::expectedTranslatedInputs(
      expect_overrides_only: TRUE,
      // SDC props populated by prop sources other than StaticPropSource should
      // not store translations.
      my_hero_cta1: NULL,
      my_hero_cta1href: NULL,
    );
    self::assertSame(
      [
        'component_tree' => \array_map(
          static fn (array $inputs) => ['inputs' => $inputs],
          $expected_inputs,
        ),
      ],
      $override->getRawData(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Modules are installed in groups, which means this module cannot be
    // installed in the same group as canvas.
    \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->rebuildContainer();
    $this->drupalLogin($this->rootUser);

    $existing_template = ContentTemplate::load('node.article.full');
    if ($existing_template instanceof ContentTemplate) {
      $existing_template->delete();
    }

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      // cta1 and cta1href use non-static prop sources to exercise that
      // EntityFieldPropSource and HostEntityUrlPropSource are excluded from
      // translation. ContentTemplate allows these; Page does not.
      'component_tree' => self::populateActiveComponentVersionPlaceholders(
        self::componentTreeItems(
          cta1: [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
          cta1href: [
            'sourceType' => PropSource::HostEntityUrl->value,
            'absolute' => TRUE,
          ],
        )
      ),
    ]);
    $violations = $template->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations), $template->getConfigTarget());
    $template->save();
  }

}
