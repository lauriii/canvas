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
 * Tool that lets the agent update the props of components already on the page.
 *
 * Companion to \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents: place
 * puts a component on the page, edit tweaks the props of one already there,
 * addressed by UUID. The canvas_dev_page_builder_agent lists it; the frontend
 * applies the returned component_updates on the next hop.
 *
 * @see \Drupal\canvas_ai\Controller\CanvasBuilder::render()
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
 */
#[FunctionCall(
  id: 'canvas_ai:edit_components',
  function_name: 'edit_components',
  name: 'Edit Components',
  description: "Updates the props of one or more components already on the page. Provide a list of edits; each edit targets one component by its 'component_uuid' and carries a 'props' YAML block of 'prop_name: value' pairs to set on it. Include only the props being changed. May return validation errors if a UUID is not on the page or a prop value is invalid.",
  group: 'modification_tools',
  context_definitions: [
    'component_edits' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup("Component edits"),
      description: new TranslatableMarkup("A list of component edits, one entry per component to update."),
      required: TRUE,
      constraints: [
        'ComplexToolItems' => ComponentEdit::class,
      ],
    ),
  ],
)]
final class EditComponents extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * The Canvas page builder helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $pageBuilderHelper;

  /**
   * The Canvas AI tempstore service.
   *
   * @var \Drupal\canvas_ai\CanvasAiTempStore
   */
  protected CanvasAiTempStore $canvasAiTempStore;

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

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
    $instance->canvasAiTempStore = $container->get(CanvasAiTempStore::class);
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
    $instance->loggerFactory = $container->get(LoggerChannelFactoryInterface::class);
    $instance->currentUser = $container->get(AccountProxyInterface::class);
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
      // The context-definition schema guarantees a non-empty component_edits
      // list (validateContexts() rejects an empty/absent value before execute),
      // but its ComplexToolItems check does not descend into each record, so
      // the per-edit shape is guarded here.
      $component_updates = [];
      foreach ($this->getContextValue('component_edits') as $edit) {
        $uuid = $edit['component_uuid'] ?? NULL;
        $props = Yaml::parse($edit['props'] ?? '');
        if (!\is_string($uuid) || !$props) {
          throw new \Exception('Each edit must provide a "component_uuid" and its prop changes.');
        }
        // A scalar parses fine but is not a props map; the client would spread
        // it into the component's values character by character.
        if (!\is_array($props)) {
          throw new \Exception(\sprintf('The props value for component %s must be a YAML mapping of prop names to values, one "prop_name: value" pair per line.', $uuid));
        }
        $component_updates[$uuid] = $props;
      }

      $this->validateComponentUpdates($component_updates);

      // The frontend applies these updates to the page on the next hop.
      $this->setStructuredOutput(['component_updates' => $component_updates]);
      $this->setOutput("The updates were applied successfully.\n" . Yaml::dump($component_updates));
    }
    catch (ParseException $e) {
      // A raw parse error gives the model nothing to act on, so it retries
      // the same payload; tell it how to fix the YAML instead.
      $message = \sprintf("The props value is not valid YAML: %s Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too.", $e->getMessage());
      $this->loggerFactory->get('canvas_ai')->error($message);
      $this->setOutput(\sprintf('Failed to edit components: %s', $message));
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(\sprintf('Failed to edit components: %s', $e->getMessage()));
    }
  }

  /**
   * Validates the agent-supplied prop updates against the current layout.
   *
   * @param array $component_updates
   *   The parsed updates, keyed by component UUID with prop_name => value pairs.
   *
   * @throws \Exception
   *   When an edited UUID is not present on the page.
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   When the merged prop values fail validation.
   */
  private function validateComponentUpdates(array $component_updates): void {
    $current_layout = Json::decode($this->canvasAiTempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY) ?? '');
    $current_layout = \is_array($current_layout) ? $current_layout : [];
    $components_by_uuid = $this->pageBuilderHelper->getComponentsByUuid($current_layout);

    $errors = [];
    $component_groups = [];
    foreach ($component_updates as $uuid => $prop_updates) {
      if (!isset($components_by_uuid[$uuid])) {
        $errors[] = \sprintf('Component %s was not found on the page.', $uuid);
        continue;
      }
      $component = $components_by_uuid[$uuid];
      // Merge over existing props so validation does not treat a partial edit
      // as dropping the component's other required props.
      $merged_props = (\is_array($prop_updates) ? $prop_updates : []) + $component['props'];
      $component_groups[] = [$component['component_id'] => ['props' => $merged_props]];
    }

    if ($errors !== []) {
      throw new \Exception(implode("\n", $errors));
    }

    $this->responseValidator->validateComponentStructure($component_groups);
  }

}
