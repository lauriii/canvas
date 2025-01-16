<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\InternalXbFieldNameResolver;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiLayoutController {

  use EntityFormTrait;

  private array $regions;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
  ) {}

  public function __invoke(FieldableEntityInterface $entity): JsonResponse {
    $template = PageTemplate::forActiveTheme();
    $theme = $this->themeManager->getActiveTheme()->getName();
    $this->regions = system_region_list($theme);

    // Ensure the Content region always exists.
    $this->regions['content'] ??= t('Content');

    if ($body = $this->autoSaveManager->getAutoSaveData($entity)) {
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
      // Maps to the `props` property of the XB field type,.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues
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
      $hydrated = $item->get('hydrated');
      assert($hydrated instanceof ComponentTreeHydrated);
      $decoded_tree = json_decode($tree->getValue(), TRUE);
      $components = $this->buildLayout($model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID], $hydrated->getValue()->getTree()[ComponentTreeStructure::ROOT_UUID]);
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
   * @todo Follow up issue to extract this logic into a trait: https://www.drupal.org/project/experience_builder/issues/3499632
   */
  private function buildLayout(array &$model, ComponentTreeItem $item, array $tree_tier, array $hydrated): array {
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
      if (isset($hydrated[$component_instance_uuid])) {
        // @todo This needs to be smarter than checking props or settings.
        // Fix in https://drupal.org/i/3494684.
        $model[$component_instance_uuid] = $hydrated[$component_instance_uuid]['props'] ?? $hydrated[$component_instance_uuid]['settings'];
      }
      if (isset($full_tree[$component_instance_uuid])) {
        foreach ($full_tree[$component_instance_uuid] as $slot_name => $slot_children) {
          $component_instance['slots'][] = [
            'nodeType' => 'slot',
            'id' => $component_instance_uuid . '/' . $slot_name,
            'name' => $slot_name,
            'components' => $this->buildLayout($model, $item, $slot_children, $hydrated[$component_instance_uuid]['slots'][$slot_name]),
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
    $form = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $form_state = $this->buildFormState($form, $entity, 'default');
    $this->formBuilder->buildForm($form, $form_state);
    // Collapse form values into the respective element name, e.g.
    // ['title' => ['value' => 'Node title']] becomes
    // ['title[0][value]' => 'Node title'. This keeps the data sent in the same
    // shape as the 'name' attributes on each of the form elements built by the
    // form element and avoids needing to smooth out the idiosyncrasies of each
    // widget's structure.
    // @see \Drupal\experience_builder\Controller\EntityFormController::form
    return Query::parse(\http_build_query(\array_intersect_key($form_state->getValues(), $entity->toArray())));
  }

  private function addGlobalRegions(PageTemplate $template, array &$model, array &$layout): void {
    $active_component_trees = iterator_to_array($template->getComponentTrees());
    // Only expose regions marked as editable in the `layout` for the client.
    $editable_regions = $template->getEditableRegions();

    $draft_template = $this->autoSaveManager->getAutoSaveData($template);
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
          // @todo In principle, $model should be updated too, to omit props for components in the omitted regions. There's no consequences yet for not doing that though.
        }
      }
    }

    $layout = \array_merge($layout, $draft_template['layout']);
    $model += $draft_template['model'];
  }

}
