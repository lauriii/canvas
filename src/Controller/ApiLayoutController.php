<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\experience_builder\Render\PreviewEnvelope;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 * @phpstan-type ComponentClientStructureArray array{nodeType: 'component', uuid: string, type: ComponentConfigEntityId, slots: array<int, mixed>}
 * @phpstan-type RegionClientStructureArray array{nodeType: 'region', id: string, name: string, components: array<int, ComponentClientStructureArray>}
 * @phpstan-type LayoutClientStructureArray array<int, RegionClientStructureArray>
 */
final class ApiLayoutController {

  use EntityFormTrait;
  private array $regions;
  private array $regionsClientSideIds;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ClientDataToEntityConverter $converter,
    private readonly ComponentTreeLoader $componentTreeLoader,
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
    $server_side_ids = array_map(
      fn (string $region_name): string => $region_name === XbPageVariant::MAIN_CONTENT_REGION
        ? XbPageVariant::MAIN_CONTENT_REGION
        : "$theme.$region_name",
      array_keys($theme_regions)
    );
    $this->regionsClientSideIds = array_combine($server_side_ids, array_keys($theme_regions));
    $this->regions = array_combine($server_side_ids, $theme_regions);
    assert(array_key_exists(XbPageVariant::MAIN_CONTENT_REGION, $this->regions));
  }

  public function get(ContentEntityInterface&EntityPublishedInterface $entity): PreviewEnvelope {
    $regions = PageRegion::loadForActiveTheme();

    $is_published = $entity->isPublished();

    $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($entity);
    if (!$autoSaveData->isEmpty()) {
      $entity = $autoSaveData->entity;
      \assert($entity instanceof ContentEntityInterface);
    }

    $model = [];
    $entity_form_fields = $this->getEntityData($entity);
    // Build the content region.
    $tree = $this->componentTreeLoader->load($entity);
    $content_layout = $this->buildRegion(XbPageVariant::MAIN_CONTENT_REGION, $tree, $model);
    $layout = [$content_layout];
    $is_new = AutoSaveManager::contentEntityIsConsideredNew($entity);

    if ($regions) {
      $this->addGlobalRegions($regions, $model, $layout);
      $layout_keyed_by_region = array_combine(array_map(static fn($region) => $region['id'], $layout), $layout);
      // Reorder the layout to match theme order.
      $layout = array_values(array_replace(
        array_intersect_key(array_flip($this->regionsClientSideIds), $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }

    $data = [
      // Maps to the `tree` property of the XB field type.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
      // @todo Settle on final names and get in sync.
      'layout' => $layout,
      // Maps to the `inputs` property of the XB field type.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentInputs
      // @todo Settle on final names and get in sync.
      // If the model is empty return an empty object to ensure it is encoded as
      // an object and not empty array.
      'model' => empty($model) ? new \stdClass() : $model,
      'entity_form_fields' => $entity_form_fields,
      'isNew' => $is_new,
      'isPublished' => $is_published,
      'autoSaves' => $this->getAutoSaveHashes($entity),
    ];
    return new PreviewEnvelope($this->buildPreviewRenderable($data, $entity, FALSE), $data);
  }

  private function buildRegion(string $id, ?ComponentTreeItemList $items = NULL, ?array &$model = NULL): array {
    if ($items) {
      $built = $items->getClientSideRepresentation();
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

  private function getEntityData(FieldableEntityInterface $entity): array {
    // @todo Try to return this from the form controller instead.
    // @see https://www.drupal.org/project/experience_builder/issues/3496875
    // This mirrors a lot of the logic of EntityFormController::form. We want
    // the entity data in the same shape as form state for an entity form so
    // that if matches that of the form built by EntityFormController::form.
    // @see \Drupal\experience_builder\Controller\EntityFormController::form
    $form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $form_state = $this->buildFormState($form_object, $entity, 'default');
    $form = $this->formBuilder->buildForm($form_object, $form_state);
    // Filter out form values that are not accessible to the client.
    $values = self::filterFormValues($form_state->getValues(), $form);

    // If the user had previously submitted any invalid values, these will be
    // stored in their respective violations in the auto-save manager. We
    // restore invalid values so that if a user is attempting to rectify invalid
    // values the value shown matches what was previously entered.
    $violations = $this->autoSaveManager->getEntityFormViolation($entity);
    foreach ($violations as $violation) {
      $property_path = $violation->getPropertyPath();
      // @see \Drupal\experience_builder\ClientDataToEntityConverter::setEntityFields
      $parents = \explode('.', $property_path);
      NestedArray::setValue($values, $parents, $violation->getInvalidValue());
    }

    // Collapse form values into the respective element name, e.g.
    // ['title' => ['value' => 'Node title']] becomes
    // ['title[0][value]' => 'Node title'. This keeps the data sent in the same
    // shape as the 'name' attributes on each of the form elements built by the
    // form element and avoids needing to smooth out the idiosyncrasies of each
    // widget's structure.
    // @see \Drupal\experience_builder\Controller\EntityFormController::form
    return Query::parse(\http_build_query(\array_intersect_key($values, $entity->toArray())));
  }

  private function addGlobalRegions(array $regions, array &$model, array &$layout, bool $includeAllRegions = FALSE): void {
    // Only expose regions marked as editable in the `layout` for the client.
    foreach ($regions as $id => $region) {
      assert($region instanceof PageRegion);
      assert($region->status() === TRUE);
      if (!$region->access('edit') && !$includeAllRegions) {
        // If the user doesn't have access to a region, we don't need to include
        // it.
        continue;
      }

      // Use auto-save data for each PageRegion config entity if available.
      if ($draft_region = $this->autoSaveManager->getAutoSaveEntity($region)->entity) {
        \assert($draft_region instanceof PageRegion);
        $layout[] = $this->buildRegion($id, $draft_region->getComponentTree(), $model);
      }
      // Otherwise fall back to the currently live PageRegion config entity.
      // (Note: this automatically ignores auto-saves for PageRegions that were
      // editable at the time, but no longer are.)
      else {
        $layout[] = $this->buildRegion($id, $region->getComponentTree(), $model);
      }
    }
  }

  /**
   * PATCH request updates the auto-saved model and returns a preview.
   */
  public function patch(Request $request, FieldableEntityInterface $entity): PreviewEnvelope {
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
    [
      'componentInstanceUuid' => $componentInstanceUuid,
      'componentType' => $componentTypeAndVersion,
      'model' => $model,
    ] = $body;

    $this->validateAutoSaves($entity, $body);

    $data = $this->getLastStoredData($entity, includeAllRegions: TRUE);
    if (!\array_key_exists('model', $data)) {
      throw new NotFoundHttpException('Missing model');
    }
    if (!\array_key_exists($componentInstanceUuid, $data['model'])) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
    }
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
    \assert($entity instanceof FieldableEntityInterface);

    // Validate that we have access to the page region of this component.
    $page_regions = PageRegion::loadForActiveThemeByClientSideId();
    if (!empty($page_regions)) {
      $regionForComponentId = $this->getRegionForComponentInstance($data['layout'], $componentInstanceUuid);
      if ($regionForComponentId !== XbPageVariant::MAIN_CONTENT_REGION) {
        if (!$page_regions[$regionForComponentId]->access('edit')) {
          throw new AccessDeniedHttpException(sprintf('Access denied for region %s', $regionForComponentId));
        }
      }
    }
    $data['model'][$componentInstanceUuid] = $model;
    return new PreviewEnvelope($this->buildPreviewRenderable($data, $entity, TRUE), $data + [
      // Add the auto-save hashes. We do this after building the preview
      // render array, because the auto-save entry is written during building
      // the preview.
      'autoSaves' => $this->getAutoSaveHashes($entity),
    ]);
  }

  /**
   * POST request returns a preview, but does not update any stored data.
   *
   * @todo Remove this in https://drupal.org/i/3492065
   */
  public function post(Request $request, FieldableEntityInterface $entity): PreviewEnvelope {
    $body = json_decode($request->getContent(), TRUE);
    \assert(\array_key_exists('model', $body));
    \assert(\array_key_exists('layout', $body));
    \assert(\array_key_exists('entity_form_fields', $body));

    $this->validateAutoSaves($entity, $body);

    $regions = PageRegion::loadForActiveThemeByClientSideId();
    if (!empty($regions)) {
      foreach ($body['layout'] as $region) {
        if ($region['id'] !== XbPageVariant::MAIN_CONTENT_REGION) {
          // Check access to regions if any component was added or removed from them.
          if (!$regions[$region['id']]->access('edit')) {
            throw new AccessDeniedHttpException(sprintf('Access denied for region %s', $region['id']));
          }
        }
      }
    }
    return new PreviewEnvelope($this->buildPreviewRenderable($body, $entity, TRUE), [
      'autoSaves' => $this->getAutoSaveHashes($entity),
    ]);
  }

  private function buildPreviewRenderable(array $body, EntityInterface $entity, bool $updateAutoSave): array {
    ['layout' => $layout, 'model' => $model] = $body;

    $page_regions = PageRegion::loadForActiveThemeByClientSideId();
    foreach ($layout as $region_node) {
      $client_side_region_id = $region_node['id'];
      if ($client_side_region_id === XbPageVariant::MAIN_CONTENT_REGION) {
        $content = $region_node;
      }
      // Save the global region if it has a corresponding enabled PageRegion.
      elseif ($updateAutoSave && array_key_exists($client_side_region_id, $page_regions)) {
        \assert($page_regions[$client_side_region_id] instanceof PageRegion);
        $page_region = $page_regions[$client_side_region_id]->forAutoSaveData([
          'layout' => $region_node['components'],
          'model' => self::extractModelForSubtree($region_node, (array) $model),
        ], validate: FALSE);
        $this->autoSaveManager->saveEntity($page_region);
      }
    }

    assert(isset($content));
    \assert($entity instanceof FieldableEntityInterface);
    $this->converter->convert([
      'layout' => $content,
      // An empty model needs to be represented as \stdClass so that it is
      // correctly json encoded. But we need to convert it to an array before
      // we can extract it.
      'model' => (array) $model,
      // If we are not auto-saving there is no reason to convert the
      // 'entity_form_fields'. This can cause access issue for just viewing the
      // preview. This runs the conversion as if the user had no access to edit
      // the entity fields which is all the that is necessary when not
      // auto-saving.
      'entity_form_fields' => $updateAutoSave ? $body['entity_form_fields'] : [],
    ], $entity, validate: FALSE);
    // Store the auto-save entry.
    if ($updateAutoSave) {
      $this->autoSaveManager->saveEntity($entity);
    }
    $renderable = $this->componentTreeLoader->load($entity)->toRenderable($entity, TRUE);

    $build = [];
    if (isset($renderable[ComponentTreeItemList::ROOT_UUID])) {
      $build = $renderable[ComponentTreeItemList::ROOT_UUID];
    }

    $build['#prefix'] = !empty($build)
      ? Markup::create('<!-- xb-region-start-content -->')
      : Markup::create('<!-- xb-region-start-content --><div class="xb--region-empty-placeholder"></div>');
    $build['#suffix'] = Markup::create('<!-- xb-region-end-content -->');
    $build['#attached']['library'][] = 'experience_builder/preview';
    return $build;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return string
   */
  public function getLabel(EntityInterface $entity): string {
    // Get title from auto saved data if available.
    $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($entity);
    if (!$autoSaveData->isEmpty()) {
      \assert($autoSaveData->entity instanceof EntityInterface);
      return (string) $autoSaveData->entity->label();
    }
    return (string) $entity->label();
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

  /**
   * Get last stored data, taking auto-saved data into account if any.
   */
  private function getLastStoredData(EntityInterface $entity, bool $includeAllRegions = FALSE): array {
    assert($entity instanceof FieldableEntityInterface);
    $data = NULL;
    $build_entity = $entity;
    $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($entity);
    if (!$autoSaveData->isEmpty()) {
      // There are no changes (everything is published), read back the original
      // model.
      \assert($autoSaveData->entity instanceof FieldableEntityInterface);
      $build_entity = $autoSaveData->entity;
    }
    $data['model'] = [];
    $data['entity_form_fields'] = $this->getEntityData($build_entity);
    // Build the content region.
    $tree = $this->componentTreeLoader->load($build_entity);
    $data['layout'] = [$this->buildRegion(XbPageVariant::MAIN_CONTENT_REGION, $tree, $data['model'])];
    assert(is_array($data));
    assert(is_array($data['model']) && is_array($data['entity_form_fields']) && is_array($data['layout']));

    $regions = PageRegion::loadForActiveTheme();
    if (!empty($regions)) {
      $this->addGlobalRegions($regions, $data['model'], $data['layout'], $includeAllRegions);
      $layout_keyed_by_region = array_combine(array_map(static fn($region) => $region['id'], $data['layout']), $data['layout']);
      // Reorder the layout to match theme order.
      $data['layout'] = array_values(array_replace(
        array_intersect_key(array_flip($this->regionsClientSideIds), $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }
    return $data;
  }

  /**
   * @param LayoutClientStructureArray $layout
   * @param string $componentInstanceUuid
   * @return string|null
   */
  private function getRegionForComponentInstance(array $layout, string $componentInstanceUuid): ?string {
    foreach ($layout as $layout_region) {
      assert(count(array_intersect(['nodeType', 'id', 'name', 'components'], array_keys($layout_region))) === 4);
      assert($layout_region['nodeType'] === 'region');
      assert(is_array($layout_region['components']));
    }

    // Validate that we have access to the page region of this component.
    $regions = PageRegion::loadForActiveTheme();
    if (!empty($regions)) {
      $layout_by_client_side_ids = array_combine(array_map(static fn($region) => $region['id'], $layout), $layout);
      $regionForComponent = array_filter($layout_by_client_side_ids, function ($item) use ($componentInstanceUuid) {
        foreach ($item['components'] as $componentData) {
          if ($componentData['uuid'] === $componentInstanceUuid) {
            return TRUE;
          }
          // Maybe it's not a component, but a slot inside a component.
          foreach ($componentData['slots'] as $slotData) {
            foreach ($slotData['components'] as $slotComponentData) {
              if ($slotComponentData['uuid'] === $componentInstanceUuid) {
                return TRUE;
              }
            }
          }
        }
        return FALSE;
      });
    }
    return (!empty($regions) && count($regionForComponent) === 1) ? (string) key($regionForComponent) : NULL;
  }

  private function validateAutoSaves(EntityInterface $entity, array $body): void {
    // @todo Remove the special case for testing in https://drupal.org/i/3526907.
    // @phpstan-ignore-next-line
    if (!(drupal_valid_test_ua() && \Drupal::installProfile() !== 'nightwatch_testing')) {
      return;
    }
    if (!\array_key_exists('autoSaves', $body)) {
      throw new BadRequestHttpException('Missing autoSaves');
    }
    $autoSaves = $body['autoSaves'];
    $expected_auto_saves = [];
    $expected_auto_saves[AutoSaveManager::getAutoSaveKey($entity)] = $this->autoSaveManager->getAutoSaveEntity($entity)->hash;
    $regions = PageRegion::loadForActiveTheme();
    foreach ($regions as $region) {
      assert($region instanceof PageRegion);
      if ($region->access('edit') === FALSE) {
        continue;
      }
      $expected_auto_saves[AutoSaveManager::getAutoSaveKey($region)] = $this->autoSaveManager->getAutoSaveEntity($region)->hash;
    }
    $expected_auto_saves = array_filter($expected_auto_saves);
    ksort($expected_auto_saves);
    ksort($autoSaves);
    if ($expected_auto_saves !== $autoSaves) {
      throw new ConflictHttpException('You do not have the latest changes, please refresh your browser.');
    }
  }

  private function getAutoSaveHashes(FieldableEntityInterface $entity): array {
    // Collect entities that need auto-save hashes.
    $entities = [$entity];
    // Add the regions the user has access to edit.
    foreach (PageRegion::loadForActiveTheme() as $region) {
      if ($region->access('edit') !== FALSE) {
        $entities[] = $region;
      }
    }
    $autoSaveHashes = [];
    foreach ($entities as $entity) {
      \assert($entity instanceof EntityInterface);
      $autoSaveHashes[AutoSaveManager::getAutoSaveKey($entity)] = $this->autoSaveManager->getAutoSaveEntity($entity)->hash;
    }
    return array_filter($autoSaveHashes);
  }

}
