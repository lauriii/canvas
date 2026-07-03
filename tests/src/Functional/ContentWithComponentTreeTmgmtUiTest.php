<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle Charte graphique

use Behat\Mink\Element\NodeElement;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Hook\TmgmtHooks;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\tmgmt\Functional\TmgmtTestTrait;
use Drupal\tmgmt\Entity\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TMGMT UI translation of a content entity with a component_tree field.
 *
 * Uses Page (not Node) because ComponentTreeFieldProcessor behavior is driven
 * entirely by field type and prop source type — neither varies by entity type.
 * Testing Node would require a ContentTemplate fixture and the extra complexity
 * is orthogonal to what this test covers.
 *
 * Contrast with ComponentTreeFieldSymmetricalTranslationSynchronizerTest, which
 * DOES test both entity types because Canvas' custom synchronizer must handle
 * both and the logic differs per entity type.
 *
 * @see \Drupal\Tests\canvas\Functional\ConfigWithComponentTreeTmgmtUiTest
 * @see \Drupal\Tests\canvas\Kernel\Translation\ComponentTreeFieldSymmetricalTranslationSynchronizerTest
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
#[CoversClass(ComponentTreeFieldProcessor::class)]
#[CoversMethod(TmgmtHooks::class, 'fieldInfoAlter')]
class ContentWithComponentTreeTmgmtUiTest extends FunctionalTestBase {

