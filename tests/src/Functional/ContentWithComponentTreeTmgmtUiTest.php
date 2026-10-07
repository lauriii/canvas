<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle Charte graphique Bonjour Traduit Gitane tjid tuid

use Behat\Mink\Element\NodeElement;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Hook\TmgmtHooks;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputTranslatability;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\tmgmt\Functional\TmgmtTestTrait;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt_local\Entity\LocalTask;
use Drupal\tmgmt_local\LocalTaskInterface;
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
#[CoversMethod(TmgmtHooks::class, 'tmgmtDataItemTextOutputAlter')]
#[CoversMethod(TmgmtHooks::class, 'formTmgmtLocalTaskItemEditFormAlter')]
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
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_test']);
    // Rebuild necessary for TMGMT-specific field metadata changes.
    // @see \Drupal\canvas\Hook\TmgmtHooks::fieldInfoAlter()
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
    // language but still offered for translation: the internal ∅ sentinel is
    // never shown, an explanatory message replaces the source textarea.
    $assert->pageTextContains('This field is empty in the source language.');

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
      // still offered for translation (its source column shows a message),
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

  /**
   * Tests empty-field translation semantics on the TMGMT review form.
   *
   * The ∅ sentinel is internal only and must never be visible to translators
   * nor stored. A blank translation is valid — no "The field is empty." error
   * — and is stored as an explicitly empty string:
   *
   * AC1: REQUIRED prop ("heading") left blank → TMGMT's "The field is empty."
   *      error remains: Canvas rejects an empty required prop in any language,
   *      so the translator must provide a value. The same applies to a nested
   *      row of a required prop ("cta1href|uri").
   * AC2: Source has value, real translation provided → translated value
   *      stored.
   * AC3: Source is empty (message shown instead of a source textarea),
   *      translation left blank → stays empty; ∅ never stored.
   * AC4: Source is empty, translator provides "Bonjour" → "Bonjour" is stored;
   *      source field remains empty.
   * AC5: OPTIONAL prop with source value "Sub" left blank → an explicitly
   *      empty string is stored (NOT the source value, NOT ∅), with no
   *      validation error.
   * AC6: A translator plugin echoing the exact ∅ sentinel back for one item of
   *      a composite input, next to a really translated sibling item → the
   *      echoed item is refilled from the default translation; no NULL leaks
   *      into the stored composite value.
   * AC7: A translator plugin echoing the exact ∅ sentinel back for a scalar
   *      prop → discarded; the default translation's value is stored.
   * AC8: A translation that merely CONTAINS the sentinel (tmgmt_test's
   *      translator returns "fr: ∅" for an empty source) → stored verbatim.
   *
   * Falling back to the default translation's value is intentionally NOT
   * supported here; that is a separate design discussion.
   */
  public function testEmptySetPlaceholderBehavior(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_test']);
    $this->rebuildContainer();

    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);

    [$page_id, $uuid_hero, $uuid_block, $uuid_deep] = $this->createEmptySetTestPageFixture();

    $translator = $this->createTranslator([
      'name' => 'test_tmgmt_ac',
      'label' => 'Test TMGMT AC',
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

    $hero_delta = 0;
    $block_delta = 1;
    $deep_delta = 2;

    // The ∅ sentinel must never be visible in the source column. Empty source
    // inputs (label, name) show an explanatory message instead of a textarea,
    // confirming they are still offered for translation.
    // (The translation column may contain ∅ here because tmgmt_test's dummy
    // translator prefixes the raw source text; real stored sentinels are
    // blanked by tmgmtDataItemTextOutputAlter and never shown.)
    $page = $this->getSession()->getPage();
    $source_cells = $page->findAll('css', 'td.tmgmt-ui-data-item-source');
    self::assertNotEmpty($source_cells);
    foreach ($source_cells as $source_cell) {
      self::assertStringNotContainsString('∅', $source_cell->getText());
    }
    // AC3: empty-source rows have no source textarea, only the message; rows
    // with a source value have the textarea and no message.
    $empty_message = 'This field is empty in the source language.';
    $source_cell_xpath = "//textarea[@name='%s[translation]']/ancestor::tr[1]/td[contains(@class, 'tmgmt-ui-data-item-source')]";
    foreach (["components|{$block_delta}|label", "components|{$block_delta}|name", "components|{$deep_delta}|label"] as $empty_source_key) {
      $assert->elementNotExists('css', "textarea[name='{$empty_source_key}[source]']");
      $assert->elementTextContains('xpath', \sprintf($source_cell_xpath, $empty_source_key), $empty_message);
    }
    foreach (["components|{$hero_delta}|heading", "components|{$hero_delta}|subheading"] as $filled_source_key) {
      $assert->elementExists('css', "textarea[name='{$filled_source_key}[source]']");
      $assert->elementTextNotContains('xpath', \sprintf($source_cell_xpath, $filled_source_key), $empty_message);
    }

    $translations = [
      // AC1: required prop left blank — must keep TMGMT's error.
      "components|{$hero_delta}|heading[translation]" => '',
      // AC2: real French translation for a non-empty source field.
      "components|{$hero_delta}|cta2[translation]" => 'En savoir plus',
      // AC5: optional prop with a non-empty source left blank — explicitly
      // empty, no error.
      "components|{$hero_delta}|subheading[translation]" => '',
      // AC7: a translator plugin echoing the exact sentinel back for a scalar
      // prop with a non-empty source.
      "components|{$hero_delta}|cta1[translation]" => '∅',
      // AC1: nested row of a required prop left blank — must keep TMGMT's
      // error too.
      "components|{$hero_delta}|cta1href|uri[translation]" => '',
      // AC3: label source is empty; left blank — stays empty, ∅ never stored.
      "components|{$block_delta}|label[translation]" => '',
      // AC4: name source is empty; provide a real French value.
      "components|{$block_delta}|name[translation]" => 'Bonjour',
      // AC6: a translator plugin echoing the exact sentinel back for ONE item
      // of a composite input while its sibling gets a real translation. The
      // echoed item must be refilled from the default translation — no NULL
      // may leak into the stored composite value.
      // AC8: this empty-source row is deliberately NOT overridden, so the test
      // translator's "fr: ∅" (contains, but is not, the sentinel) is kept.
      "components|{$deep_delta}|deeply_nested_translatable|0|bar[translation]" => '∅',
      "components|{$deep_delta}|deeply_nested_translatable|1|bar[translation]" => 'Traduit',
    ];

    // Blank translations must survive a plain "Save" and a fresh page load
    // without turning into the sentinel or an error ("Save" does not run
    // TMGMT's per-row validation).
    $this->submitForm($translations, 'Save');
    $this->drupalGet('admin/tmgmt/items/' . $job_item->id());
    $assert->fieldValueEquals("components|{$hero_delta}|heading[translation]", '');
    $assert->fieldValueEquals("components|{$block_delta}|label[translation]", '');
    // AC7/AC6: an echoed sentinel was saved into the job item but must never
    // be shown in the translation column, i.e. it is blanked just like in the
    // source column (tmgmtDataItemTextOutputAlter()).
    $assert->fieldValueEquals("components|{$hero_delta}|cta1[translation]", '');
    $assert->fieldValueEquals("components|{$deep_delta}|deeply_nested_translatable|0|bar[translation]", '');

    // AC1: Validate errors on the blank REQUIRED prop only. Blank optional
    // props are valid.
    $this->submitForm($translations, 'Validate');
    $assert->pageTextContains('The field is empty.');
    $assert->elementExists('css', "textarea[name='components|{$hero_delta}|heading[translation]'].error");
    $assert->elementExists('css', "textarea[name='components|{$hero_delta}|cta1href|uri[translation]'].error");
    $assert->elementNotExists('css', "textarea[name='components|{$hero_delta}|subheading[translation]'].error");
    $assert->elementNotExists('css', "textarea[name='components|{$hero_delta}|cta1[translation]'].error");
    $assert->elementNotExists('css', "textarea[name='components|{$block_delta}|label[translation]'].error");
    // The masking of empty translations for TMGMT's validation must never
    // leak the sentinel into the re-rendered textareas.
    $assert->fieldValueEquals("components|{$hero_delta}|subheading[translation]", '');

    // With the required prop translated, validation passes: no error for the
    // blank (explicitly empty) optional rows.
    $translations["components|{$hero_delta}|heading[translation]"] = 'Bienvenue';
    $translations["components|{$hero_delta}|cta1href|uri[translation]"] = 'https://fr.example.com';
    $this->submitForm($translations, 'Validate');
    $assert->pageTextNotContains('The field is empty.');
    $assert->pageTextContains('Validation completed successfully.');

    $this->submitForm($translations, 'Save as completed');
    $canvas_page = Page::load($page_id);
    self::assertNotNull($canvas_page);
    $assert->pageTextContains(\sprintf('The translation for %s has been accepted', $canvas_page->label()));

    $fr_page = $canvas_page->getTranslation('fr');
    $fr_tree = $fr_page->getComponentTree();

    $hero_item = $fr_tree->getComponentTreeItemByUuid($uuid_hero);
    self::assertNotNull($hero_item);
    $hero_inputs = $hero_item->getInputs();

    // AC1: the required prop and the nested row of a required prop had to be
    // translated to pass validation.
    self::assertSame('Bienvenue', $hero_inputs['heading'] ?? NULL,
      'AC1: A required prop must carry the provided translation.'
    );
    self::assertSame('https://fr.example.com', $hero_inputs['cta1href']['uri'] ?? NULL,
      'AC1: A nested row of a required prop must carry the provided translation.'
    );

    // AC7: the exact-sentinel echo for a scalar prop is discarded and the
    // default translation's value is stored.
    self::assertSame('View', $hero_inputs['cta1'] ?? NULL,
      'AC7: A discarded sentinel echo for a scalar prop must be refilled from the default translation.'
    );

    // AC2: real translation stored verbatim.
    self::assertSame('En savoir plus', $hero_inputs['cta2'],
      'AC2: Provided translation must be stored verbatim.'
    );

    // AC5: blank translation for another non-empty source ("Sub") — also an
    // explicitly empty string.
    self::assertSame('', $hero_inputs['subheading'] ?? NULL,
      'AC5: A blank translation must be stored as an explicitly empty string.'
    );

    $block_item = $fr_tree->getComponentTreeItemByUuid($uuid_block);
    self::assertNotNull($block_item);
    $block_inputs = $block_item->getInputs();

    // AC3: label source was empty and the translation was left blank → stays
    // empty; ∅ never stored.
    self::assertSame('', $block_inputs['label'] ?? NULL,
      'AC3: An empty-source field left blank must stay empty.'
    );

    // AC4: real French value stored for an empty-source field.
    self::assertSame('Bonjour', $block_inputs['name'],
      'AC4: A real translation for an empty-source field must be stored.'
    );

    $deep_item = $fr_tree->getComponentTreeItemByUuid($uuid_deep);
    self::assertNotNull($deep_item);
    $deep_inputs = $deep_item->getInputs();

    // AC8: a translation that merely contains the sentinel is stored verbatim.
    self::assertSame('fr: ∅', $deep_inputs['label'] ?? NULL,
      'AC8: A translation containing (but not equal to) the sentinel must be stored verbatim.'
    );

    // The sentinel must never be stored, at any depth, in any item.
    foreach (['hero' => $hero_inputs, 'block' => $block_inputs, 'deep' => $deep_inputs] as $label => $inputs) {
      \array_walk_recursive($inputs, static function (mixed $value, int|string $key) use ($label): void {
        self::assertNotSame('∅', $value, "The ∅ sentinel must never appear as a stored input value ($label: $key).");
      });
    }

    // AC6: the exact-sentinel echo for item 0 is discarded and the whole item
    // is refilled from the default translation; the sibling item keeps its
    // real translation. No NULL leaks into the stored composite value.
    self::assertSame(
      [
        ['foo' => 'Huh?', 'bar' => 'Gitane'],
        ['foo' => 'Meh', 'bar' => 'Traduit'],
      ],
      $deep_inputs['deeply_nested_translatable'] ?? NULL,
      'AC6: A discarded sentinel echo inside a composite input must be refilled from the default translation, next to a really translated sibling.'
    );
  }

  /**
   * Tests empty-field sentinel behavior via the tmgmt_local translation UI.
   *
   * Mirrors testEmptySetPlaceholderBehavior() but exercises the
   * LocalTaskItemForm at /translate/items/{id} instead of TMGMT's own
   * JobItemForm at /admin/tmgmt/items/{id}. The two forms are parallel,
   * independent implementations: LocalTaskItemForm has no hook extension
   * points, so sentinel handling must be applied via a separate
   * hook_form_tmgmt_local_task_item_edit_form_alter().
   *
   * Acceptance criteria (same semantics as testEmptySetPlaceholderBehavior):
   *
   * AC1: REQUIRED prop left blank → "Missing translation." error fires.
   * AC2: Source has value, real translation provided → stored verbatim.
   * AC3: Source is empty (message instead of source textarea), translation
   *      left blank → stored as ''; ∅ never stored.
   * AC4: Source is empty, translator provides value → that value stored.
   * AC5: OPTIONAL prop with non-empty source left blank → stored as '';
   *      no "Missing translation." error.
   * AC7: Exact ∅ echo for a scalar prop → discarded; default value stored.
   * AC8: Translation merely containing ∅ ("fr: ∅") → stored verbatim.
   *
   * @see \Drupal\canvas\Hook\TmgmtHooks::formTmgmtJobItemEditFormAlter()
   */
  public function testEmptySetPlaceholderBehaviorViaLocalTaskItemForm(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_local', 'tmgmt_test']);
    $this->rebuildContainer();

    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);

    [$page_id, $uuid_hero, $uuid_block, $uuid_deep] = $this->createEmptySetTestPageFixture();

    $local_translator = Translator::load('local');
    if ($local_translator === NULL) {
      $local_translator = Translator::create([
        'name' => 'local',
        'label' => 'Drupal user',
        'plugin' => 'local',
        'remote_languages_mappings' => [],
        'settings' => [],
      ]);
      $local_translator->save();
    }

    $this->config('tmgmt_local.settings')->set('allow_all', TRUE)->save();

    $job = $this->createJob('en', 'fr', $this->rootUser->id(), ['translator' => $local_translator->id()]);
    $job_item = $job->addItem('content', Page::ENTITY_TYPE_ID, (string) $page_id);
    $job->setState(Job::STATE_ACTIVE);
    $local_translator->getPlugin()->requestTranslation($job);

    $task_ids = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('tmgmt_local_task')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('tjid', $job->id())
      ->execute();
    $local_task = LocalTask::load(\reset($task_ids));
    self::assertNotNull($local_task);

    $local_task->set('tuid', $this->rootUser->id());
    $local_task->set('status', LocalTaskInterface::STATUS_PENDING);
    $local_task->save();

    $task_items = $local_task->getItems();
    $local_task_item = \reset($task_items);
    self::assertNotNull($local_task_item);
    self::assertIsObject($local_task_item);

    $this->drupalGet('translate/items/' . $local_task_item->id());
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    $hero_delta = 0;
    $block_delta = 1;
    $deep_delta = 2;

    // The ∅ sentinel must never appear in the source textarea. Empty-source
    // rows must show an explanatory message instead of a source textarea.
    $source_textareas = $this->getSession()->getPage()->findAll('css', 'textarea[disabled]');
    foreach ($source_textareas as $textarea) {
      if (\str_contains((string) $textarea->getAttribute('name'), '[source]')) {
        self::assertStringNotContainsString('∅', $textarea->getText(),
          'AC3: ∅ sentinel must not appear in any source textarea on LocalTaskItemForm.'
        );
      }
    }
    $empty_message = 'This field is empty in the source language.';
    foreach (["components|{$block_delta}|label", "components|{$block_delta}|name", "components|{$deep_delta}|label"] as $empty_source_key) {
      // No source textarea for empty-source rows.
      $assert->elementNotExists('css', "textarea[name='{$empty_source_key}[source]']");
      // The explanatory message appears somewhere in the form row.
      $assert->pageTextContains($empty_message);
    }

    $translations = [
      // The local translator does not auto-translate; provide the title
      // translation so validateSaveAsComplete() does not error on it.
      'title|0|value[translation]' => 'Page de test AC (ensemble vide)',
      // AC1: required prop left blank.
      "components|{$hero_delta}|heading[translation]" => '',
      // AC2: real French translation.
      "components|{$hero_delta}|cta2[translation]" => 'En savoir plus',
      // AC5: optional prop with non-empty source, left blank.
      "components|{$hero_delta}|subheading[translation]" => '',
      // AC7: exact sentinel echo for a scalar prop.
      "components|{$hero_delta}|cta1[translation]" => '∅',
      // AC1: nested required prop left blank.
      "components|{$hero_delta}|cta1href|uri[translation]" => '',
      // AC3: empty-source label left blank.
      "components|{$block_delta}|label[translation]" => '',
      // AC4: real value for an empty-source field.
      "components|{$block_delta}|name[translation]" => 'Bonjour',
      // AC7/AC6: sentinel echo for one item; real translation for its sibling.
      "components|{$deep_delta}|deeply_nested_translatable|0|bar[translation]" => '∅',
      "components|{$deep_delta}|deeply_nested_translatable|1|bar[translation]" => 'Traduit',
    ];

    // AC1: "Save as completed" must error on the blank required prop.
    $this->submitForm($translations, 'Save as completed');
    $assert->pageTextContains('Missing translation.');
    // AC5: the blank optional translation must not be replaced with the
    // sentinel in re-rendered textareas after a failed validation —
    // LocalTaskItemForm::validateSaveAsComplete() errors at the page level
    // only (no field-level .error class), so we verify AC1/AC5 via values.
    $assert->fieldValueEquals("components|{$hero_delta}|subheading[translation]", '');
    $assert->fieldValueEquals("components|{$block_delta}|label[translation]", '');
    // The required prop was submitted blank — it must still be blank.
    $assert->fieldValueEquals("components|{$hero_delta}|heading[translation]", '');
    $assert->fieldValueEquals("components|{$hero_delta}|cta1href|uri[translation]", '');

    // Fill the required props and save as completed.
    $translations["components|{$hero_delta}|heading[translation]"] = 'Bienvenue';
    $translations["components|{$hero_delta}|cta1href|uri[translation]"] = 'https://fr.example.com';
    $this->submitForm($translations, 'Save as completed');
    $assert->pageTextNotContains('Missing translation.');

    // LocalTaskItemForm::saveAsComplete() calls addTranslatedData() with
    // TMGMT_DATA_ITEM_STATE_TRANSLATED, putting the job item into "needs
    // review". Accept programmatically so the French translation is applied to
    // the Drupal entity without going through JobItemForm's review step (which
    // is covered by testEmptySetPlaceholderBehavior()).
    $job_item_id = $job_item->id();
    self::assertNotNull($job_item_id);
    $reloaded_job_item = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('tmgmt_job_item')
      ->load($job_item_id);
    self::assertInstanceOf(JobItemInterface::class, $reloaded_job_item);
    $reloaded_job_item->acceptTranslation();

    $canvas_page = Page::load($page_id);
    self::assertNotNull($canvas_page);
    $fr_page = $canvas_page->getTranslation('fr');
    $fr_tree = $fr_page->getComponentTree();

    $hero_item = $fr_tree->getComponentTreeItemByUuid($uuid_hero);
    self::assertNotNull($hero_item);
    $hero_inputs = $hero_item->getInputs();

    // AC1: required props carry the provided translation.
    self::assertSame('Bienvenue', $hero_inputs['heading'] ?? NULL,
      'AC1: A required prop must carry the provided translation.'
    );
    self::assertSame('https://fr.example.com', $hero_inputs['cta1href']['uri'] ?? NULL,
      'AC1: A nested required prop must carry the provided translation.'
    );

    // AC2: real translation stored verbatim.
    self::assertSame('En savoir plus', $hero_inputs['cta2'] ?? NULL,
      'AC2: Provided translation must be stored verbatim.'
    );

    // AC5: blank optional translation stored as an explicit empty string.
    self::assertSame('', $hero_inputs['subheading'] ?? NULL,
      'AC5: A blank optional translation must be stored as an explicitly empty string.'
    );

    // AC7: exact-sentinel echo for a scalar prop is discarded; default stored.
    self::assertSame('View', $hero_inputs['cta1'] ?? NULL,
      'AC7: An echoed sentinel for a scalar prop must be discarded; the default translation value must be stored.'
    );

    $block_item = $fr_tree->getComponentTreeItemByUuid($uuid_block);
    self::assertNotNull($block_item);
    $block_inputs = $block_item->getInputs();

    // AC3: empty-source field left blank → stored as ''; ∅ never stored.
    self::assertSame('', $block_inputs['label'] ?? NULL,
      'AC3: An empty-source field left blank must stay empty.'
    );

    // AC4: real value for an empty-source field stored verbatim.
    self::assertSame('Bonjour', $block_inputs['name'] ?? NULL,
      'AC4: A real translation for an empty-source field must be stored.'
    );

    $deep_item = $fr_tree->getComponentTreeItemByUuid($uuid_deep);
    self::assertNotNull($deep_item);
    $deep_inputs = $deep_item->getInputs();

    // AC8: translation merely containing ∅ is stored verbatim.
    // (The deep block label source is empty; tmgmt_local stores whatever the
    // translator typed, which in this test fixture is left blank → ''.)
    self::assertSame('', $deep_inputs['label'] ?? NULL,
      'AC8 (label): Blank translation of empty-source field must be stored as empty string.'
    );

    // The sentinel must never appear as a stored value at any depth.
    foreach (['hero' => $hero_inputs, 'block' => $block_inputs, 'deep' => $deep_inputs] as $label => $inputs) {
      \array_walk_recursive($inputs, static function (mixed $value, int|string $key) use ($label): void {
        self::assertNotSame('∅', $value,
          "The ∅ sentinel must never appear as a stored input value ($label: $key)."
        );
      });
    }

    // AC6: sentinel echo for item 0 of a composite is discarded and refilled
    // from the default translation; sibling item keeps its real translation.
    self::assertSame(
      [
        ['foo' => 'Huh?', 'bar' => 'Gitane'],
        ['foo' => 'Meh', 'bar' => 'Traduit'],
      ],
      $deep_inputs['deeply_nested_translatable'] ?? NULL,
      'AC6: A discarded sentinel echo inside a composite input must be refilled from the default translation.'
    );
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

  /**
   * Creates the Page fixture used by both empty-set placeholder behavior tests.
   *
   * Used by testEmptySetPlaceholderBehavior() and
   * testEmptySetPlaceholderBehaviorViaLocalTaskItemForm().
   *
   * @return array{int|string, string, string, string}
   *   [$page_id, $uuid_hero, $uuid_block, $uuid_deep]
   */
  private static function createEmptySetTestPageFixture(): array {
    $uuid_hero = 'ac1ac2ac-0000-4000-8000-000000000001';
    $uuid_block = 'ac3ac4ac-0000-4000-8000-000000000002';
    $uuid_deep = 'ac6ac6ac-0000-4000-8000-000000000003';

    $canvas_page = Page::create([
      'title' => 'Empty-set AC test page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => $uuid_hero,
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'heading' => 'Welcome',
            'subheading' => 'Sub',
            'cta1' => 'View',
            'cta1href' => ['uri' => 'https://example.com', 'options' => []],
            'cta2' => 'Learn more',
          ],
        ],
        [
          'uuid' => $uuid_block,
          'component_id' => 'block.canvas_test_block_input_validatable',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'label' => '',
            'label_display' => '0',
            'name' => '',
          ],
        ],
        [
          'uuid' => $uuid_deep,
          'component_id' => 'block.' . CanvasTestBlockInputTranslatability::PLUGIN_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'label' => '',
            'label_display' => '0',
            'top_level_translatable_regardless_of_type' => ['translations', 'are', 'hard'],
            'deeply_nested_translatable' => [
              ['foo' => 'Huh?', 'bar' => 'Gitane'],
              ['foo' => 'Meh', 'bar' => 'Kro'],
            ],
          ],
        ],
      ]),
    ]);
    $violations = $canvas_page->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations));
    $canvas_page->save();

    $page_id = $canvas_page->id();
    self::assertNotNull($page_id);

    return [$page_id, $uuid_hero, $uuid_block, $uuid_deep];
  }

}
