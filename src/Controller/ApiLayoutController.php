<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas\Render\ServerTiming;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Asset\AssetCollectionRendererInterface;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Asset\LibraryDependencyResolverInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 * @phpstan-import-type SingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 * @phpstan-type ComponentClientStructureArray array{nodeType: 'component', uuid: string, type: ComponentConfigEntityId, slots: array<int, mixed>}
 * @phpstan-type RegionClientStructureArray array{nodeType: 'region', id: string, name: string, components: array<int, ComponentClientStructureArray>}
 * @phpstan-type LayoutClientStructureArray array<int, RegionClientStructureArray>
 */
final class ApiLayoutController {

  use AutoSaveValidateTrait;
  use ClientServerConversionTrait;
  use ComponentTreeItemListInstantiatorTrait;
  use EntityFormTrait;
  public const string AUTO_SAVED_QUERY_KEY = 'autoSaved';

  /**
   * Request attribute marking global regions as frozen for this render.
   *
   * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
   */
  public const string FROZEN_REGIONS_ATTRIBUTE = 'canvas_frozen_regions';

  /**
   * Valid values for the `frozen` preview request body key.
   *
   * The client declares, per request, which tree it is NOT editing: that
   * tree's auto-save validation, overlay, writes, and rendering are skipped.
   * The freeze is stateless: the client computes it from per-tree edit/persist
   * version counters, and a tree with unpersisted edits is never frozen.
   *
   * @see docs/adr/0017-preview-partial-rendering-frozen-regions.md
   */
  public const string FROZEN_TREE_REGIONS = 'regions';
  public const string FROZEN_TREE_CONTENT = 'content';

