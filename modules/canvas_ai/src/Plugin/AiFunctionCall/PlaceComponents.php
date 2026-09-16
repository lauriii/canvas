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
 * The canvas_dev_page_builder_agent lists this tool, restricted to one call
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
      foreach ($this->getContextValue('operations') as $index => $operation) {
        try {
          $operation['components'] = Yaml::parse($operation['components'] ?? '');
        }
        catch (ParseException $e) {
          // A raw parse error gives the model nothing to act on, so it retries
          // the same payload; tell it how to fix the YAML instead.
          $all_errors['Operation ' . $index][] = \sprintf("The components value is not valid YAML: %s Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too.", $e->getMessage());
          continue;
        }
        $all_errors = array_merge($all_errors, $this->validatePlacementParams($operation, $index, $current_layout));
        if (\is_array($operation['components'])) {
          $this->responseValidator->validateComponentStructure($operation['components']);
        }
        $operations[] = $operation;
      }

      if (!empty($all_errors)) {
        throw new \Exception(Yaml::dump($all_errors));
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
        "Components placed successfully.\nThe placed components with their assigned UUIDs:\n%s\nThe expected page layout after placement (UUID tree):\n%s\nThese UUIDs are valid immediately — use them as reference_uuid for the next section in this same turn. The page layout you were given at the start of this turn does not list them yet and will only do so from your next turn, so for anything placed during this turn this result is authoritative and that layout is not. This is expected, not a sign that the layout is missing or that you should wait.\n\nThis result is a continuation point, not a stopping point: if any section from your approved plan is still unplaced, your next output MUST be the next place_components call — a turn with text and no tool call would freeze the build here. Only once every planned section is on the page do you stop and write the closing confirmation.",
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
   * Validates the placement parameters of a single operation.
   *
   * @param array $operation
   *   The operation to validate, with its components block already parsed.
   * @param int $index
   *   The index of the operation, used for error messages.
   * @param string $current_layout
   *   The current layout JSON string, used to resolve the target region.
   *
   * @return array
   *   An array of validation errors, keyed by operation, or empty if valid.
   */
  private function validatePlacementParams(array $operation, int $index, string $current_layout): array {
    $errors = [];
    $error_key = 'Operation ' . $index;

    if (!isset($operation['target']) || !\is_string($operation['target']) || $operation['target'] === '') {
      $errors[$error_key][] = 'The target key is missing in the operation.';
      return $errors;
    }

    if (!isset($operation['placement']) || !\in_array($operation['placement'], ['above', 'below', 'inside'], TRUE)) {
      $errors[$error_key][] = 'The placement key is missing or invalid in the operation.';
      return $errors;
    }

    // A target naming a region must match a region present in the layout. A
    // target containing a slash names a `parent_uuid/slot_name` pair instead,
    // whose parent is resolved during nodePath calculation.
    if (strpos($operation['target'], '/') === FALSE) {
      $region_error = $this->pageBuilderHelper->validateRegionExists($operation['target'], $current_layout);
      if ($region_error !== NULL) {
        $errors[$error_key][] = $region_error;
        return $errors;
      }
    }

    $placement = $operation['placement'];
    // If placement is 'above' or 'below', reference_uuid must be provided.
    if (\in_array($placement, ['above', 'below'], TRUE) && empty($operation['reference_uuid'])) {
      $errors[$error_key][] = 'The reference_uuid must be provided for above/below placement.';
    }

    // If placement is 'inside', reference_uuid is not needed and the target
    // must not already contain child components.
    if ($placement === 'inside') {
      if (!empty($operation['reference_uuid'])) {
        $errors[$error_key][] = 'The reference_uuid is not required for inside placement.';
      }
      if ($this->pageBuilderHelper->hasChildComponents($operation['target'])) {
        $errors[$error_key][] = 'The target ' . $operation['target'] . ' has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.';
      }
    }

    // Operation must contain components, as a parseable YAML list.
    if (!\is_array($operation['components'])) {
      $errors[$error_key][] = 'The components value must be a YAML list.';
    }
    elseif ($operation['components'] === []) {
      $errors[$error_key][] = 'The operation must contain components.';
    }

    return $errors;
  }

}
