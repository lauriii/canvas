<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of edit the component js function.
 */
#[FunctionCall(
  id: 'ai_agent:edit_component_js',
  function_name: 'ai_agent_edit_component_js',
  name: 'Edit javascript on components',
  description: 'This method allows you to edit the javascript on components.',
  group: 'modification_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'javascript' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Javascript"),
      description: new TranslatableMarkup("All the new javascript that should replace the old one."),
      required: TRUE
    ),
    'props_metadata' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Props"),
      description: new TranslatableMarkup("Metadata for props"),
      required: TRUE
    ),
    'slots_metadata' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Slots"),
      description: new TranslatableMarkup("Metadata for slots. A JSON array where each entry is an object with an 'id' (slot machine name), 'name' (human-readable title) and an optional 'example' (sample child markup)."),
      required: FALSE
    ),
    'component_machine_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component machine name"),
      description: new TranslatableMarkup("The machine name of the component to edit."),
      required: TRUE
    ),
    'selected_component_required_props' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup("Selected Component Required Props"),
      description: new TranslatableMarkup("The existing required props of the selected component."),
      required: FALSE
    ),
  ],
)]
final class EditComponentJs extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  use ConstraintPropertyPathTranslatorTrait;
  use AiGeneratedJsComponentPropsAndSlotsTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected LoggerInterface $logger;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface | static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->logger = $container->get(LoggerChannelFactoryInterface::class)->get('canvas_ai');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $machine_name = $this->getContextValue('component_machine_name');
      $js = $this->getContextValue('javascript');
      $props = $this->getContextValue('props_metadata');
      $slots = $this->getContextValue('slots_metadata') ?? '';
      $props_array = Json::decode($props);
      // Check if the component exists.
      /** @var \Drupal\canvas\Entity\JavaScriptComponent $component */
      $component = $this->entityTypeManager->getStorage('js_component')->load($machine_name);

      if (!$component) {
        throw new \Exception("Could not find component $machine_name.");
      }

      $existing_required_props = $this->getContextValue('selected_component_required_props');
      $transformed_props = [];
      $required_props = [];
      $explicitly_marked_required_props = [];
      if (\is_array($props_array)) {
        foreach ($props_array as $prop) {
          if (!empty($prop['id']) && !empty($prop['name']) && !empty($prop['type']) && !empty($prop['example'])) {
            $transformed = [
              'title' => $prop['name'],
              'type' => $prop['type'],
              'examples' => [$prop['example']],
            ];
            // 'contentMediaType' (with optional 'x-formatting-context') is what
            // marks a string prop as formatted/rich text in Canvas; it must be
            // preserved or the prop is stored as plain text.
            // @see \Drupal\canvas\PropShape\PropShape
            foreach (['format', '$ref', 'enum', 'contentMediaType', 'x-formatting-context'] as $optional) {
              if (isset($prop[$optional])) {
                $transformed[$optional] = $prop[$optional];
              }
            }
            $transformed_props[$prop['id']] = $transformed;

            if (isset($prop['required'])) {
              $explicitly_marked_required_props[$prop['id']] = $prop['required'];
              if ($prop['required'] === TRUE) {
                $required_props[] = $prop['id'];
              }
            }
          }
        }
      }

      // Retain existing required props that were not explicitly marked.
      if (!empty($existing_required_props)) {
        foreach ($existing_required_props as $existing_prop) {
          if (!isset($explicitly_marked_required_props[$existing_prop])) {
            $required_props[] = $existing_prop;
          }
        }
      }

      $required_props = array_unique($required_props);

      $transformed_slots = $this->transformSlotsMetadata($slots);

      $output = [
        'name' => $component->get('name'),
        'machineName' => $machine_name,
        // Mark this code component as "internal": do not make it available to Content Creators yet.
        // @see docs/config-management.md, section 3.2.1
        'status' => FALSE,
        'sourceCodeJs' => $js,
        'sourceCodeCss' => '',
        'compiledJs' => '',
        'compiledCss' => '',
        'importedJsComponents' => [],
        'props' => $transformed_props,
        'required' => $required_props,
        'slots' => $transformed_slots,
        'dataDependencies' => [],
      ];
      $violations = JavaScriptComponent::createFromClientSide($output)->getTypedData()->validate();
      if ($violations->count() > 0) {

        // Translate constraint property paths to match YAML input structure
        $translatedViolations = $this->translateConstraintPropertyPathsAndRoot(
          ['' => 'component_structure.'],
          $violations,
          ''
        );
        throw new ConstraintViolationException($translatedViolations, 'Component validation errors');
      }

    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      // \Drupal\canvas_ai\Controller\CanvasBuilder::render() also YAML parsable output.
      // @see \Drupal\canvas_ai\Controller\CanvasBuilder::render()
      $this->setOutput(Yaml::dump(['error' => \sprintf('Failed to process Javascript component data: %s', $e->getMessage())], 10, 2));
      return;
    }
    $output = [
      'js_structure' => $js,
      'props_metadata' => Json::encode($transformed_props),
      'slots_metadata' => Json::encode($transformed_slots),
      'required_props' => $required_props,
    ];
    $this->setStructuredOutput($output);
    $message = 'Component with id "' . $machine_name . '" has been successfully updated.';
    $warning = $this->buildRemovedMetadataWarning(
      $component->get('props') ?? [],
      $transformed_props,
      $component->get('slots') ?? [],
      $transformed_slots,
    );
    if ($warning !== '') {
      $message .= ' ' . $warning;
    }
    $this->setOutput($message);
    $this->logger->info($message);
  }

}
