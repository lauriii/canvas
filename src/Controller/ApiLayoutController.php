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
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
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
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
  public function get(Request $request, (ContentEntityInterface&EntityPublishedInterface)|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
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
    // Build the editable layout. In per-content mode (a templated entity
    // whose bundle exposes at least one active slot) each exposed slot is its
    // own top-level editable node keyed by its backing field name; template
    // chrome renders only as inert context in the preview HTML and is absent
    // from the layout payload. Otherwise the layout is the single content
    // region holding the entity's own component tree.
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
      'autoSaves' => $this->getAutoSaveHashes(array_merge(
        [$entity],
        $this->getEditableRegions($entity),
      )),
    ];
    $available_translations = [];
    $links = [];
    if ($entity instanceof ContentEntityInterface) {
      $available_translations = \array_keys($entity->getTranslationLanguages(FALSE));
      if ($this->moduleHandler->moduleExists('content_translation')) {
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

    // In per-content mode, expose the slot definitions, per-slot override
    // state, and each slot's template default content (as data, not as
    // editable layout) so the editor can distinguish inherited defaults from
    // per-entity overrides and can fork a default locally on unlock.
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $data['exposedSlots'] = self::normalizeExposedSlotsForClient($per_content_template);
      $data['slotOverrides'] = self::computeSlotOverrides($entity, $per_content_template);
      $data['slotDefaults'] = $this->computeSlotDefaults($per_content_template, $entity);
      // The applicable template's identity, so the client can link to the
      // template editor without guessing from exposed-slot aliases.
      $data['contentTemplate'] = [
        'entityType' => $per_content_template->getTargetEntityTypeId(),
        'bundle' => $per_content_template->getTargetBundle(),
        'viewMode' => $per_content_template->getMode(),
      ];
    }
    elseif ($entity instanceof ContentTemplate) {
      // Template editor: surface the template's own exposed slots (including a
      // pending draft's) so the editor's working set survives a reload.
      $data['exposedSlots'] = self::normalizeExposedSlotsForClient($entity);
    }

    return new PreviewEnvelope($this->buildPreviewRenderable($entity, $preview_entity), $data);
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

    $this->validateAutoSaves(
      array_merge([$entity], $this->getEditableRegions($entity)),
      $autoSaves,
      $clientInstanceId,
    );

    // Determine which entity to PATCH. Page variants edit only the content
    // entity's own tree, so the patched component instance belongs to it.
    $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
    \assert($entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeConfigEntityBase);

    // In per-content mode the editable layout contains only entity-owned
    // components living in exposed-slot fields, so a template-owned UUID is
    // simply never found.
    $per_content_template = $this->getPerContentTemplate($entity);
    $entity_to_patch = $this->getEntityWithComponentInstance([$entity], $componentInstanceUuid);

    // In per-content mode only exposed-slot fields are editable: a component
    // living in a detached or unexposed component-tree field is part of the
    // entity but not of the editable payload, so it is not addressable.
    if ($per_content_template !== NULL) {
      $containing_list = $this->componentTreeLoader->findItemListContaining($entity_to_patch, $componentInstanceUuid);
      if ($containing_list === NULL || !\array_key_exists((string) $containing_list->getName(), $per_content_template->getExposedSlots())) {
        throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
      }
      // An edit-denied slot field is never served in the editable layout, so
      // addressing its content can only be a crafted request.
      if (!$containing_list->access('edit')) {
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
    $data = $this->buildLayoutAndModel($entity, preview_entity: $host_entity);
    \assert(['layout', 'model'] === \array_keys($data));
    if ($entity instanceof FieldableEntityInterface) {
      $data['entity_form_fields'] = $this->getFilteredEntityData($entity);
    }
    elseif ($entity instanceof ComponentTreeConfigEntityBase) {
      // Config entities have no entity form; keep the response shape uniform.
      $data['entity_form_fields'] = new \stdClass();
    }
    $data['autoSaves'] = $this->getAutoSaveHashes(array_merge(
      [$entity],
      $this->getEditableRegions($entity),
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
  public function post(Request $request, FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
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
      array_merge([$entity], $this->getEditableRegions($entity)),
      $autoSaves,
      $clientInstanceId,
    );

    // Per-content mode: the layout carries only exposed-slot nodes, each
    // keyed by its backing field machine name. Anything else, including
    // template chrome, is not part of the per-entity payload and is rejected.
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      \assert(\is_array($entity_form_fields));
      $slot_layouts = self::getRegionLayoutNodesKeyedByClientSideId($layout);
      $unknown = \array_diff_key($slot_layouts, $per_content_template->getExposedSlots());
      if ($unknown !== []) {
        throw new AccessDeniedHttpException('Only exposed slots can be edited per-entity. Unknown nodes: ' . \implode(', ', \array_keys($unknown)));
      }
      $entity = $this->getAutoSavedVersionIfAvailable([$entity])[$entity->id()];
      \assert($entity instanceof FieldableEntityInterface);
      $this->writeSlotRegions($entity, $slot_layouts, (array) $model, $entity_form_fields, $per_content_template);
      $this->autoSaveManager->saveEntity($entity, $clientInstanceId);
      return new PreviewEnvelope(
        $this->buildPreviewRenderable($entity, $preview_entity),
        additionalData: [
          'autoSaves' => $this->getAutoSaveHashes([$entity]),
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
      $entity->set('exposed_slots', $body['exposed_slots']);
    }
    $this->autoSaveManager->saveEntity($entity, $clientInstanceId);

    return new PreviewEnvelope(
      $this->buildPreviewRenderable($entity, $preview_entity),
      additionalData: [
        'autoSaves' => $this->getAutoSaveHashes(array_merge(
          [$entity],
          $this->getEditableRegions($entity),
        )),
      ],
    );
  }

  private function buildPreviewRenderable(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?FieldableEntityInterface $preview_entity = NULL): array {
    $varies_by_language = FALSE;
    // In per-content mode compose the preview from the (possibly draft)
    // template merged with the (possibly draft) entity slot content, consistent
    // with the rendered output and honoring the editing lifecycle.
    $per_content_template = $entity instanceof FieldableEntityInterface
      ? $this->getPerContentTemplate($entity)
      : NULL;
    if ($entity instanceof ContentTemplate) {
      // @phpstan-ignore-next-line
      $renderable = $entity->build($preview_entity, isPreview: TRUE);
    }
    elseif ($per_content_template instanceof ContentTemplate) {
      // Template chrome renders as inert markup in per-content mode: its
      // component wrapper markers are suppressed so the client cannot address
      // it, while slot markers keep emitting so exposed slots stay anchored.
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

  private function buildLayoutAndModel(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?FieldableEntityInterface $preview_entity = NULL): array {
    $data = ['layout' => [], 'model' => []];
    // Mirror get(): in per-content mode the layout is one node per exposed
    // slot; otherwise it is the single content region.
    $per_content_template = $this->getPerContentTemplate($entity);
    if ($per_content_template !== NULL) {
      \assert($entity instanceof FieldableEntityInterface);
      $data['layout'] = $this->buildPerContentSlotRegions($entity, $per_content_template, $data['model']);
      \assert(\is_array($data['model']));
      return $data;
    }
    $tree = $this->componentTreeLoader->load($entity);
    $data['layout'] = [$this->buildRegion(CanvasPageVariant::MAIN_CONTENT_REGION, $tree, $data['model'], self::resolveHostEntity($entity, $preview_entity))];
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
   * @return array<never>
   *   Always empty: page variants replaced editable global regions. The
   *   surrounding chrome is a page variant, edited separately from the content.
   */
  private static function getEditableRegions(FieldableEntityInterface|ComponentTreeConfigEntityBase $entity): array {
    return [];
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

  /**
   * Detects "per-content" mode and returns the applicable content template.
   *
   * Per-content mode is when the opened entity is a fieldable content entity
   * (not a config entity with its own component tree) whose bundle has an
   * enabled full-view-mode template exposing at least one active slot. In
   * that mode the Layout API serves one editable region per exposed slot
   * (each backed directly by its own `component_tree` field on the entity),
   * renders the template chrome as inert preview HTML only, and writes each
   * submitted slot region straight to its backing field.
   *
   * A pending template draft (if any) is preferred, so the slot regions, the
   * defaults side-channel, the writes and the preview all use the same
   * template state.
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
    // The preview renders the pending draft whenever one exists, so the slot
    // contract must come from the same revision — including a draft that
    // detached every slot, which yields zero editable regions rather than
    // stale ones with no marker in the preview HTML.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::loadTemplate()
    $draft = $this->autoSaveManager->getAutoSaveEntity($template)->entity;
    return $draft instanceof ContentTemplate ? $draft : $template;
  }

  /**
   * Builds one top-level editable node per exposed slot for per-content mode.
   *
   * Each exposed slot is served as its own region-like layout node, keyed by
   * the slot's backing field machine name and containing only the entity's
   * own rows for that slot (an ordinary component tree). Template chrome and
   * global regions are not part of the layout in per-content mode: they
   * render as inert context in the preview HTML only, so per-entity editing
   * cannot address them.
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
      // Respect field-level access (hook_entity_field_access() etc.): the
      // layout is an editing surface and the client writes back every slot
      // node it was served, so a slot field the user may not view AND edit is
      // left out of the editable payload entirely, exactly like a missing
      // field. (A view-only field still renders in the preview HTML.)
      if (!$slot_field->access('view') || !$slot_field->access('edit')) {
        continue;
      }
      $layout[] = $this->buildRegion(
        $field_name,
        $slot_field,
        $model,
        preview_entity: $entity,
        client_id: $field_name,
        name: (string) ($definition['label'] ?? $field_name),
      );
    }
    return $layout;
  }

  /**
   * Computes each exposed slot's template default content, as data.
   *
   * The defaults are not part of the editable layout: the client uses them to
   * materialize an entity-owned copy (with fresh instance identity) when a
   * not-yet-overridden slot is unlocked, in a single client transaction. A
   * slot with no default content maps to NULL, which is also how the client
   * knows the slot has no default (and therefore no lock).
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
    // Re-root each exposed slot's default subtree exactly like an entity
    // override would be stored: partitioning the template's own tree with an
    // empty template-owned set treats all default content as extractable.
    ['fields' => $default_rows] = $template->getComponentTree()->partitionSlotFields($template->getExposedSlots(), []);
    $defaults = [];
    foreach ($default_rows as $field_name => $rows) {
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
   * The per-content layout carries only exposed-slot nodes, each keyed by its
   * backing field machine name and containing an ordinary component tree.
   * Every submitted slot's field is written (empty when the node is empty),
   * so reverting an override clears its field. This bypasses the whole-tree
   * converter, which loads a single Canvas field the templated entity does
   * not have.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The edited entity, updated by reference.
   * @param array<string, RegionClientStructureArray> $slot_layouts
   *   The submitted slot nodes, keyed by backing field machine name.
   * @param array<string, mixed> $model
   *   The submitted client-side model.
   * @param array<string, mixed> $entity_form_fields
   *   The submitted entity form fields.
   * @param \Drupal\canvas\Entity\ContentTemplate $template
   *   The applicable content template.
   */
  private function writeSlotRegions(FieldableEntityInterface $entity, array $slot_layouts, array $model, array $entity_form_fields, ContentTemplate $template): void {
    // Process the entity's own page-data fields (title, etc.). This must NOT go
    // through the whole ::convert() (which loads a single Canvas field the
    // templated entity does not have); the component tree is written to the
    // per-slot fields below instead.
    if (\count($entity_form_fields) > 0) {
      $this->converter->applyEntityFormFields($entity, $entity_form_fields, validate: FALSE);
    }
    // Component instance UUIDs must stay unique across the template and every
    // slot field: `injectSlotContent()` refuses to merge colliding subtrees at
    // render time, so reject the collision at write time instead. Seed the
    // set with the template's UUIDs and those of untouched slot fields.
    $seen_uuids = self::collectTemplateOwnedUuids($template);
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
      // Respect field-level access: an edit-denied slot field is never served
      // in the editable layout, so a write to one can only be a crafted
      // request and is rejected.
      if (!$entity->get($field_name)->access('edit')) {
        throw new AccessDeniedHttpException(\sprintf('Access denied for the %s field.', $field_name));
      }
      // @phpstan-ignore-next-line argument.type
      $rows = self::convertClientToServer($slot_layouts[$field_name]['components'], $model, $entity, validate: FALSE);
      foreach ($rows as $row) {
        $uuid = (string) ($row['uuid'] ?? '');
        if (\array_key_exists($uuid, $seen_uuids)) {
          throw new BadRequestHttpException(\sprintf('Component %s appears more than once across the template and slot fields.', $uuid));
        }
        $seen_uuids[$uuid] = TRUE;
      }
      $entity->set($field_name, \array_values($rows));
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
      if (!$entity->hasField($field_name) || !$entity->get($field_name)->access('view') || !$entity->get($field_name)->access('edit')) {
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

}
