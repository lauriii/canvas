<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore editado

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
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
  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_A = '11111111-1111-4111-8111-111111111111';
  private const string UUID_B = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   *
   * Core ships the `content_translation` `translation_sync` third-party schema
   * for `field_config` but not for `base_field_override`.
   *
   * @todo Remove this exclusion once core adds the missing schema in
   *   https://www.drupal.org/project/drupal/issues/3387100.
   * @see \Drupal\content_translation\config\schema\content_translation.schema.yml
   */
  protected static $configSchemaCheckerExclusions = [
    'core.base_field_override.canvas_page.canvas_page.components',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'content_translation',
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

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $page_key = AutoSaveManager::getAutoSaveKey($es);
    self::assertArrayHasKey($page_key, $auto_save_data, 'The auto-save entry must be keyed by the Spanish translation.');

    $response = $this->makePublishAllRequest([
      $page_key => $auto_save_data[$page_key],
    ]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    $published = $page_storage->loadUnchanged($page_id);
    \assert($published instanceof Page);
    $model = $tree_is_symmetric ? 'symmetric' : 'asymmetric';

    // The Spanish edit is published, including its URL alias …
    self::assertSame('Hola A editado (es)', self::getItemInputText($published->getTranslation('es'), self::UUID_A), $model);
    self::assertSame('Spanish A edited', self::getItem($published->getTranslation('es'), self::UUID_A)->getLabel(), $model);
    self::assertSame('/spanish-page-edited', $published->getTranslation('es')->get('path')->first()?->getValue()['alias'], $model);
    // … while the English default translation is left completely untouched.
    self::assertSame('English title', $published->label(), $model);
    self::assertSame('Hello A (en)', self::getItemInputText($published, self::UUID_A), $model);
    self::assertSame('English A', self::getItem($published, self::UUID_A)->getLabel(), $model);
    self::assertSame('/english-page', $published->get('path')->first()?->getValue()['alias'], $model);
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

}
