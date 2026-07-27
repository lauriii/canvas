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
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tool that lets the agent place one or more components onto the page.
 *
 * This tool is intentionally not listed in any agent's tool set yet: it ships
 * inert and is wired to the dev agent in a later issue. It will eventually
 * replace \Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedComponentStructure.
 *
 * @see \Drupal\canvas_ai\Controller\CanvasBuilder::render()
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetCurrentLayout
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\SetAIGeneratedComponentStructure
 */
#[FunctionCall(
  id: 'canvas_ai:place_components',
  function_name: 'place_components',
  name: 'Place Components',
  description: 'Places a section of components onto the current page. Call this once per section/row. The input is a YAML string with an "operations" list, each describing where to place its components (target region or "parent-uuid/slot", a placement of "above", "below" or "inside", and a reference_uuid for above/below). Components are not added to the page unless this tool is called. It may return validation errors if the structure is invalid.',
  group: 'modification_tools',
  context_definitions: [
    'component_structure_yaml' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component structure in yml format"),
      description: new TranslatableMarkup("The components to place, as a YAML string containing an 'operations' list."),
      required: TRUE,
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
    $instance->loggerFactory = $container->get('logger.factory');
    $instance->currentUser = $container->get('current_user');
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
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
      $component_structure = $this->getContextValue('component_structure_yaml');
      $component_structure_array = Yaml::parse($component_structure);
      if (empty($component_structure_array['operations'])) {
        throw new \Exception('The operations key is missing in the component structure.');
      }

      $all_errors = [];
      foreach ($component_structure_array['operations'] as $index => $operation) {
        $all_errors = array_merge($all_errors, $this->validatePlacementParams($operation, $index));
        $this->responseValidator->validateComponentStructure($operation['components'] ?? []);
      }

      if (!empty($all_errors)) {
        throw new \Exception(Yaml::dump($all_errors));
      }

      // Once validated, convert the YAML to the operations structure (with
      // calculated nodePaths and assigned UUIDs) consumed by the Canvas UI.
      $placement = $this->pageBuilderHelper->generateComponentPlacementData($component_structure);
      \assert(\array_keys($placement->operations) === ['operations']);
      $this->setStructuredOutput($placement->operations);
      // Return the backend-assigned UUIDs and the predicted layout in the tool
      // result, so the model knows where the placed components landed and can
      // reference them when placing the next section. Remind it to call this
      // tool again while any planned section is still unplaced.
      $output = \sprintf(
        "Components placed successfully.\nThe placed components with their assigned UUIDs:\n%s\nThe expected page layout after placement (UUID tree):\n%s\n\nThis result is a continuation point, not a stopping point: if any section from your approved plan is still unplaced, your next output MUST be the next place_components call — a turn with text and no tool call would freeze the build here. Only once every planned section is on the page do you stop and write the closing confirmation.",
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
   *   The operation to validate.
   * @param int $index
   *   The index of the operation, used for error messages.
   *
   * @return array
   *   An array of validation errors, keyed by operation, or empty if valid.
   */
  private function validatePlacementParams(array $operation, int $index): array {
    $errors = [];
    $error_key = 'Operation ' . $index;

    if (!isset($operation['placement']) || !\in_array($operation['placement'], ['above', 'below', 'inside'], TRUE)) {
      $errors[$error_key][] = 'The placement key is missing or invalid in the operation.';
      return $errors;
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
      if (isset($operation['target']) && $this->pageBuilderHelper->hasChildComponents($operation['target'])) {
        $errors[$error_key][] = 'The target ' . $operation['target'] . ' has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.';
      }
    }

    // Operation must contain components.
    if (empty($operation['components'])) {
      $errors[$error_key][] = 'The operation must contain components.';
    }

    return $errors;
  }

}
