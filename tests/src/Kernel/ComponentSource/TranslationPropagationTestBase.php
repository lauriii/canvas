<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola mundo opcional

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared fixture for symmetrical translation propagation kernel tests.
 *
 * Provides the JavaScriptComponent fixture, prop-mutation helpers, a shared
 * testPropagation() test, and its data provider. Concrete subclasses supply
 * the entity-type-specific translation setup and assertion logic via three
 * abstract methods.
 *
 * @phpstan-import-type OptimizedSingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 */
abstract class TranslationPropagationTestBase extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'language',
  ];

  /**
   * The component instance UUID under test in every fixture's component tree.
   */
  protected const string COMPONENT_INSTANCE_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * The default (English) inputs every fixture's component instance starts with.
   */
  protected const array EN_TRANSLATION_INPUTS = [
    'required_text' => 'Hello world',
    'optional_text' => 'Optional EN',
    'features' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
  ];

  /**
   * The ES translation inputs written before each test's component update.
   *
   * Subclasses use this constant both in their setUp() and in providerPropagation()
   * expected values so the two stay automatically in sync.
   */
  protected const array ES_TRANSLATION_INPUTS = [
    'required_text' => 'Hola mundo',
    'optional_text' => 'opcional ES',
    'features' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
  ];

  /**
   * The default-translation draft a Content Creator stages before the bump.
   *
   * Drops the (untranslatable) `features` value and edits the two strings.
   */
  protected const array DEFAULT_DRAFT_INPUTS = [
    'required_text' => 'YO',
    'optional_text' => 'HO',
  ];

  protected JavaScriptComponent $jsComponent;
  protected string $originalVersion;

  /**
   * @var \Drupal\canvas\Entity\ComponentTreeConfigEntityBase|\Drupal\Core\Entity\ContentEntityInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);

    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->jsComponent = JavaScriptComponent::create([
      'machineName' => 'translatable_js_component',
      'name' => 'Prop Propagation Test',
      'status' => TRUE,
      'props' => [
        'required_text' => [
          'type' => 'string',
          'title' => 'Required Text',
          'examples' => ['Press'],
        ],
        'optional_text' => [
          'type' => 'string',
          'title' => 'Optional Text',
          'examples' => ['Click me'],
        ],
        'features' => [
          // No `maxItems` → unlimited cardinality.
          'type' => 'array',
          'items' => ['type' => 'string'],
          'title' => 'Features',
          'examples' => [['Alpha', 'Beta', 'Gamma', 'Delta']],
        ],
      ],
      'required' => ['required_text'],
      'slots' => [
        'test_slot' => [
          'title' => 'Test slot',
          'description' => 'A slot used to exercise exposed-slot translations.',
          'examples' => ['Slot content'],
        ],
      ],
      'js' => [
        'original' => 'console.log("test")',
        'compiled' => 'console.log("test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'dataDependencies' => [],
    ]);
    self::assertSame(SAVED_NEW, $this->jsComponent->save());

    $component_id = JsComponentDiscovery::getComponentConfigEntityId($this->jsComponent->id());
    $component = Component::load($component_id);
    self::assertNotNull($component);
    $this->originalVersion = $component->getActiveVersion();
  }

  /**
   * Sets up the non-default (ES) translation before the component is updated.
   *
   * Called once at the start of testPropagation(), before the prop mutation.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\canvas\Entity\ComponentTreeConfigEntityBase
   *   The default-translation entity whose component tree will be updated.
   */
  abstract protected function setUpTranslation(): ContentEntityInterface|ComponentTreeConfigEntityBase;

  /**
   * Asserts the ES translation state after the update.
   *
   * @param OptimizedSingleComponentInputArray $expected_content
   *   Exact expected ES inputs for the content entity translation.
   * @param OptimizedSingleComponentInputArray|false $expected_config
   *   Exact expected ES inputs stored in the config entity's
   *   LanguageConfigOverride, or FALSE when the override record is deleted
   *   entirely (all translatable inputs removed).
   */
  abstract protected function assertTranslationAfterUpdate(array $expected_content, array|false $expected_config): void;

  /**
   * Tests that non-default translations are updated when component props change.
   *
   * @param string $setup_method
   *   A method name on $this that mutates $this->jsComponent.
   * @param bool $expected_modified
   *   Whether updateComponentInstances() should report a modification.
   * @param OptimizedSingleComponentInputArray $expected_content
   *   Exact expected ES inputs for the content entity translation after update.
   * @param OptimizedSingleComponentInputArray|false $expected_config
   *   Exact expected ES inputs for the config entity's LanguageConfigOverride
   *   after update, or FALSE when the override is deleted entirely.
   */
  #[DataProvider('providerPropagation')]
  public function testPropagation(
    string $setup_method,
    bool $expected_modified,
    array $expected_content,
    array|false $expected_config,
  ): void {
    $this->entity = $this->setUpTranslation();
    self::assertEntityIsValid($this->entity);

    $this->{$setup_method}();

    $loader = $this->container->get(ComponentTreeLoader::class);
    \assert($loader instanceof ComponentTreeLoader);
    $tree = $loader->load($this->entity);
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertSame($expected_modified, $was_modified);

    $this->assertTranslationAfterUpdate($expected_content, $expected_config);
  }

  public static function providerPropagation(): \Generator {
    yield 'New optional prop added — translation unchanged (updater skips optional props)' => [
      'setup_method' => 'addOptionalProp',
      'expected_modified' => TRUE,
      'expected_content' => self::ES_TRANSLATION_INPUTS,
      'expected_config' => self::ES_TRANSLATION_INPUTS,
    ];
    yield 'New required prop added — translation gets example value' => [
      'setup_method' => 'addRequiredProp',
      'expected_modified' => TRUE,
      'expected_content' => self::ES_TRANSLATION_INPUTS + ['voice' => 'polite'],
      'expected_config' => self::ES_TRANSLATION_INPUTS,
    ];
    yield 'Optional prop deleted — orphaned input removed from translation' => [
      'setup_method' => 'removeOptionalProp',
      'expected_modified' => TRUE,
      'expected_content' => ['required_text' => 'Hola mundo', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']],
      'expected_config' => ['required_text' => 'Hola mundo', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']],
    ];
    yield 'Unsafe prop type change — update blocked, translation unchanged' => [
      'setup_method' => 'changePropType',
      'expected_modified' => FALSE,
      'expected_content' => self::ES_TRANSLATION_INPUTS,
      'expected_config' => self::ES_TRANSLATION_INPUTS,
    ];
    yield 'Prop removed and another added — removed key gone, new optional key absent from translation' => [
      'setup_method' => 'removeAndAddProp',
      'expected_modified' => TRUE,
      'expected_content' => ['required_text' => 'Hola mundo', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']],
      'expected_config' => ['required_text' => 'Hola mundo', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']],
    ];
    yield 'All translatable props deleted — config override deleted entirely, content inputs emptied' => [
      'setup_method' => 'removeAllProps',
      'expected_modified' => TRUE,
      'expected_content' => [],
      'expected_config' => FALSE,
    ];
    yield 'Array prop cardinality decreased — translated array truncated to new max' => [
      'setup_method' => 'decreaseArrayPropCardinality',
      'expected_modified' => TRUE,
      'expected_content' => ['required_text' => 'Hola mundo', 'optional_text' => 'opcional ES', 'features' => ['Alpha', 'Beta', 'Gamma']],
      'expected_config' => ['required_text' => 'Hola mundo', 'optional_text' => 'opcional ES', 'features' => ['Alpha', 'Beta', 'Gamma']],
    ];
  }

  /**
   * Creates the default-translation entity with no translation yet.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\canvas\Entity\ComponentTreeConfigEntityBase
   *   The default-translation entity whose component tree carries the
   *   ::COMPONENT_INSTANCE_UUID instance at ::$originalVersion.
   */
  abstract protected function createBaseEntity(): ContentEntityInterface|ComponentTreeConfigEntityBase;

  /**
   * Stores a symmetrical translation for the entity under test.
   *
   * Content entities store it on the entity; config entities store a sparse
   * LanguageConfigOverride.
   *
   * @param string $langcode
   *   The translation language.
   * @param OptimizedSingleComponentInputArray $inputs
   *   The translated component instance inputs.
   */
  abstract protected function createTranslation(string $langcode, array $inputs): void;

  /**
   * Previews the entity under test through the real layout GET endpoint.
   *
   * @param string $preview_langcode
   *   The language to preview: 'en' (default) or 'es' (the translation).
   */
  abstract protected function previewThroughLayoutController(string $preview_langcode): void;

  /**
   * Permissions the previewing+publishing user needs for this entity type.
   *
   * @return string[]
   */
  abstract protected function previewAndPublishPermissions(): array;

  /**
   * Asserts the auto-save list once a single preview has run.
   *
   * Content entities list one auto-save entry per translation; config entities
   * list only the base draft (their overrides are server-internal).
   *
   * @param string $default_key
   *   The default translation's (base draft's) auto-save key.
   */
  abstract protected function assertAutoSaveListAfterPreview(string $default_key): void;

  /**
   * Asserts the reconciled, staged (not yet published) translation draft.
   *
   * @param OptimizedSingleComponentInputArray $expected_inputs
   *   The expected reconciled translation inputs.
   */
  abstract protected function assertReconciledTranslationDraft(array $expected_inputs): void;

  /**
   * The live (published) translation's component version and inputs.
   *
   * Read from the entity's own representation: content entities keep a full
   * per-translation tree; config entities keep a sparse LanguageConfigOverride
   * whose effective component version is the base entity's.
   *
   * @return array{version: ?string, inputs: ?array<string, mixed>}
   */
  abstract protected function translationVersionAndInputs(): array;

  /**
   * Loads a fresh, unchanged copy of the (default-translation) entity under test.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\canvas\Entity\ComponentTreeConfigEntityBase
   *   The reloaded entity.
   */
  protected function reloadEntity(): ContentEntityInterface|ComponentTreeConfigEntityBase {
    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage($this->entity->getEntityTypeId())
      ->loadUnchanged((string) $this->entity->id());
    \assert($reloaded instanceof ContentEntityInterface || $reloaded instanceof ComponentTreeConfigEntityBase);
    return $reloaded;
  }

  /**
   * The live (published) default translation's component version and inputs.
   *
   * @return array{version: ?string, inputs: ?array<string, mixed>}
   */
  protected function defaultVersionAndInputs(): array {
    $entity = $this->reloadEntity();
    \assert($entity instanceof ComponentTreeEntityInterface);
    $item = $entity->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    return ['version' => $item?->getComponentVersion(), 'inputs' => $item?->getInputs()];
  }

  /**
   * Previewing one translation reconciles + auto-saves all, then publishes all.
   *
   * A Content Creator drafts a change to the default translation, then the
   * component evolves, then a single preview is requested. The preview must
   * reconcile the base draft AND every translation to the new version, keeping
   * each translator's value and pruning the deleted prop. Publishing the (only
   * pending) default draft must then publish every symmetrical translation.
   *
   * Two axes are covered. The $scenario axis asserts the order of operations
   * does not matter: a translation stored before the base draft is created is
   * reconciled the same as one stored afterwards. The $preview_langcode axis
   * asserts the previewed language does not matter: every translation is
   * reconciled regardless of which one is previewed.
   *
   * Content and config entities differ only in how the per-translation drafts
   * are stored and listed; those differences live in the abstract hooks, while
   * the flow and invariants asserted here are shared by all entity types.
   *
   * @param string $preview_langcode
   * @param 'translate-before-auto-save'|'translate-after-auto-save' $scenario
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::get()
   * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::buildRegion()
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  #[TestWith(['en', 'translate-before-auto-save'])]
  #[TestWith(['es', 'translate-before-auto-save'])]
  #[TestWith(['en', 'translate-after-auto-save'])]
  #[TestWith(['es', 'translate-after-auto-save'])]
  public function testPreviewTriggersInstanceUpdateWrittenToAutoSaveForAllTranslations(string $preview_langcode, string $scenario): void {
    \assert(\in_array($preview_langcode, ['en', 'es'], TRUE));
    \assert(\in_array($scenario, ['translate-before-auto-save', 'translate-after-auto-save'], TRUE));
    $uuid = self::COMPONENT_INSTANCE_UUID;

    // Allow the preview request to target a language via a URL prefix. The
    // container must be rebuilt so the URL language negotiator picks up the
    // prefixes and strips them inbound when routing the preview request.
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'es' => 'es'])->save();
    $this->container->get('kernel')->rebuildContainer();
    // Router must be built before UserCreationTrait::setUpCurrentUser() triggers
    // FilterPermissions::permissions() URL generation.
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], $this->previewAndPublishPermissions());

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // The default-translation entity, with no translation yet.
    $this->entity = $this->createBaseEntity();
    $default_key = AutoSaveManager::getAutoSaveKey($this->entity);

    // A translator stores an ES translation before the base draft is created.
    if ($scenario === 'translate-before-auto-save') {
      $this->createTranslation('es', self::ES_TRANSLATION_INPUTS);
    }

    // The Content Creator drafts a change to the default translation, creating a
    // base auto-save BEFORE the component evolves. Deletes the value for the
    // (untranslatable) `features` prop, changes the two strings.
    $default_entity = $this->reloadEntity();
    \assert($default_entity instanceof ComponentTreeEntityInterface);
    $tree = $default_entity->getComponentTree();
    $item = $tree->getComponentTreeItemByUuid($uuid);
    self::assertNotNull($item);
    $item->setInput(self::DEFAULT_DRAFT_INPUTS);
    $default_entity->setComponentTree($tree->getValue());
    $auto_save_manager->saveEntity($default_entity);
    $this->entity = $this->reloadEntity();

    // Only the default's draft is listed, at the original component version.
    self::assertSame([$default_key], \array_keys($auto_save_manager->getAllAutoSaveList(FALSE, FALSE)));
    $draft = $auto_save_manager->getAutoSaveEntity($this->entity)->entity;
    \assert($draft instanceof ComponentTreeEntityInterface);
    $draft_item = $draft->getComponentTree()->getComponentTreeItemByUuid($uuid);
    self::assertNotNull($draft_item);
    self::assertSame($this->originalVersion, $draft_item->getComponentVersion());
    self::assertSameInputs(self::DEFAULT_DRAFT_INPUTS, $draft_item->getInputs());

    // The same translator stores the ES translation only AFTER the base draft.
    if ($scenario === 'translate-after-auto-save') {
      $this->createTranslation('es', self::ES_TRANSLATION_INPUTS);
    }

    // Record the pre-evolution "before" state (live, original version + inputs)
    // for the default and the translation, to assert against the published
    // "after" state at the end.
    $actual = [];
    $actual['default']['before'] = $this->defaultVersionAndInputs();
    $actual['es']['before'] = $this->translationVersionAndInputs();

    // The component evolves: optional_text removed, voice added → new version.
    $this->removeAndAddProp();
    $versions = Component::load('js.translatable_js_component')?->getVersions() ?? [];
    self::assertCount(2, $versions);
    [$active_version, $old_version] = $versions;
    self::assertSame($this->originalVersion, $old_version);

    // A single preview through the real layout GET endpoint reconciles the base
    // draft AND every translation to the new version, writing them to auto-save:
    // symmetrical translations must remain in sync.
    $this->previewThroughLayoutController($preview_langcode);

    // The model-specific shape of the auto-save list after previewing.
    $this->assertAutoSaveListAfterPreview($default_key);

    // The default draft was reconciled to the new version: the editor's value is
    // kept and the deleted optional_text is pruned.
    $this->entity = $this->reloadEntity();
    $draft = $auto_save_manager->getAutoSaveEntity($this->entity)->entity;
    \assert($draft instanceof ComponentTreeEntityInterface);
    $draft_item = $draft->getComponentTree()->getComponentTreeItemByUuid($uuid);
    self::assertNotNull($draft_item);
    self::assertSame($active_version, $draft_item->getComponentVersion());
    self::assertSameInputs(['required_text' => 'YO'], $draft_item->getInputs());

    // The translation was reconciled too: optional_text pruned, the translator's
    // required_text kept, the untranslated `features` (never populated by the
    // default) preserved.
    $this->assertReconciledTranslationDraft([
      'required_text' => 'Hola mundo',
      'features' => self::ES_TRANSLATION_INPUTS['features'],
    ]);

    // The Content Creator should see only the default translation pending.
    $response = $this->request(Request::create('/canvas/api/v0/auto-saves/pending', 'GET'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $json = \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
    self::assertSame([$default_key], \array_keys($json['data'] ?? []));

    // Publishing the (only pending) default draft publishes every symmetrical
    // translation, too.
    $payload = [$default_key => ['data_hash' => $json['data'][$default_key]['data_hash']]];
    $response = $this->request(Request::create('/canvas/api/v0/auto-saves/publish', 'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode($payload, flags: \JSON_THROW_ON_ERROR),
    ));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame([], $auto_save_manager->getAllAutoSaveList(FALSE, FALSE));

    // Record the published "after" state for the default and the translation.
    $actual['default']['after'] = $this->defaultVersionAndInputs();
    $actual['es']['after'] = $this->translationVersionAndInputs();

    // Assert the precise before/after for both, in an optimized-for-reading
    // format. Note how `features` did NOT disappear from ES even though the
    // Content Creator dropped it from the default: it is untranslatable and
    // ::removeAndAddProp() did not remove it from the schema, and a translation
    // may populate optional inputs the default leaves empty.
    // @phpcs:disable
    self::assertSameVersionAndInputs([
      'before' => ['version' => $old_version,    'inputs' => ['required_text' => 'Hello world', 'optional_text' => 'Optional EN', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']]],
      'after'  => ['version' => $active_version, 'inputs' => ['required_text' => 'YO',                                                                                             ]],
    ], $actual['default']);
    self::assertSameVersionAndInputs([
      'before' => ['version' => $old_version,    'inputs' => ['required_text' => 'Hola mundo', 'optional_text' => 'opcional ES', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']]],
      'after'  => ['version' => $active_version, 'inputs' => ['required_text' => 'Hola mundo',                                   'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']]],
    ], $actual['es']);
    // @phpcs:enable
  }

  /**
   * Asserts a before/after {version, inputs} map for a single component.
   *
   * Versions are compared exactly; inputs go through ::assertSameInputs() so
   * the comparison ignores key order (database-backend-dependent: MySQL and
   * PostgreSQL reorder JSON object keys).
   *
   * @param array{before: array{version: ?string, inputs: array<string, mixed>}, after: array{version: ?string, inputs: array<string, mixed>}} $expected
   * @param array{before: array{version: ?string, inputs: ?array<string, mixed>}, after: array{version: ?string, inputs: ?array<string, mixed>}} $actual
   */
  private static function assertSameVersionAndInputs(array $expected, array $actual): void {
    foreach (['before', 'after'] as $phase) {
      self::assertSame($expected[$phase]['version'], $actual[$phase]['version'], "$phase version");
      self::assertSameInputs($expected[$phase]['inputs'], $actual[$phase]['inputs']);
    }
  }

  protected function addOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(!\array_key_exists('voice', $props));
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
  }

  protected function addRequiredProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(!\array_key_exists('voice', $props));
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $required = $this->jsComponent->getRequiredProps();
    $required[] = 'voice';
    $this->jsComponent->setProps($props)->set('required', $required)->save();
  }

  protected function removeOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('optional_text', $props));
    unset($props['optional_text']);
    $this->jsComponent->setProps($props)->save();
  }

  protected function changePropType(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('required_text', $props));
    // Change required_text from string to integer — an unsafe change that
    // blocks the update for all translations.
    $props['required_text'] = ['type' => 'integer', 'title' => 'Required Int', 'examples' => [42]];
    $this->jsComponent->setProps($props)->save();
  }

  protected function removeAndAddProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('optional_text', $props));
    \assert(!\array_key_exists('voice', $props));
    unset($props['optional_text']);
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
  }

  protected function removeAllProps(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('optional_text', $props));
    \assert(\array_key_exists('required_text', $props));
    \assert(\array_key_exists('features', $props));
    \assert(!\array_key_exists('count', $props));
    unset($props['required_text'], $props['optional_text'], $props['features']);
    $props['count'] = ['type' => 'integer', 'title' => 'Count', 'examples' => [3]];
    $this->jsComponent->setProps($props)->set('required', [])->save();
  }

  protected function decreaseArrayPropCardinality(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('features', $props));
    $props['features']['maxItems'] = 3;
    // Adjust the example too!
    unset($props['features']['examples'][0][3]);
    $this->jsComponent->setProps($props)->save();
  }

}
