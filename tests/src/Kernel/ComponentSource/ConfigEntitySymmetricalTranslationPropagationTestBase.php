<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola mundo opcional optionnel editado

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared tests for config entity translation propagation kernel tests.
 *
 * Validates that after the default (English) component tree is updated via
 * ComponentSourceManager::updateComponentInstances(), each language's
 * LanguageConfigOverride is reconciled: deleted-prop keys are pruned,
 * and orphan-free overrides are deleted in their entirety.
 *
 * Config entity translations store only the translatable subset of inputs in
 * LanguageConfigOverride records (sparse, not a full copy). Propagation
 * therefore operates at the override level, not at the entity level.
 *
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type OptimizedSingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 */
abstract class ConfigEntitySymmetricalTranslationPropagationTestBase extends TranslationPropagationTestBase {

  use DataProviderWithComponentTreeTrait;

  /**
   * @var \Drupal\canvas\Entity\ComponentTreeConfigEntityBase
   * @phpstan-ignore-next-line property.phpDocType
   */
  protected $entity;

  /**
   * The component instance UUID used in the config entity's component tree.
   */
  protected const string TRANSLATED_COMPONENT_INSTANCE_UUID = self::COMPONENT_INSTANCE_UUID;

  /**
   * @var ComponentTreeItemListArray
   * @todo Move to TranslationPropagationTestBase
   */
  protected array $translatableComponentTree = [
    [
      'uuid' => self::TRANSLATED_COMPONENT_INSTANCE_UUID,
      'component_id' => 'js.translatable_js_component',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => self::EN_TRANSLATION_INPUTS,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // TRICKY: no extra modules are needed: Drupal core provides the no-UI
    // translation infrastructure in the `language` module.
    // @see \Drupal\language\Config\LanguageConfigFactoryOverride
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUpTranslation(): ComponentTreeConfigEntityBase {
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    return $this->entity;
  }

  /**
   * Permissions the preview request needs beyond the config entity's own admin.
   *
   * The layout GET route enforces edit access to the previewed entity and view
   * access to any preview entity, which differ per config entity type (a
   * ContentTemplate is previewed against a node; a PageRegion is previewed while
   * rendering a Page). ::previewThroughLayoutController() differs likewise: a
   * ContentTemplate is previewed directly; a PageRegion is reconciled as a
   * global region while a Page is previewed.
   *
   * @return string[]
   */
  abstract protected function additionalPreviewPermissions(): array;

  /**
   * {@inheritdoc}
   */
  protected function createBaseEntity(): ComponentTreeConfigEntityBase {
    // The config entity (with no translation override yet) is created in setUp().
    return $this->reloadEntity();
  }

  /**
   * {@inheritdoc}
   */
  protected function createTranslation(string $langcode, array $inputs): void {
    $this->createComponentTreeTranslation($langcode, $inputs);
    self::assertEntityIsValid($this->entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function previewAndPublishPermissions(): array {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $admin_permission = $this->entity->getEntityType()->getAdminPermission();
    \assert(\is_string($admin_permission));
    return [
      $admin_permission,
      AutoSaveManager::PUBLISH_PERMISSION,
      ...$this->additionalPreviewPermissions(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Config overrides are server-internal StagedLanguageConfigOverrides: only the
   * base draft is ever listed, before and after previewing.
   */
  protected function assertAutoSaveListAfterPreview(string $default_key): void {
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    self::assertSame([$default_key], \array_keys($auto_save_manager->getAllAutoSaveList(FALSE, FALSE)));
  }

  /**
   * {@inheritdoc}
   */
  protected function assertReconciledTranslationDraft(array $expected_inputs): void {
    $override = $this->reloadEntity()->getTranslation('es');
    self::assertFalse($override->isEmpty());
    self::assertSame(
      $expected_inputs,
      $override->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * A config translation is a sparse LanguageConfigOverride with no component
   * version of its own; its effective version is the base entity's.
   */
  protected function translationVersionAndInputs(): array {
    $base_item = $this->reloadEntity()->getComponentTree()->getComponentTreeItemByUuid(static::TRANSLATED_COMPONENT_INSTANCE_UUID);
    $override = $this->getStoredTranslation('es');
    return [
      'version' => $base_item?->getComponentVersion(),
      'inputs' => $override->isNew() ? NULL : $override->get('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Config entity translations are sparse LanguageConfigOverride records
   * containing only translatable overrides. New props (required or optional)
   * are never injected — they have no translated value until a translator sets
   * one. Only removed props are pruned from the stored override. When all
   * translatable props are removed, the override record is deleted entirely
   * ($expected_config === FALSE).
   */
  protected function assertTranslationAfterUpdate(array $expected_content, array|false $expected_config): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $staged = $this->entity->getTranslation('es');

    if ($expected_config === FALSE) {
      self::assertTrue($staged->isEmpty(), 'Staged override must be empty when no translatable inputs remain.');
      return;
    }

    self::assertFalse($staged->isEmpty(), 'Staged override must still have data.');
    $stored = $staged->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertSame($expected_config, $stored);
  }

  /**
   * Tests that a translation with no prior override is skipped gracefully.
   */
  public function testNoOverrideSkipped(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    // Do NOT write an override — the language exists but has no translation.
    $this->addOptionalProp();

    $tree = $this->entity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // No override existed before — reconciliation must not populate a staged one.
    self::assertTrue($this->entity->getTranslation('es')->isEmpty(), 'No staged override should be created for a language with no prior translation.');
  }

  /**
   * Tests that all LanguageConfigOverrides for the entity are updated together.
   */
  public function testMultipleLanguageOverridesReconciled(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    $this->createComponentTreeTranslation('fr', [
      'required_text' => 'Bonjour monde',
      'optional_text' => 'optionnel FR',
    ]);
    self::assertHasStoredTranslation('es');
    self::assertHasStoredTranslation('fr');
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->entity);

    $this->removeOptionalProp();

    $tree = $this->entity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // Both staged overrides should have optional_text pruned in-memory.
    $es_stored = $this->entity->getTranslation('es')
      ->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertSame(['required_text' => 'Hola mundo', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']], $es_stored);

    $fr_stored = $this->entity->getTranslation('fr')
      ->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertSame(['required_text' => 'Bonjour monde'], $fr_stored);
  }

  /**
   * Runs updateComponentInstances() and publishes via the real auto-save path.
   *
   * Exercises the full lifecycle:
   * 1. A `LanguageConfigOverride` exists for `es`
   * 2. Generate a `StagedLanguageConfigOverride for this `es` translation
   * 3. It must now not yet be saved, only exist in memory
   * 4. Let it be updated via:
   *    ComponentSourceManager::updateComponentInstances()
   * 5. Call StagedLanguageConfigOverride::save() to move it from PHP memory to
   *    the entity type's storage (i.e. AutoSaveManager)
   * 6. Publish it via the real auto-save controller, which will first ensure it
   *    is valid:
   *    and will then trigger
   *    StagedLanguageConfigOverride::autoSavePublish()
   *    → StagedConfigEntityStorageTrait::save()
   *    → StagedLanguageConfigOverrideStorage::publish()
   *    → LanguageConfigOverride::set()
   *    → LanguageConfigOverride::save()
   *
   * @return \Drupal\canvas\Entity\StagedLanguageConfigOverride
   *   The in-memory staged config translation, so callers can inspect the
   *   in-memory, pre-publish state if needed.
   */
  protected function updateAndPublishOverrides(string $assert_langcode = 'es'): StagedLanguageConfigOverride {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    // Router must be built before UserCreationTrait::setUpCurrentUser()
    // triggers FilterPermissions::permissions()  URL generation.
    $this->container->get('router.builder')->rebuild();

    $admin_permission = $this->entity->getEntityType()->getAdminPermission();
    \assert(\is_string($admin_permission));
    $this->setUpCurrentUser([], [
      $admin_permission,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    $tree = $this->entity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $manager->updateComponentInstances($tree);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Stage the translation override BEFORE setComponentTree() — the latter
    // clears stagedOverrides, so a subsequent getTranslation() would re-read
    // un-pruned data from live config, discarding the reconciliation.
    $staged = $this->entity->getTranslation($assert_langcode);
    self::assertTrue($staged->isNew());
    self::assertFalse($staged->status());
    $staged->save();

    // Assert that both the retrieved-and-now-saved StagedLanguageConfigOverride
    // and its origin (the config entity's ::getTranslation() method) convey
    // that the StagedLanguageConfigOverride has been saved.
    self::assertFalse($staged->isNew());
    self::assertFalse($staged->status());
    self::assertFalse($this->entity->getTranslation($assert_langcode)->isNew());

    // Stage the updated base entity.
    $this->entity->setComponentTree($tree->getValue());
    self::assertFalse($this->entity->getTranslation($assert_langcode)->isNew());
    // Validate the StagedLanguageConfigOverride; it is minimally validated.
    // @see canvas.schema.yml, `canvas.staged_language_config_override.*:data`.
    self::assertEntityIsValid($staged);
    self::assertEntityIsValid($this->entity);
    $auto_save_manager->saveEntity($this->entity);

    // The base config entity must be in auto-save storage. The
    // StagedLanguageConfigOverride is stored internally but filtered from
    // getAllAutoSaveList(); it will be published implicitly when the base
    // entity is published.
    $all_auto_saves = $auto_save_manager->getAllAutoSaveList(FALSE, FALSE);
    self::assertSame([
      AutoSaveManager::getAutoSaveKey($this->entity),
    ], \array_keys($all_auto_saves));

    // Publish everything through the real auto-save publish controller.
    $payload = [];
    foreach ($all_auto_saves as $key => $info) {
      $payload[$key] = ['data_hash' => $info['data_hash']];
    }
    $request = Request::create('/canvas/api/v0/auto-saves/publish', 'POST', content: (string) \json_encode($payload));
    $controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($controller instanceof ApiAutoSaveController);
    $response = $controller->post($request);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // No auto-saves left.
    self::assertSame([], $auto_save_manager->getAllAutoSaveList(FALSE, FALSE));

    return $staged;
  }

  /**
   * Tests that publishing a staged override writes it to the live LanguageConfigOverride.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage
   */
  public function testPublishWritesToLiveOverride(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->entity);

    $this->removeOptionalProp();

    $this->updateAndPublishOverrides();

    // After publish, the live LanguageConfigOverride must have optional_text
    // removed and required_text preserved.
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $live = $language_manager->getLanguageConfigOverride('es', $this->entity->getConfigDependencyName());
    \assert($live instanceof LanguageConfigOverride);
    self::assertFalse($live->isNew(), 'Live override must still exist after partial reconciliation.');
    $inputs = $live->get('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertIsArray($inputs);
    self::assertArrayNotHasKey('optional_text', $inputs, 'Deleted prop must be removed from live override on publish.');
    self::assertSame('Hola mundo', $inputs['required_text']);
  }

  /**
   * Tests that publishing a staged override deletes the live record when empty.
   *
   * When reconciliation removes all translated inputs (all props deleted from
   * the base component), the resulting staged override is empty. Publishing it
   * must delete the live LanguageConfigOverride rather than writing empty data.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage
   */
  public function testPublishDeletesEmptyOverride(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    // Write an override that only has the two props that will both be deleted.
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->entity);

    $this->removeAllProps();

    $staged = $this->updateAndPublishOverrides();
    self::assertTrue($staged->isEmpty(), 'Staged override must be empty after both props deleted.');

    // Live LanguageConfigOverride must be deleted when the staged override is empty.
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $live = $language_manager->getLanguageConfigOverride('es', $this->entity->getConfigDependencyName());
    \assert($live instanceof LanguageConfigOverride);
    self::assertTrue($live->isNew(), 'Live override must be deleted when staged override is empty.');
    self::assertSame([], $live->getRawData());
  }

  /**
   * Tests deleting the entity discards its base and staged-override drafts.
   *
   * A config entity's base draft and its per-language
   * StagedLanguageConfigOverride drafts are separate auto-save entries. Deleting
   * the entity must discard the whole group via hook_entity_delete, so no
   * orphaned override draft is left behind.
   *
   * @legacy-covers \Drupal\canvas\Hook\AutoSaveHooks::entityDelete()
   * @legacy-covers \Drupal\canvas\AutoSave\AutoSaveManager::getTranslationGroupAutoSaves()
   *
   * @see \Drupal\Tests\canvas\Kernel\AutoSaveManagerTest::testPageAutoSaveTranslationBehavior()
   */
  public function testEntityDeleteDiscardsStagedOverrides(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    self::assertEntityIsValid($this->entity);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);

    // Mutate a prop so reconciliation produces an actual override draft to stage.
    $this->removeOptionalProp();

    // Stage the override draft BEFORE setComponentTree() — the latter clears
    // stagedOverrides — then stage the base draft.
    $tree = $this->entity->getComponentTree();
    $manager->updateComponentInstances($tree);
    $staged = $this->entity->getTranslation('es');
    self::assertTrue($staged->isNew());
    $staged->save();
    $this->entity->setComponentTree($tree->getValue());
    $auto_save_manager->saveEntity($this->entity);

    // The base draft and its override draft form one pending-changes set.
    self::assertCount(2, $auto_save_manager->getTranslationGroupAutoSaves($this->entity));

    // Deleting the entity must cascade and discard both drafts together.
    $this->entity->delete();
    self::assertSame([], $auto_save_manager->getTranslationGroupAutoSaves($this->entity));
    self::assertSame([], $auto_save_manager->getAllAutoSaveList(FALSE, FALSE));
  }

  /**
   * Tests a pending translation draft is reconciled at a bump, then published.
   *
   * A translator drafts an edited override (an unpublished `required_text`),
   * then the component evolves and `optional_text` is removed. When the base is
   * next updated, reconciliation must act on that *pending auto-save draft* —
   * not on live config — so the editor's value survives and the deleted prop is
   * pruned. getTranslation() is auto-save-aware, which makes the bump-time
   * updater operate on the real draft.
   *
   * StagedLanguageConfigOverride drafts are server-internal: they never appear
   * in the auto-save list and are published implicitly when their base config
   * entity is published. Publishing the base therefore writes the reconciled
   * override to live config.
   *
   * @legacy-covers \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::getTranslation()
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  public function testPublishReconcilesStaleStagedOverride(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    // Set up a publish-capable user up front, so every auto-save entry created
    // below is owned by the user that later publishes them.
    $admin_permission = $this->entity->getEntityType()->getAdminPermission();
    \assert(\is_string($admin_permission));
    $this->setUpCurrentUser([], [$admin_permission, AutoSaveManager::PUBLISH_PERMISSION]);

    // Live ES override at the original component version.
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    self::assertEntityIsValid($this->entity);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $uuid = static::TRANSLATED_COMPONENT_INSTANCE_UUID;

    // A pending ES draft edits required_text (not yet in live config) while the
    // component still has optional_text. It is staged to auto-save.
    $draft = $this->entity->getTranslation('es');
    $draft->setData("component_tree.$uuid.inputs", [
      'required_text' => 'Hola editado',
      'optional_text' => 'opcional ES',
    ]);
    $draft->save();

    // The component evolves: optional_text is removed.
    $this->removeOptionalProp();

    // Reconcile and re-stage the base + override drafts. No controller persists
    // config translation overrides to auto-save yet (the editor flow for that is
    // not built), so the test performs the reconciliation that flow will do:
    // updateComponentInstances() prunes optional_text from the pending draft
    // while keeping the editor's required_text (getTranslation() is auto-save-
    // aware), and both reconciled drafts are written back to auto-save.
    $base = $this->container->get('entity_type.manager')
      ->getStorage($this->entity->getEntityTypeId())
      ->loadUnchanged((string) $this->entity->id());
    \assert($base instanceof ComponentTreeConfigEntityBase);
    $tree = $base->getComponentTree();
    $manager->updateComponentInstances($tree);
    $reconciled = $base->getTranslation('es');
    self::assertSame(
      ['required_text' => 'Hola editado'],
      $reconciled->getData("component_tree.$uuid.inputs"),
      'The bump must reconcile the pending draft: editor value kept, deleted prop pruned.',
    );
    // Stage the override before setComponentTree() clears the in-memory cache.
    $reconciled->save();
    $base->setComponentTree($tree->getValue());
    $auto_save_manager->saveEntity($base);

    // Only the base appears in the auto-save list; the override is internal.
    $base_key = AutoSaveManager::getAutoSaveKey($base);
    $all = $auto_save_manager->getAllAutoSaveList(FALSE, FALSE);
    self::assertSame([$base_key], \array_keys($all));
    self::assertFalse($auto_save_manager->getAutoSaveEntity($draft)->isEmpty(), 'The override draft is staged, just not listed.');

    // Publish the base. Its staged override is published implicitly.
    $payload = [$base_key => ['data_hash' => $all[$base_key]['data_hash']]];
    $request = Request::create('/canvas/api/v0/auto-saves/publish', 'POST', content: (string) \json_encode($payload));
    $controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($controller instanceof ApiAutoSaveController);
    $response = $controller->post($request);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // Both base and override drafts are cleared once published.
    self::assertSame([], $auto_save_manager->getAllAutoSaveList(FALSE, FALSE));
    self::assertTrue($auto_save_manager->getAutoSaveEntity($draft)->isEmpty(), 'The override draft must be cleared after the base publishes it.');

    // The published live override reflects the reconciled draft: the editor's
    // required_text survives and the deleted optional_text is gone.
    $live = $this->getStoredTranslation('es');
    self::assertFalse($live->isNew());
    self::assertSame(
      ['required_text' => 'Hola editado'],
      $live->get("component_tree.$uuid.inputs"),
    );
  }

  /**
   * Tests StagedLanguageConfigOverride access delegates to its base entity.
   *
   * Create/update/delete defer to the base config entity's `update` access;
   * `view` defers to Canvas UI access. Access is forbidden when the target
   * config entity does not exist or is not a config entity.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideAccessControlHandler
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedConfigEntityAccessControlTrait
   */
  public function testStagedOverrideAccess(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $admin_permission = $this->entity->getEntityType()->getAdminPermission();
    \assert(\is_string($admin_permission));
    $config_name = $this->entity->getConfigDependencyName();
    $entity_type_id = $this->entity->getEntityTypeId();

    $handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler(StagedLanguageConfigOverride::ENTITY_TYPE_ID);

    // An override targeting the existing (saved) base config entity.
    $override = StagedLanguageConfigOverride::create([
      'id' => "es.$config_name",
      'langcode' => 'es',
      'config_name' => $config_name,
      'data' => [],
    ]);

    // A user who can update the base config entity can create/update/delete its
    // overrides.
    $editor = $this->createUser([$admin_permission]);
    self::assertNotFalse($editor);
    foreach (['update', 'delete'] as $op) {
      self::assertTrue($handler->access($override, $op, $editor), "Editor can $op the override.");
    }
    self::assertTrue($handler->createAccess(NULL, $editor, ['config_name' => $config_name]), 'Editor can create an override for an editable base.');

    // A user with no permission cannot view, update, delete or create it.
    $stranger = $this->createUser([]);
    self::assertNotFalse($stranger);
    foreach (['view', 'update', 'delete'] as $op) {
      self::assertFalse($handler->access($override, $op, $stranger), "Stranger cannot $op the override.");
    }
    self::assertFalse($handler->createAccess(NULL, $stranger, ['config_name' => $config_name]), 'Stranger cannot create an override.');

    // Forbidden: the override targets a config entity of the right type that
    // does not exist.
    [$prefix_a, $prefix_b] = \explode('.', $config_name, 3);
    $ghost_config_name = "$prefix_a.$prefix_b.does_not_exist";
    $ghost = StagedLanguageConfigOverride::create([
      'id' => "es.$ghost_config_name",
      'langcode' => 'es',
      'config_name' => $ghost_config_name,
      'data' => [],
    ]);
    $ghost_result = $handler->access($ghost, 'update', $editor, TRUE);
    self::assertFalse($ghost_result->isAllowed());
    self::assertSame(
      "Target configuration entity 'does_not_exist' of type '$entity_type_id' does not exist.",
      $ghost_result instanceof AccessResultReasonInterface ? $ghost_result->getReason() : '',
    );

    // Forbidden: a config name that is not a config entity at all. Reachable
    // only via createAccess(); the entity constructor rejects such names.
    $unsupported = $handler->createAccess(NULL, $editor, ['config_name' => 'system.site'], TRUE);
    self::assertFalse($unsupported->isAllowed());
    self::assertSame(
      "Unsupported configuration object 'system.site'.",
      $unsupported instanceof AccessResultReasonInterface ? $unsupported->getReason() : '',
    );
  }

  /**
   * Tests that discarding the base draft discards the whole pending set.
   *
   * A config entity's draft and its per-language StagedLanguageConfigOverride
   * drafts are one atomic set of pending changes. Discarding the base config
   * entity must discard the base draft and every override draft for that config
   * entity, so none is left orphaned.
   *
   * @legacy-covers \Drupal\canvas\AutoSave\AutoSaveManager::getTranslationGroupAutoSaves()
   * @legacy-covers \Drupal\canvas\AutoSave\AutoSaveManager::groupConfigEntityAutoSaves()
   */
  public function testDiscardingDiscardsWholeConfigTranslationGroup(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    $this->createComponentTreeTranslation('fr', [
      'required_text' => 'Bonjour monde',
      'optional_text' => 'optionnel FR',
    ]);
    self::assertEntityIsValid($this->entity);

    $this->removeOptionalProp();

    $tree = $this->entity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $manager->updateComponentInstances($tree);

    // Stage both override drafts BEFORE setComponentTree() clears stagedOverrides.
    $es_staged = $this->entity->getTranslation('es');
    $es_staged->save();
    $fr_staged = $this->entity->getTranslation('fr');
    $fr_staged->save();

    // Stage the base config-entity draft too.
    $this->entity->setComponentTree($tree->getValue());
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $auto_save_manager->saveEntity($this->entity);

    // All three drafts are now in auto-save.
    self::assertFalse($auto_save_manager->getAutoSaveEntity($this->entity)->isEmpty(), 'Base draft must exist before discarding.');
    self::assertFalse($auto_save_manager->getAutoSaveEntity($es_staged)->isEmpty(), 'ES override draft must exist before discarding.');
    self::assertFalse($auto_save_manager->getAutoSaveEntity($fr_staged)->isEmpty(), 'FR override draft must exist before discarding.');

    // Router must be built before UserCreationTrait::setUpCurrentUser() triggers
    // FilterPermissions::permissions() URL generation.
    $this->container->get('router.builder')->rebuild();
    $admin_permission = $this->entity->getEntityType()->getAdminPermission();
    \assert(\is_string($admin_permission));
    $this->setUpCurrentUser([], [$admin_permission]);

    $controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($controller instanceof ApiAutoSaveController);
    $response = $controller->delete($this->entity);
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

    // The whole atomic set is discarded, whichever entry point was used.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($this->entity)->isEmpty(), 'Base draft must be discarded.');
    self::assertTrue($auto_save_manager->getAutoSaveEntity($es_staged)->isEmpty(), 'ES override draft must be discarded.');
    self::assertTrue($auto_save_manager->getAutoSaveEntity($fr_staged)->isEmpty(), 'FR override draft must be discarded.');

    // The live config is untouched: discarding throws away the reconciliation
    // drafts, so each live override keeps its original (un-pruned) translation.
    $expected = [
      'es' => self::ES_TRANSLATION_INPUTS,
      'fr' => ['required_text' => 'Bonjour monde', 'optional_text' => 'optionnel FR'],
    ];
    foreach ($expected as $langcode => $inputs) {
      $live = $this->getStoredTranslation($langcode);
      self::assertFalse($live->isNew(), "Live $langcode override must survive the discard.");
      self::assertSame($inputs, $live->get('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs'), "Live $langcode override must keep its original translation.");
    }
  }

  /**
   * Tests that non-translatable (enum) props are not leaked into the staged override.
   *
   * Config entity translations store only the translatable subset of inputs.
   * Enum-typed props are not translatable, so a new enum prop added to the base
   * component must not appear in the staged override after reconciliation.
   */
  public function testNonTranslatablePropNotStaged(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->entity);

    // Add a new enum (non-translatable) optional prop.
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    $props['alignment'] = [
      'type' => 'string',
      'title' => 'Alignment',
      'enum' => ['left', 'right'],
      'examples' => ['left'],
    ];
    $this->jsComponent->setProps($props)->save();

    $tree = $this->entity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $manager->updateComponentInstances($tree);

    $staged = $this->entity->getTranslation('es');
    $inputs = $staged->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertIsArray($inputs);
    self::assertArrayNotHasKey('alignment', $inputs, 'Non-translatable enum prop must not appear in staged override.');
    // Existing translatable values are preserved.
    self::assertSame('Hola mundo', $inputs['required_text']);
    self::assertSame('opcional ES', $inputs['optional_text']);
  }

  /**
   * {@inheritdoc}
   */
  protected function reloadEntity(): ComponentTreeConfigEntityBase {
    $reloaded = parent::reloadEntity();
    \assert($reloaded instanceof ComponentTreeConfigEntityBase);
    return $reloaded;
  }

  private function getStoredTranslation(string $langcode): LanguageConfigOverride {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    return $language_manager->getLanguageConfigOverride($langcode, $this->entity->getConfigDependencyName());
  }

  protected function assertHasStoredTranslation(string $langcode): void {
    $translation = $this->getStoredTranslation($langcode);
    self::assertFalse($translation->isNew());
    self::assertNotSame([], $translation->getRawData());
  }

  protected function assertHasNoStoredTranslation(string $langcode): void {
    $translation = $this->getStoredTranslation($langcode);
    self::assertTrue($translation->isNew());
    self::assertSame([], $translation->getRawData());
  }

  /**
   * Writes a LanguageConfigOverride with a translated component tree.
   *
   * @param string $langcode
   * @param OptimizedSingleComponentInputArray $inputs
   *   The symmetrical translation to store: translated component instance
   *   inputs.
   *
   * @return void
   */
  protected function createComponentTreeTranslation(string $langcode, array $inputs): void {
    self::assertHasNoStoredTranslation($langcode);
    $this->getStoredTranslation($langcode)->set('component_tree', [
      static::TRANSLATED_COMPONENT_INSTANCE_UUID => [
        'inputs' => $inputs,
      ],
    ])->save();
    self::assertHasStoredTranslation($langcode);
  }

}
