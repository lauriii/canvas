<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Tool that lets the agent place one or more components onto the page.
 *
 * The drupal_canvas_page_agent lists this tool, restricted to one call
 * per model response. It will eventually replace
 * \Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedComponentStructure.
 *
 * @see \Drupal\canvas_ai\Controller\CanvasBuilder::render()
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedComponentStructure
 */
#[FunctionCall(
  id: 'canvas_ai:place_components',
  function_name: 'place_components',
  name: 'Place Components',
  description: 'Places a section of components onto the current page. Call this once per section/row. Each placement operation targets a region or slot and carries the components to place there. Components are not added to the page unless this tool is called. It may return validation errors if a target, placement, or component is invalid.',
  group: 'modification_tools',
  context_definitions: [
    'operations' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup("Placement operations"),
      description: new TranslatableMarkup("The placements to apply, one entry per target position."),
      required: TRUE,
      constraints: [
        'ComplexToolItems' => PlacementOperation::class,
      ],
    ),
  ],
)]
final class PlaceComponents extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * The first line of a successful result; the rest is appended to it.
   *
   * A conversation's next turn keeps only this line of the result: the UUIDs
   * and layout that follow it are scoped to the turn the tool ran in.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::trimKeptToolResults()
   */
  public const SUCCESS_MESSAGE = 'Components placed successfully.';

  /**
   * The Canvas page builder helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $pageBuilderHelper;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

  /**
   * The Canvas AI tempstore.
   *
   * @var \Drupal\canvas_ai\CanvasAiTempStore
   */
  protected CanvasAiTempStore $tempStore;

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
    $instance->pageBuilderHelper = $container->get('canvas_ai.page_builder_helper');
    $instance->loggerFactory = $container->get(LoggerChannelFactoryInterface::class);
    $instance->currentUser = $container->get(AccountProxyInterface::class);
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
    $instance->tempStore = $container->get(CanvasAiTempStore::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    // Make sure that the user has the right permissions.
    if (!$this->currentUser->hasPermission(CanvasAiPermissions::USE_CANVAS_AI)) {
      throw new \Exception('The current user does not have the right permissions to run this tool.');
    }
    try {
      $operations = [];
      $all_errors = [];
      $current_layout = $this->tempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY) ?? '';
      $layout_data = Json::decode($current_layout);
      $components_by_uuid = $this->pageBuilderHelper->getComponentsByUuid(
        \is_array($layout_data) ? $layout_data : [],
      );
      foreach ($this->getContextValue('operations') as $index => $operation) {
        $errors = $this->validatePlacementParams($operation, $current_layout, $components_by_uuid);
        $components_yaml = $operation['components'] ?? '';
        // The schema types components as a string, but ComplexToolItems does
        // not check a record's fields, so a model that sends the list as JSON
        // gets there too; Yaml::parse() would throw a TypeError on it.
        if (!\is_string($components_yaml)) {
          $errors[] = 'The components value must be a string containing a YAML list.';
        }
        else {
          try {
            $operation['components'] = Yaml::parse($components_yaml);
            // A block that did not parse is reported below; its shape is only
            // checked once it has, and the components in it only once it is a
            // list of mappings.
            $component_errors = self::validateComponents($operation['components']);
            if ($component_errors === []) {
              try {
                $this->responseValidator->validateComponentStructure($operation['components']);
              }
              catch (\Exception $e) {
                $component_errors[] = $e->getMessage();
              }
            }
            $errors = \array_merge($errors, $component_errors);
          }
          catch (ParseException $e) {
            // A raw parse error gives the model nothing to act on, so it
            // retries the same payload; tell it how to fix the YAML instead.
            $errors[] = \sprintf("The components value is not valid YAML: %s Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too.", $e->getMessage());
          }
        }
        if ($errors !== []) {
          $all_errors['Operation ' . $index] = $errors;
          continue;
        }
        $operations[] = $operation;
      }

      if ($all_errors !== []) {
        $message = $this->responseValidator->formatErrors($all_errors);
        $this->loggerFactory->get('canvas_ai')->warning($message);
        $this->setOutput($message);
        return;
      }

      // Once validated, convert the operations to the structure (with
      // calculated nodePaths and assigned UUIDs) consumed by the Canvas UI.
      $placement = $this->pageBuilderHelper->generateComponentPlacementData(['operations' => $operations]);
      \assert(\array_keys($placement->operations) === ['operations']);
      $this->setStructuredOutput($placement->operations);
      // Return the backend-assigned UUIDs and the predicted layout in the tool
      // result, so the model knows where the placed components landed and can
      // reference them when placing the next section. State that these UUIDs
      // outrank the layout supplied at the start of the turn, which lists them
      // only from the next turn. Remind it to call this tool again while any
      // planned section is still unplaced.
      $output = \sprintf(
        self::SUCCESS_MESSAGE . "\nThe placed components with their assigned UUIDs:\n%s\nThe expected page layout after placement (UUID tree):\n%s\nThese UUIDs are valid immediately — use them as reference_uuid for the next section in this same turn. The page layout you were given at the start of this turn does not list them yet and will only do so from your next turn, so for anything placed during this turn this result is authoritative and that layout is not. This is expected, not a sign that the layout is missing or that you should wait.\n\nThis result is a continuation point, not a stopping point: if any section from your approved plan is still unplaced, your next output MUST be the next place_components call — a turn with text and no tool call would freeze the build here. Only once every planned section is on the page do you stop and write the closing confirmation.",
        Yaml::dump($placement->componentStructureWithUuids, 10, 2),
        Yaml::dump($placement->predictedLayout, 10, 2),
      );
      $this->setOutput($output);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(\sprintf('Failed to place components: %s', $e->getMessage()));
    }
  }

  /**
   * Validates the target, placement and reference of a single operation.
   *
   * @param array $operation
   *   The operation to validate.
   * @param string $current_layout
   *   The current layout JSON string, used to resolve the target region.
   * @param array $components_by_uuid
   *   The current page's components, keyed by UUID.
   *
   * @return list<string>
   *   The validation errors found, or an empty list if the operation is valid.
   */
  private function validatePlacementParams(array $operation, string $current_layout, array $components_by_uuid): array {
    $errors = [];

    $target = $operation['target'] ?? NULL;
    $has_target = \is_string($target) && $target !== '';
    if (!$has_target) {
      $errors[] = 'The target key is missing in the operation.';
    }

    $placement = $operation['placement'] ?? NULL;
    if (!\in_array($placement, ['above', 'below', 'inside'], TRUE)) {
      $errors[] = 'The placement key is missing or invalid in the operation.';
    }

    // A target naming a region must match a region present in the layout. A
    // target containing a slash names a `parent_uuid/slot_name` pair instead,
    // which must name a component on the page and one of its slots.
    if ($has_target) {
      if (strpos($target, '/') === FALSE) {
        $target_error = $this->pageBuilderHelper->validateRegionExists($target, $current_layout);
      }
      else {
        $target_error = $this->pageBuilderHelper->validateSlotTargetExists($target, $components_by_uuid);
      }
      if ($target_error !== NULL) {
        $errors[] = $target_error;
      }
    }

    // If placement is 'above' or 'below', reference_uuid must be provided.
    if (\in_array($placement, ['above', 'below'], TRUE)) {
      $reference_uuid = $operation['reference_uuid'] ?? NULL;
      if (empty($reference_uuid)) {
        $errors[] = 'The reference_uuid must be provided for above/below placement.';
      }
      elseif (!\is_string($reference_uuid) || !isset($components_by_uuid[$reference_uuid])) {
        $errors[] = \sprintf('Component with UUID "%s" not found in layout', $reference_uuid);
      }
    }

    // If placement is 'inside', reference_uuid is not needed and the target
    // must not already contain child components.
    if ($placement === 'inside') {
      if (!empty($operation['reference_uuid'])) {
        $errors[] = 'The reference_uuid is not required for inside placement.';
      }
      if ($has_target && $this->pageBuilderHelper->hasChildComponents($target)) {
        $errors[] = 'The target ' . $target . ' has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.';
      }
    }

    return $errors;
  }

  /**
   * Validates the parsed components block of a single operation.
   *
   * @param mixed $components
   *   The operation's parsed components value.
   *
   * @return list<string>
   *   The validation errors found, or an empty list if the block is a
   *   non-empty list of mappings.
   */
  private static function validateComponents(mixed $components): array {
    if (!\is_array($components)) {
      return ['The components value must be a YAML list.'];
    }
    if ($components === []) {
      return ['The operation must contain components.'];
    }
    // A bare component ID parses as a string; the structure validation
    // iterates each entry, so it must be a mapping.
    $errors = [];
    foreach ($components as $position => $component) {
      if (!\is_array($component)) {
        $errors[] = \sprintf('Entry %d of the components list must be a mapping keyed by the component ID, with its props and slots under it.', $position);
      }
    }
    return $errors;
  }

}
