<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore editado

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Controller\ErrorCodesEnum;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests translation-related behavior of the auto-save API.
 *
 * The auto-save store only ever serializes the *active* (default) translation
 * of a content entity (see \Drupal\canvas\AutoSave\AutoSaveManager::saveEntity()).
 * When that draft is published, the entity is regenerated from that
 * single-translation snapshot, so without intervention the publish would drop
 * every non-default-language translation.
 *
 * This test exercises the auto-save publish path on a faithful, translated
 * Canvas site — i.e. with `content_translation` installed and enabled for the
 * `canvas_page` bundle, and with a real (non-empty) component tree — in both the
 * symmetric and asymmetric translation models:
 *
 * - symmetric:  the `tree` column group is NOT translatable (shared across
 *   translations); only `inputs`/`label` are translatable. Core's field
 *   synchronization re-propagates the just-published tree onto the re-added
 *   translation.
 * - asymmetric: both `tree` and `inputs` are translatable, so every translation
 *   keeps its own independent component tree.
 *
 * @see \Drupal\canvas\Controller\ApiAutoSaveController
 * @see \Drupal\Tests\canvas\Functional\TranslationTest::testCanvasFieldTranslation()
 * @see \Drupal\content_translation\FieldTranslationSynchronizer::synchronizeFields()
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiAutoSaveController::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ApiAutoSaveControllerTranslationTest extends CanvasKernelTestBase {

  use RequestTrait;
  use AutoSaveRequestTestTrait;
  use ConstraintViolationsTestTrait;
  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_A = '11111111-1111-4111-8111-111111111111';
  private const string UUID_B = '22222222-2222-4222-8222-222222222222';
  private const string REGION_COMPONENT_UUID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'content_translation',
    'canvas_dev_translation',
    'config_translation',
    'field',
    'locale',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installConfig(['node']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->generateComponentConfig();
  }

  /**
   * Data provider for ::testPublishingStructuralEditPreservesTranslations().
   *
   * @return array<string, array{0: array<string, string|int>, 1: bool}>
   */
  public static function translationModelProvider(): array {
    return [
      // Symmetric: the `tree` group is synced (non-translatable), `inputs` are
      // translatable. A structural edit on the default translation must
      // propagate to the non-default translation.
      'symmetric' => [['inputs' => 'inputs', 'tree' => 0], TRUE],
      // Asymmetric: both groups translatable. Each translation keeps its own
      // tree, so a structural edit on the default translation must NOT leak in.
      'asymmetric' => [['inputs' => 'inputs', 'tree' => 'tree'], FALSE],
    ];
  }

  /**
   * Tests publishing a structural edit preserves non-default translations.
   *
   * @param array<string, string|int> $translation_sync
   *   The `content_translation` `translation_sync` setting for the `components`
   *   field, controlling which column groups are translatable.
   * @param bool $tree_is_symmetric
   *   Whether the `tree` column group is shared across translations (symmetric).
   *
   * @legacy-covers ::post
   */
  #[DataProvider('translationModelProvider')]
  public function testPublishingStructuralEditPreservesTranslations(array $translation_sync, bool $tree_is_symmetric): void {
    $this->setComponentsColumnSync($translation_sync);

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $page_storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $version = $this->getHeadingComponentVersion();

    // 1. Create the English (default) page with a single component, A.
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'path' => ['alias' => '/english-page'],
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => ['text' => 'Hello A (en)', 'element' => 'h1'],
          'label' => 'English A',
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    self::assertSame(SAVED_NEW, $page->save());
    $page_id = $page->id();
    self::assertNotNull($page_id);

    // 2. Add a Spanish translation with the same component tree but translated
    // `inputs`/`label` for component A, and its own URL alias (the `path`
    // field is translatable).
    $es = $page->addTranslation('es', [
      'title' => 'Spanish title',
      'status' => TRUE,
      'path' => ['alias' => '/spanish-page'],
      'components' => $page->get('components')->getValue(),
    ]);
    \assert($es instanceof Page);
    self::setItemInput($es, self::UUID_A, ['text' => 'Hola A (es)', 'element' => 'h1'], 'Spanish A');
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($es)
      ->setSource('en');
    self::assertEntityIsValid($es);
    $es->save();

    // Sanity check: both translations exist before publishing.
    $page = $page_storage->loadUnchanged($page_id);
    \assert($page instanceof Page);
    self::assertTrue($page->hasTranslation('es'));
    self::assertSame('Hola A (es)', self::getItemInputText($page->getTranslation('es'), self::UUID_A));

    // 3. Make a *structural* edit on the default translation: keep component A
    // (with a changed input), append a new component, B, and change the URL
    // alias. Auto-save it.
    $page->set('components', [
      [
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $version,
        'inputs' => ['text' => 'Hello A updated (en)', 'element' => 'h1'],
        'label' => 'English A',
      ],
      [
        'uuid' => self::UUID_B,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $version,
        'inputs' => ['text' => 'Hello B (en)', 'element' => 'h2'],
        'label' => 'English B',
      ],
    ]);
    $page->set('path', '/english-page-updated');
    self::assertEntityIsValid($page);
    $autoSave->saveEntity($page);

    // 4. Publish the auto-saved default-language draft via the auto-save API.
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $page_key = AutoSaveManager::getAutoSaveKey($page);
    self::assertArrayHasKey($page_key, $auto_save_data);
    $response = $this->makePublishAllRequest([
      $page_key => $auto_save_data[$page_key],
    ]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // 5a. The English (default) translation reflects the structural edit,
    // including the changed URL alias: the `path` field is computed (aliases
    // are persisted as `path_alias` entities), but it must still be published.
    $published = $page_storage->loadUnchanged($page_id);
    \assert($published instanceof Page);
    self::assertSame('English title', $published->label());
    self::assertSame('/english-page-updated', $published->get('path')->first()?->getValue()['alias']);
    self::assertSame('Hello A updated (en)', self::getItemInputText($published, self::UUID_A));
    self::assertSame('Hello B (en)', self::getItemInputText($published, self::UUID_B));

    // 5b. The Spanish translation is preserved …
    self::assertTrue(
      $published->hasTranslation('es'),
      'The Spanish translation must survive publishing the English auto-save.',
    );
    $es_published = $published->getTranslation('es');
    // … its translatable title and URL alias are untouched …
    self::assertSame('Spanish title', $es_published->label());
    self::assertSame('/spanish-page', $es_published->get('path')->first()?->getValue()['alias']);
    // … and its translatable `inputs`/`label` on A are NOT overwritten by the
    // English values, in both translation models.
    self::assertSame('Hola A (es)', self::getItemInputText($es_published, self::UUID_A));
    $es_item_a = self::getItem($es_published, self::UUID_A);
    self::assertSame('Spanish A', $es_item_a->getLabel());

    // 5c. The component tree behaves according to the translation model.
    $es_item_b = self::findItem($es_published, self::UUID_B);
    if ($tree_is_symmetric) {
      // Symmetric: the new component B was synced onto the translation, and
      // shows the source-language input until it is itself translated.
      self::assertNotNull(
        $es_item_b,
        'Symmetric model: the published structural edit must be synchronized to the translation.',
      );
      self::assertSame('Hello B (en)', self::getItemInputText($es_published, self::UUID_B));
    }
    else {
      // Asymmetric: the translation keeps its independent tree (A only); the
      // structural edit on the default translation must not leak in.
      self::assertNull(
        $es_item_b,
        'Asymmetric model: the translation tree is independent of the default translation.',
      );
    }
  }

  /**
   * Tests publishing an auto-saved *non-default* translation.
   *
   * The auto-save snapshot belongs to whichever translation was edited. When a
   * non-default translation is auto-saved and then published, the changes must
   * land on that translation and must not clobber the default translation. The
   * edited column here is `inputs`, which is translatable in both the symmetric
   * and asymmetric models, so the outcome is identical for both.
   *
   * @param array<string, string|int> $translation_sync
   *   The `content_translation` `translation_sync` setting for the `components`
   *   field, controlling which column groups are translatable.
   * @param bool $tree_is_symmetric
   *   Whether the `tree` column group is shared across translations (symmetric).
   *
   * @legacy-covers ::post
   */
  #[DataProvider('translationModelProvider')]
  public function testPublishingNonDefaultTranslationPreservesDefaultTranslation(array $translation_sync, bool $tree_is_symmetric): void {
    $this->setComponentsColumnSync($translation_sync);

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $page_storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $version = $this->getHeadingComponentVersion();

    // English (default) page with a single component, A.
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'path' => ['alias' => '/english-page'],
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => ['text' => 'Hello A (en)', 'element' => 'h1'],
          'label' => 'English A',
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    self::assertSame(SAVED_NEW, $page->save());
    $page_id = $page->id();
    self::assertNotNull($page_id);

    // Spanish translation with its own translated input/label for component A,
    // and its own URL alias (the `path` field is translatable).
    $es = $page->addTranslation('es', [
      'title' => 'Spanish title',
      'status' => TRUE,
      'path' => ['alias' => '/spanish-page'],
      'components' => $page->get('components')->getValue(),
    ]);
    \assert($es instanceof Page);
    self::setItemInput($es, self::UUID_A, ['text' => 'Hola A (es)', 'element' => 'h1'], 'Spanish A');
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($es)
      ->setSource('en');
    self::assertEntityIsValid($es);
    $es->save();

    // Edit *the Spanish translation* (inputs, label and URL alias) and
    // auto-save it. The auto-save entry is keyed by the 'es' langcode and only
    // contains the Spanish translation.
    $page = $page_storage->loadUnchanged($page_id);
    \assert($page instanceof Page);
    $es = $page->getTranslation('es');
    \assert($es instanceof Page);
    self::setItemInput($es, self::UUID_A, ['text' => 'Hola A editado (es)', 'element' => 'h1'], 'Spanish A edited');
    $es->set('path', '/spanish-page-edited');
    $autoSave->saveEntity($es);

    $page_key = AutoSaveManager::getAutoSaveKey($es);
    // The GET endpoint hides non-default-translation entries; the es key must
    // not appear in the pending-changes list.
    $pending = $this->getAutoSaveStatesFromServer();
    self::assertArrayNotHasKey($page_key, $pending, 'Non-default-translation auto-saves must be hidden from the pending-changes list.');

    // The auto-save entry is still stored internally.
    $all_auto_saves = $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertArrayHasKey($page_key, $all_auto_saves, 'The auto-save entry must be stored for the Spanish translation.');

    // POST must reject the non-default-translation key with 409
    // UnexpectedItemInPublishRequest, because GET never exposes it to the
    // client — there is nothing to publish from the client's perspective.
    // @todo This should be publishable once https://git.drupalcode.org/project/canvas/-/work_items/3591703 is fixed and
    //   asymmetrical translations are supported in https://git.drupalcode.org/project/canvas/-/work_items/3571130.
    $response = $this->makePublishAllRequest([
      $page_key => \array_diff_key($all_auto_saves[$page_key], \array_flip(AutoSaveManager::AUTO_SAVE_INTERNAL_PROPERTIES)),
    ]);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode(), (string) $response->getContent());
    $decoded = \json_decode((string) $response->getContent(), TRUE);
    self::assertCount(1, $decoded['errors']);
    self::assertSame(ErrorCodesEnum::UnexpectedItemInPublishRequest->value, $decoded['errors'][0]['code']);
    self::assertSame($page_key, $decoded['errors'][0]['source']['pointer']);

    // The default-translation content must be untouched (the POST was rejected).
    $unpublished = $page_storage->loadUnchanged($page_id);
    \assert($unpublished instanceof Page);
    self::assertSame('Hello A (en)', self::getItemInputText($unpublished, self::UUID_A), 'English translation must be untouched after rejected publish.');
    self::assertSame('Hola A (es)', self::getItemInputText($unpublished->getTranslation('es'), self::UUID_A), 'Spanish translation must be untouched after rejected publish.');
  }

  /**
   * Configures the `translation_sync` column settings for the components field.
   *
   * The `components` field is a base field, so the setting lives on a
   * BaseFieldOverride rather than a FieldConfig.
   *
   * @param array<string, string|int> $translation_sync
   *   The `content_translation` `translation_sync` third-party setting.
   *
   * @see \content_translation_form_language_content_settings_submit()
   */
  private function setComponentsColumnSync(array $translation_sync): void {
    $field_manager = $this->container->get('entity_field.manager');
    $components = $field_manager->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID)['components'];
    \assert($components instanceof BaseFieldDefinition);
    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components')
      ?? BaseFieldOverride::createFromBaseFieldDefinition($components, Page::ENTITY_TYPE_ID);
    $override->setThirdPartySetting('content_translation', 'translation_sync', $translation_sync);
    $override->save();
    $field_manager->clearCachedFieldDefinitions();
  }

  /**
   * Returns the active version of the heading SDC component.
   */
  private function getHeadingComponentVersion(): string {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('component')
      ->load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    return $component->getActiveVersion();
  }

  /**
   * Returns the component tree item with the given UUID, or NULL if absent.
   */
  private static function findItem(Page $page, string $uuid): ?ComponentTreeItem {
    $list = $page->get('components');
    \assert($list instanceof ComponentTreeItemList);
    return $list->getComponentTreeItemByUuid($uuid);
  }

  /**
   * Returns the component tree item with the given UUID from a page.
   */
  private static function getItem(Page $page, string $uuid): ComponentTreeItem {
    $item = self::findItem($page, $uuid);
    \assert($item instanceof ComponentTreeItem);
    return $item;
  }

  /**
   * Returns the `text` input of the component tree item with the given UUID.
   */
  private static function getItemInputText(Page $page, string $uuid): mixed {
    return self::getItem($page, $uuid)->getInputs()['text'] ?? NULL;
  }

  /**
   * Sets the inputs and label of the component tree item with the given UUID.
   *
   * @param array<string, mixed> $inputs
   *   The component inputs to set.
   */
  private static function setItemInput(Page $page, string $uuid, array $inputs, string $label): void {
    self::getItem($page, $uuid)->setInput($inputs)->setLabel($label);
  }

  /**
   * Tests that publishing reconciles a stale ES override on a PageRegion.
   *
   * When a code component's prop is removed, calling ApiLayoutController::get()
   * (by requesting a page) triggers updateComponentInstances(), which auto-saves
   * the PageRegion and its language config overrides. This test verifies that
   * when those auto-saves are published, the removed prop is absent from the
   * live ES override.
   *
   * @legacy-covers ::post
   */
  public function testPageRegionComponentPropRemovalCreatesAutoSavesForTranslation(): void {
    $account = $this->setUpCurrentUser(permissions: [
      PageRegion::ADMIN_PERMISSION,
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    // 1. Create a JavaScriptComponent with two optional text props.
    $component = JavaScriptComponent::create([
      'machineName' => 'test_two_props',
      'name' => 'Test Two Props',
      'status' => TRUE,
      'props' => [
        'text_one' => ['type' => 'string', 'title' => 'Text One'],
        'text_two' => ['type' => 'string', 'title' => 'Text Two'],
      ],
      'js' => ['original' => 'console.log("test")', 'compiled' => 'console.log("test")'],
      'css' => ['original' => '.test{display:none;}', 'compiled' => '.test{display:none;}'],
      'slots' => [],
      'dataDependencies' => [],
    ]);
    self::assertEntityIsValid($component);
    self::assertSame(SAVED_NEW, $component->save());

    $component_version = Component::load('js.test_two_props')?->getActiveVersion();
    self::assertNotNull($component_version);

    // 2. Create PageRegions from the theme block layout. Set the component tree
    // on one region; save all so the theme has its full set of PageRegion
    // config entities (required for loadForActiveTheme() during layout GET).
    $regions = PageRegion::createFromBlockLayout('stark');
    $region = $regions['stark.sidebar_first'];
    $region->setComponentTree([
      [
        'uuid' => self::REGION_COMPONENT_UUID,
        'component_id' => 'js.test_two_props',
        'component_version' => $component_version,
        'inputs' => [
          'text_one' => 'Hello',
          'text_two' => 'World',
        ],
      ],
    ]);
    self::assertEntityIsValid($region);
    $region->save();
    foreach ($regions as $key => $r) {
      if ($key !== 'stark.sidebar_first') {
        self::assertEntityIsValid($region);
        $r->save();
      }
    }

    // A Page entity is required — PageRegions are only rendered when a canvas
    // page is requested via the layout API.
    $page = Page::create(['title' => 'Test page', 'status' => FALSE, 'owner' => $account->id()]);
    self::assertEntityIsValid($page);
    $page->save();

    // 3. Create a Spanish LanguageConfigOverride for the PageRegion.
    $language_manager = $this->container->get('language_manager');
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $override = $language_manager->getLanguageConfigOverride('es', $region->getConfigDependencyName());
    $override->set('component_tree', [
      self::REGION_COMPONENT_UUID => [
        'inputs' => [
          'text_one' => 'Hola',
          'text_two' => 'Hola 2',
        ],
      ],
    ])->save();
    self::assertEntityIsValid($region);

    // 4. Remove the second prop from the code component. This triggers the
    // creation of a new component version.
    $component_config_entity_before = Component::load(JsComponentDiscovery::getComponentConfigEntityId($component->id()));
    self::assertNotNull($component_config_entity_before);
    self::assertSame(['9cf0a5f76460e069'], $component_config_entity_before->getVersions());
    // @phpstan-ignore-next-line method.notFound
    $prop_definitions_before = $component_config_entity_before->getComponentSource()->getExplicitInputDefinitions()['shapes'];
    $component->set('props', ['text_one' => ['type' => 'string', 'title' => 'Text One']]);
    self::assertEntityIsValid($component);
    $component->save();
    $component_config_entity_after = Component::load(JsComponentDiscovery::getComponentConfigEntityId($component->id()));
    self::assertNotNull($component_config_entity_after);
    self::assertSame(['fc47b59d52e9c9e0', '9cf0a5f76460e069'], $component_config_entity_after->getVersions());
    // TRICKY: This should NOT make the PageRegion invalid, because it continues
    // to point to the same component version (the old one) and the removed prop
    // is optional. However, because
    // JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator must inspect
    // the actual JSON schema, and SDCs nor code components retain
    // the JSON schema for deleted/modified props, no JSON schema is available.
    // @todo This is a regression for default translations of config-defined component trees, caused by ComponentInputsMapping in https://git.drupalcode.org/project/canvas/-/work_items/3582478. Although automatic component instance updating (ComponentInstanceUpdaterInterface) automatically fixes it. Consider fixing this regression by expanding what `type: canvas.json_schema_props`'s `prop_field_definitions` stores: either add `translatable: { type: boolean }`, `json_schema: { type: ignore }`, or something in between (like the prop shape) in <URL>.
    // @phpstan-ignore-next-line method.notFound
    $prop_definitions_after = $component_config_entity_after->getComponentSource()->getExplicitInputDefinitions()['shapes'];
    self::assertSame(['text_one' => ['type' => 'string'], 'text_two' => ['type' => 'string']], $prop_definitions_before);
    self::assertSame(['text_one' => ['type' => 'string']], $prop_definitions_after);
    self::assertSame([
      '' => '[<em class="placeholder">es</em>] [<em class="placeholder">component_tree.' . self::REGION_COMPONENT_UUID . '.inputs.text_two</em>] <em class="placeholder">&#039;text_two&#039; is not a supported key.</em>',
      'component_tree.' . self::REGION_COMPONENT_UUID . '.inputs.text_two' => "'text_two' is not a supported key.",
    ], self::violationsToArray($region->getTypedData()->validate()));

    // 5. Request the page layout — this triggers addGlobalRegions() →
    // buildRegion() → updateComponentInstances() → autoSaveManager->saveEntity()
    // for the PageRegion and its ES override.
    $url = Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ])->toString();
    $response = $this->request(Request::create($url));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    // 6. Verify auto-saves were created for the region and its override.
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    $all_auto_saves = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    $region_key = AutoSaveManager::getAutoSaveKey($region);
    self::assertArrayHasKey($region_key, $all_auto_saves, 'PageRegion must have an auto-save after the layout GET removed a prop.');

    // The ES staged override is embedded in the PageRegion auto-save group,
    // not as a separate entry in getAllAutoSaveList(). Verify it was staged and
    // what exactly was staged.
    $staged_es = $region->getTranslation('es');
    self::assertFalse($staged_es->isEmpty(), 'The ES staged override must not be empty after updateComponentInstances().');
    self::assertSame([
      'component_tree' => [
        self::REGION_COMPONENT_UUID => [
          'inputs' => [
            'text_one' => 'Hola',
          ],
        ],
      ],
    ], $staged_es->getData());
    // @todo Remove this assertion in favor of the commented out assertion once <URL> is fixed.
    // self::assertEntityIsValid($region);
    self::assertSame([
      '' => '[<em class="placeholder">es</em>] [<em class="placeholder">component_tree.' . self::REGION_COMPONENT_UUID . '.inputs.text_two</em>] <em class="placeholder">&#039;text_two&#039; is not a supported key.</em>',
      'component_tree.' . self::REGION_COMPONENT_UUID . '.inputs.text_two' => "'text_two' is not a supported key.",
    ], self::violationsToArray($region->getTypedData()->validate()));

    // 7. Publish only the PageRegion auto-save via the auto-save API.
    $response = $this->makePublishAllRequest([$region_key => \array_diff_key($all_auto_saves[$region_key], \array_flip(AutoSaveManager::AUTO_SAVE_INTERNAL_PROPERTIES))]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // After publishing, the live ES override must not contain the removed prop.
    $live_override = $language_manager->getLanguageConfigOverride('es', $region->getConfigDependencyName());
    $inputs = $live_override->get('component_tree.' . self::REGION_COMPONENT_UUID . '.inputs');
    self::assertIsArray($inputs);
    self::assertSame('Hola', $inputs['text_one'], 'The translated value for text_one must be preserved.');
    self::assertArrayNotHasKey('text_two', $inputs, 'The removed prop text_two must not appear in the published ES override.');
    $reloaded = PageRegion::load($region->id());
    self::assertNotNull($reloaded);
    self::assertEntityIsValid($reloaded);
  }

  /**
   * Tests that publishing reconciles a stale ES override on a ContentTemplate.
   *
   * Same scenario as testPageRegionComponentPropRemovalCreatesAutoSavesForTranslation()
   * but using a ContentTemplate config entity, which uses the
   * canvas.api.layout.get.content_template route.
   *
   * @legacy-covers ::post
   */
  public function testContentTemplateComponentPropRemovalCreatesAutoSavesForTranslation(): void {
    $this->setUpCurrentUser(permissions: [
      ContentTemplate::ADMIN_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      'access content',
    ]);

    // 1. Create a JavaScriptComponent with two optional text props.
    $component = JavaScriptComponent::create([
      'machineName' => 'test_two_props_ct',
      'name' => 'Test Two Props CT',
      'status' => TRUE,
      'props' => [
        'text_one' => ['type' => 'string', 'title' => 'Text One'],
        'text_two' => ['type' => 'string', 'title' => 'Text Two'],
      ],
      'js' => ['original' => 'console.log("test")', 'compiled' => 'console.log("test")'],
      'css' => ['original' => '.test{display:none;}', 'compiled' => '.test{display:none;}'],
      'slots' => [],
      'dataDependencies' => [],
    ]);
    self::assertSame(SAVED_NEW, $component->save());

    $component_version = Component::load('js.test_two_props_ct')?->getActiveVersion();
    self::assertNotNull($component_version);

    // 2. Create an article node type and a node to serve as the preview entity.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Preview node']);
    $node->save();

    // 3. Create the ContentTemplate with the component in its tree.
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::REGION_COMPONENT_UUID,
          'component_id' => 'js.test_two_props_ct',
          'component_version' => $component_version,
          'inputs' => [
            'text_one' => 'Hello',
            'text_two' => 'World',
          ],
        ],
      ],
    ]);
    $template->save();

    // 4. Create a Spanish LanguageConfigOverride for the ContentTemplate.
    $language_manager = $this->container->get('language_manager');
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $override = $language_manager->getLanguageConfigOverride('es', $template->getConfigDependencyName());
    $override->set('component_tree', [
      self::REGION_COMPONENT_UUID => [
        'inputs' => [
          'text_one' => 'Hola',
          'text_two' => 'Hola 2',
        ],
      ],
    ])->save();

    // 5. Remove the second prop from the component.
    $component->set('props', ['text_one' => ['type' => 'string', 'title' => 'Text One']]);
    $component->save();

    // 6. Request the ContentTemplate layout — this triggers buildRegion() →
    // updateComponentInstances() → autoSaveManager->saveEntity() for the
    // ContentTemplate and its ES override.
    $url = Url::fromRoute('canvas.api.layout.get.content_template', [
      'entity' => $template->id(),
      'preview_entity' => $node->id(),
    ])->toString();
    $response = $this->request(Request::create($url));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    // 7. Verify auto-saves were created for the template and its override.
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    $all_auto_saves = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    $template_key = AutoSaveManager::getAutoSaveKey($template);
    self::assertArrayHasKey($template_key, $all_auto_saves, 'ContentTemplate must have an auto-save after the layout GET removed a prop.');

    $staged_es = $template->getTranslation('es');
    self::assertFalse($staged_es->isEmpty(), 'The ES staged override must not be empty after updateComponentInstances().');

    // 8. Publish only the ContentTemplate auto-save via the auto-save API.
    $response = $this->makePublishAllRequest([$template_key => \array_diff_key($all_auto_saves[$template_key], \array_flip(AutoSaveManager::AUTO_SAVE_INTERNAL_PROPERTIES))]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // After publishing, the live ES override must not contain the removed prop.
    $live_override = $language_manager->getLanguageConfigOverride('es', $template->getConfigDependencyName());
    $inputs = $live_override->get('component_tree.' . self::REGION_COMPONENT_UUID . '.inputs');
    self::assertIsArray($inputs);
    self::assertSame('Hola', $inputs['text_one'], 'The translated value for text_one must be preserved.');
    self::assertArrayNotHasKey('text_two', $inputs, 'The removed prop text_two must not appear in the published ES override.');
  }

}
