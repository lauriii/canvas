<?php

namespace Drupal\canvas_ai;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;
use Drupal\Component\Utility\DiffArray;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;

/**
 * Provides helper methods for AI page builder.
 */
class CanvasAiPageBuilderHelper {

  use StringTranslationTrait;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentPluginManager
   *   The component plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The HTTP kernel.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The stack of requests.
   * @param \Drupal\Component\Uuid\UuidInterface $uuidService
   *   The UUID service.
   * @param \Drupal\canvas_ai\CanvasAiTempStore $canvasAiTempstore
   *   The Canvas AI tempstore.
   */
  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly HttpKernelInterface $httpKernel,
    private readonly RequestStack $requestStack,
    private readonly UuidInterface $uuidService,
    private readonly CanvasAiTempStore $canvasAiTempstore,
  ) {
  }

  /**
   * Gets the data of all the usable component entities.
   *
   * The output will be used as the context for the AI agent.
   */
  public function getComponentContextForAi(): string {
    $component_context = [];
    $component_context_from_config = $this->getComponentContextFromConfig();
    $available_components = !empty($component_context_from_config) ? $component_context_from_config : $this->getAllComponentsKeyedBySource();
    foreach ($available_components as $components) {
      // Component info would be under 'components' key, when not loaded from
      // config.
      if (isset($components['components'])) {
        $component_context += $components['components'];
      }
      else {
        $component_context += $components;
      }

    }
    return Yaml::dump($component_context, 4, 2);
  }

  /**
   * Converts a YAML string to an array format with calculated nodePaths.
   *
   * @param string $yaml_string
   *   The YAML string to convert.
   *
   * @return array
   *   Structured array with calculated nodePaths for components.
   */
  public function customYamlToArrayMapper(string $yaml_string): array {
    $result = [
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [],
        ],
      ],
    ];
    $parsed_yaml = Yaml::parse($yaml_string);
    $parsed_yaml = \is_array($parsed_yaml) ? $parsed_yaml : [];
    // Add UUIDs to all components in the page builder output, so that their
    // nodePaths can be extracted later from the expected layout.
    $data_to_process = $this->addUuidToAllComponents($parsed_yaml);

    $current_layout = $this->canvasAiTempstore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY) ?? '';
    $current_layout = Json::decode($current_layout);
    $current_layout = \is_array($current_layout) ? $current_layout : [];

    // Create the final layout structure by adding the components at the expected
    // positions in the layout.
    $predicted_layout = $this->createExpectedPageLayout($current_layout, $data_to_process);

    // Get the nodePaths of newly added components from the predicted layout.
    // Then append them to the result.
    foreach ($data_to_process['operations'] as $operation) {
      $target = strpos($operation['target'], '/') === FALSE ? $operation['target'] : NULL;
      $this->appendComponentsRecursive($operation['components'], $predicted_layout, $target, $result['operations'][0]['components']);
    }

    return $result;
  }

  /**
   * Creates the expected output structure for each component.
   *
   * @param array $components
   *   The array of components to process.
   * @param array $predicted_layout
   *   The predicted layout array used for nodePath calculation.
   * @param string|null $target
   *   The target region, if any.
   * @param array &$result_components
   *   Reference to array where processed components are collected.
   */
  protected function appendComponentsRecursive(array $components, array $predicted_layout, ?string $target, array &$result_components): void {
    foreach ($components as $component) {
      foreach ($component as $id => $component_data) {
        // Process the current component.
        $component_data_to_append = [];
        // Get the nodePath of the component from the predicted layout, using
        // the uuid.
        $node_path = $this->getCalculatedNodepath($predicted_layout, $component_data['uuid'], $target);
        $component_data_to_append['id'] = $id;
        $component_data_to_append['nodePath'] = $node_path;
        $component_data_to_append['fieldValues'] = $component_data['props'] ?? [];
        $result_components[] = $component_data_to_append;

        // Recursively process any components in slots.
        if (!empty($component_data['slots'])) {
          foreach ($component_data['slots'] as $slot_components) {
            if (is_array($slot_components)) {
              $this->appendComponentsRecursive($slot_components, $predicted_layout, $target, $result_components);
            }
          }
        }
      }
    }
  }

  /**
   * Process components for 'below' placement.
   *
   * @param array $components
   *   The components to process.
   * @param array $reference_path
   *   The reference nodePath.
   * @param array &$result_components
   *   The array to store processed components.
   */
  protected function processComponentsBelow(array $components, array $reference_path, array &$result_components): void {
    $first_node_path = $reference_path;
    $first_node_path[count($first_node_path) - 1]++;

    $this->processComponents($components, $first_node_path, $result_components);
  }

  /**
   * Process component slots recursively.
   *
   * @param array $slots
   *   The slots to process.
   * @param array $parent_node_path
   *   The parent component's nodePath.
   * @param array &$result_components
   *   The array to store processed components.
   * @param string $component_id
   *   The component ID for the component having this slot.
   */
  protected function processSlots(array $slots, array $parent_node_path, array &$result_components, $component_id): void {

    foreach ($slots as $slot_name => $slot_components) {
      if (!is_array($slot_components)) {
        continue;
      }

      $slot_index = $this->getSlotIndexFromSlotName($slot_name, $component_id);

      foreach ($slot_components as $component_index => $component) {
        foreach ($component as $component_type => $component_data) {
          $node_path = $parent_node_path;
          $node_path[] = $slot_index;
          $node_path[] = $component_index;

          $component_structure = [
            'id' => $component_type,
            'nodePath' => $node_path,
            'fieldValues' => $component_data['props'] ?? [],
          ];

          $result_components[] = $component_structure;

          if (isset($component_data['slots'])) {
            $this->processSlots($component_data['slots'], $node_path, $result_components, $component_type);
          }
        }
      }
    }
  }

  /**
   * Process components and calculate nodePaths.
   *
   * @param array $components
   *   Components to process.
   * @param array $first_node_path
   *   First component's nodePath.
   * @param array &$result_components
   *   Array to store results.
   */
  protected function processComponents(array $components, array $first_node_path, array &$result_components): void {
    $current_node_path = $first_node_path;

    foreach ($components as $component) {
      foreach ($component as $component_type => $component_data) {
        $component_structure = [
          'id' => $component_type,
          'nodePath' => $current_node_path,
          'fieldValues' => $component_data['props'] ?? [],
        ];

        $result_components[] = $component_structure;

        if (isset($component_data['slots'])) {
          $this->processSlots($component_data['slots'], $current_node_path, $result_components, $component_type);
        }

        $current_node_path[count($current_node_path) - 1]++;
      }
    }
  }

  /**
   * Gets all the component entities keyed by source plugin id.
   *
   * @return array
   *   The components keyed by source.
   */
  public function getAllComponentsKeyedBySource(): array {
    $output = [];
    $current_request = $this->requestStack->getCurrentRequest();
    $sub_request = Request::create(
      Url::fromRoute('canvas.api.config.list', ['canvas_config_entity_type_id' => Component::ENTITY_TYPE_ID])->toString(),
      'GET',
      [],
      $current_request?->cookies->all() ?? [],
      [],
      $current_request?->server->all() ?? []
    );
    $sub_request->attributes->set('_format', 'json');
    try {
      $available_components_response = $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST);
      $available_components = (string) $available_components_response->getContent();
      $available_components = Json::decode($available_components);
    }
    catch (\Exception) {
      return [];
    }
    if (empty($available_components)) {
      return [];
    }

    /** @var \Drupal\canvas\Entity\Component[] $component_entities */
    $component_entities = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->loadMultiple(array_keys($available_components));
    $sdc_definitions = $this->componentPluginManager->getDefinitions();

    foreach ($component_entities as $component) {
      $source = $component->getComponentSource()->getPluginId();
      $source_label = (string) $component->getComponentSource()->getPluginDefinition()['label'];
      if (empty($source_label)) {
        $source_label = $source;
      }
      $output[$source]['label'] = $source_label;
      $component_id = $component->id();

      if ($source === SingleDirectoryComponent::SOURCE_PLUGIN_ID) {
        $this->processSdc($component, $sdc_definitions, $output);
      }
      elseif ($source === JsComponent::SOURCE_PLUGIN_ID) {
        $this->processCodeComponents($component, $output, $available_components[$component_id]);
      }
      else {
        // Other sources: id, name, description (description = name)
        $output[$source]['components'][$component_id] = [
          'id' => $component_id,
          'name' => $component->label(),
          'description' => $component->label(),
        ];
      }
    }
    return $output;
  }

  /**
   * Gets the component context from the config.
   *
   * @return array
   *   The component context array.
   */
  public function getComponentContextFromConfig(): array {
    $config = $this->configFactory->get('canvas_ai.component_description.settings');
    $component_context = $config->get('component_context');

    if (empty($component_context)) {
      return [];
    }

    // Refresh the config to ensure it has the latest components.
    $this->refreshComponentContext($component_context);

    // Provide only the components from enabled sources.
    foreach ($component_context as $source => $components) {
      if ($components['enabled']) {
        $enabled_sources[$source] = Yaml::parse($components['data']);
      }
    }

    return $enabled_sources ?? [];
  }

  /**
   * Updates the component context in the config, if there are changes.
   *
   * @param array $component_context
   *   The component context array loaded from the config.
   */
  private function refreshComponentContext(array &$component_context): void {
    // Update the config with the data of newly added/removed components.
    $latest_components = $this->getAllComponentsKeyedBySource();
    $resave_config = FALSE;
    $has_changes = FALSE;

    foreach ($component_context as $source => &$source_info) {
      $source_components_in_config = $source_info['data'] ?? [];
      $source_components_in_config = Yaml::parse($source_components_in_config);
      $latest_components_under_source = $latest_components[$source]['components'] ?? [];
      // Remove components that are not in the latest components.
      $new_config = array_intersect_key($source_components_in_config, $latest_components_under_source);
      // Add new components that are in the latest components but not in the config.
      $new_config += array_diff_key($latest_components_under_source, $new_config);
      // Refresh the props and slots for the components.
      $has_changes = $this->refreshPropsAndSlots($new_config, $latest_components_under_source);
      // Save the changes if there were differences.
      if (array_diff_key($new_config, $source_components_in_config) || array_diff_key($source_components_in_config, $new_config) || $has_changes) {
        $resave_config = TRUE;
        $source_components_in_config = $new_config;
        // Update the source info with the latest components.
        $source_info['data'] = Yaml::dump($source_components_in_config);
      }
    }

    // Save the updated component context to the config only if there were changes.
    if ($resave_config) {
      $this->configFactory->getEditable('canvas_ai.component_description.settings')
        ->set('component_context', $component_context)
        ->save();
    }
  }

  /**
   * Refreshes the props and slots for the components.
   *
   * @param array $new_config
   *   The new config with the latest components.
   * @param array $latest_components_under_source
   *   The latest components under the source.
   *
   * @return bool
   *   Returns TRUE if there were changes, FALSE otherwise.
   */
  private function refreshPropsAndSlots(array &$new_config, array $latest_components_under_source): bool {
    $has_changes = FALSE;

    foreach ($new_config as $component_id => &$component_data) {

      // Refresh component props.
      if (isset($component_data['props'])) {
        // Check if any new props have been added or existing props have been modified.
        $previous_props = is_array($component_data['props']) ? $component_data['props'] : [];
        $current_props = is_array($latest_components_under_source[$component_id]['props']) ? $latest_components_under_source[$component_id]['props'] : [];

        if (array_keys($previous_props) != array_keys($current_props)) {
          // If the keys of the previous props and current props are different,
          // then there are changes.
          $has_changes = TRUE;
        }

        foreach ($current_props as $prop_name => &$prop_details) {

          // Check if its a new prop.
          if (!isset($previous_props[$prop_name])) {
            continue;
          }

          if (isset($previous_props[$prop_name]) && isset($previous_props[$prop_name]['description'])) {
            // If a description exists in the config for a prop, use that.
            $prop_details['description'] = $previous_props[$prop_name]['description'];
          }

          // Check if any other data of the prop have been modified.
          // Eg: Change in type, default value, enums, etc.
          $previous_prop_data_without_description = array_diff_key($previous_props[$prop_name], ['description' => TRUE]);
          $current_prop_data_without_description = array_diff_key($prop_details, ['description' => TRUE]);
          $differences = DiffArray::diffAssocRecursive($previous_prop_data_without_description, $current_prop_data_without_description);
          $differences += DiffArray::diffAssocRecursive($current_prop_data_without_description, $previous_prop_data_without_description);
          // If there are differences, set has_changes to TRUE.
          if (!empty($differences)) {
            $has_changes = TRUE;
          }
        }
        $component_data['props'] = !empty($current_props) ? $current_props : 'No props';
      }

      // Refresh component slots.
      if (isset($component_data['slots'])) {
        // Check if any new slots have been added or existing slots have been modified.
        $previous_slots = is_array($component_data['slots']) ? $component_data['slots'] : [];
        $current_slots = is_array($latest_components_under_source[$component_id]['slots']) ? $latest_components_under_source[$component_id]['slots'] : [];

        if (array_keys($previous_slots) != array_keys($current_slots)) {
          // If the keys of the previous slots and current slots are different,
          // then there are changes.
          $has_changes = TRUE;
        }

        foreach ($current_slots as $slot_name => &$slot_details) {
          // Check if its a new slot.
          if (!isset($previous_slots[$slot_name])) {
            continue;
          }

          if (isset($previous_slots[$slot_name]) && isset($previous_slots[$slot_name]['description'])) {
            // If a description exists in the config for a slot, use that.
            $slot_details['description'] = $previous_slots[$slot_name]['description'];
          }

          // Check if any other slots data have been modified.
          $previous_slot_data_without_description = array_diff_key($previous_slots[$slot_name], ['description' => TRUE]);
          $current_slot_data_without_description = array_diff_key($slot_details, ['description' => TRUE]);
          $differences = DiffArray::diffAssocRecursive($previous_slot_data_without_description, $current_slot_data_without_description);
          $differences += DiffArray::diffAssocRecursive($current_slot_data_without_description, $previous_slot_data_without_description);
          // If there are differences,.
          if (!empty($differences)) {
            $has_changes = TRUE;
          }
        }
        $component_data['slots'] = !empty($current_slots) ? $current_slots : 'No slots';
      }

    }
    return $has_changes;
  }

  /**
   * Create the context data for SDCs.
   *
   * @param \Drupal\canvas\Entity\Component $component
   *   The component entity.
   * @param array $sdc_definitions
   *   The SDC definitions.
   * @param array &$output
   *   The output array to store the SDC component data.
   */
  private function processSdc(Component $component, array $sdc_definitions, array &$output): void {
    $sdc_definition = $sdc_definitions[$component->get('source_local_id')];
    $component_id = $component->id();
    $source_id = SingleDirectoryComponent::SOURCE_PLUGIN_ID;
    $output[$source_id]['components'][$component_id] = [
      'id' => $component_id,
      'name' => $sdc_definition['name'],
      'description' => $sdc_definition['description'] ?? $sdc_definition['name'],
      'group' => $sdc_definition['group'] ?? '',
      'props' => 'No props',
      'slots' => 'No slots',
    ];
    // Get slots.
    $slots = $sdc_definition['slots'] ?? [];
    if ($slots) {
      $output[$source_id]['components'][$component_id]['slots'] = [];
      foreach ($slots as $slot => $details) {
        $output[$source_id]['components'][$component_id]['slots'][$slot] = [
          'name' => $details['title'] ?? $slot,
          'description' => $details['description'] ?? 'No description available',
        ];
      }
    }
    // Get props.
    $props = $sdc_definition['props']['properties'] ?? [];
    if ($props) {
      $client_normalized = $component->normalizeForClientSide()->values;
      $output[$source_id]['components'][$component_id]['props'] = [];
      foreach ($props as $prop_name => $prop_details) {
        if ($prop_name === 'attributes') {
          continue;
        }
        $output[$source_id]['components'][$component_id]['props'][$prop_name] = [
          'name' => $prop_details['title'] ?? $prop_name,
          'description' => $prop_details['description'] ?? 'No description available',
          'type' => $prop_details['type'],
          'default' => $client_normalized["propSources"][$prop_name]["default_values"]["resolved"] ?? $prop_details['default'] ?? $prop_details['examples'][0] ?? NULL,
        ];

        // Mark required props.
        if (isset($sdc_definition['props']['required']) && in_array($prop_name, $sdc_definition['props']['required'], TRUE)) {
          $output[$source_id]['components'][$component_id]['props'][$prop_name]['required'] = TRUE;
        }
        if (isset($prop_details['enum'])) {
          $output[$source_id]['components'][$component_id]['props'][$prop_name]['enum'] = $prop_details['enum'];
        }
      }
    }
  }

  /**
   * Create the context data for JS components.
   *
   * @param \Drupal\canvas\Entity\Component $component
   *   The component entity.
   * @param array &$output
   *   The output array to store the JS component data.
   * @param array $component_data
   *   The component data array containing prop and slots metadata.
   */
  private function processCodeComponents(Component $component, &$output, array $component_data): void {
    $component_id = $component->id();
    $output[JsComponent::SOURCE_PLUGIN_ID]['components'][$component_id] = [
      'id' => $component_id,
      'name' => $component->label(),
      'description' => $component->label(),
    ];

    // Get the descriptions for props of the JS component.
    if (isset($component_data['propSources']) && is_array($component_data['propSources'])) {
      $output[JsComponent::SOURCE_PLUGIN_ID]['components'][$component_id]['props'] = [];
      foreach ($component_data['propSources'] as $prop_name => $prop_details) {
        $output[JsComponent::SOURCE_PLUGIN_ID]['components'][$component_id]['props'][$prop_name] = [
          'name' => $prop_name,
          // Keep the prop description as the prop name for as there is no
          // option to provide a description in the JS component.
          'description' => $prop_name,
          'type' => $prop_details['jsonSchema']['type'],
          'default' => $prop_details['default_values']['resolved'] ?? '',
          'format' => $prop_details['jsonSchema']['format'] ?? '',
          'enum' => $prop_details['jsonSchema']['enum'] ?? '',
        ];
      }
    }

    // Get the descriptions for slots of the JS component.
    if (isset($component_data['metadata']['slots']) && is_array($component_data['metadata']['slots'])) {
      $output[JsComponent::SOURCE_PLUGIN_ID]['components'][$component_id]['slots'] = [];
      foreach ($component_data['metadata']['slots'] as $slot_name => $slot_details) {
        $output[JsComponent::SOURCE_PLUGIN_ID]['components'][$component_id]['slots'][$slot_name] = [
          'name' => $slot_details['title'] ?? $slot_name,
          // Keep the slot description as the slot name for as there is no
          // option to provide a description in the JS component.
          'description' => $slot_name,
        ];
      }
    }
  }

  /**
   * Gets the index of a slot by its name for a given component ID.
   *
   * @param string $slot_name
   *   The name of the slot.
   * @param string $component_id
   *   The ID of component with this slot.
   *
   * @return int
   *   The index of the slot, or 0 if not found.
   */
  public function getSlotIndexFromSlotName(string $slot_name, string $component_id) : int {
    $component_context = $this->getAllComponentsKeyedBySource();
    if (empty($component_context)) {
      return 0;
    }

    foreach ($component_context as $source_info) {
      if (isset($source_info['components'][$component_id]['slots'][$slot_name])) {
        $index = array_search($slot_name, array_keys($source_info['components'][$component_id]['slots']), TRUE);
        return ($index === FALSE) ? 0 : (int) $index;
      }
    }
    return 0;
  }

  /**
   * Creates the expected page layout structure.
   *
   * @param array $current_layout
   *   The current layout structure.
   * @param array $page_builder_output
   *   The page builder output.
   *
   * @return array
   *   The expected page layout structure after adding the components at the
   *   expected positions.
   */
  public function createExpectedPageLayout(array $current_layout, array $page_builder_output) : array {
    // Convert the current layout to another format that is easier to process.
    $current_layout_tree = $this->convertCurrentLayoutToTree($current_layout);
    $modified_layout = $this->placeComponentsInLayout($current_layout_tree, $page_builder_output);
    return $modified_layout;
  }

  /**
   * Converts the current layout structure into a region-keyed UUID tree.
   *
   * @param array $data
   *   The layout array in the format described above.
   *
   * @return array
   *   A region-keyed tree that only contains UUIDs, preserving
   *   parent-child relationships per slot.
   */
  public function convertCurrentLayoutToTree(array $data): array {
    if (!isset($data['regions']) || !is_array($data['regions'])) {
      return [];
    }

    $result = [];
    foreach ($data['regions'] as $region => $region_data) {
      if (!is_array($region_data)) {
        continue;
      }

      $components = $region_data['components'] ?? [];
      if (!is_array($components)) {
        $components = [];
      }

      $result[$region] = $this->buildComponentUuidTree($components);
    }

    return $result;
  }

  /**
   * Builds a UUID-only tree for a list of components.
   *
   * @param array $components
   *   The components array at a given region or slot.
   *
   * @return array
   *   An associative array keyed by component UUID. Values are either an empty
   *   array (no slots) or an associative array keyed by slot name whose values
   *   are themselves UUID-keyed arrays of child components.
   */
  private function buildComponentUuidTree(array $components): array {
    $tree = [];

    foreach ($components as $component) {
      if (!is_array($component) || !isset($component['uuid'])) {
        continue;
      }

      $uuid = $component['uuid'];
      $children_by_slot = [];

      if (isset($component['slots']) && is_array($component['slots'])) {
        foreach ($component['slots'] as $slot_id => $slot_payload) {
          $slot_name = $this->extractSlotNameFromId($slot_id);
          $slot_components = [];
          if (is_array($slot_payload)) {
            $slot_components = $slot_payload['components'] ?? [];
          }
          $children_by_slot[$slot_name] = $this->buildComponentUuidTree(
            is_array($slot_components) ? $slot_components : []
          );
        }
      }

      $tree[$uuid] = $children_by_slot;
    }

    return $tree;
  }

  /**
   * Extracts the slot name from slot id.
   *
   * @param string $slot_id
   *   The slot id.
   *
   * @return string
   *   The extracted slot name.
   */
  private function extractSlotNameFromId(string $slot_id): string {
    if (strpos($slot_id, '/') !== FALSE) {
      $parts = explode('/', $slot_id);
      $candidate = end($parts);
      return $candidate === FALSE ? $slot_id : (string) $candidate;
    }
    return $slot_id;
  }

  /**
   * Adds a UUID to every component in the page builder output.
   *
   * @param array $page_builder_output
   *   The page builder output.
   *
   * @return array
   *   The page builder output with UUIDs added to all components.
   */
  public function addUuidToAllComponents(array $page_builder_output): array {
    if (!isset($page_builder_output['operations']) || !is_array($page_builder_output['operations'])) {
      return $page_builder_output;
    }

    foreach ($page_builder_output['operations'] as &$operation) {
      if (!isset($operation['components']) || !is_array($operation['components'])) {
        continue;
      }
      $this->assignUuidsRecursively($operation['components']);
    }

    return $page_builder_output;
  }

  /**
   * Recursively assigns UUIDs all the components.
   *
   * @param array $components
   *   The list of components to process.
   */
  private function assignUuidsRecursively(array &$components): void {
    foreach ($components as &$component_wrapper) {
      if (!is_array($component_wrapper)) {
        continue;
      }

      foreach ($component_wrapper as &$component_details) {
        if (!is_array($component_details)) {
          continue;
        }

        // Add UUID only if missing.
        if (empty($component_details['uuid']) || !is_string($component_details['uuid'])) {
          $component_details['uuid'] = $this->uuidService->generate();
        }

        // Recurse into slots if present.
        if (isset($component_details['slots']) && is_array($component_details['slots'])) {
          foreach ($component_details['slots'] as &$slot_components) {
            if (!is_array($slot_components)) {
              continue;
            }

            $this->assignUuidsRecursively($slot_components);
          }
        }
      }
    }
  }

  /**
   * Place the components in the layout.
   *
   * The page builder agent's output contains one or more operations, each
   * corresponding to the component(s) to be added to the layout. Each operation
   * has a target, placement, reference_uuid, and components. The target,
   * placement, and reference_uuid are used to determine the position of the
   * components in the layout.
   *
   * @param array $current_layout
   *   The current layout structure with regions and components.
   * @param array $operations
   *   Array of operations containing target, placement, and components.
   *
   * @return array
   *   Modified layout with components placed according to operations.
   */
  private function placeComponentsInLayout(array $current_layout, array $operations): array {
    $modified_layout = $current_layout;

    foreach ($operations['operations'] as $operation) {
      $target = $operation['target'];
      $placement = $operation['placement'];
      $components = $operation['components'];

      // Convert the components array to a tree structure. Output will have the
      // same structure as returned by convertCurrentLayoutToTree method.
      // This is done to make it easier to place the components in the layout to
      // create the expected final layout.
      $component_tree = $this->createInputComponentTree($components);

      if ($placement === 'inside') {
        // Placement inside is for adding components to an empty region or slot.
        $modified_layout = $this->placeComponentsInside($modified_layout, $target, $component_tree);
      }
      elseif ($placement === 'below' || $placement === 'above') {
        // Placement above or below is for adding components above or below
        // an existing component in the current layout.
        $reference_uuid = $operation['reference_uuid'];
        $modified_layout = $this->placeComponentsAboveOrBelow($modified_layout, $reference_uuid, $placement, $component_tree);
      }
    }

    return $modified_layout;
  }

  /**
   * Creates a component tree structure from the components array.
   *
   * This method converts the component array returned by the page builder
   * agent to the same structure as returned by convertCurrentLayoutToTree
   * method.
   *
   * @param array $components
   *   The components array returned by the page builder agent.
   *
   * @return array
   *   The component tree with UUIDs as keys and slots/nested components as
   *   values.
   */
  private function createInputComponentTree(array $components): array {
    $tree = [];

    foreach ($components as $component) {
      foreach ($component as $component_id => $component_data) {
        $uuid = $component_data['uuid'];
        $slots = $component_data['slots'] ?? [];

        // Initialize component entry.
        $tree[$uuid] = [];

        // Process slots if they exist.
        if (!empty($slots)) {
          foreach ($slots as $slot_name => $slot_components) {
            if (!empty($slot_components)) {
              $tree[$uuid][$slot_name]['slot_index'] = $this->getSlotIndexFromSlotName($slot_name, $component_id);
              // Recursively build nested component tree.
              $nested_tree = $this->createInputComponentTree($slot_components);
              $tree[$uuid][$slot_name]['components'] = $nested_tree;
            }
            else {
              $tree[$uuid][$slot_name] = [];
            }
          }
        }
      }
    }

    return $tree;
  }

  /**
   * Places components to an empty region or slot.
   *
   * @param array $current_layout
   *   The current layout structure.
   * @param string $target
   *   Target region name or slot ID (uuid/slot_name)
   * @param array $component_tree
   *   The component tree to place.
   *
   * @return array
   *   The modified layout.
   *
   * @throws \Exception
   *   If the component is not found.
   */
  private function placeComponentsInside(array $current_layout, string $target, array $component_tree): array {
    $modified_layout = $current_layout;

    // Check if target contains a slash (slot path)
    if (strpos($target, '/') !== FALSE) {
      [$parent_uuid, $slot_name] = explode('/', $target, 2);

      // Find the parent component and place in its slot.
      $path = $this->getPathFromUuid($modified_layout, $parent_uuid);
      if (empty($path)) {
        throw new \Exception(\sprintf('Component with UUID "%s" not found in layout', $parent_uuid));
      }
      $modified_layout = $this->insertComponentsAtSlot($modified_layout, $path, $slot_name, $component_tree);
    }
    else {
      // Target is a region name.
      if (isset($modified_layout[$target])) {
        // Add the component to the region.
        $modified_layout[$target] = array_merge($component_tree, $modified_layout[$target]);
      }
      else {
        throw new \Exception(\sprintf('Region "%s" not found in layout', $target));
      }
    }

    return $modified_layout;
  }

  /**
   * Places components above or below a reference component in the layout.
   *
   * @param array $current_layout
   *   The current layout structure.
   * @param string $reference_uuid
   *   UUID of the reference component.
   * @param string $above_or_below
   *   The placement type ('above' or 'below').
   * @param array $component_tree
   *   The component tree to place.
   *
   * @return array
   *   The modified layout.
   *
   * @throws \Exception
   *   If the component is not found.
   */
  private function placeComponentsAboveOrBelow(array $current_layout, string $reference_uuid, string $above_or_below, array $component_tree): array {
    $modified_layout = $current_layout;

    // Get path to the reference component.
    $path = $this->getPathFromUuid($modified_layout, $reference_uuid);
    if (empty($path)) {
      throw new \Exception(\sprintf('Component with UUID "%s" not found in layout', $reference_uuid));
    }

    $modified_layout = $this->insertComponents($modified_layout, $path, $component_tree, $above_or_below);

    return $modified_layout;
  }

  /**
   * Finds the path to a component by its UUID in the layout.
   *
   * Recursively searches through the layout structure to find a component
   * and returns the path as an array of keys.
   *
   * @param array $layout
   *   The layout structure to search.
   * @param string $target_uuid
   *   UUID of the component to find.
   * @param array $current_path
   *   The current path being built during recursion.
   *
   * @return array|null
   *   Path to the component or null if not found.
   */
  private function getPathFromUuid(array $layout, string $target_uuid, array $current_path = []): ?array {
    foreach ($layout as $key => $value) {
      $new_path = array_merge($current_path, [$key]);

      // Check if current key is the target UUID.
      if ($key === $target_uuid) {
        return $new_path;
      }

      // If value is an array, search recursively.
      if (is_array($value)) {
        $result = $this->getPathFromUuid($value, $target_uuid, $new_path);
        if ($result !== NULL) {
          return $result;
        }
      }
    }

    return NULL;
  }

  /**
   * Inserts components at a specific path in the layout.
   *
   * Takes a path array and inserts components relative to the component at that
   * path, based on the placement type.
   *
   * @param array $layout
   *   The current layout structure.
   * @param array $path
   *   The path to the reference component.
   * @param array $component_tree
   *   The component tree to insert.
   * @param string $placement
   *   The placement type ('above' or 'below').
   *
   * @return array
   *   The modified layout.
   */
  private function insertComponents(array $layout, array $path, array $component_tree, string $placement = 'above'): array {
    $modified_layout = $layout;
    // phpcs:ignore
    $reference = &$modified_layout;

    // Navigate to the parent of the target component.
    $parent_path = array_slice($path, 0, -1);
    foreach ($parent_path as $key) {
      $reference = &$reference[$key];
    }

    // Get the position of the reference component.
    $reference_key = end($path);
    $keys = array_keys($reference);
    $reference_position = array_search($reference_key, $keys, TRUE);

    if ($reference_position !== FALSE) {
      // Split the array at the reference position.
      if ($placement == 'above') {
        $before = array_slice($reference, 0, $reference_position, TRUE);
        $after = array_slice($reference, $reference_position, NULL, TRUE);
      }
      else {
        $before = array_slice($reference, 0, $reference_position + 1, TRUE);
        $after = array_slice($reference, $reference_position + 1, NULL, TRUE);
      }

      // Insert component tree between them.
      $reference = array_merge($before, $component_tree, $after);
    }

    return $modified_layout;
  }

  /**
   * Inserts components at a specific slot within a component.
   *
   * @param array $layout
   *   The current layout structure.
   * @param array $path
   *   The path to the parent component.
   * @param string $slot_name
   *   The name of the slot.
   * @param array $component_tree
   *   The component tree to insert.
   *
   * @throws \Exception
   *   If the slot is not found.
   *
   * @return array
   *   The modified layout.
   */
  private function insertComponentsAtSlot(array $layout, array $path, string $slot_name, array $component_tree): array {
    $modified_layout = $layout;
    // phpcs:ignore
    $reference = &$modified_layout;

    // Navigate to the target component.
    foreach ($path as $key) {
      $reference = &$reference[$key];
    }

    // Ensure slot exists.
    if (!isset($reference[$slot_name])) {
      throw new \Exception(\sprintf('Slot "%s" not found in path "%s"', $slot_name, implode('/', $path)));
    }

    // Insert components to the slot.
    $reference[$slot_name] = array_merge($component_tree, $reference[$slot_name]);

    return $modified_layout;
  }

  /**
   * Gets the nodePath of a component from the layout.
   *
   * @param array $layout
   *   The layout structure to search.
   * @param string $uuid
   *   The UUID of the component to find.
   * @param string|null $region
   *   (optional) Limit search to a region.
   *
   * @return array
   *   Returns [] if not found.
   */
  public function getCalculatedNodepath(array $layout, string $uuid, ?string $region = NULL): array {
    $findPath = function ($array, $uuid, $path = []) use (&$findPath) {
      $i = 0;
      foreach ($array as $key => $value) {
        if (isset($value['slot_index']) && !empty($value['components'])) {
          $currentPath = array_merge($path, [$value['slot_index']]);
          $value = $value['components'];
        }
        else {
          $currentPath = array_merge($path, [$i]);
        }

        if ($key === $uuid) {
          return $currentPath;
        }

        if (is_array($value) && !empty($value)) {
          $found = $findPath($value, $uuid, $currentPath);
          if (!empty($found)) {
            return $found;
          }
        }
        $i++;
      }
      return [];
    };

    // If region specified, only search there.
    if ($region !== NULL) {
      if (!isset($layout[$region])) {
        return [];
      }
      $path = $findPath($layout[$region], $uuid);
      if (!empty($path)) {
        $regionIndex = array_search($region, array_keys($layout), TRUE);
        if ($regionIndex !== FALSE) {
          array_unshift($path, $regionIndex);
        }
      }
      return $path;
    }

    // Otherwise search all regions.
    $regionIndex = 0;
    foreach ($layout as $regionArray) {
      $path = $findPath($regionArray, $uuid);
      if (!empty($path)) {
        // Prepend the region index when found.
        array_unshift($path, $regionIndex);
        return $path;
      }
      $regionIndex++;
    }

    return [];
  }

  /**
   * Checks whether a region or slot contains child components.
   *
   * @param string $target
   *   The region name or slot id to check.
   *
   * @return bool
   *   TRUE if the target has child components, FALSE otherwise.
   */
  public function hasChildComponents(string $target): bool {
    $current_layout = $this->canvasAiTempstore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY) ?? '';
    $current_layout = Json::decode($current_layout);
    $current_layout = is_array($current_layout) ? $current_layout : [];

    // Region case: no slash means region name.
    if (strpos($target, '/') === FALSE) {
      $region = $target;
      $components = $current_layout['regions'][$region]['components'] ?? [];
      return is_array($components) && !empty($components);
    }

    // Slot case: formatted as "parent_uuid/slot_name".
    [$parent_uuid, $slot_name] = explode('/', $target, 2);
    if (empty($parent_uuid) || empty($slot_name)) {
      return FALSE;
    }

    // Convert to UUID tree and locate the parent component path.
    $layout_tree = $this->convertCurrentLayoutToTree($current_layout);
    $path = $this->getPathFromUuid($layout_tree, $parent_uuid);
    if (empty($path)) {
      return FALSE;
    }

    // Traverse to the parent component's slots array in the tree.
    $node = $layout_tree;
    foreach ($path as $key) {
      if (!isset($node[$key]) || !is_array($node[$key])) {
        return FALSE;
      }
      $node = $node[$key];
    }

    // In the tree, slots are keyed by slot name and contain child components
    // keyed by their UUIDs. Non-empty means there are child components.
    if (!isset($node[$slot_name]) || !is_array($node[$slot_name])) {
      return FALSE;
    }

    return !empty($node[$slot_name]);
  }

  /**
   * Generate verbose context for Orchestrator.
   *
   * @param array $prompt
   *   Array containing context details.
   *
   * @return string
   *   Verbose context string.
   */
  public function generateVerboseContextForOrchestrator(array $prompt) : string {
    // Check if selected_component exists.
    if (!empty($prompt['selected_component'])) {
      return 'User is now in the code component editor, viewing a code component with id ' . $prompt['selected_component'];
    }

    if (empty($prompt['entity_type'])) {
      return 'User has not created any entities';
    }

    // If entity_type is node.
    if ($prompt['entity_type'] === 'node') {
      return 'The user is currently working on a \'node\' entity';
    }

    // If entity_type is canvas_page.
    if ($prompt['entity_type'] === 'canvas_page') {
      $has_active_component = !empty($prompt['active_component_uuid']) &&
        $prompt['active_component_uuid'] !== 'None';

      $base_message = 'The user is currently working on a canvas_page entity. ';

      if ($has_active_component) {
        $base_message .= 'User has selected a component in the page with uuid ' . $prompt['active_component_uuid'] . '. ';
      }
      else {
        $base_message .= 'User has not selected any particular component from the page. ';
      }

      // Add page title.
      if (empty($prompt['page_title']) || $prompt['page_title'] === 'Untitled page') {
        $base_message .= 'Page title is empty. GENERATE THE TITLE FOR THE PAGE. ';
      }
      else {
        $base_message .= 'Page title: ' . $prompt['page_title'] . '. ';
      }

      // Add page description.
      if (!empty($prompt['page_description'])) {
        $base_message .= 'Page description: ' . $prompt['page_description'];
      }
      else {
        $base_message .= 'Page description is empty. GENERATE THE DESCRIPTION FOR THE PAGE.';
      }

      return $base_message;
    }

    // For any other entity_type.
    return 'User has not created any entities';
  }

}
