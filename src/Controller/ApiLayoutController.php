<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Component\Utility\NestedArray;
use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

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
    private readonly PageVariantResolver $pageVariantResolver,
    // The synchronizer belongs to content_translation, an optional dependency,
    // so it is NULL when that module is not installed.
    // @see \Drupal\canvas\CanvasServiceProvider
    private readonly ?FieldTranslationSynchronizerInterface $translationSynchronizer = NULL,
  ) {
    $theme = $this->themeManager->getActiveTheme()->getName();
    $theme_regions = system_region_list($theme);

    // Component trees are wrapped in `nodeType: region` nodes in the
    // client-side representation. Build their IDs and labels from the active
    // theme. This controller emits only the required `content` region because
    // page variants are edited separately.
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
  public function get(Request $request, ContentEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));

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
        \assert($entity instanceof ContentEntityInterface || $entity instanceof ComponentTreeConfigEntityBase);
      }
    }

    $model = [];
    // In per-content mode the layout has one node per exposed slot; otherwise
    // it is the single content region.
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $layout = $this->buildPerContentSlotRegions($entity, $per_content_template, $model);
    }
    else {
      $tree = $this->componentTreeLoader->load($entity);
      $layout = [$this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $model, self::resolveHostEntity($entity, $preview_entity))];
    }
    // Determine if entity is a draft based on the original entity,
    // not auto-save, because draft status is an intrinsic property
    // of the stored entity.
    $is_new = AutoSaveManager::entityIsConsideredNew($original_entity);

    // Page variants render the chrome around the content, so the layout serves
    // only the single content region; the surrounding variant is edited
    // separately.
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
      'autoSaves' => $this->getAutoSaveHashes(self::getAutoSaveScope($entity, $this->loadSavedTemplate($per_content_template))),
    ];
    $available_translations = [];
    $links = [];
    if ($entity instanceof ContentEntityInterface) {
      $available_translations = \array_keys($entity->getTranslationLanguages(FALSE));
      // The translation delete route exists only for Canvas pages.
      // @todo Offer translation deletion for other content entity types.
      if ($entity instanceof Page && $this->moduleHandler->moduleExists('content_translation')) {
        foreach ($available_translations as $langcode) {
          $translation = $entity->getTranslation($langcode);
          // The delete route gates on update access, so emit the link to the
          // same users to avoid offering a link that would return 403.
          // @see canvas.api.content.translation.delete in canvas.routing.yml
          if ($translation->access('update')) {
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
      // Which page variant renders this entity, so the editor can offer to
      // jump to editing it. NULL when core block layout renders the page.
      $data['resolvedPageVariant'] = $this->pageVariantResolver->resolve($entity)?->id();

      // Determine if there's an unsaved status change by comparing the current
      // entity (which may be autosaved) with the original stored entity.
      $data['hasUnsavedStatusChange'] = FALSE;
      if ($original_entity instanceof EntityPublishedInterface
        && $entity !== $original_entity) {
        $data['hasUnsavedStatusChange'] = $entity->isPublished() !== $original_entity->isPublished();
      }
    }
    elseif ($entity instanceof PageVariant) {
      // The client shows the same published/changed status badge for page
      // variants as for content entities.
      $data['isPublished'] = $entity->status();
      // Config entities have no entity form; keep the response shape uniform.
      $data['entity_form_fields'] = new \stdClass();
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

    // Per-content mode: slot definitions, override state and defaults, so the
    // editor can tell inherited defaults from overrides.
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $data['exposedSlots'] = self::mapForClient(self::normalizeExposedSlotsForClient($per_content_template));
      $data['slotOverrides'] = self::mapForClient(self::computeSlotOverrides($entity, $per_content_template));
      $data['slotDefaults'] = self::mapForClient($this->computeSlotDefaults($per_content_template, $entity));
      // The applicable template's identity, so the client can link to the
      // template editor.
      $data['contentTemplate'] = [
        'entityType' => $per_content_template->getTargetEntityTypeId(),
        'bundle' => $per_content_template->getTargetBundle(),
        'viewMode' => $per_content_template->getMode(),
      ];
      // Drupal's own edit form for the entity's content fields, linked from the
      // editor's Content tab. Per-content editing access implies update access.
      $data['editFormUrl'] = $entity->hasLinkTemplate('edit-form')
        ? $entity->toUrl('edit-form')->toString()
        : NULL;
      // The component type (`<id>@<active version>`) for an empty override.
      // The marker is not in the component library, so the client cannot look
      // up its version there.
      // A config import can delete the marker. It is recreated from the
      // module's install config.
      // @see canvas_post_update_0032_install_empty_slot_marker()
      $empty_slot_marker = Marker::ensureComponent(Marker::EMPTY_SLOT_LOCAL_ID);
      $data['emptySlotMarkerType'] = $empty_slot_marker->id() . '@' . $empty_slot_marker->getActiveVersion();
    }
    elseif ($entity instanceof ContentTemplate) {
      // Template editor: surface the template's own exposed slots (including a
      // pending draft's) so the editor's working set survives a reload.
      $data['exposedSlots'] = self::mapForClient(self::normalizeExposedSlotsForClient($entity));
      // The preview item's own content replaces the template default in each
      // slot it overrides, so the editor can tell the preview from the
      // template's tree.
      \assert($preview_entity instanceof FieldableEntityInterface);
      $data['previewSlotOverrides'] = self::mapForClient(self::computeSlotOverrides($preview_entity, $entity, require_edit_access: FALSE));
    }

    return new PreviewEnvelope($this->buildPreviewRenderable($entity, $per_content_template, $preview_entity), $data);
  }

  private function buildRegion(string $id, ?ComponentTreeItemList $items = NULL, ?array &$model = NULL, ?FieldableEntityInterface $preview_entity = NULL, ?string $client_id = NULL, ?string $name = NULL): array {
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
      'id' => $client_id ?? $this->regionsClientSideIds[$id],
      'name' => $name ?? $this->regions[$id],
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

  /**
   * Updates single component instance's auto-save entry and returns a preview.
   */
  public function patch(Request $request, FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
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

    // In per-content mode the editable layout contains only entity-owned
    // components living in exposed-slot fields, so a template-owned UUID is
    // never found. Resolved once: it depends only on the bundle.
    $per_content_template = $this->getPerContentTemplate($entity);
    $saved_template = $this->loadSavedTemplate($per_content_template);

    $this->validateAutoSaves(
      self::getAutoSaveScope($entity, $saved_template),
      $autoSaves,
      $clientInstanceId,
    );

    // Determine which entity to PATCH. Page variants edit only the content
    // entity's own tree, so the patched component instance belongs to it.
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    \assert($entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeConfigEntityBase);

    $entity_to_patch = $this->getEntityWithComponentInstance([$entity], $componentInstanceUuid);

    // In per-content mode only exposed-slot fields are editable: a component
    // living in a detached or unexposed component-tree field is part of the
    // entity but not of the editable payload, so it is not addressable.
    if ($per_content_template !== NULL) {
      $containing_list = $this->componentTreeLoader->findItemListContaining($entity_to_patch, $componentInstanceUuid);
      if ($containing_list === NULL || !\array_key_exists((string) $containing_list->getName(), $per_content_template->getExposedSlots())) {
        throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
      }
      // A slot field the user may not edit is never served, so addressing its
      // content can only be a crafted request.
      if (!self::mayEditSlotField($containing_list)) {
        throw new AccessDeniedHttpException(\sprintf('Access denied for the %s field.', $containing_list->getName()));
      }
    }

    // Update the entity & auto-save it. We might be updating a component
    // instance version aside of the model itself. In per-content mode the host
    // entity used to resolve dynamic props is the edited entity itself.
    $host_entity = ($per_content_template !== NULL && $entity_to_patch instanceof FieldableEntityInterface)
      ? $entity_to_patch
      : self::resolveHostEntity($entity, $preview_entity);
    $this->updateComponentInstance($entity_to_patch, $componentInstanceUuid, $version, $model, $host_entity);
    $this->autoSaveManager->saveEntity($entity_to_patch, $clientInstanceId);

    // Inform the UI of the updated reality.
    $data = $this->buildLayoutAndModel($entity, $per_content_template, preview_entity: $host_entity);
    \assert(['layout', 'model'] === \array_keys($data));
    if ($entity instanceof FieldableEntityInterface) {
      $data['entity_form_fields'] = $this->getFilteredEntityData($entity);
    }
    elseif ($entity instanceof ComponentTreeConfigEntityBase) {
      // Config entities have no entity form; keep the response shape uniform.
      $data['entity_form_fields'] = new \stdClass();
    }
    $data['autoSaves'] = $this->getAutoSaveHashes(self::getAutoSaveScope($entity, $saved_template));
    return new PreviewEnvelope(
      $this->buildPreviewRenderable($entity, $per_content_template, $preview_entity),
      additionalData: $data
    );
  }

  /**
   * Updates the auto-saved layout, model and entity form fields.
   *
   * @todo Remove this in https://drupal.org/i/3492065
   */
  public function post(Request $request, FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
    $body = json_decode($request->getContent(), TRUE);
    if (!\is_array($body)) {
      throw new BadRequestHttpException('Request body must be a JSON object.');
    }
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
    if (!\is_array($layout) || !\is_array($model)) {
      throw new BadRequestHttpException('"layout" and "model" must be JSON arrays or objects.');
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

    // Per-content mode: the layout may only hold exposed-slot nodes.
    $per_content_template = $this->getPerContentTemplate($entity);
    $saved_template = $this->loadSavedTemplate($per_content_template);

    $this->validateAutoSaves(
      self::getAutoSaveScope($entity, $saved_template),
      $autoSaves,
      $clientInstanceId,
    );

    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      if (!\is_array($entity_form_fields)) {
        throw new BadRequestHttpException('"entity_form_fields" must be an object.');
      }
      $slot_layouts = self::getRegionLayoutNodesKeyedByClientSideId($layout);
      $unknown = \array_diff_key($slot_layouts, $per_content_template->getExposedSlots());
      if ($unknown !== []) {
        // A stale tab whose slot was detached does not get here: any change to
        // the saved template or its draft changes its `autoSaves` entry, so
        // ::validateAutoSaves() responds with a 409 first.
        throw new BadRequestHttpException('Only exposed slots can be edited per-entity. Unknown nodes: ' . \implode(', ', \array_keys($unknown)));
      }
      $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
      \assert($entity instanceof FieldableEntityInterface);
      $this->writeSlotRegions($entity, $slot_layouts, $model, $entity_form_fields, $per_content_template, $saved_template);
      $this->autoSaveManager->saveEntity($entity, $clientInstanceId);
      return new PreviewEnvelope(
        $this->buildPreviewRenderable($entity, $per_content_template, $preview_entity),
        additionalData: [
          'autoSaves' => $this->getAutoSaveHashes(self::getAutoSaveScope($entity, $saved_template)),
        ],
      );
    }

    // The layout serves only the single content region; its tree belongs to the
    // edited entity. (Page variants are edited separately from page content.)
    $region_layouts = self::getRegionLayoutNodesKeyedByClientSideId($layout);
    \assert(\array_key_exists(CanvasPageVariant::MAIN_CONTENT_REGION, $region_layouts));
    $main_content_layout = $region_layouts[CanvasPageVariant::MAIN_CONTENT_REGION];
    // Since the migration to page variants, no editable global regions remain,
    // so a valid save carries only the single content region. A layout with any
    // other region node comes from a client built against the old contract
    // (e.g. a stale editor tab open from before the deploy). Reject it loudly
    // rather than silently dropping those regions' edits.
    $unexpected_regions = \array_diff_key($region_layouts, [CanvasPageVariant::MAIN_CONTENT_REGION => TRUE]);
    if ($unexpected_regions) {
      throw new ConflictHttpException('The submitted layout contains regions that are no longer editable; please refresh your browser.');
    }

    // We want to work with the auto-save entity from this point so that any
    // previously saved values from e.g. another user are respected.
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];

    // A page variant whole-tree save must modify only the routed variant. The
    // editor's model store is shared across entities and this save targets the
    // routed variant, so a save carrying a *different* variant's tree (e.g. a
    // stale model left over from navigating between variants while the new one
    // loaded) would otherwise overwrite the routed variant with that other
    // variant's content. Every valid variant tree carries exactly one intrinsic
    // "Page content" marker whose instance UUID is its stable identity, so a
    // submitted tree whose marker does not match this variant's is a mis-routed
    // save and is rejected before anything is written. This mirrors the
    // exposed-slots isolation, which likewise makes editing one surface unable
    // to mutate a shared one.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker
    // @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraint
    if ($entity instanceof PageVariant) {
      $expected_marker = self::pageContentMarkerUuid($entity->getComponentTree());
      if ($expected_marker !== NULL && self::submittedPageContentMarkerUuids($main_content_layout) !== [$expected_marker]) {
        throw new ConflictHttpException('The submitted layout does not belong to this page variant; please refresh your browser.');
      }
    }

    // Update the entity & auto-save it. This can update both:
    // - the component tree in the entity (using `layout` and `model`)
    // - the fields in the entity, if any (using `entity_form_fields`)
    $this->updateEntity($entity, $main_content_layout, $model, $entity_form_fields, $preview_entity);
    // The template editor carries its working set of exposed slots with the
    // layout; persist them onto the (auto-saved) template alongside the tree.
    if ($entity instanceof ContentTemplate && \array_key_exists('exposed_slots', $body)) {
      $entity->set('exposed_slots', self::validateExposedSlotsPayload($body['exposed_slots']));
      self::validateExposedSlots($entity);
    }
    $this->autoSaveManager->saveEntity($entity, $clientInstanceId);

    $data = [
      'autoSaves' => $this->getAutoSaveHashes(self::getAutoSaveScope($entity, $saved_template)),
    ];
    // Mirror get(): exposing or detaching a slot changes which of the preview
    // entity's slot overrides the preview shows.
    if ($entity instanceof ContentTemplate) {
      \assert($preview_entity instanceof FieldableEntityInterface);
      $data['previewSlotOverrides'] = self::mapForClient(self::computeSlotOverrides($preview_entity, $entity, require_edit_access: FALSE));
    }
    return new PreviewEnvelope(
      $this->buildPreviewRenderable($entity, $per_content_template, $preview_entity),
      additionalData: $data,
    );
  }

  private function buildPreviewRenderable(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentTemplate $per_content_template, ?FieldableEntityInterface $preview_entity = NULL): array {
    $varies_by_language = FALSE;
    if ($entity instanceof ContentTemplate) {
      // @phpstan-ignore-next-line
      $renderable = $entity->build($preview_entity, isPreview: TRUE);
    }
    elseif ($per_content_template instanceof ContentTemplate) {
      // Per-content mode: render the (possibly draft) template with the
      // entity's slot content. The template's own components get no editor
      // annotations, so the editor cannot select them. Slot annotations stay,
      // so the editor can find the exposed slots.
      \assert($entity instanceof FieldableEntityInterface);
      $renderable = $per_content_template->build($entity, isPreview: TRUE, suppressAnnotationsFor: self::collectTemplateOwnedUuids($per_content_template));
    }
    else {
      $tree = $this->componentTreeLoader->load($entity);
      // Render the preview in the negotiated language: when this request is
      // for a non-default language that has a translation override, preview
      // the merged (base + override) tree, exactly what the front end will
      // serve for that language. The entity reaching this controller carries
      // the untranslated base: it was upcast during routing, and both the
      // client model and PATCHes must keep operating on base config anyway --
      // only the preview should follow the language.
      // @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::getTranslatedComponentTree()
      if ($entity instanceof ComponentTreeConfigEntityBase) {
        // ::getTranslationLanguages() already excludes the site default
        // language and any language without a stored override, so membership
        // alone decides whether an override applies.
        $preview_langcode = $this->languageManager->getCurrentLanguage()->getId();
        if (\array_key_exists($preview_langcode, $entity->getTranslationLanguages(include_default: FALSE))) {
          $tree = $entity->getTranslatedComponentTree($preview_langcode);
          // The preview now varies by interface language. The override needs
          // no cache tag of its own: it shares the base config object's tag,
          // which the rendered tree already carries.
          // @see \Drupal\language\Config\LanguageConfigOverride::save()
          $varies_by_language = TRUE;
        }
      }
      $renderable = $tree->toRenderable($entity, isPreview: TRUE);
    }

    $build = [];
    if (isset($renderable[ComponentTreeItemList::ROOT_UUID])) {
      $build = $renderable[ComponentTreeItemList::ROOT_UUID];
    }

    $build['#prefix'] = !empty($build)
      ? Markup::create('<!-- canvas-region-start-content -->')
      : Markup::create('<!-- canvas-region-start-content --><div class="canvas--region-empty-placeholder"></div>');
    $build['#suffix'] = Markup::create('<!-- canvas-region-end-content -->');
    $build['#attached']['library'][] = 'canvas/preview';
    if ($varies_by_language) {
      $build['#cache']['contexts'][] = 'languages:' . LanguageInterface::TYPE_INTERFACE;
    }
    if (!self::shouldIncludePageChrome($entity)) {
      $build['#canvas_hide_page_chrome'] = TRUE;
    }
    return $build;
  }

  public function getLabel(Request $request, (ContentEntityInterface&EntityPublishedInterface)|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): string {
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
      'exposed_slots' => self::validateExposedSlotsPayload($body['exposed_slots'] ?? []),
      'status' => TRUE,
    ]);
    self::validateExposedSlots($draft);

    $tree = $this->componentTreeLoader->load($draft);
    $this->componentSourceManager->updateComponentInstances($tree);
    $built = $tree->getClientSideRepresentation($preview_entity);

    return new JsonResponse([
      'model' => empty($built['model']) ? new \stdClass() : $built['model'],
    ]);
  }

  private function buildLayoutAndModel(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentTemplate $per_content_template, ?FieldableEntityInterface $preview_entity = NULL): array {
    $data = ['layout' => [], 'model' => []];
    // Mirror get(): in per-content mode the layout is one node per exposed
    // slot; otherwise it is the single content region.
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $data['layout'] = $this->buildPerContentSlotRegions($entity, $per_content_template, $data['model']);
      \assert(\is_array($data['model']));
      return $data;
    }
    // Build the single content region.
    $tree = $this->componentTreeLoader->load($entity);
    $data['layout'] = [$this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $data['model'], $preview_entity)];
    \assert(\is_array($data['model']));
    return $data;
  }

  /**
   * Whether surrounding page chrome is included in the preview.
   *
   * Page variants and patterns render standalone. Content templates use the
   * resolved page chrome only for the "full" view mode.
   */
  private static function shouldIncludePageChrome(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity): bool {
    // A page variant is itself the chrome around the content: its preview must
    // show only its own tree, not nest it inside the route's resolved variant.
    // Patterns likewise render standalone.
    if ($entity instanceof PageVariant || $entity instanceof Pattern) {
      return FALSE;
    }
    return !($entity instanceof ContentTemplate && $entity->getMode() !== 'full');
  }

  /**
   * Returns the entities whose auto-saves an edit of the given entity spans.
   *
   * In per-content mode this includes the template, so changing it mid-edit
   * is a 409 conflict.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|\Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   The edited entity.
   * @param \Drupal\canvas\Entity\ContentTemplate|null $saved_template
   *   In per-content mode, the saved applicable content template.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The entities to hash and validate auto-saves for.
   *
   * @see ::loadSavedTemplate()
   */
  private static function getAutoSaveScope(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentTemplate $saved_template): array {
    $scope = [$entity, ...self::getEditableRegions($entity)];
    if ($saved_template !== NULL) {
      $scope[] = $saved_template;
    }
    return $scope;
  }

  /**
   * Loads the saved version of a per-content template that may be a draft.
   *
   * Load it once per request and pass it on: the auto-save scope and the slot
   * UUID check both need it.
   */
  private function loadSavedTemplate(?ContentTemplate $template): ?ContentTemplate {
    if ($template === NULL) {
      return NULL;
    }
    $saved_template = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->load($template->id());
    \assert($saved_template === NULL || $saved_template instanceof ContentTemplate);
    return $saved_template;
  }

  /**
   * @return array<never>
   *   Always empty: page variants replaced editable global regions. The
   *   surrounding chrome is a page variant, edited separately from the content.
   */
  private static function getEditableRegions(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity): array {
    return [];
  }

  /**
   * Checks the shape of a client-sent `exposed_slots` map.
   *
   * Only the shape: ::validateExposedSlots() applies the config validation
   * rules once the map is set on the template.
   *
   * @param mixed $exposed_slots
   *   The submitted value.
   *
   * @return array<string, array{component_uuid: string, slot_name: string, label: string}>
   *   The exposed slots, keyed by backing field machine name.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   */
  private static function validateExposedSlotsPayload(mixed $exposed_slots): array {
    if (!\is_array($exposed_slots)) {
      throw new BadRequestHttpException('"exposed_slots" must be an object keyed by field name.');
    }
    $validated = [];
    foreach ($exposed_slots as $field_name => $definition) {
      if (!\is_string($field_name)) {
        throw new BadRequestHttpException('"exposed_slots" must be an object keyed by field name.');
      }
      if (!\is_array($definition)) {
        throw new BadRequestHttpException(\sprintf('Exposed slot "%s" must be an object.', $field_name));
      }
      foreach (['component_uuid', 'slot_name', 'label'] as $key) {
        if (!\is_string($definition[$key] ?? NULL) || $definition[$key] === '') {
          throw new BadRequestHttpException(\sprintf('Exposed slot "%s" is missing a valid "%s".', $field_name, $key));
        }
      }
      $validated[$field_name] = [
        'component_uuid' => $definition['component_uuid'],
        'slot_name' => $definition['slot_name'],
        'label' => $definition['label'],
      ];
    }
    return $validated;
  }

  /**
   * Validates a template's exposed slots before it is auto-saved as a draft.
   *
   * Config validation of the whole template only runs at publish, but the
   * draft's exposed slots reach every content editor of the bundle at once: a
   * duplicate or nested target, or an unknown component, slot or field, would
   * break per-content editing until discarded. So the `ValidExposedSlot`
   * constraint runs now, and only its violations count.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The template, with the submitted exposed slots and component tree.
   *
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ValidExposedSlotConstraintValidator
   */
  private static function validateExposedSlots(ContentTemplate $template): void {
    $violations = \array_filter(
      \iterator_to_array($template->getTypedData()->validate()),
      static fn (ConstraintViolationInterface $violation): bool => \str_starts_with($violation->getPropertyPath(), 'exposed_slots'),
    );
    if ($violations !== []) {
      throw new ConstraintViolationException(new ConstraintViolationList(\array_values($violations)));
    }
  }

  /**
   * @param LayoutClientStructureArray $page_layout
   *   The submitted layout. Current clients send only the "main content"
   *   region.
   *
   * @return array<string, RegionClientStructureArray>
   *   Keys: client-side region IDs, values: the "region" layout node and its
   *   contents.
   */
  private static function getRegionLayoutNodesKeyedByClientSideId(array $page_layout): array {
    $keyed_region_nodes = [];
    foreach ($page_layout as $region_node) {
      // Explicit checks, not assertions: this is client input, and assertions
      // are disabled in production.
      if (!\is_array($region_node) || ($region_node['nodeType'] ?? NULL) !== 'region' || !\is_string($region_node['id'] ?? NULL) || !\is_array($region_node['components'] ?? NULL)) {
        throw new BadRequestHttpException('Each layout node must be a region with an "id" and a "components" list.');
      }
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
      if ($this->componentTreeLoader->findItemListContaining($entity, $componentInstanceUuid) !== NULL) {
        return $entity;
      }
    }
    throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
  }

  /**
   * The instance UUID of a component tree's "Page content" marker, if any.
   *
   * A valid page variant tree carries exactly one marker; this returns its
   * stable instance UUID (the variant's identity), or NULL when no marker is
   * present.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   A stored component tree.
   */
  private static function pageContentMarkerUuid(ComponentTreeItemList $tree): ?string {
    foreach ($tree as $item) {
      \assert($item instanceof ComponentTreeItem);
      if ($item->getComponentId() === Marker::PAGE_CONTENT_COMPONENT_ID) {
        return $item->getUuid();
      }
    }
    return NULL;
  }

  /**
   * Collects the "Page content" marker UUIDs in a client-side layout node.
   *
   * Walks a client-side region/component/slot node recursively. A marker is a
   * component node whose type is the marker component id (optionally suffixed
   * with a version). Returns every match so an unexpected count (zero, or more
   * than one) also fails the identity check in ::post().
   *
   * @param array $node
   *   A client-side layout node (region, component, or slot).
   *
   * @return array<int, string>
   *   The marker instance UUIDs found, in encounter order.
   */
  private static function submittedPageContentMarkerUuids(array $node): array {
    $uuids = [];
    if (($node['nodeType'] ?? NULL) === 'component'
      && \explode('@', (string) ($node['type'] ?? ''))[0] === Marker::PAGE_CONTENT_COMPONENT_ID) {
      $uuids[] = (string) ($node['uuid'] ?? '');
    }
    // Region and slot nodes carry 'components'; component nodes carry 'slots'.
    foreach ((array) ($node['components'] ?? $node['slots'] ?? []) as $child) {
      if (\is_array($child)) {
        $uuids = \array_merge($uuids, self::submittedPageContentMarkerUuids($child));
      }
    }
    return $uuids;
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
    $tree = $this->componentTreeLoader->findItemListContaining($entity, $componentInstanceUuid);
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
   * @param \Drupal\canvas\Entity\ContentTemplate|\Drupal\canvas\Entity\PageVariant|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity that is updated by reference: its fields (if any) and its
   *   component tree.
   * @param RegionClientStructureArray $layout
   * @param array<string, array{source: SingleComponentInputArray, resolved: array<string, mixed>}> $model
   * @param ?array $entity_form_fields
   *   Entity form fields. Required only if $entity is fieldable.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $preview_entity
   *   Preview entity. Required only if $entity is a ContentTemplate.
   */
  private function updateEntity(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, array $layout, array $model, ?array $entity_form_fields, ?FieldableEntityInterface $preview_entity): void {
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
      \assert(!$entity instanceof ContentTemplate || !\is_null($preview_entity));
      $host_entity = self::resolveHostEntity($entity, $preview_entity);
      // @todo Use \Drupal\canvas\ClientDataToEntityConverter here
      //   as well in https://drupal.org/i/3543197.
      // @todo Remove php-stan-ignore in https://drupal.org/i/3548273.
      // @phpstan-ignore-next-line argument.type
      $entity->setComponentTree(self::convertClientToServer($layout['components'], $model, $host_entity, FALSE));
    }
  }

  /**
   * Detects "per-content" mode and returns the applicable content template.
   *
   * Per-content mode applies to a content entity whose bundle has an enabled
   * full-view-mode template that exposes slots. The Layout API then serves one
   * editable region per exposed slot and renders template chrome as inert
   * preview HTML. A pending template draft is preferred, matching the preview.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity opened in the Layout API.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The applicable content template, or NULL if not in per-content mode.
   */
  private function getPerContentTemplate(ComponentTreeConfigEntityBase|FieldableEntityInterface $entity): ?ContentTemplate {
    if (!$entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeEntityInterface) {
      return NULL;
    }
    $template = ContentTemplate::loadForEntity($entity, 'full');
    if (!$template instanceof ContentTemplate || !$template->status() || empty($template->getExposedSlots())) {
      return NULL;
    }
    // A draft that detached every slot yields zero editable regions.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::loadTemplate()
    $draft = $this->autoSaveManager->getAutoSaveEntity($template)->entity;
    if (!$draft instanceof ContentTemplate) {
      $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
      \assert($storage instanceof ConfigEntityStorageInterface);
      $draft = $storage->loadOverrideFree($template->id());
      \assert($draft instanceof ContentTemplate);
    }
    return $this->translateTemplate($draft);
  }

  /**
   * Merges the current language's config override into a template.
   *
   * Drafts are untranslated base data, and loading a saved template merges
   * its override by array key, which misses a component tree that is not yet
   * keyed by UUID. So per-content mode always starts from the base and merges
   * the override here, as ::buildPreviewRenderable() does for config entities.
   * The result is never saved.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The untranslated template: a draft, or the saved one without overrides.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate
   *   The template in the current language.
   *
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::getTranslatedComponentTree()
   * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::getPreviewComponentTree()
   */
  private function translateTemplate(ContentTemplate $template): ContentTemplate {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    // ::getTranslationLanguages() already excludes the site default language
    // and any language without a stored override.
    if (!\array_key_exists($langcode, $template->getTranslationLanguages(include_default: FALSE))) {
      return $template;
    }
    $translated = clone $template;
    // An override can target a component the draft removed: it merges into an
    // instance-less row, which is dropped.
    $rows = \array_filter(
      $template->getTranslatedComponentTree($langcode)->getValue(),
      static fn (array $row): bool => isset($row['uuid'], $row['component_id']),
    );
    $translated->set('component_tree', \array_values($rows));
    $exposed_slots = $template->getExposedSlots();
    $override = $template->getTranslation($langcode)->getData();
    foreach ($override['exposed_slots'] ?? [] as $field_name => $definition) {
      if (isset($exposed_slots[$field_name]) && \is_string($definition['label'] ?? NULL)) {
        $exposed_slots[$field_name]['label'] = $definition['label'];
      }
    }
    $translated->set('exposed_slots', $exposed_slots);
    return $translated;
  }

  /**
   * Builds one top-level editable node per exposed slot for per-content mode.
   *
   * Each node is keyed by the slot's backing field name and holds only the
   * entity's own content for that slot. Template chrome is not in the layout.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   * @param array|null $model
   *   The client-side model, updated by reference.
   *
   * @return array
   *   One region-like layout node per exposed slot.
   */
  private function buildPerContentSlotRegions(FieldableEntityInterface $entity, ContentTemplate $template, ?array &$model = NULL): array {
    $layout = [];
    foreach ($template->getExposedSlots() as $field_name => $definition) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $slot_field = $entity->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      // The client writes back every node it is served, so leave out slot
      // fields the user may not edit.
      if (!self::mayEditSlotField($slot_field)) {
        continue;
      }
      $layout[] = $this->buildRegion(
        $field_name,
        $slot_field,
        $model,
        preview_entity: $entity,
        client_id: $field_name,
        name: $definition['label'],
      );
    }
    return $layout;
  }

  /**
   * Computes each exposed slot's template default content, as data.
   *
   * The client copies a default (with fresh UUIDs) when the entity overrides
   * the slot. A slot without default content maps to NULL and is never
   * inherited.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity, used as the host for dynamic prop resolution.
   *
   * @return array<string, array{layout: array<int, mixed>, model: array<string, mixed>}|null>
   *   For each exposed slot (keyed by backing field name): the default
   *   content's client-side layout and model fragments, or NULL when the
   *   slot has no default content.
   */
  private function computeSlotDefaults(ContentTemplate $template, FieldableEntityInterface $entity): array {
    $defaults = [];
    foreach ($template->getComponentTree()->extractExposedSlotContent($template->getExposedSlots()) as $field_name => $rows) {
      if ($rows === []) {
        $defaults[$field_name] = NULL;
        continue;
      }
      $list = $this->createDanglingComponentTreeItemList($entity);
      $list->setValue(\array_values($rows));
      $built = $list->getClientSideRepresentation($entity);
      $defaults[$field_name] = [
        'layout' => $built['layout'],
        'model' => $built['model'],
      ];
    }
    return $defaults;
  }

  /**
   * Writes submitted per-slot nodes into the entity's slot fields.
   *
   * Every submitted slot's field is written, so reverting an override (an
   * empty node) clears its field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity, whose slot fields are written.
   * @param array<string, RegionClientStructureArray> $slot_layouts
   *   The submitted slot nodes, keyed by backing field machine name.
   * @param array<string, mixed> $model
   *   The submitted client-side model.
   * @param array<string, mixed> $entity_form_fields
   *   The submitted entity form fields.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template, possibly a draft.
   * @param \Drupal\canvas\Entity\ContentTemplate|null $saved_template
   *   The saved version of the applicable content template.
   */
  private function writeSlotRegions(FieldableEntityInterface $entity, array $slot_layouts, array $model, array $entity_form_fields, ContentTemplate $template, ?ContentTemplate $saved_template): void {
    // Component UUIDs must be unique across the template (draft and saved)
    // and all slot fields, or ::injectSlotContent() refuses to merge them.
    $seen_uuids = self::collectTemplateOwnedUuids($template);
    if ($saved_template !== NULL) {
      $seen_uuids += self::collectTemplateOwnedUuids($saved_template);
    }
    foreach ($template->getExposedSlots() as $field_name => $definition) {
      if (\array_key_exists($field_name, $slot_layouts) || !$entity->hasField($field_name)) {
        continue;
      }
      $slot_field = $entity->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      foreach ($slot_field->componentTreeItemsIterator() as $item) {
        \assert($item instanceof ComponentTreeItem);
        $seen_uuids[$item->getUuid()] = TRUE;
      }
    }
    foreach ($template->getExposedSlots() as $field_name => $definition) {
      if (!\array_key_exists($field_name, $slot_layouts) || !$entity->hasField($field_name)) {
        continue;
      }
      // Slot fields the user may not edit are never served, so this is a
      // crafted request.
      $slot_field = $entity->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      if (!self::mayEditSlotField($slot_field)) {
        throw new AccessDeniedHttpException(\sprintf('Access denied for the %s field.', $field_name));
      }
      self::assertClientNodesHaveUuids($slot_layouts[$field_name]['components'], $field_name);
      // @phpstan-ignore-next-line argument.type
      $rows = self::convertClientToServer($slot_layouts[$field_name]['components'], $model, $entity, validate: FALSE);
      // Whether a slot is overridden, and with which components, is shared by
      // all translations when the tree is synchronized. A non-default
      // translation may only edit the inputs.
      // @todo Revisit once non-default translations can be published in https://git.drupalcode.org/project/canvas/-/work_items/3591703
      if ($entity instanceof TranslatableInterface && !$entity->isDefaultTranslation() && $this->isTreeShared($slot_field) && self::treeStructure($rows) !== self::treeStructure($slot_field->getValue())) {
        throw new AccessDeniedHttpException(\sprintf('The structure of the %s field can only be changed in the default translation.', $field_name));
      }
      foreach ($rows as $row) {
        $uuid = (string) ($row['uuid'] ?? '');
        if (\array_key_exists($uuid, $seen_uuids)) {
          throw new BadRequestHttpException(\sprintf('Component %s appears more than once across the template and slot fields.', $uuid));
        }
        $seen_uuids[$uuid] = TRUE;
      }
      $entity->set($field_name, \array_values($rows));
    }
    // Not ::convert(): it expects a single Canvas field, which a templated
    // entity does not have. Called after the slot fields are written, so the
    // form validation sees the converged translations.
    $this->converter->applyEntityFormFields($entity, $entity_form_fields, validate: FALSE);
  }

  /**
   * Checks that every submitted component node in a slot has a UUID.
   *
   * Conversion needs the UUID: without it, a row would have no identity, and
   * conversion fails with a TypeError.
   *
   * @param array<mixed> $components
   *   The client-side component nodes of a slot, or of one of their slots.
   * @param string $field_name
   *   The slot's backing field name.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   */
  private static function assertClientNodesHaveUuids(array $components, string $field_name): void {
    foreach ($components as $node) {
      if (!\is_array($node) || !\is_string($node['uuid'] ?? NULL) || $node['uuid'] === '') {
        throw new BadRequestHttpException(\sprintf('A component in the %s slot has no UUID.', $field_name));
      }
      foreach (\is_array($node['slots'] ?? NULL) ? $node['slots'] : [] as $slot) {
        if (\is_array($slot) && \is_array($slot['components'] ?? NULL)) {
          self::assertClientNodesHaveUuids($slot['components'], $field_name);
        }
      }
    }
  }

  /**
   * Whether all translations share a slot field's component tree structure.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $slot_field
   *   The slot field.
   *
   * @return bool
   *   TRUE when the field is not translatable, or synchronizes its tree.
   */
  private function isTreeShared(ComponentTreeItemList $slot_field): bool {
    $field_definition = $slot_field->getFieldDefinition();
    if (!$field_definition->isTranslatable()) {
      return TRUE;
    }
    return $this->translationSynchronizer !== NULL
      && ComponentTreeFieldSymmetricalTranslationSynchronizer::isTreeSynced($this->translationSynchronizer, $field_definition);
  }

  /**
   * Reduces component tree rows to their structure.
   *
   * @param array<int|string, array<string, mixed>> $rows
   *   Component tree rows.
   *
   * @return array<string, list<array{0: string, 1: string}>>
   *   The component UUIDs and IDs of each slot in order, keyed by parent UUID
   *   and slot name.
   */
  private static function treeStructure(array $rows): array {
    $structure = [];
    foreach ($rows as $row) {
      $parent = (string) ($row['parent_uuid'] ?? '') . ':' . (string) ($row['slot'] ?? '');
      $structure[$parent][] = [(string) ($row['uuid'] ?? ''), (string) ($row['component_id'] ?? '')];
    }
    \ksort($structure);
    return $structure;
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
        'label' => $definition['label'],
        'slotName' => $definition['slot_name'],
        'componentUuid' => $definition['component_uuid'],
      ];
    }
    return $slots;
  }

  /**
   * Computes per-slot override state from the entity's slot fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   * @param bool $require_edit_access
   *   Whether a slot the user may not edit counts as not overridden. FALSE
   *   matches the rendered preview, which only checks view access.
   *
   * @return array<string, array{overridden: bool, empty: bool}>
   *   For each exposed slot (keyed by backing field machine name): whether the
   *   entity overrides it, and whether that override is empty (its only item
   *   is the empty slot marker).
   *
   * @see \Drupal\canvas\Entity\ContentTemplate::getMergedComponentTree()
   */
  private static function computeSlotOverrides(FieldableEntityInterface $entity, ContentTemplate $template, bool $require_edit_access = TRUE): array {
    $overrides = [];
    foreach (\array_keys($template->getExposedSlots()) as $field_name) {
      $slot_field = $entity->hasField($field_name) ? $entity->get($field_name) : NULL;
      \assert($slot_field === NULL || $slot_field instanceof ComponentTreeItemList);
      if ($slot_field === NULL || !($require_edit_access ? self::mayEditSlotField($slot_field) : $slot_field->access('view'))) {
        $overrides[$field_name] = ['overridden' => FALSE, 'empty' => FALSE];
        continue;
      }
      $roots = [];
      foreach ($slot_field->componentTreeItemsIterator(ComponentTreeItemList::inRootLevel()) as $item) {
        \assert($item instanceof ComponentTreeItem);
        $roots[] = $item;
      }
      $overrides[$field_name] = [
        'overridden' => \count($roots) > 0,
        'empty' => \count($roots) === 1 && $roots[0]->getComponentId() === Marker::EMPTY_SLOT_COMPONENT_ID,
      ];
    }
    return $overrides;
  }

  /**
   * Returns a map for a JSON response, so that an empty map is a JSON object.
   *
   * @param array<string, mixed> $map
   *   The map.
   *
   * @return array<string, mixed>|\stdClass
   *   The map, or an empty object when the map is empty.
   */
  private static function mapForClient(array $map): array|\stdClass {
    return $map === [] ? new \stdClass() : $map;
  }

  /**
   * Whether the current user may view and edit a slot field.
   *
   * Per-content editing only serves and accepts slot fields that pass both.
   */
  private static function mayEditSlotField(ComponentTreeItemList $slot_field): bool {
    return $slot_field->access('view') && $slot_field->access('edit');
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
   * Resolves the host entity for component input conversion/representation.
   *
   * Page variant trees have no host entity, but pasted components may carry
   * entity field prop sources; an empty stand-in entity keeps input
   * conversion and the client-side model working, exactly as the component
   * instance form does. Other entities keep the route's preview entity (which
   * is NULL outside content template routes).
   *
   * @see \Drupal\canvas\Form\ComponentInstanceForm::buildForm()
   * @see \Drupal\canvas\Entity\PageVariant::createEmptyTargetEntity()
   */
  private static function resolveHostEntity(ComponentTreeConfigEntityBase|FieldableEntityInterface $entity, ?FieldableEntityInterface $preview_entity): ?FieldableEntityInterface {
    if ($entity instanceof PageVariant && $preview_entity === NULL) {
      return $entity->createEmptyTargetEntity();
    }
    return $preview_entity;
  }

}
