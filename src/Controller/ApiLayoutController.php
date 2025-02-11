<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\InternalXbFieldNameResolver;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Render\PreviewEnvelope;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiLayoutController {

  use ClientServerConversionTrait;
  use EntityFormTrait;

  private array $regions;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ClientDataToEntityConverter $converter,
  ) {}

  public function get(FieldableEntityInterface $entity): JsonResponse {
    $template = PageTemplate::forActiveTheme();
    $theme = $this->themeManager->getActiveTheme()->getName();
    $this->regions = system_region_list($theme);

    // Ensure the Content region always exists.
    $this->regions['content'] ??= t('Content');

    if ($body = $this->autoSaveManager->getAutoSaveData($entity)->data) {
      ['layout' => $layout, 'model' => $model, 'entity_form_fields' => $entity_form_fields] = $body;
    }
    else {
      $model = [];
      $entity_form_fields = $this->getEntityData($entity);
      // Build the content region.
      $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
      $tree = $entity->get($field_name)->first();
      assert($tree instanceof ComponentTreeItem);
      $layout = [$this->buildRegion('content', $tree, $model)];
    }

    if ($template) {
      $this->addGlobalRegions($template, $model, $layout);
      $layout_keyed_by_region = array_combine(array_map(static fn($region) => $region['id'], $layout), $layout);
      // Reorder the layout to match theme order.
      $layout = array_values(array_replace(
        array_intersect_key($this->regions, $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }

    return new JsonResponse([
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
    ]);
  }

  /**
   * @todo Follow up issue to extract this logic into a trait: https://www.drupal.org/project/experience_builder/issues/3499632
   */
  private function buildRegion(string $id, ?ComponentTreeItem $item = NULL, ?array &$model = NULL): array {
    if ($item) {
      $tree = $item->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $decoded_tree = json_decode($tree->getValue(), TRUE);
      $components = $this->buildLayout($model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID]);
    }
    else {
      $components = [];
    }

    return [
      'nodeType' => 'region',
      'id' => $id,
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
    assert($tree instanceof ComponentTreeStructure);
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

  private static function filterFormValues(array $values, array $form): array {
    foreach (Element::children($form) as $child) {
      $element = $form[$child];
      $values = self::filterFormValues($values, $element);

      if (isset($element['#access']) && $element['#access'] === FALSE) {
        NestedArray::unsetValue($values, $element['#parents']);
      }
    }

    return $values;
  }

  private function addGlobalRegions(PageTemplate $template, array &$model, array &$layout): void {
    $active_component_trees = iterator_to_array($template->getComponentTrees());
    // Only expose regions marked as editable in the `layout` for the client.
    $editable_regions = $template->getEditableRegions();

    $draft_template = $this->autoSaveManager->getAutoSaveData($template)->data;
    if ($draft_template === NULL) {
      foreach ($editable_regions as $region) {
        if ($region === 'content') {
          continue;
        }
        $layout[] = $this->buildRegion($region, $active_component_trees[$region] ?? NULL, $model);
      }
      return;
    }

    // An auto-save may have occurred when a region was either editable or not,
    // and that may now have changed. Make sure it always matches the currently
    // editable regions.
    $draft_layout_region_nodes = array_filter($draft_template['layout'], fn (array $layout_node): bool => $layout_node['nodeType'] === 'region');
    $autosaved_regions = array_column($draft_layout_region_nodes, 'id');
    $missing_regions_in_auto_save = array_diff($editable_regions, $autosaved_regions);
    foreach ($missing_regions_in_auto_save as $region) {
      if ($region === 'content') {
        continue;
      }
      $layout[] = $this->buildRegion($region, $active_component_trees[$region] ?? NULL, $model);
    }
    $extraneous_regions_in_auto_save = array_diff($autosaved_regions, $editable_regions);
    foreach ($extraneous_regions_in_auto_save as $region) {
      foreach ($draft_template['layout'] as $index => $region_node) {
        if ($region_node['id'] === $region) {
          unset($draft_template['layout'][$index]);
          // @todo In principle, $model should be updated too, to omit inputs for components in the omitted regions. There's no consequences yet for not doing that though.
        }
      }
    }

    $layout = \array_merge($layout, $draft_template['layout']);
    $model += $draft_template['model'];
  }

  /**
   * PATCH request updates the autosaved model and returns a preview.
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

    $theme = $this->themeManager->getActiveTheme()->getName();
    $this->regions = system_region_list($theme);

    $data = $this->autoSaveManager->getAutoSaveData($entity)->data;
    if ($data === NULL) {
      // There are no changes (everything is published), read back the original
      // model.
      $data['model'] = [];
      $data['entity_form_fields'] = $this->getEntityData($entity);
      // Build the content region.
      $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
      $tree = $entity->get($field_name)->first();
      assert($tree instanceof ComponentTreeItem);
      $data['layout'] = [$this->buildRegion('content', $tree, $data['model'])];
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
    $template = PageTemplate::forActiveTheme();
    if ($template) {
      $this->addGlobalRegions($template, $model, $data['layout']);
      $layout_keyed_by_region = array_combine(array_map(static fn($region) => $region['id'], $data['layout']), $data['layout']);
      // Reorder the layout to match theme order.
      $data['layout'] = array_values(array_replace(
        array_intersect_key($this->regions, $layout_keyed_by_region),
        $layout_keyed_by_region
      ));
    }
    return new PreviewEnvelope($this->buildPreviewRenderable($data, $entity), $data);
  }

  /**
   * POST request returns a preview, but does not update any stored data.
   *
   * @todo Remove this in https://www.drupal.org/i/3492061
   */
  public function post(Request $request, EntityInterface $entity): PreviewEnvelope {
    $body = json_decode($request->getContent(), TRUE);
    \assert(\array_key_exists('model', $body));
    \assert(\array_key_exists('layout', $body));
    \assert(\array_key_exists('entity_form_fields', $body));
    return new PreviewEnvelope($this->buildPreviewRenderable($body, $entity));
  }

  private function buildPreviewRenderable(array $body, EntityInterface $entity): array {
    ['layout' => $layout, 'model' => $model] = $body;

    // Save the content region.
    // @todo Store model values for content vs global regions only with their
    // respective entities.
    // @see https://www.drupal.org/project/experience_builder/issues/3495598
    foreach ($layout as $key => $region) {
      if ($region['id'] === 'content') {
        $this->autoSaveManager->save($entity, [
          'layout' => [$region],
          'model' => $model,
          'entity_form_fields' => $body['entity_form_fields'],
        ]);
        $content = $region;
        unset($layout[$key]);
      }
    }

    // Save the global regions if the page template is active.
    if ($template = PageTemplate::forActiveTheme()) {
      $this->autoSaveManager->save($template, [
        'layout' => \array_values($layout),
        'model' => $model,
      ]);
    }

    assert(isset($content));
    \assert($entity instanceof FieldableEntityInterface);
    $this->converter->convert([
      'layout' => $content,
      'model' => $model,
      'entity_form_fields' => $body['entity_form_fields'],
    ], $entity, validate: FALSE);
    $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
    $item = $entity->get($field_name)->first();
    assert($item instanceof ComponentTreeItem);
    $renderable = $item->toRenderable();

    if (isset($renderable[ComponentTreeStructure::ROOT_UUID])) {
      $build = $renderable[ComponentTreeStructure::ROOT_UUID];
    }
    // @todo Remove/replace this in https://www.drupal.org/project/experience_builder/issues/3499364
    $build['#prefix'] = '<div data-xb-uuid="content" data-xb-region="content">';
    $build['#suffix'] = '</div>';
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

}
