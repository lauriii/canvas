<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Render\PreviewEnvelope;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiLayoutController {

  use ClientServerConversionTrait;
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

  private function contentEntityIsConsideredNew(string $current_entity_label, EntityTypeInterface $entity_type): bool {
    return $current_entity_label == ApiContentControllers::defaultTitle($entity_type);
  }

  public function get(FieldableEntityInterface&EntityPublishedInterface $entity): PreviewEnvelope {
    $regions = PageRegion::loadForActiveTheme();

    $content_entity_type = $entity->getEntityType();
    $is_published = $entity->isPublished();

    $autoSaveData = $this->autoSaveManager->getAutoSaveData($entity);
    if (!$autoSaveData->isEmpty()) {
      $body = $autoSaveData->data;
      \assert(\is_array($body));
      ['layout' => $layout, 'model' => $model, 'entity_form_fields' => $entity_form_fields] = $body;
      $label_field_input_name = sprintf("%s[0][value]", $content_entity_type->getKey('label'));
      $is_new = $this->contentEntityIsConsideredNew($entity_form_fields[$label_field_input_name], $content_entity_type);
    }
    else {
      $model = [];
      $entity_form_fields = $this->getEntityData($entity);
      // Build the content region.
      $tree = $this->componentTreeLoader->load($entity);
      $content_layout = $this->buildRegion(XbPageVariant::MAIN_CONTENT_REGION, $tree, $model);
      $layout = [$content_layout];
      $is_new = $this->contentEntityIsConsideredNew((string) $entity->label(), $content_entity_type);
      // Remember the initial client-side representation of this XB-enabled
      // content entity (prior to auto-saves existing), to allow detecting when
      // an auto-save request from the client should actually be stored (i.e.
      // when changes are detected).
      $this->autoSaveManager->recordInitialClientSideRepresentation($entity, [
        'layout' => [$content_layout],
        'model' => self::extractModelForSubtree($content_layout, $model),
        'entity_form_fields' => $entity_form_fields,
      ]);
    }

    if ($regions) {
      // Also remember the initial client-side representation of (editable)
      // PageRegions without prior auto-saves existing.
      foreach ($regions as $id => $region) {
        assert($region instanceof PageRegion);
        assert($region->status() === TRUE);
        if ($this->autoSaveManager->getAutoSaveData($region)->isEmpty()) {
          $region_model = [];
          $region_layout = $this->buildRegion($id, $region->getComponentTree(), $region_model);
          $this->autoSaveManager->recordInitialClientSideRepresentation($region, [
            'layout' => $region_layout['components'],
            'model' => $region_model,
          ]);
        }
      }
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
    ];
    return new PreviewEnvelope($this->buildPreviewRenderable($data, $entity, FALSE), $data);
  }

  /**
   * @todo Follow up issue to extract this logic into a trait: https://www.drupal.org/project/experience_builder/issues/3499632
   */
  private function buildRegion(string $id, ?ComponentTreeItem $item = NULL, ?array &$model = NULL): array {
    if ($item) {
      $decoded_tree = json_decode($item->get('tree')->getValue(), TRUE);
      $components = $this->buildLayout($model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID]);
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

  /**
   * @todo Follow up issue to extract this logic into a trait: https://www.drupal.org/project/experience_builder/issues /3499632
   */
  private function buildLayout(array &$model, ComponentTreeItem $item, array $tree_tier): array {
    $layout = [];
    $tree = $item->get('tree');
    $full_tree = json_decode($tree->getValue(), TRUE);
    foreach ($tree_tier as ['uuid' => $component_instance_uuid, 'component' => $component_type]) {
      $component_instance = [
        'nodeType' => 'component',
        'uuid' => $component_instance_uuid,
        'type' => $component_type,
        'slots' => [],
      ];

      // Use ComponentSourceInterface::inputToClientModel() to map the server-
      // stored `inputs` data to the client-side `model`.
      // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentInputs
      // @see SimpleComponent type-script definition.
      // @see ComponentModel type-script definition.
      // @see PropSourceComponent type-script definition.
      // @see EvaluatedComponentModel type-script definition.
      $source = $tree->getComponentSource($component_instance_uuid);
      \assert($source instanceof ComponentSourceInterface);
      if ($source->requiresExplicitInput()) {
        $model[$component_instance_uuid] = $source->inputToClientModel($source->getExplicitInput($component_instance_uuid, $item));
      }

      if (isset($full_tree[$component_instance_uuid])) {
        foreach ($full_tree[$component_instance_uuid] as $slot_name => $slot_children) {
          $component_instance['slots'][] = [
            'nodeType' => 'slot',
            'id' => $component_instance_uuid . '/' . $slot_name,
            'name' => $slot_name,
            'components' => $this->buildLayout($model, $item, $slot_children),
          ];
        }
      }
      $layout[] = $component_instance;
    }
    return $layout;
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
    // Collapse form values into the respective element name, e.g.
    // ['title' => ['value' => 'Node title']] becomes
    // ['title[0][value]' => 'Node title'. This keeps the data sent in the same
    // shape as the 'name' attributes on each of the form elements built by the
    // form element and avoids needing to smooth out the idiosyncrasies of each
    // widget's structure.
    // @see \Drupal\experience_builder\Controller\EntityFormController::form
    return Query::parse(\http_build_query(\array_intersect_key($values, $entity->toArray())));
  }

  private function addGlobalRegions(array $regions, array &$model, array &$layout): void {
    // Only expose regions marked as editable in the `layout` for the client.
    foreach ($regions as $id => $region) {
      assert($region instanceof PageRegion);
      assert($region->status() === TRUE);
      // Use auto-save data for each PageRegion config entity if available.
      if ($draft_region = $this->autoSaveManager->getAutoSaveData($region)->data) {
        $layout[] = [
          'nodeType' => 'region',
          'id' => $this->regionsClientSideIds[$id],
          'name' => $this->regions[$id],
          'components' => $draft_region['layout'],
        ];
        $model += $draft_region['model'];
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
      'componentType' => $componentType,
      'model' => $model,
    ] = $body;

    $data = $this->autoSaveManager->getAutoSaveData($entity)->data;
    if ($data === NULL) {
      // There are no changes (everything is published), read back the original
      // model.
      $data['model'] = [];
      $data['entity_form_fields'] = $this->getEntityData($entity);
      // Build the content region.
      $tree = $this->componentTreeLoader->load($entity);
      $data['layout'] = [$this->buildRegion(XbPageVariant::MAIN_CONTENT_REGION, $tree, $data['model'])];
    }
    $regions = PageRegion::loadForActiveTheme();
    if (!empty($regions)) {
      $this->addGlobalRegions($regions, $data['model'], $data['layout']);
      $layout_keyed_by_region = array_combine(array_map(static fn($region) => $region['id'], $data['layout']), $data['layout']);
      // Reorder the layout to match theme order.
      $data['layout'] = array_values(array_replace(
        array_intersect_key(array_flip($this->regionsClientSideIds), $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }
    if (!\array_key_exists('model', $data)) {
      throw new NotFoundHttpException('Missing model');
    }
    if (!\array_key_exists($componentInstanceUuid, $data['model'])) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
    }
    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($componentType);
    \assert($component instanceof Component || $component === NULL);
    if ($component === NULL) {
      throw new NotFoundHttpException('No such component: ' . $componentType);
    }
    \assert($entity instanceof FieldableEntityInterface);

    $data['model'][$componentInstanceUuid] = $model;
    return new PreviewEnvelope($this->buildPreviewRenderable($data, $entity, TRUE), $data);
  }

  /**
   * POST request returns a preview, but does not update any stored data.
   *
   * @todo Remove this in https://drupal.org/i/3492065
   */
  public function post(Request $request, EntityInterface $entity): PreviewEnvelope {
    $body = json_decode($request->getContent(), TRUE);
    \assert(\array_key_exists('model', $body));
    \assert(\array_key_exists('layout', $body));
    \assert(\array_key_exists('entity_form_fields', $body));
    return new PreviewEnvelope($this->buildPreviewRenderable($body, $entity, TRUE));
  }

  private function buildPreviewRenderable(array $body, EntityInterface $entity, bool $updateAutoSave): array {
    ['layout' => $layout, 'model' => $model] = $body;

    $page_regions = PageRegion::loadForActiveTheme();
    $page_regions = array_combine(
      array_map(fn (PageRegion $r) => $r->get('region'), $page_regions),
      $page_regions,
    );
    foreach ($layout as $region_node) {
      $client_side_region_id = $region_node['id'];
      if ($client_side_region_id === XbPageVariant::MAIN_CONTENT_REGION) {
        $content = $region_node;
      }
      // Save the global region if it has a corresponding enabled PageRegion.
      elseif (array_key_exists($client_side_region_id, $page_regions)) {
        $page_region = $page_regions[$client_side_region_id];
        $this->autoSaveManager->save($page_region, [
          'layout' => $region_node['components'],
          'model' => self::extractModelForSubtree($region_node, (array) $model),
        ]);
      }
    }

    assert(isset($content));
    \assert($entity instanceof FieldableEntityInterface);
    $updated_entity_form_fields = $this->converter->convert([
      'layout' => $content,
      // An empty model needs to be represented as \stdClass so that it is
      // correctly json encoded. But we need to convert it to an array before
      // we can extract it.
      'model' => (array) $model,
      'entity_form_fields' => $body['entity_form_fields'],
    ], $entity, validate: FALSE);
    // Store the auto-save entry.
    if ($updateAutoSave) {
      $this->autoSaveManager->save($entity, [
        'layout' => [$content],
        // An empty model needs to be represented as \stdClass so that it is
        // correctly json encoded. But we need to convert it to an array before
        // we can extract it.
        'model' => self::extractModelForSubtree($content, (array) $model),
        // Store the updated form build ID but leave all other fields as-is.
        // This allows us to re-submit the values from auto-save when we finally
        // publish the entity. Some field widgets make transformations to the form
        // data which cannot be repeated.
        // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase::validateElement
        'entity_form_fields' => \array_filter([
          'form_build_id' => $updated_entity_form_fields['form_build_id'] ?? NULL,
        ]) + $body['entity_form_fields'],
      ]);
    }
    $renderable = $this->componentTreeLoader->load($entity)->toRenderable(TRUE);

    if (isset($renderable[ComponentTreeStructure::ROOT_UUID])) {
      $build = $renderable[ComponentTreeStructure::ROOT_UUID];
    }

    $build['#prefix'] = Markup::create('<!-- xb-region-start-content -->');
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

}