  private array $regions;
  private array $regionsClientSideIds;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ClientDataToEntityConverter $converter,
    private readonly ComponentTreeLoader $componentTreeLoader,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LanguageManagerInterface $languageManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ServerTiming $serverTiming,
    private readonly RendererInterface $renderer,
    private readonly AssetResolverInterface $assetResolver,
    private readonly LibraryDependencyResolverInterface $libraryDependencyResolver,
    #[Autowire(service: 'asset.css.collection_renderer')]
    private readonly AssetCollectionRendererInterface $cssCollectionRenderer,
    #[Autowire(service: 'asset.js.collection_renderer')]
    private readonly AssetCollectionRendererInterface $jsCollectionRenderer,
  ) {
    $theme = $this->themeManager->getActiveTheme()->getName();
    $theme_regions = system_region_list($theme);

    // The PageRegion config entities get a corresponding `nodeType: region` in
    // the client-side representation. Their IDs match that of the server-side
    // PageRegion config entities. With the exception of the special-cased
    // `content` region, because that is the only region guaranteed to exist
    // across all themes, and for which no PageRegion config entity is allowed
    // to exist.
    // @see \Drupal\system\Controller\SystemController::themesPage()
    $server_side_ids = \array_map(
      fn (string $region_name): string => $region_name === CanvasPageVariant::MAIN_CONTENT_REGION
        ? CanvasPageVariant::MAIN_CONTENT_REGION
        : "$theme.$region_name",
      \array_keys($theme_regions)
    );
    $this->regionsClientSideIds = array_combine($server_side_ids, \array_keys($theme_regions));
    $this->regions = array_combine($server_side_ids, $theme_regions);
    \assert(\array_key_exists(CanvasPageVariant::MAIN_CONTENT_REGION, $this->regions));
  }

  /**
   * Returns JSON for the entity layout and fields that the user can edit.
   */
  public function get(Request $request, (ContentEntityInterface&EntityPublishedInterface)|ContentTemplate $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
    $this->serverTiming->recordBootstrap((float) $request->server->get('REQUEST_TIME_FLOAT'));
    $regions = self::shouldIncludeGlobalRegions($entity) ? PageRegion::loadForActiveTheme() : [];

    // @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    $conflict_resolution_dev_mode = $this->moduleHandler->moduleExists('canvas_dev_cd');

    // Determine if we are working with auto-save or published version of the
    // entity.
    $auto_saved = !$conflict_resolution_dev_mode || $request->query->getBoolean(self::AUTO_SAVED_QUERY_KEY, default: TRUE);

    // Store the original entity for comparison purposes.
    $original_entity = $entity;
    if ($auto_saved) {
      // For content entities, reconstruct the draft with every pending
      // translation overlaid: previewing one translation reconciles and
      // re-saves all of them (symmetric component-tree columns must stay in
      // sync), so a sibling translation's draft must be present or it would be
      // clobbered.
      $autoSaveData = $this->serverTiming->time('auto-save-read', fn () => $entity instanceof ContentEntityInterface
        ? $this->autoSaveManager->getAutoSaveEntityForPreview($entity)
        : $this->autoSaveManager->getAutoSaveEntity($entity));
      if (!$autoSaveData->isEmpty()) {
        $entity = $autoSaveData->entity;
        \assert($entity instanceof ContentEntityInterface || $entity instanceof ContentTemplate);
      }
    }

    $model = [];
    // Build the content region.
    $this->serverTiming->start('client-model');
    $tree = $this->componentTreeLoader->load($entity);
    $content_layout = $this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $model, $preview_entity);
    $layout = [$content_layout];
    // Determine if entity is a draft based on the original entity,
    // not auto-save, because draft status is an intrinsic property
    // of the stored entity.
    $is_new = AutoSaveManager::entityIsConsideredNew($original_entity);

    if ($regions) {
      \assert($model !== NULL);
      $this->addGlobalRegions($regions, $model, $layout);
      $layout_keyed_by_region = array_combine(\array_map(static fn($region) => $region['id'], $layout), $layout);
      // Reorder the layout to match theme order.
      $layout = array_values(array_replace(
        array_intersect_key(array_flip($this->regionsClientSideIds), $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }
    $this->serverTiming->stop('client-model');

    $data = [
      // Maps to the `tree` property of the Canvas field type.
      // @see \Drupal\canvas\Plugin\DataType\ComponentTreeStructure
      // @todo Settle on final names and get in sync.
      'layout' => $layout,
      // Maps to the `inputs` property of the Canvas field type.
      // @see \Drupal\canvas\Plugin\DataType\ComponentInputs
      // @todo Settle on final names and get in sync.
      // If the model is empty return an empty object to ensure it is encoded as
      // an object and not empty array.
      'model' => empty($model) ? new \stdClass() : $model,
      'isNew' => $is_new,
      'autoSaves' => $this->getAutoSaveHashes(array_merge(
        [$entity],
        self::getEditableRegions($entity),
      )),
    ];
    $available_translations = [];
    $links = [];
    if ($entity instanceof ContentEntityInterface) {
      $available_translations = \array_keys($entity->getTranslationLanguages(FALSE));
      if ($this->moduleHandler->moduleExists('content_translation')) {
        foreach ($available_translations as $langcode) {
          $translation = $entity->getTranslation($langcode);
          // The delete route requires both update access and the
          // 'delete content translations' permission, so emit the link only
          // when the current user satisfies both conditions.
          // @see canvas.api.content.translation.delete in canvas.routing.yml
          if ($translation->access('update') && $this->currentUser->hasPermission('delete content translations')) {
            $links[$langcode] = [
              CanvasUriDefinitions::LINK_REL_DELETE => Url::fromRoute(
                'canvas.api.content.translation.delete',
                ['canvas_page' => $entity->id()],
                ['language' => $translation->language()],
              )->toString(),
            ];
          }
        }
      }
    }
    // Also collect languages that have config language overrides for the
    // ContentTemplate.
    if ($entity instanceof ContentTemplate
      && $this->moduleHandler->moduleExists('config_translation')
      && $this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      $config_name = $entity->getConfigDependencyName();
      foreach ($this->languageManager->getLanguages() as $langcode => $language) {
        if ($language->isDefault() || \in_array($langcode, $available_translations, TRUE)) {
          continue;
        }
        $override = $this->languageManager->getLanguageConfigOverride($langcode, $config_name);
        if (!$override->isNew()) {
          $available_translations[] = $langcode;
          if (!isset($links[$langcode]) && $this->currentUser->hasPermission('translate configuration')) {
            $delete_url = Url::fromRoute(
              'canvas.api.config.translation.delete',
              [
                'canvas_config_entity_type_id' => 'content_template',
                'config_entity' => $entity->id(),
              ],
              ['language' => $language],
            );
            $links[$langcode] = [
              CanvasUriDefinitions::LINK_REL_DELETE => $delete_url->toString(),
            ];
          }
        }
      }
    }
    // The client should also list the default language.
    $default_langcode = $entity instanceof ContentEntityInterface
      ? $entity->getUntranslated()->language()->getId()
      : $entity->language()->getId();
    array_unshift($available_translations, $default_langcode);
    $data['translations'] = [
      'available' => $available_translations,
      'links' => $links,
    ];
    if ($entity instanceof ContentEntityInterface && $entity instanceof EntityPublishedInterface) {
      $data['isPublished'] = $entity->isPublished();
      $data['entity_form_fields'] = $this->getFilteredEntityData($entity);

      // Determine if there's an unsaved status change by comparing the current
      // entity (which may be autosaved) with the original stored entity.
      $data['hasUnsavedStatusChange'] = FALSE;
      if ($original_entity instanceof EntityPublishedInterface
        && $entity !== $original_entity) {
        $data['hasUnsavedStatusChange'] = $entity->isPublished() !== $original_entity->isPublished();
      }
    }

    // Add 'updated' property that provides value for 'Updated' element in the
    // side-by-side comparison UI.
    // @todo Revisit as part of https://www.drupal.org/project/canvas/issues/3591544
    if ($conflict_resolution_dev_mode && $entity instanceof Page) {
      // For published entities use actual entity revision time.
      if (!$auto_saved) {
        $data['updated'] = (int) $entity->getRevisionCreationTime();
      }
      // For auto-save items use 'updated' property of auto-save entry itself.
      elseif (!$autoSaveData->isEmpty()) {
        $data['updated'] = $autoSaveData->updated;
      }
    }
    return new PreviewEnvelope($this->buildPreviewRenderable($entity, $preview_entity), $data);
  }

  private function buildRegion(string $id, ?ComponentTreeItemList $items = NULL, ?array &$model = NULL, ?FieldableEntityInterface $preview_entity = NULL): array {
    if ($items) {
      // Auto-update component instances before serving them, which will make
      // the preview accurate with what the editor would see when editing the
      // component tree.
      $wasModified = $this->componentSourceManager->updateComponentInstances($items);

      // If the tree was modified (e.g., orphaned children removed due to
      // component evolution), create an auto-save so later PATCH requests
      // load the updated tree instead of the published version.
      if ($wasModified) {
        $entity = $items->getParent()?->getValue();
        \assert($entity instanceof ComponentTreeEntityInterface || $entity instanceof FieldableEntityInterface);
        // Capture reconciled staged overrides BEFORE setComponentTree() calls
        // set(), which clears the $stagedOverrides cache. If captured after,
        // getTranslation() would re-create from the live LanguageConfigOverride
        // (still containing removed props), discarding the reconciliation.
        $configTranslationsToSave = $entity instanceof ComponentTreeConfigEntityBase
          ? \array_values(\array_map(
            fn($language) => $entity->getTranslation($language->getId()),
            $entity->getTranslationLanguages(include_default: FALSE),
          ))
          : [];
        if ($entity instanceof ComponentTreeEntityInterface) {
          // @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types (aka FieldableEntityInterface)
          $entity->setComponentTree($items->getValue());
        }
        // This called ::updateComponentInstances(), that means all symmetrical
        // translations (for content or config entities) have had their
        // component instances updated, too. They must remain in sync, so also
        // save the updated translations.
        // @see ADR #13, decision 4: propagation is in-memory only.
        $this->autoSaveManager->saveEntity($entity instanceof ContentEntityInterface
          // For content entities $entity may be a non-default translation.
          ? $entity->getUntranslated()
          // For config entities, $entity is always the default translation.
          : $entity
        );
        if ($entity instanceof ContentEntityInterface) {
          foreach ($entity->getTranslationLanguages(include_default: FALSE) as $language) {
            $this->autoSaveManager->saveEntity($entity->getTranslation($language->getId()));
          }
        }
        foreach ($configTranslationsToSave as $stagedOverride) {
          $this->autoSaveManager->saveEntity($stagedOverride);
        }
      }

      $built = $items->getClientSideRepresentation($preview_entity);
      $model += $built['model'];
      $components = $built['layout'];
    }
    else {
      $components = [];
    }

    return [
      'nodeType' => 'region',
      'id' => $this->regionsClientSideIds[$id],
      'name' => $this->regions[$id],
      'components' => $components,
    ];
  }

  private function getFilteredEntityData(FieldableEntityInterface $entity): array {
    // @todo Try to return this from the form controller instead.
    // @see https://www.drupal.org/project/canvas/issues/3496875
    // This mirrors a lot of the logic of EntityFormController::form. We want
    // the entity data in the same shape as form state for an entity form so
    // that if matches that of the form built by EntityFormController::form.
    // @see \Drupal\canvas\Controller\EntityFormController::form
    $form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $form_state = $this->buildFormState($form_object, $entity, 'default');
    $form = $this->formBuilder->buildForm($form_object, $form_state);
    // Filter out form values that are not accessible to the client.
    $values = self::filterFormValues($form_state->getValues(), $form, $entity);

    // If the user had previously submitted any invalid values, these will be
    // stored in their respective violations in the auto-save manager. We
    // restore invalid values so that if a user is attempting to rectify invalid
    // values the value shown matches what was previously entered.
    $violations = $this->autoSaveManager->getEntityFormViolations($entity);
    foreach ($violations as $violation) {
      $property_path = $violation->getPropertyPath();
      // @see \Drupal\canvas\ClientDataToEntityConverter::setEntityFields
      $parents = \explode('.', $property_path);
      NestedArray::setValue($values, $parents, $violation->getInvalidValue());
    }

    // Collapse form values into the respective element name, e.g.
    // ['title' => ['value' => 'Node title']] becomes
    // ['title[0][value]' => 'Node title'. This keeps the data sent in the same
    // shape as the 'name' attributes on each of the form elements built by the
    // form element and avoids needing to smooth out the idiosyncrasies of each
    // widget's structure.
    // @see \Drupal\canvas\Controller\EntityFormController::form
    return Query::parse(\http_build_query($values));
  }

  private function addGlobalRegions(array $regions, array &$model, array &$layout, bool $includeAllRegions = FALSE): void {
    // Only expose regions marked as editable in the `layout` for the client.
    foreach ($regions as $id => $region) {
      \assert($region instanceof PageRegion);
      \assert($region->status() === TRUE);
      if (!$region->access('edit') && !$includeAllRegions) {
        // If the user doesn't have access to a region, we don't need to include
        // it.
        continue;
      }

      // Use auto-save data for each PageRegion config entity if available.
      if ($draft_region = $this->autoSaveManager->getAutoSaveEntity($region)->entity) {
        \assert($draft_region instanceof PageRegion);
        // @phpstan-ignore-next-line parameterByRef.type
        $layout[] = $this->buildRegion($id, $draft_region->getComponentTree(), $model);
      }
      // Otherwise fall back to the currently live PageRegion config entity.
      // (Note: this automatically ignores auto-saves for PageRegions that were
      // editable at the time, but no longer are.)
      else {
        // @phpstan-ignore-next-line parameterByRef.type
        $layout[] = $this->buildRegion($id, $region->getComponentTree(), $model);
      }
    }
  }

  /**
   * Updates single component instance's auto-save entry and returns a preview.
   */
  public function patch(Request $request, FieldableEntityInterface|ContentTemplate $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
    $this->serverTiming->recordBootstrap((float) $request->server->get('REQUEST_TIME_FLOAT'));
    $body = \json_decode($request->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!\array_key_exists('componentInstanceUuid', $body)) {
      throw new BadRequestHttpException('Missing componentInstanceUuid');
    }
    if (!\array_key_exists('componentType', $body)) {
      throw new BadRequestHttpException('Missing componentType');
    }
    if (!\array_key_exists('model', $body)) {
      throw new BadRequestHttpException('Missing model');
    }
    if (!\array_key_exists('autoSaves', $body)) {
      throw new BadRequestHttpException('Missing autoSaves');
    }
    if (!\array_key_exists('clientInstanceId', $body)) {
      throw new BadRequestHttpException('Missing clientInstanceId');
    }
    [
      'componentInstanceUuid' => $componentInstanceUuid,
      'componentType' => $componentTypeAndVersion,
      'model' => $model,
      'autoSaves' => $autoSaves,
      'clientInstanceId' => $clientInstanceId,
    ] = $body;
    $frozen = self::getFrozenTree($body, $entity);

    if (!str_contains($componentTypeAndVersion, '@')) {
      throw new NotFoundHttpException(\sprintf('Missing version for component %s', $componentTypeAndVersion));
    }

    [$componentType, $version] = \explode('@', $componentTypeAndVersion);
    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($componentType);
    \assert($component instanceof Component || $component === NULL);
    if ($component === NULL) {
      throw new NotFoundHttpException('No such component: ' . $componentType);
    }
    try {
      $component->loadVersion($version);
    }
    catch (\OutOfRangeException) {
      throw new NotFoundHttpException(\sprintf('No such version %s for component %s', $version, $componentType));
    }

    // @todo Currently ::validateAutoSaves() validates all page regions as well
    //   as `$entity` even though below we will only auto-save the entity
    //   containing the component, determine if here we should only validate
    //   that entity in https://drupal.org/i/3532056 or implement concurrent
    //   editing in https://drupal.org/i/3492065.
    // When the client declares a frozen tree, that tree is exempt from
    // validation: only the hot tree(s) are validated.
    $this->serverTiming->time('auto-save-validate', fn () => $this->validateAutoSaves(
      ...self::filterToHotTrees($frozen, $entity, $autoSaves),
      clientId: $clientInstanceId,
    ));

    // Determine which entity to PATCH.
    $this->serverTiming->start('auto-save-read');
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    \assert($entity instanceof FieldableEntityInterface || $entity instanceof ContentTemplate);
    $regions = self::shouldIncludeGlobalRegions($entity)
      ? $this->getAutoSavedVersionIfAvailable(PageRegion::loadForActiveTheme())
      : [];
    $this->serverTiming->stop('auto-save-read');
    $entity_to_patch = $this->getEntityWithComponentInstance([$entity, ...$regions], $componentInstanceUuid);

    // A frozen tree is exempt from validation above, so refuse to modify it:
    // the client's freeze declaration and the targeted component disagree,
    // which means the client must re-request with the correct declaration.
    if (($frozen === self::FROZEN_TREE_REGIONS && $entity_to_patch instanceof PageRegion)
      || ($frozen === self::FROZEN_TREE_CONTENT && !$entity_to_patch instanceof PageRegion)) {
      throw new BadRequestHttpException(\sprintf('Cannot update component instance %s: it is part of the frozen %s tree.', $componentInstanceUuid, $frozen));
    }

    // Route-level access checks already verified `edit` access to $entity. Only
    // perform an additional `edit` access check if $entity_to_patch is not
    // $entity, but a PageRegion entity.
    if ($entity_to_patch instanceof PageRegion && !$entity_to_patch->access('edit')) {
      throw new AccessDeniedHttpException(\sprintf('Access denied for region %s', $entity_to_patch->get('region')));
    }

    // Update the entity & auto-save it. We might be updating a component
    // instance version aside of the model itself.
    $this->serverTiming->time('conversion', fn () => $this->updateComponentInstance($entity_to_patch, $componentInstanceUuid, $version, $model, $preview_entity));
    $this->serverTiming->time('auto-save-write', fn () => $this->autoSaveManager->saveEntity($entity_to_patch, $clientInstanceId));

    // Inform the UI of the updated reality.
    $this->serverTiming->start('client-model');
    $data = $this->buildLayoutAndModel($entity, $regions, preview_entity: $preview_entity);
    \assert(['layout', 'model'] === \array_keys($data));
    if ($entity instanceof FieldableEntityInterface) {
      $data['entity_form_fields'] = $this->getFilteredEntityData($entity);
    }
    $data['autoSaves'] = $this->getAutoSaveHashes(array_merge(
      [$entity],
      self::getEditableRegions($entity),
    ));
    $this->serverTiming->stop('client-model');
    if ($frozen === self::FROZEN_TREE_REGIONS) {
      $request->attributes->set(self::FROZEN_REGIONS_ATTRIBUTE, TRUE);
    }
    return new PreviewEnvelope(
      $frozen === self::FROZEN_TREE_CONTENT
        ? self::buildFrozenContentPlaceholder()
        : $this->buildPreviewRenderable($entity, $preview_entity),
      additionalData: $data
    );
  }

  /**
   * Updates the auto-saved layout, model and entity form fields.
   *
   * Two request body keys narrow the work performed:
   * - `frozen` ("regions"|"content"): the declared tree is not validated,
   *   overlaid, written, or rendered.
   * - `render` (bool, default TRUE): when FALSE, the request only persists
   *   auto-save state and returns the auto-save hashes without rendering any
   *   preview HTML. This is the decoupled auto-save ("persist") mode.
   *
   * @todo Remove this in https://drupal.org/i/3492065
   */
  public function post(Request $request, FieldableEntityInterface|ContentTemplate $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope|JsonResponse {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
    $this->serverTiming->recordBootstrap((float) $request->server->get('REQUEST_TIME_FLOAT'));
    $body = json_decode($request->getContent(), TRUE);
    if (!\array_key_exists('model', $body)) {
      throw new BadRequestHttpException('Missing model');
    }
    if (!\array_key_exists('layout', $body)) {
      throw new BadRequestHttpException('Missing layout');
    }
    if (!\array_key_exists('autoSaves', $body)) {
      throw new BadRequestHttpException('Missing autoSaves');
    }
    if (!\array_key_exists('clientInstanceId', $body)) {
      throw new BadRequestHttpException('Missing clientInstanceId');
    }
    [
      'layout' => $layout,
      'model' => $model,
      'autoSaves' => $autoSaves,
      'clientInstanceId' => $clientInstanceId,
    ] = $body;
    $frozen = self::getFrozenTree($body, $entity);
    $render = $body['render'] ?? TRUE;
    if (!\is_bool($render)) {
      throw new BadRequestHttpException('The `render` key must be a boolean.');
    }

    if ($entity instanceof FieldableEntityInterface) {
      if (!\array_key_exists('entity_form_fields', $body)) {
        throw new BadRequestHttpException('Missing entity_form_fields');
      }
      $entity_form_fields = $body['entity_form_fields'];
    }
    else {
      $entity_form_fields = NULL;
    }

    // When the client declares a frozen tree, that tree is exempt from
    // validation: only the hot tree(s) are validated.
    $this->serverTiming->time('auto-save-validate', fn () => $this->validateAutoSaves(
      ...self::filterToHotTrees($frozen, $entity, $autoSaves),
      clientId: $clientInstanceId,
    ));

    $region_layouts = self::getRegionLayoutNodesKeyedByClientSideId($layout);
    \assert(\array_key_exists(CanvasPageVariant::MAIN_CONTENT_REGION, $region_layouts));
    // The main content region's component tree is for the edited entity.
    $main_content_layout = $region_layouts[CanvasPageVariant::MAIN_CONTENT_REGION];
    unset($region_layouts[CanvasPageVariant::MAIN_CONTENT_REGION]);
    if ($frozen === self::FROZEN_TREE_REGIONS) {
      // Frozen regions: any region trees in the client payload are ignored,
      // so no region is overlaid, access-checked, or written.
      $region_layouts = [];
      $regions = [];
    }
    else {
      // Route-level access checks already verified `edit` access to $entity.
      // But any PageRegion entities present in the layout provided by the
      // client still need their `edit` access checked.
      $regions = PageRegion::loadForActiveThemeByClientSideId();
      $missing_regions = array_diff_key($region_layouts, $regions);
      if ($missing_regions) {
        throw new NotFoundHttpException('Unknown regions: ' . implode(', ', \array_keys($missing_regions)));
      }
      foreach (\array_keys($region_layouts) as $client_side_region_id) {
        // Check access to regions if any component was added or removed.
        if (!$regions[$client_side_region_id]->access('edit')) {
          throw new AccessDeniedHttpException(\sprintf('Access denied for region %s', $client_side_region_id));
        }
      }
    }

    // We want to work with the auto-save entity from this point so that any
    // previously saved values from e.g. another user are respected.
    $this->serverTiming->start('auto-save-read');
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    $regions = $this->getAutoSavedVersionIfAvailable($regions);
    $this->serverTiming->stop('auto-save-read');

    if ($frozen !== self::FROZEN_TREE_CONTENT) {
      // Update the entity & auto-save it. This can update both:
      // - the component tree in the entity (using `layout` and `model`)
      // - the fields in the entity, if any (using `entity_form_fields`)
      $this->serverTiming->time('conversion', fn () => $this->updateEntity($entity, $main_content_layout, $model, $entity_form_fields, $preview_entity));
      $this->serverTiming->time('auto-save-write', fn () => $this->autoSaveManager->saveEntity($entity, $clientInstanceId));
    }

    // Update all PageRegions' component trees.
    $this->serverTiming->start('auto-save-write');
    foreach ($region_layouts as $client_side_region_id => $region_layout) {
      $regions[$client_side_region_id] = $regions[$client_side_region_id]->forAutoSaveData([
        'layout' => $region_layout['components'],
        'model' => self::extractModelForSubtree($region_layout, (array) $model),
      ], validate: FALSE);
      $this->autoSaveManager->saveEntity($regions[$client_side_region_id], $clientInstanceId);
    }
    $this->serverTiming->stop('auto-save-write');

    $data = [
      'autoSaves' => $this->getAutoSaveHashes(array_merge(
        [$entity],
        self::getEditableRegions($entity),
      )),
    ];
    if (!$render) {
      // Persist-only mode: no preview HTML is rendered at all. The client
      // paints through the partial render endpoint and optimistic DOM
      // operations; this response only confirms (or 409-rejects) the write.
      return new JsonResponse($data);
    }
    if ($frozen === self::FROZEN_TREE_REGIONS) {
      $request->attributes->set(self::FROZEN_REGIONS_ATTRIBUTE, TRUE);
    }
    return new PreviewEnvelope(
      $frozen === self::FROZEN_TREE_CONTENT
        ? self::buildFrozenContentPlaceholder()
        : $this->buildPreviewRenderable($entity, $preview_entity),
      additionalData: $data,
    );
  }

  /**
   * Renders a set of component instances as isolated subtrees.
   *
   * This endpoint is a pure function of draft state plus the optional client-
   * supplied model overlay: it never writes auto-save entries, never
   * invalidates cache tags, and never touches global region state. Requests
   * are therefore safe to issue concurrently and to abort.
   *
   * Request body:
   * - `uuids` (string[], required): component instance UUIDs to render. Each
   *   is rendered as a subtree: a slot-bearing component includes its current
   *   children.
   * - `model` (object, optional): client-side model keyed by UUID, overlaid
   *   on the draft state before rendering. Absent means "render the current
   *   draft state" (the contract the realtime-collaboration op flow uses).
   * - `libraries` (string[], optional): asset libraries the preview document
   *   already has; the response contains only assets outside this set
   *   (the ajaxPageState pattern).
   * - `token` (string|int|null, optional): opaque value echoed back
   *   unmodified; the client uses it for latest-wins ordering per component.
   *
   * Response body: `html` (markup keyed by UUID), `model` (evaluated client
   * model for the rendered UUIDs), `assets` (`css`, `js`, `importMap`,
   * `libraries`), and the echoed `token`.
   *
   * @see docs/adr/0017-preview-partial-rendering-frozen-regions.md
   */
  public function render(Request $request, FieldableEntityInterface $entity): JsonResponse {
    $this->serverTiming->recordBootstrap((float) $request->server->get('REQUEST_TIME_FLOAT'));
    $body = \json_decode($request->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!\array_key_exists('uuids', $body) || !\is_array($body['uuids']) || $body['uuids'] === []) {
      throw new BadRequestHttpException('Missing or empty uuids');
    }
    $uuids = $body['uuids'];
    $client_model = $body['model'] ?? [];
    $client_libraries = $body['libraries'] ?? [];
    $token = $body['token'] ?? NULL;
    if (!\is_array($client_model)) {
      throw new BadRequestHttpException('The `model` key must be an object.');
    }
    // The first render request sends the preview document's own (compressed)
    // ajaxPageState value; later requests echo the expanded list from the
    // previous response.
    if (\is_string($client_libraries)) {
      $client_libraries = \array_filter(\explode(',', UrlHelper::uncompressQueryParameter($client_libraries)));
    }
    if (!\is_array($client_libraries)) {
      throw new BadRequestHttpException('The `libraries` key must be an array or a compressed ajaxPageState string.');
    }

    // Read-only overlay of the draft state; nothing below may write to it.
    $this->serverTiming->start('auto-save-read');
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    \assert($entity instanceof FieldableEntityInterface);
    $regions = self::shouldIncludeGlobalRegions($entity)
      ? $this->getAutoSavedVersionIfAvailable(PageRegion::loadForActiveTheme())
      : [];
    $this->serverTiming->stop('auto-save-read');

    $html = [];
    $model = [];
    $attached = [];
    $this->serverTiming->start('render');
    foreach ($uuids as $uuid) {
      if (!\is_string($uuid)) {
        throw new BadRequestHttpException('Each uuid must be a string.');
      }
      $source_entity = $this->getEntityWithComponentInstance([$entity, ...$regions], $uuid);
      $subtree = $this->buildDanglingSubtree($source_entity, $uuid, $client_model);
      $build = $this->renderSubtreeInFiber($subtree, $source_entity, (string) $entity->label());
      $html[$uuid] = (string) $this->renderer->renderInIsolation($build);
      $model += $subtree->getClientSideRepresentation()['model'];
      $attached = NestedArray::mergeDeep($attached, $build['#attached'] ?? []);
    }
    $this->serverTiming->stop('render');

    $this->serverTiming->start('attachments');
    $assets = $this->buildAssetDelta($attached, $client_libraries);
    $this->serverTiming->stop('attachments');

    return new JsonResponse([
      'html' => $html,
      'model' => empty($model) ? new \stdClass() : $model,
      'assets' => $assets,
      'token' => $token,
    ]);
  }

  /**
   * Builds a dangling component tree containing one instance and its children.
   *
   * The dangling copy exists so that the shared (statically cached) auto-saved
   * entity is never mutated: the client model overlay is applied to the copy.
   */
  private function buildDanglingSubtree(ComponentTreeEntityInterface|FieldableEntityInterface $source_entity, string $root_uuid, array $client_model): ComponentTreeItemList {
    $tree = $this->componentTreeLoader->load($source_entity);
    $subtree = $this->createDanglingComponentTreeItemList();

    // Collect the root item plus all its slot descendants, breadth-first.
    $collected = [$root_uuid];
    $queue = [$root_uuid];
    while ($queue) {
      $parent_uuid = \array_shift($queue);
      foreach ($tree->componentTreeItemsIterator(static fn (ComponentTreeItem $item): bool => $item->getParentUuid() === $parent_uuid) as $item) {
        \assert($item instanceof ComponentTreeItem);
        $collected[] = $item->getUuid();
        $queue[] = $item->getUuid();
      }
    }
    foreach ($collected as $uuid) {
      $item = $tree->getComponentTreeItemByUuid($uuid);
      \assert($item instanceof ComponentTreeItem);
      $value = $item->getValue();
      if ($uuid === $root_uuid) {
        // The requested instance renders as the root of its own subtree.
        unset($value['parent_uuid'], $value['slot']);
      }
      $subtree->appendItem($value);
    }

    // Overlay client-supplied inputs on the copy. Only inputs are overlaid:
    // component version changes go through the full PATCH/POST flow.
    foreach (\array_intersect($collected, \array_keys($client_model)) as $uuid) {
      $item = $subtree->getComponentTreeItemByUuid($uuid);
      \assert($item instanceof ComponentTreeItem);
      $component = $item->getComponent()?->loadVersion($item->getComponentVersion());
      \assert($component instanceof Component);
      $item->setInput($component->getComponentSource()->clientModelToInput(
        $uuid,
        $component,
        $client_model[$uuid],
        NULL,
      ));
    }
    return $subtree;
  }

  /**
   * Renders a subtree inside a fiber so global-context blocks keep working.
   *
   * Mirrors the fiber handling in CanvasPageVariant::build(): title blocks
   * receive the entity label (the render endpoint runs no page controller, so
   * no routed page title exists).
   *
   * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
   */
  private function renderSubtreeInFiber(ComponentTreeItemList $subtree, ComponentTreeEntityInterface|FieldableEntityInterface $source_entity, string $title): array {
    $fiber = new \Fiber(static fn (): array => $subtree->toRenderable($source_entity, isPreview: TRUE));
    $suspended = $fiber->start();
    while ($fiber->isSuspended()) {
      $suspended = match (TRUE) {
        $suspended instanceof TitleBlockPluginInterface => (static function () use ($suspended, $fiber, $title) {
          $suspended->setTitle($title);
          return $fiber->resume();
        })(),
        $suspended instanceof MessagesBlockPluginInterface => $fiber->resume(),
        default => $fiber->resume(),
      };
    }
    \assert($fiber->isTerminated());
    $renderable = $fiber->getReturn();
    return $renderable[ComponentTreeItemList::ROOT_UUID] ?? [];
  }

  /**
   * Computes the asset payload not yet present in the preview document.
   *
   * @return array{css: list<string>, js: list<string>, importMap: array, libraries: list<string>}
   *   Rendered CSS/JS tag markup for new assets, the subtree's normalized
   *   import map (the client diffs it against the document's map), and the
   *   cumulative expanded library list for the client to echo on the next
   *   request.
   */
  private function buildAssetDelta(array $attached, array $client_libraries): array {
    $assets = AttachedAssets::createFromRenderArray(['#attached' => $attached]);
    $assets->setAlreadyLoadedLibraries($client_libraries);
    $language = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE);
    // Preview responses are uncached development-time responses: skip
    // aggregation just like the preview document itself does not require it.
    $css = $this->assetResolver->getCssAssets($assets, FALSE, $language);
    [$js_header, $js_footer] = $this->assetResolver->getJsAssets($assets, FALSE, $language);
    // Do not ship drupalSettings updates: the preview document keeps its
    // load-time settings; components needing new settings fall back to a full
    // render client-side.
    unset($js_header['drupalSettings']);

    $render_tags = function (AssetCollectionRendererInterface $collectionRenderer, array $collection): array {
      if ($collection === []) {
        return [];
      }
      $elements = $collectionRenderer->render($collection);
      return \array_values(\array_map(fn (array $element): string => (string) $this->renderer->renderInIsolation($element), $elements));
    };

    $all_libraries = $this->libraryDependencyResolver->getLibrariesWithDependencies($assets->getLibraries());
    $new_libraries = \array_diff($all_libraries, $this->libraryDependencyResolver->getLibrariesWithDependencies($client_libraries));

    return [
      'css' => $render_tags($this->cssCollectionRenderer, $css),
      'js' => \array_merge($render_tags($this->jsCollectionRenderer, $js_header), $render_tags($this->jsCollectionRenderer, $js_footer)),
      'importMap' => ImportMapResponseAttachmentsProcessor::normalizeImportMaps($attached['import_maps'] ?? []),
      'libraries' => \array_values(\array_unique(\array_merge($client_libraries, $new_libraries))),
    ];
  }

  /**
   * Reads and validates the `frozen` key from a preview request body.
   *
   * @return string|null
   *   One of the FROZEN_TREE_* constants, or NULL when nothing is frozen.
   *   Always NULL for entities without global regions (nothing to freeze).
   */
  private static function getFrozenTree(array $body, ContentTemplate|FieldableEntityInterface $entity): ?string {
    $frozen = $body['frozen'] ?? NULL;
    if ($frozen === NULL || !self::shouldIncludeGlobalRegions($entity)) {
      return NULL;
    }
    if (!\in_array($frozen, [self::FROZEN_TREE_REGIONS, self::FROZEN_TREE_CONTENT], TRUE)) {
      throw new BadRequestHttpException('Invalid value for `frozen`: expected "regions" or "content".');
    }
    return $frozen;
  }

  /**
   * Restricts auto-save validation to the trees the client is editing.
   *
   * @return array{0: array<\Drupal\Core\Entity\EntityInterface>, 1: array}
   *   The entities to validate and the client-sent auto-save hashes filtered
   *   to those entities, suitable for spreading into ::validateAutoSaves().
   */
  private static function filterToHotTrees(?string $frozen, ContentTemplate|FieldableEntityInterface $entity, array $autoSaves): array {
    $entitiesToValidate = match ($frozen) {
      self::FROZEN_TREE_REGIONS => [$entity],
      self::FROZEN_TREE_CONTENT => self::getEditableRegions($entity),
      default => array_merge([$entity], self::getEditableRegions($entity)),
    };
    if ($frozen !== NULL) {
      $hotKeys = \array_map(AutoSaveManager::getAutoSaveKey(...), $entitiesToValidate);
      $autoSaves = \array_intersect_key($autoSaves, \array_flip($hotKeys));
    }
    return [$entitiesToValidate, $autoSaves];
  }

  /**
   * A stand-in for the content region when the content tree is frozen.
   *
   * Frozen responses are never applied as a full document by the client (the
   * live preview DOM is the snapshot), so the content region only needs its
   * markers to remain structurally valid.
   */
  private static function buildFrozenContentPlaceholder(): array {
    return [
      '#prefix' => Markup::create('<!-- canvas-region-start-content --><div class="canvas--region-empty-placeholder"></div>'),
      '#suffix' => Markup::create('<!-- canvas-region-end-content -->'),
      '#attached' => ['library' => ['canvas/preview']],
    ];
  }

  private function buildPreviewRenderable(ContentTemplate|FieldableEntityInterface $entity, ?FieldableEntityInterface $preview_entity = NULL): array {
    $renderable = $this->serverTiming->time('hydration', fn (): array => $entity instanceof ContentTemplate
      // @phpstan-ignore-next-line
      ? $entity->build($preview_entity, isPreview: TRUE)
      : $this->componentTreeLoader->load($entity)->toRenderable($entity, isPreview: TRUE));

    $build = [];
    if (isset($renderable[ComponentTreeItemList::ROOT_UUID])) {
      $build = $renderable[ComponentTreeItemList::ROOT_UUID];
    }

    $build['#prefix'] = !empty($build)
      ? Markup::create('<!-- canvas-region-start-content -->')
      : Markup::create('<!-- canvas-region-start-content --><div class="canvas--region-empty-placeholder"></div>');
    $build['#suffix'] = Markup::create('<!-- canvas-region-end-content -->');
    $build['#attached']['library'][] = 'canvas/preview';
    if (!self::shouldIncludeGlobalRegions($entity)) {
      $build['#canvas_hide_global_regions'] = TRUE;
    }
    return $build;
  }

  public function getLabel(Request $request, (ContentEntityInterface&EntityPublishedInterface)|ContentTemplate $entity, ?ContentEntityInterface $preview_entity = NULL): string {
    if ($entity instanceof ContentTemplate) {
      \assert($preview_entity !== NULL);
      return (string) $preview_entity->label();
    }
    // Determine if we are working with auto-save or published version of the
    // entity.
    $auto_saved = !$this->moduleHandler->moduleExists('canvas_dev_cd') || $request->query->getBoolean(self::AUTO_SAVED_QUERY_KEY, default: TRUE);
    if ($auto_saved) {
      // Get title from auto saved data if available.
      $auto_save_data = $this->autoSaveManager->getAutoSaveEntity($entity);
      if (!$auto_save_data->isEmpty()) {
        \assert($auto_save_data->entity instanceof EntityInterface);
        return (string) $auto_save_data->entity->label();
      }
    }

    return (string) $entity->label();
  }

  /**
   * Renders a draft content template against a preview entity.
   */
  public function draftContentTemplate(Request $request, string $entity_type, ContentEntityInterface $preview_entity): JsonResponse {
    $body = \json_decode($request->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
    if (!\is_array($body)) {
      throw new BadRequestHttpException('Request body must be a JSON object.');
    }
    foreach (['bundle', 'viewMode'] as $required) {
      if (!\array_key_exists($required, $body) || !\is_string($body[$required]) || $body[$required] === '') {
        throw new BadRequestHttpException(\sprintf('Missing or invalid "%s" in request body.', $required));
      }
    }
    if ($preview_entity->bundle() !== $body['bundle']) {
      throw new BadRequestHttpException(\sprintf('Preview entity bundle "%s" does not match draft bundle "%s".', $preview_entity->bundle(), $body['bundle']));
    }

    $draft = ContentTemplate::createFromClientSide([
      'entityType' => $entity_type,
      'bundle' => $body['bundle'],
      'viewMode' => $body['viewMode'],
      'component_tree' => $body['component_tree'] ?? [],
      'status' => TRUE,
    ]);

    $tree = $this->componentTreeLoader->load($draft);
    $this->componentSourceManager->updateComponentInstances($tree);
    $built = $tree->getClientSideRepresentation($preview_entity);

    return new JsonResponse([
      'model' => empty($built['model']) ? new \stdClass() : $built['model'],
    ]);
  }

  private static function extractModelForSubtree(array $initial_layout_node, array $full_model): array {
    $node_model = [];
    if ($initial_layout_node['nodeType'] === 'component') {
      foreach ($initial_layout_node['slots'] as $slot) {
        $node_model = \array_merge($node_model, self::extractModelForSubtree($slot, $full_model));
      }
    }
    elseif ($initial_layout_node['nodeType'] === 'region' || $initial_layout_node['nodeType'] === 'slot') {
      foreach ($initial_layout_node['components'] as $component) {
        if (isset($full_model[$component['uuid']])) {
          $node_model[$component['uuid']] = $full_model[$component['uuid']];
        }
        $node_model = \array_merge($node_model, self::extractModelForSubtree($component, $full_model));
      }
    }
    return $node_model;
  }

  private function buildLayoutAndModel(FieldableEntityInterface|ContentTemplate $entity, array $regions, ?FieldableEntityInterface $preview_entity = NULL): array {
    $data = ['layout' => [], 'model' => []];
    // Build the content region.
    $tree = $this->componentTreeLoader->load($entity);
    $data['layout'] = [$this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $data['model'], $preview_entity)];
    \assert(\is_array($data['model']));
    $this->addGlobalRegions($regions, $data['model'], $data['layout'], includeAllRegions: TRUE);
    $layout_keyed_by_region = array_combine(\array_map(static fn($region) => $region['id'], $data['layout']), $data['layout']);
    // Reorder the layout to match theme order.
    $data['layout'] = array_values(array_replace(
      array_intersect_key(array_flip($this->regionsClientSideIds), $layout_keyed_by_region),
      $layout_keyed_by_region
    ));
    return $data;
  }

  /**
   * Whether global regions are included in layout and preview for this entity.
   *
   * For content templates with a view mode other than "full", global regions
   * are not part of the display and are excluded from the editor and preview.
   */
  private static function shouldIncludeGlobalRegions(ContentTemplate|FieldableEntityInterface $entity): bool {
    return !($entity instanceof ContentTemplate && $entity->getMode() !== 'full');
  }

  /**
   * @return \Drupal\canvas\Entity\PageRegion[]
   *   The editable regions for the active theme, or empty if global regions
   *   should not be included for the given entity.
   */
  private static function getEditableRegions(ContentTemplate|FieldableEntityInterface $entity): array {
    if (!self::shouldIncludeGlobalRegions($entity)) {
      return [];
    }
    return array_filter(PageRegion::loadForActiveTheme(), fn(PageRegion $region) => $region->access('update'));
  }

  /**
   * @param LayoutClientStructureArray $page_layout
   *   A complete page layout: for the "main content" region, plus PageRegions,
   *   if enabled.
   *
   * @return array<string, RegionClientStructureArray>
   *   Keys: client-side region IDs, values: the "region" layout node and its
   *   contents.
   */
  private static function getRegionLayoutNodesKeyedByClientSideId(array $page_layout): array {
    $keyed_region_nodes = [];
    foreach ($page_layout as $region_node) {
      \assert($region_node['nodeType'] === 'region');
      $client_side_region_id = $region_node['id'];
      $keyed_region_nodes[$client_side_region_id] = $region_node;
    }
    return $keyed_region_nodes;
  }

  private function getAutoSavedVersionIfAvailable(array $entities): array {
    $result = [];
    foreach ($entities as $key => $stored_entity) {
      $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($stored_entity);
      if (!$autoSaveData->isEmpty()) {
        \assert($autoSaveData->entity instanceof $stored_entity);
        $stored_entity = $autoSaveData->entity;
      }
      // If keys are specified, use those (e.g. client-side IDs), otherwise re-
      // key by entity ID.
      $key = array_is_list($entities) ? $stored_entity->id() : $key;
      $result[$key] = $stored_entity;
    }
    return $result;
  }

  private function getEntityWithComponentInstance(array $entities, string $componentInstanceUuid): ComponentTreeEntityInterface|FieldableEntityInterface {
    foreach ($entities as $entity) {
      $tree = $this->componentTreeLoader->load($entity);
      if ($tree->getComponentTreeItemByUuid($componentInstanceUuid)) {
        return $entity;
      }
    }
    throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
  }

  /**
   * Updates a single component instance in the given entity's component tree.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|FieldableEntityInterface $entity
   * @param string $componentInstanceUuid
   * @param array{source: SingleComponentInputArray, resolved: array<string, mixed>} $client_model
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $host_entity
   *
   * @return void
   */
  private function updateComponentInstance(ComponentTreeEntityInterface|FieldableEntityInterface $entity, string $componentInstanceUuid, string $version, array $client_model, ?FieldableEntityInterface $host_entity): void {
    $tree = $this->componentTreeLoader->load($entity);
    if ($item = $tree->getComponentTreeItemByUuid($componentInstanceUuid)) {
      // We might be not only updating the inputs, but also the component
      // instance version (if automatically updating is feasible).
      // @see \Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface
      // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::getClientSideRepresentation()
      $component = $item->getComponent()?->loadVersion($version);
      \assert($component instanceof Component);
      $item->set('component_version', $version);
      $item->setInput(
        $component->getComponentSource()->clientModelToInput(
          $componentInstanceUuid,
          $component,
          $client_model,
          $host_entity
        )
      );
      if ($entity instanceof ComponentTreeEntityInterface) {
        // This might be dangling item list so we should update explicitly.
        $entity->setComponentTree($tree->getValue());
      }
    }
  }

  /**
   * Updates the entire component tree in the given entity (+ fields if any).
   *
   * @param \Drupal\canvas\Entity\ContentTemplate|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity that is updated by reference: its fields (if any) and its
   *   component tree.
   * @param RegionClientStructureArray $layout
   * @param array<string, array{source: SingleComponentInputArray, resolved: array<string, mixed>}> $model
   * @param ?array $entity_form_fields
   *   Entity form fields. Required only if $entity is fieldable.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $preview_entity
   *   Preview entity. Required only if $entity is a ContentTemplates.
   */
  private function updateEntity(ContentTemplate|FieldableEntityInterface $entity, array $layout, array $model, ?array $entity_form_fields, ?FieldableEntityInterface $preview_entity): void {
    if ($entity instanceof FieldableEntityInterface) {
      \assert(!\is_null($entity_form_fields));
      // If we are not auto-saving there is no reason to convert the
      // 'entity_form_fields'. This can cause access issue for just viewing the
      // preview. This runs the conversion as if the user had no access to edit
      // the entity fields which is all the that is necessary when not
      // auto-saving.
      $this->converter->convert([
        'layout' => $layout,
        'model' => $model,
        'entity_form_fields' => $entity_form_fields,
      ], $entity, validate: FALSE);
    }
    else {
      \assert(\is_null($entity_form_fields));
      \assert(!\is_null($preview_entity));
      // @todo Use \Drupal\canvas\ClientDataToEntityConverter here
      //   as well in https://drupal.org/i/3543197.
      // @todo Remove php-stan-ignore in https://drupal.org/i/3548273.
      // @phpstan-ignore-next-line argument.type
      $entity->setComponentTree(self::convertClientToServer($layout['components'], $model, $preview_entity, FALSE));
    }
  }

}