  use ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
  use ConstraintViolationsTestTrait;
  use ContentTranslationTestTrait;
  use DataProviderWithComponentTreeTrait;
  use TmgmtTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_block',
    'canvas_test_sdc',
    'canvas_test_translation',
    'content_translation',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->rebuildContainer();
    $this->drupalLogin($this->rootUser);
  }

  public function test(): void {
    // Install TMGMT after other modules — rebuildContainer() triggers
    // TmgmtHooks::fieldInfoAlter() with tmgmt_content present,
    // registering ComponentTreeFieldProcessor for component_tree fields.
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_test', 'canvas_dev_translation']);
    $this->rebuildContainer();

    // Enable content translation for canvas_page so the TMGMT content source
    // can create French translations.
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);

    // All cta1/cta1href inputs use static values: Page's component_tree does
    // not support EntityFieldPropSource or HostEntityUrlPropSource.
    $canvas_page = Page::create([
      'title' => 'TMGMT test page',
      'components' => self::populateActiveComponentVersionPlaceholders(
        self::componentTreeItems(
          cta1: 'View here',
          cta1href: ['uri' => 'https://example.com', 'options' => []],
        )
      ),
    ]);
    $violations = $canvas_page->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations), 'canvas_page');
    $canvas_page->save();
    $page_id = $canvas_page->id();

    $translator = $this->createTranslator([
      'name' => 'test_tmgmt',
      'label' => 'Test TMGMT',
      // @see \Drupal\tmgmt_test\Plugin\tmgmt\Translator\TestTranslator
      'plugin' => 'test_translator',
      'remote_languages_mappings' => [],
      'settings' => ['key' => 'test', 'another_key' => 'test'],
    ]);
    $job = $this->createJob('en', 'fr', $this->rootUser->id(), ['translator' => $translator->id()]);
    $job_item = $job->addItem('content', Page::ENTITY_TYPE_ID, (string) $page_id);

    $job->setState(Job::STATE_ACTIVE);
    $translator->getPlugin()->requestTranslation($job);

    $this->drupalGet('admin/tmgmt/items/' . $job_item->id());
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    // Assert source texts visible for all edge cases.
    // Multiple-cardinality: each tag shown individually.
    $assert->pageTextContains('baz');
    $assert->pageTextContains('bar');
    $assert->pageTextContains('foo');
    // my-hero plain string props.
    $assert->pageTextContains('Welcome to Canvas');
    $assert->pageTextContains('View here');
    $assert->pageTextContains('https://example.com');
    $assert->pageTextContains('Learn more');
    // my-cta plain prose + URI-esque.
    $assert->pageTextContains('Press');
    $assert->pageTextContains('https://www.drupal.org');
    // Banner plain prose + rich prose.
    $assert->pageTextContains('A heading element! :)');
    $assert->pageTextContains('In a curious work, published in');
    // Block `label`s and the validatable block's `name` are empty in the source
    // language but still offered for translation, shown as the "empty set"
    // placeholder.
    $assert->pageTextContains('∅');

    // Assert translations generated by test_translator (prefix "fr: ").
    $assert->pageTextContains('fr: baz');
    $assert->pageTextContains('fr: bar');
    $assert->pageTextContains('fr: foo');
    $assert->pageTextContains('fr: Welcome to Canvas');
    $assert->pageTextContains('fr: View here');
    $assert->pageTextContains('fr: https://example.com');
    $assert->pageTextContains('fr: Learn more');
    $assert->pageTextContains('fr: Press');
    $assert->pageTextContains('fr: https://www.drupal.org');
    $assert->pageTextContains('fr: A heading element! :)');

    // Explicitly assert all the form fields that exist per component instance,
    // to prove the generated UI matches expectations.
    self::assertSame([
      self::UUID_TAGS => [
        'components|0|tags|0[translation]',
        'components|0|tags|1[translation]',
        'components|0|tags|2[translation]',
      ],
      // @todo Consider respecting the defined order of props; the config_translation UI does support that.
      self::UUID_MY_HERO => [
        // SDC props populated by StaticPropSources are translatable.
        'components|1|heading[translation]',
        'components|1|cta1[translation]',
        'components|1|cta2[translation]',

        // Optional prop NOT populated in default is translatable: a translation
        // may opt to populate it even when the default translation leaves it
        // empty.
        // @see \Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor::extractTranslatablesIncludingEmpty()
        'components|1|subheading[translation]',

        'components|1|cta1href|uri[translation]',
      ],
      self::UUID_MY_CTA => [
        'components|2|text[translation]',
        'components|2|href|uri[translation]',
      ],
      self::UUID_BANNER => [
        'components|3|heading[translation]',
        'components|3|text|value[translation][value]',
        // Text format is present to load CKEditor 5, but is immutable because
        // it is an `input[type=hidden]`. See the next assertion.
        'components|3|text|value[translation][format]',
      ],
      // Branding block: only `label` is translatable.
      self::UUID_BRANDING => [
        'components|4|label[translation]',
      ],
      // Translatability test block: only `label` and the deeply nested `bar`
      // are translatable.
      self::UUID_BLOCK_DEEP_TRANSLATABLE => [
        'components|5|label[translation]',
        'components|5|deeply_nested_translatable|0|bar[translation]',
      ],
      // Block whose translatable `name` is empty in the source language: it is
      // still offered for translation (shown via the `∅` placeholder),
      // alongside the populated `label`.
      self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT => [
        'components|6|label[translation]',
        'components|6|name[translation]',
      ],
      // untranslatable-prop-shapes component: no prop shape is translatable, so
      // none is offered for translation. `date` (datetime field, date-only),
      // `email`, `integer` and `boolean` are all excluded.
      self::UUID_UNTRANSLATABLE_PROP_SHAPES => [],
    ], $this->getTmgmtFormElementsForComponentInstances());
    // The "format" input exists (to load CKEditor) but is hidden, so it cannot
    // be changed.
    $assert->elementExists(
      'css',
      'input[type="hidden"][name="components|' . self::COMPONENT_DELTA[self::UUID_BANNER] . '|text|value[translation][format]"]',
    );

    // Note: all other translatables get a `fr: ` prefix by the test translator.
    $deltaHero = self::COMPONENT_DELTA[self::UUID_MY_HERO];
    $deltaCta = self::COMPONENT_DELTA[self::UUID_MY_CTA];
    $deltaBranding = self::COMPONENT_DELTA[self::UUID_BRANDING];
    $deltaDeepBlock = self::COMPONENT_DELTA[self::UUID_BLOCK_DEEP_TRANSLATABLE];
    $deltaEmptyBlock = self::COMPONENT_DELTA[self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT];
    $this->submitForm([
      "components|$deltaHero|subheading[translation]" => 'Découvrez Canvas',
      "components|$deltaHero|heading[translation]" => 'Bienvenue à Canvas',
      "components|$deltaHero|cta2[translation]" => 'En savoir plus',
      // Override auto-prefixed URI values to keep them valid.
      "components|$deltaHero|cta1href|uri[translation]" => 'https://fr.example.com',
      "components|$deltaCta|href|uri[translation]" => 'https://fr.drupal.org',
      // Each block's `label` is empty in the source language; translate it
      // explicitly so the test translator does not map `∅` to `fr: ∅`.
      "components|$deltaBranding|label[translation]" => 'Identité visuelle',
      "components|$deltaDeepBlock|label[translation]" => 'fr: Canvas Test Block for testing input translatability',
      "components|$deltaEmptyBlock|label[translation]" => 'fr: Test block with settings',
      // The block's `name` is also empty in the source language.
      "components|$deltaEmptyBlock|name[translation]" => 'Charte graphique',
    ], 'Save as completed');
    $assert->pageTextContains(\sprintf('The translation for %s has been accepted', $canvas_page->label()));

    $canvas_page = Page::load($page_id);
    self::assertNotNull($canvas_page);
    $fr_page = $canvas_page->getTranslation('fr');
    $fr_tree = $fr_page->getComponentTree();

    $expected_inputs = self::expectedTranslatedInputs(
      my_hero_cta1: 'fr: View here',
      my_hero_cta1href: ['uri' => 'https://fr.example.com', 'options' => []],
      expect_overrides_only: FALSE,
    );
    foreach ($expected_inputs as $uuid => $inputs) {
      $fr_item = $fr_tree->getComponentTreeItemByUuid($uuid);
      self::assertNotNull($fr_item, "Component $uuid missing from translated tree");
      $fr_inputs = $fr_item->getInputs();
      self::assertSame($inputs, $fr_inputs);
    }
  }

  private function getTmgmtFormElementsForComponentInstances(): array {
    $page = $this->getSession()->getPage();

    $form_field_names = [];
    foreach (self::COMPONENT_DELTA as $component_instance_uuid => $delta) {
      $form_field_names[$component_instance_uuid] = \array_map(
        fn(NodeElement $n) => $n->getAttribute('name'),
        $page->findAll('css', "[name*='components|$delta'][name*='[translation]']"),
      );
    }

    return $form_field_names;
  }

}
