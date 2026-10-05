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
 * addressed by UUID. The drupal_canvas_page_agent lists it; the frontend
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
   * The first line of a successful result; the rest is appended to it.
   *
   * A conversation's next turn keeps only this line of the result: the dump
   * of the applied updates that follows it is scoped to the turn the tool
   * ran in.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::trimKeptToolResults()
   */
  public const SUCCESS_MESSAGE = 'The updates were applied successfully.';

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
      $current_layout = Json::decode($this->canvasAiTempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY) ?? '');
      $current_layout = \is_array($current_layout) ? $current_layout : [];
      $components_by_uuid = $this->pageBuilderHelper->getComponentsByUuid($current_layout);

      // Validates and applies each edit, accumulating every edit's errors
      // instead of stopping at the first invalid one.
      $component_updates = [];
      $all_errors = [];
      foreach ($this->getContextValue('component_edits') as $index => $edit) {
        $uuid = $edit['component_uuid'] ?? NULL;
        $errors = self::validateComponentUuid($uuid, $components_by_uuid);
        $props = NULL;
        $props_yaml = $edit['props'] ?? '';
        // The schema types props as a string, but ComplexToolItems does not
        // check a record's fields, so a model that sends the mapping as JSON
        // gets there too; Yaml::parse() would throw a TypeError on it.
        if (!\is_string($props_yaml)) {
          $errors[] = 'The props value must be a string containing a YAML mapping of prop names to values.';
        }
        else {
          try {
            $props = Yaml::parse($props_yaml);
            // A block that did not parse is reported below; its shape is only
            // checked once it has.
            $errors = \array_merge($errors, self::validateProps($props));
          }
          catch (ParseException $e) {
            // Replaces the raw YAML parse error with instructions to quote string values.
            $errors[] = \sprintf("The props value is not valid YAML: %s Rewrite it with every string value quoted — unquoted dash-separated values such as 2233-33-33 are read as invalid dates, and HTML or multi-line text must be quoted too.", $e->getMessage());
          }
        }

        if (\is_array($props) && \is_string($uuid) && isset($components_by_uuid[$uuid])) {
          $component = $components_by_uuid[$uuid];
          // Merges the edit's props over the component's existing props.
          $merged_props = $props + $component['props'];
          try {
            $this->responseValidator->validateComponentStructure([[$component['component_id'] => ['props' => $merged_props]]]);
          }
          catch (\Exception $e) {
            $errors[] = $e->getMessage();
          }
        }

        if ($errors !== []) {
          $all_errors['Edit ' . $index] = $errors;
          continue;
        }
        $component_updates[$uuid] = $props;
      }

      if ($all_errors !== []) {
        $message = $this->responseValidator->formatErrors($all_errors);
        $this->loggerFactory->get('canvas_ai')->warning($message);
        $this->setOutput($message);
        return;
      }

      // The frontend applies these updates to the page on the next hop.
      $this->setStructuredOutput(['component_updates' => $component_updates]);
      $this->setOutput(self::SUCCESS_MESSAGE . "\n" . Yaml::dump($component_updates));
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(\sprintf('Failed to edit components: %s', $e->getMessage()));
    }
  }

  /**
   * Validates the component_uuid of a single edit.
   *
   * @param mixed $uuid
   *   The edit's component_uuid value.
   * @param array $components_by_uuid
   *   The current page's components, keyed by UUID.
   *
   * @return list<string>
   *   The validation errors found, or an empty list if the UUID is valid.
   */
  private static function validateComponentUuid(mixed $uuid, array $components_by_uuid): array {
    if (!\is_string($uuid) || $uuid === '') {
      return ['The component_uuid key is missing in the edit.'];
    }
    if (!isset($components_by_uuid[$uuid])) {
      return [\sprintf('Component %s was not found on the page.', $uuid)];
    }
    return [];
  }

  /**
   * Validates the parsed props of a single edit.
   *
   * @param mixed $props
   *   The edit's parsed props value.
   *
   * @return list<string>
   *   The validation errors found, or an empty list if the props are valid.
   */
  private static function validateProps(mixed $props): array {
    if (!\is_array($props)) {
      return ['The props value must be a YAML mapping of prop names to values, one "prop_name: value" pair per line.'];
    }
    if ($props === []) {
      return ['The edit must contain at least one prop change.'];
    }
    return [];
  }

}
