<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 * @phpstan-import-type SingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 * @phpstan-type ComponentClientStructureArray array{nodeType: 'component', uuid: string, type: ComponentConfigEntityId, slots: array<int, mixed>, editable?: bool}
 * @phpstan-type RegionClientStructureArray array{nodeType: 'region', id: string, name: string, components: array<int, ComponentClientStructureArray>}
 * @phpstan-type LayoutClientStructureArray array<int, RegionClientStructureArray>
 */
final class ApiLayoutController {

  use AutoSaveValidateTrait;
  use ClientServerConversionTrait;
  use EntityFormTrait;
  public const string AUTO_SAVED_QUERY_KEY = 'autoSaved';
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
      $autoSaveData = $entity instanceof ContentEntityInterface
        ? $this->autoSaveManager->getAutoSaveEntityForPreview($entity)
        : $this->autoSaveManager->getAutoSaveEntity($entity);
      if (!$autoSaveData->isEmpty()) {
        $entity = $autoSaveData->entity;
        \assert($entity instanceof ContentEntityInterface || $entity instanceof ContentTemplate);
      }
    }

    $model = [];
    // Build the content region. In per-content mode (a templated entity whose
    // bundle exposes at least one active slot) this is the MERGED template +
    // entity tree with per-component `editable` annotations; otherwise it is
    // the entity's own component tree.
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $content_layout = $this->buildPerContentContentRegion($entity, $per_content_template, $model);
    }
    else {
      $tree = $this->componentTreeLoader->load($entity);
      $content_layout = $this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $model, $preview_entity);
    }
    $layout = [$content_layout];
    // Determine if entity is a draft based on the original entity,
    // not auto-save, because draft status is an intrinsic property
    // of the stored entity.
    $is_new = AutoSaveManager::entityIsConsideredNew($original_entity);

    if ($regions) {
      \assert($model !== NULL);
      $this->addGlobalRegions($regions, $model, $layout, per_content_mode: $per_content_template !== NULL);
      $layout_keyed_by_region = array_combine(\array_map(static fn($region) => $region['id'], $layout), $layout);
      // Reorder the layout to match theme order.
      $layout = array_values(array_replace(
        array_intersect_key(array_flip($this->regionsClientSideIds), $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }

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

    // In per-content mode, expose the slot definitions and per-slot override
    // state so the editor can distinguish inherited defaults from per-entity
    // overrides (including empty overrides).
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $data['exposedSlots'] = self::normalizeExposedSlotsForClient($per_content_template);
      $data['slotOverrides'] = self::computeSlotOverrides($entity, $per_content_template);
    }
    elseif ($entity instanceof ContentTemplate) {
      // Template editor: surface the template's own exposed slots (including a
      // pending draft's) so the editor's working set survives a reload.
      $data['exposedSlots'] = self::normalizeExposedSlotsForClient($entity);
    }

    return new PreviewEnvelope($this->buildPreviewRenderable($entity, $preview_entity), $data);
  }

  private function buildRegion(string $id, ?ComponentTreeItemList $items = NULL, ?array &$model = NULL, ?FieldableEntityInterface $preview_entity = NULL, ?array $template_owned_uuids = NULL, bool $skip_instance_update = FALSE): array {
    if ($items) {
      // Auto-update component instances before serving them, which will make
      // the preview accurate with what the editor would see when editing the
      // component tree. Skipped for the ephemeral per-content merged tree,
      // which has no owning field and cannot drive translation propagation.
      $wasModified = $skip_instance_update
        ? FALSE
        : $this->componentSourceManager->updateComponentInstances($items);

      // If the tree was modified (e.g., orphaned children removed due to
      // component evolution), create an auto-save so later PATCH requests
      // load the updated tree instead of the published version.
      //
      // Skip this in per-content mode ($template_owned_uuids !== NULL): $items
      // is the MERGED template + entity tree the entity does not own, so
      // auto-saving it would persist template-owned rows into the entity's
      // slot fields (or clobber a region entity from the wrong tree).
      if ($wasModified && $template_owned_uuids === NULL) {
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

      $built = $items->getClientSideRepresentation($preview_entity, $template_owned_uuids);
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

  private function addGlobalRegions(array $regions, array &$model, array &$layout, bool $includeAllRegions = FALSE, bool $per_content_mode = FALSE): void {
    // Only expose regions marked as editable in the `layout` for the client.
    foreach ($regions as $id => $region) {
      \assert($region instanceof PageRegion);
      \assert($region->status() === TRUE);
      if (!$region->access('edit') && !$includeAllRegions) {
        // If the user doesn't have access to a region, we don't need to include
        // it.
        continue;
      }

      // Use auto-save data for each PageRegion config entity if available;
      // otherwise fall back to the currently live PageRegion config entity.
      // (Note: this automatically ignores auto-saves for PageRegions that were
      // editable at the time, but no longer are.)
      $draft_region = $this->autoSaveManager->getAutoSaveEntity($region)->entity;
      $region_tree = $draft_region instanceof PageRegion
        ? $draft_region->getComponentTree()
        : $region->getComponentTree();

      // In per-content mode, non-content regions are template chrome: annotate
      // every component in them as non-editable.
      $template_owned_uuids = $per_content_mode ? self::collectAllUuids($region_tree) : NULL;

      // @phpstan-ignore-next-line parameterByRef.type
      $layout[] = $this->buildRegion($id, $region_tree, $model, template_owned_uuids: $template_owned_uuids);
    }
  }

  /**
   * Updates single component instance's auto-save entry and returns a preview.
   */
  public function patch(Request $request, FieldableEntityInterface|ContentTemplate $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
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
    $this->validateAutoSaves(
      array_merge([$entity], self::getEditableRegions($entity)),
      $autoSaves,
      $clientInstanceId,
    );

    // Determine which entity to PATCH.
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    \assert($entity instanceof FieldableEntityInterface || $entity instanceof ContentTemplate);

    // In per-content mode, a template-owned component is locked chrome: it is
    // never in the entity's Canvas field, so reject it explicitly instead of
    // letting getEntityWithComponentInstance() return an incidental 404.
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL
      && \array_key_exists($componentInstanceUuid, self::collectTemplateOwnedUuids($per_content_template))) {
      throw new AccessDeniedHttpException(\sprintf('The component %s belongs to the template and cannot be edited per-entity.', $componentInstanceUuid));
    }

    $regions = self::shouldIncludeGlobalRegions($entity)
      ? $this->getAutoSavedVersionIfAvailable(PageRegion::loadForActiveTheme())
      : [];
    $entity_to_patch = $this->getEntityWithComponentInstance([$entity, ...$regions], $componentInstanceUuid);

    // Route-level access checks already verified `edit` access to $entity. Only
    // perform an additional `edit` access check if $entity_to_patch is not
    // $entity, but a PageRegion entity.
    if ($entity_to_patch instanceof PageRegion && !$entity_to_patch->access('edit')) {
      throw new AccessDeniedHttpException(\sprintf('Access denied for region %s', $entity_to_patch->get('region')));
    }

    // Update the entity & auto-save it. We might be updating a component
    // instance version aside of the model itself. In per-content mode the host
    // entity used to resolve dynamic props is the edited entity itself.
    $host_entity = ($per_content_template !== NULL && $entity_to_patch instanceof FieldableEntityInterface)
      ? $entity_to_patch
      : $preview_entity;
    $this->updateComponentInstance($entity_to_patch, $componentInstanceUuid, $version, $model, $host_entity);
    $this->autoSaveManager->saveEntity($entity_to_patch, $clientInstanceId);

    // Inform the UI of the updated reality.
    $data = $this->buildLayoutAndModel($entity, $regions, preview_entity: $preview_entity);
    \assert(['layout', 'model'] === \array_keys($data));
    if ($entity instanceof FieldableEntityInterface) {
      $data['entity_form_fields'] = $this->getFilteredEntityData($entity);
    }
    $data['autoSaves'] = $this->getAutoSaveHashes(array_merge(
      [$entity],
      self::getEditableRegions($entity),
    ));
    return new PreviewEnvelope(
      $this->buildPreviewRenderable($entity, $preview_entity),
      additionalData: $data
    );
  }

  /**
   * Updates the auto-saved layout, model and entity form fields.
   *
   * @todo Remove this in https://drupal.org/i/3492065
   */
  public function post(Request $request, FieldableEntityInterface|ContentTemplate $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
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

    if ($entity instanceof FieldableEntityInterface) {
      if (!\array_key_exists('entity_form_fields', $body)) {
        throw new BadRequestHttpException('Missing entity_form_fields');
      }
      $entity_form_fields = $body['entity_form_fields'];
    }
    else {
      $entity_form_fields = NULL;
    }

    $this->validateAutoSaves(
      array_merge([$entity], self::getEditableRegions($entity)),
      $autoSaves,
      $clientInstanceId,
    );

    // Route-level access checks already verified `edit` access to $entity. But
    // any PageRegion entities present in the layout provided by the client
    // still need their `edit` access checked.
    $regions = PageRegion::loadForActiveThemeByClientSideId();
    $region_layouts = self::getRegionLayoutNodesKeyedByClientSideId($layout);
    \assert(\array_key_exists(CanvasPageVariant::MAIN_CONTENT_REGION, $region_layouts));
    // The main content region's component tree is for the edited entity.
    $main_content_layout = $region_layouts[CanvasPageVariant::MAIN_CONTENT_REGION];
    unset($region_layouts[CanvasPageVariant::MAIN_CONTENT_REGION]);
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

    // We want to work with the auto-save entity from this point so that any
    // previously saved values from e.g. another user are respected.
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    $regions = $this->getAutoSavedVersionIfAvailable($regions);

    // Update the entity & auto-save it. This can update both:
    // - the component tree in the entity (using `layout` and `model`)
    // - the fields in the entity, if any (using `entity_form_fields`)
    // In per-content mode the submitted merged tree is guarded (template chrome
    // is immutable) and partitioned into per-slot fields before writing.
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      \assert(\is_array($entity_form_fields));
      $this->writePartitionedEntityContent($entity, $main_content_layout, (array) $model, $entity_form_fields, $per_content_template);
    }
    else {
      $this->updateEntity($entity, $main_content_layout, $model, $entity_form_fields, $preview_entity);
      // The template editor carries its working set of exposed slots with the
      // layout; persist them onto the (auto-saved) template alongside the tree.
      if ($entity instanceof ContentTemplate && \array_key_exists('exposed_slots', $body)) {
        $entity->set('exposed_slots', $body['exposed_slots']);
      }
    }
    $this->autoSaveManager->saveEntity($entity, $clientInstanceId);

    // Update all PageRegions' component trees.
    foreach ($region_layouts as $client_side_region_id => $region_layout) {
      $regions[$client_side_region_id] = $regions[$client_side_region_id]->forAutoSaveData([
        'layout' => $region_layout['components'],
        'model' => self::extractModelForSubtree($region_layout, (array) $model),
      ], validate: FALSE);
      $this->autoSaveManager->saveEntity($regions[$client_side_region_id], $clientInstanceId);
    }

    return new PreviewEnvelope(
      $this->buildPreviewRenderable($entity, $preview_entity),
      additionalData: [
        'autoSaves' => $this->getAutoSaveHashes(array_merge(
          [$entity],
          self::getEditableRegions($entity),
        )),
      ],
    );
  }

  private function buildPreviewRenderable(ContentTemplate|FieldableEntityInterface $entity, ?FieldableEntityInterface $preview_entity = NULL): array {
    // In per-content mode compose the preview from the (possibly draft)
    // template merged with the (possibly draft) entity slot content, consistent
    // with the rendered output and honoring the editing lifecycle.
    $per_content_template = $entity instanceof FieldableEntityInterface
      ? $this->getPerContentTemplate($entity)
      : NULL;
    $renderable = match (TRUE) {
      // @phpstan-ignore-next-line
      $entity instanceof ContentTemplate => $entity->build($preview_entity, isPreview: TRUE),
      $per_content_template instanceof ContentTemplate => $per_content_template->build($entity, isPreview: TRUE),
      default => $this->componentTreeLoader->load($entity)->toRenderable($entity, isPreview: TRUE),
    };

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
      // The template editor carries its working set of exposed slots with the
      // layout so the draft reflects (and can persist) them.
      'exposed_slots' => $body['exposed_slots'] ?? [],
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
    // Build the content region, merged with per-component `editable`
    // annotations when in per-content mode (mirroring get()).
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $data['layout'] = [$this->buildPerContentContentRegion($entity, $per_content_template, $data['model'])];
    }
    else {
      $tree = $this->componentTreeLoader->load($entity);
      $data['layout'] = [$this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $data['model'], $preview_entity)];
    }
    \assert(\is_array($data['model']));
    $this->addGlobalRegions($regions, $data['model'], $data['layout'], includeAllRegions: TRUE, per_content_mode: $per_content_template !== NULL);
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
      if ($this->findComponentTreeItemListContaining($entity, $componentInstanceUuid) !== NULL) {
        return $entity;
      }
    }
    throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
  }

  /**
   * Returns the component tree item list(s) holding an entity's editable rows.
   *
   * For a single-field entity (canvas_page) this is the one Canvas field. For a
   * per-content templated entity, editable rows live in the per-slot backing
   * fields, so all of them are returned.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList[]
   *   The item lists to search or mutate.
   */
  private function getEditableComponentTreeItemLists(ComponentTreeEntityInterface|FieldableEntityInterface $entity): array {
    if ($entity instanceof FieldableEntityInterface && !$entity instanceof ComponentTreeEntityInterface) {
      $template = $this->getPerContentTemplate($entity);
      if ($template !== NULL) {
        $lists = [];
        foreach (\array_keys($template->getExposedSlots()) as $field_name) {
          if ($entity->hasField($field_name)) {
            $list = $entity->get($field_name);
            \assert($list instanceof ComponentTreeItemList);
            $lists[] = $list;
          }
        }
        return $lists;
      }
    }
    return [$this->componentTreeLoader->load($entity)];
  }

  /**
   * Finds the item list holding a given component instance, or NULL.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $componentInstanceUuid
   *   The component instance UUID.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList|null
   *   The item list containing the instance, or NULL if none does.
   */
  private function findComponentTreeItemListContaining(ComponentTreeEntityInterface|FieldableEntityInterface $entity, string $componentInstanceUuid): ?ComponentTreeItemList {
    foreach ($this->getEditableComponentTreeItemLists($entity) as $list) {
      if ($list->getComponentTreeItemByUuid($componentInstanceUuid) !== NULL) {
        return $list;
      }
    }
    return NULL;
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
    $tree = $this->findComponentTreeItemListContaining($entity, $componentInstanceUuid);
    if ($tree !== NULL && $item = $tree->getComponentTreeItemByUuid($componentInstanceUuid)) {
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

  /**
   * Detects "per-content" mode and returns the applicable content template.
   *
   * Per-content mode is when the opened entity is a fieldable content entity
   * (not a config entity with its own component tree) whose bundle has an
   * enabled full-view-mode template exposing at least one active slot. In that
   * mode the Layout API serves the merged template + entity tree, annotates
   * editability, and partitions writes into the entity's Canvas field.
   *
   * A pending template draft (if any) is preferred, so editability annotation,
   * the merged tree, write partitioning and preview all use the same template
   * state.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity opened in the Layout API.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The applicable content template, or NULL if not in per-content mode.
   */
  private function getPerContentTemplate(ContentTemplate|FieldableEntityInterface $entity): ?ContentTemplate {
    if (!$entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeEntityInterface) {
      return NULL;
    }
    $template = ContentTemplate::loadForEntity($entity, 'full');
    if (!$template instanceof ContentTemplate || !$template->status() || empty($template->getExposedSlots())) {
      return NULL;
    }
    $draft = $this->autoSaveManager->getAutoSaveEntity($template)->entity;
    if ($draft instanceof ContentTemplate && !empty($draft->getExposedSlots())) {
      return $draft;
    }
    return $template;
  }

  /**
   * Builds the merged "content" region for per-content mode.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   * @param array|null $model
   *   The client-side model, updated by reference.
   *
   * @return array
   *   The "content" region node with per-component `editable` annotations.
   */
  private function buildPerContentContentRegion(FieldableEntityInterface $entity, ContentTemplate $template, ?array &$model = NULL): array {
    $template_owned_uuids = self::collectTemplateOwnedUuids($template);
    // getComponentTree() returns a fresh dangling copy, so merging into it does
    // not mutate the stored template. Each exposed slot is backed by its own
    // field on the entity; merge each into the target slot.
    $merged_tree = $template->getComponentTree($entity);
    foreach ($template->getExposedSlots() as $field_name => $definition) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $slot_field = $entity->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      $merged_tree->injectSlotContent($definition['component_uuid'], $definition['slot_name'], $slot_field);
    }
    return $this->buildRegion(
      CanvasPageVariant::MAIN_CONTENT_REGION,
      $merged_tree,
      $model,
      preview_entity: $entity,
      template_owned_uuids: $template_owned_uuids,
      skip_instance_update: TRUE,
    );
  }

  /**
   * Writes the submitted per-content layout into the entity's slot fields.
   *
   * The submitted layout is the merged template + entity tree. Template chrome
   * is immutable (moves are rejected) and only the entity-owned override
   * content is partitioned into per-slot fields, one `component_tree` field per
   * exposed slot. Every exposed slot's field is written (empty when not
   * overridden), so reverting an override clears its field. This bypasses the
   * whole-tree converter, which would otherwise clobber a field with
   * template-owned rows.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity, updated by reference.
   * @param RegionClientStructureArray $layout
   *   The submitted "content" region node.
   * @param array<string, mixed> $model
   *   The submitted client-side model.
   * @param array<string, mixed> $entity_form_fields
   *   The submitted entity form fields.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   */
  private function writePartitionedEntityContent(FieldableEntityInterface $entity, array $layout, array $model, array $entity_form_fields, ContentTemplate $template): void {
    $template_owned_uuids = self::collectTemplateOwnedUuids($template);

    // Denormalize the submitted merged content region into server-side items,
    // then load them into a dangling tree so they can be partitioned.
    $submitted = $this->createDanglingComponentTreeItemList($entity);
    // @phpstan-ignore-next-line argument.type
    $submitted->setValue(self::convertClientToServer($layout['components'], $model, $entity, validate: FALSE));

    // Reject any structural mutation of the locked template chrome.
    self::assertTemplateOwnedComponentsUnmoved($submitted, $template, $template_owned_uuids);

    [
      'fields' => $slot_fields,
      'kept' => $kept,
    ] = $submitted->partitionSlotFields($template->getExposedSlots(), $template_owned_uuids);

    // Reject content placed outside an exposed slot: any submitted component
    // that is neither template chrome nor part of an exposed-slot override.
    foreach ($submitted->componentTreeItemsIterator() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      if (!\array_key_exists($uuid, $template_owned_uuids) && !\array_key_exists($uuid, $kept)) {
        throw new BadRequestHttpException(\sprintf('Component %s cannot be placed outside an exposed slot.', $uuid));
      }
    }

    // Process the entity's own page-data fields (title, etc.). This must NOT go
    // through the whole ::convert() (which loads a single Canvas field the
    // templated entity does not have); the component tree is written to the
    // per-slot fields below instead.
    if (\count($entity_form_fields) > 0) {
      $this->converter->applyEntityFormFields($entity, $entity_form_fields, validate: FALSE);
    }
    foreach ($slot_fields as $field_name => $rows) {
      if ($entity->hasField($field_name)) {
        $entity->set($field_name, \array_values($rows));
      }
    }
  }

  /**
   * Computes the client-facing exposed slot metadata for per-content mode.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   *
   * @return array<string, array{label: string, slotName: string, componentUuid: string}>
   *   Exposed slot metadata keyed by the backing field machine name.
   */
  private static function normalizeExposedSlotsForClient(ContentTemplate $template): array {
    $slots = [];
    foreach ($template->getExposedSlots() as $slot_key => $definition) {
      $slots[$slot_key] = [
        'label' => (string) ($definition['label'] ?? ''),
        'slotName' => (string) ($definition['slot_name'] ?? ''),
        'componentUuid' => (string) ($definition['component_uuid'] ?? ''),
      ];
    }
    return $slots;
  }

  /**
   * Computes per-slot override state from the entity's Canvas field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   *
   * @return array<string, array{overridden: bool, empty: bool}>
   *   For each exposed slot (keyed by backing field machine name): whether the
   *   entity overrides it, and whether that override is empty (its sole root is
   *   the empty-slot marker).
   */
  private static function computeSlotOverrides(FieldableEntityInterface $entity, ContentTemplate $template): array {
    $overrides = [];
    foreach (\array_keys($template->getExposedSlots()) as $field_name) {
      if (!$entity->hasField($field_name)) {
        $overrides[$field_name] = ['overridden' => FALSE, 'empty' => FALSE];
        continue;
      }
      $slot_field = $entity->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      $roots = [];
      foreach ($slot_field->componentTreeItemsIterator(ComponentTreeItemList::inRootLevel()) as $item) {
        \assert($item instanceof ComponentTreeItem);
        $roots[] = $item;
      }
      $overrides[$field_name] = [
        'overridden' => \count($roots) > 0,
        'empty' => \count($roots) === 1 && $roots[0]->getComponentId() === ComponentInterface::EMPTY_SLOT_MARKER_ID,
      ];
    }
    return $overrides;
  }

  /**
   * Collects the set of component UUIDs owned by a template's own tree.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The content template.
   *
   * @return array<string, true>
   *   The template-owned component UUIDs, keyed by UUID.
   */
  private static function collectTemplateOwnedUuids(ContentTemplate $template): array {
    $uuids = [];
    foreach ($template->getComponentTree() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuids[$item->getUuid()] = TRUE;
    }
    return $uuids;
  }

  /**
   * Collects every component UUID present in a component tree.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   The component tree.
   *
   * @return array<string, true>
   *   All component UUIDs, keyed by UUID.
   */
  private static function collectAllUuids(ComponentTreeItemList $tree): array {
    $uuids = [];
    foreach ($tree->componentTreeItemsIterator() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuids[$item->getUuid()] = TRUE;
    }
    return $uuids;
  }

  /**
   * Rejects any structural mutation of the template's locked chrome.
   *
   * Each template-owned component that is present in the submission must keep
   * the same parent and slot as in the template. Template default content
   * nested inside an exposed slot is legitimately absent when the slot is
   * overridden, so absence is not treated as a mutation.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $submitted
   *   The submitted merged tree.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   * @param array<string, true> $template_owned_uuids
   *   The set of template-owned component UUIDs, keyed by UUID.
   */
  private static function assertTemplateOwnedComponentsUnmoved(ComponentTreeItemList $submitted, ContentTemplate $template, array $template_owned_uuids): void {
    $template_structure = [];
    foreach ($template->getComponentTree() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $template_structure[$item->getUuid()] = [$item->getParentUuid(), $item->getSlot()];
    }
    foreach ($submitted->componentTreeItemsIterator() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      if (!\array_key_exists($uuid, $template_owned_uuids)) {
        continue;
      }
      [$parent_uuid, $slot] = $template_structure[$uuid];
      if ($item->getParentUuid() !== $parent_uuid || $item->getSlot() !== $slot) {
        throw new AccessDeniedHttpException(\sprintf('The component %s belongs to the template and cannot be moved.', $uuid));
      }
    }
  }

}
