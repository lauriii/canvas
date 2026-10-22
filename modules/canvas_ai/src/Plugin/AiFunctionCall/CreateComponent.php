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
use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the create component function.
 */
#[FunctionCall(
  id: 'ai_agent:create_component',
  function_name: 'ai_agent_create_component',
  name: 'Create new component',
  description: 'This method creates a new component.',
  group: 'modification_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'component_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component Name"),
      description: new TranslatableMarkup("The data name of the component that we should create."),
      required: TRUE
    ),
    'js_structure' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Javascript"),
      description: new TranslatableMarkup("All the new javascript."),
      required: FALSE
    ),
    'css_structure' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("CSS"),
      description: new TranslatableMarkup("All the new CSS."),
      required: FALSE
    ),
    'props_metadata' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Props"),
      description: new TranslatableMarkup("Metadata for props"),
      required: FALSE
    ),
    'slots_metadata' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Slots"),
      description: new TranslatableMarkup("Metadata for slots. A JSON array where each entry is an object with an 'id' (slot machine name), 'name' (human-readable title) and an optional 'example' (sample child markup)."),
      required: FALSE
    ),
  ],
)]
final class CreateComponent extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

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
   * The AI response validator.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

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
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->logger = $container->get(LoggerChannelFactoryInterface::class)->get('canvas_ai');
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
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
      // Collect the context values.
      $component_name = $this->getContextValue('component_name');
      $javascript = $this->getContextValue('js_structure') ?? '';
      $css = $this->getContextValue('css_structure') ?? '';
      $props = $this->getContextValue('props_metadata') ?? '';
      $slots = $this->getContextValue('slots_metadata') ?? '';
      $machine_name = strtolower(preg_replace('/\s+/', '_', $component_name));

      // Every check adds to $errors instead of stopping at the first one, so
      // the model can fix everything in a single retry.
      $errors = [];

      // Check if the component exists.
      $component = $this->entityTypeManager->getStorage('js_component')->load($machine_name);
      if ($component) {
        $errors['Component'][] = 'The component with same name already exists.';
      }

      if (str_contains($css, '@apply')) {
        $errors['Component'][] = '@apply directives are not supported.';
      }
      $props_array = Json::decode($props);
      $transformed_props = [];
      $required_props = [];
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

            if (!empty($prop['required']) && $prop['required'] === TRUE) {
              $required_props[] = $prop['id'];
            }
          }
        }
      }

      $slot_errors = [];
      $transformed_slots = $this->transformSlotsMetadata($slots, $slot_errors);
      $errors = \array_merge($errors, $slot_errors);

      $output = [
        'name' => $component_name,
        'machineName' => $machine_name,
        // Mark this code component as "internal": do not make it available to Content Creators yet.
        // @see docs/config-management.md, section 3.2.1
        'status' => FALSE,
        'sourceCodeJs' => $javascript,
        'sourceCodeCss' => $css,
        'compiledJs' => '',
        'compiledCss' => '',
        'importedJsComponents' => [],
        'props' => $transformed_props,
        'required' => $required_props,
        'slots' => $transformed_slots,
        'dataDependencies' => [],
      ];
      // Invalid slots are left out of $transformed_slots, so the rest of the
      // structure can still be validated and reported in the same response.
      $violations = JavaScriptComponent::createFromClientSide($output)->getTypedData()->validate();
      if ($violations->count() > 0) {
        // Translate constraint property paths to match YAML input structure
        $translatedViolations = $this->translateConstraintPropertyPathsAndRoot(
          ['' => 'component_structure.'],
          $violations,
          ''
        );
        $errors['Component'][] = (new ConstraintViolationException($translatedViolations, 'Component validation errors'))->getMessage();
      }

      if ($errors !== []) {
        $message = $this->responseValidator->formatErrors($errors);
        $this->logger->warning($message);
        $this->setOutput($message);
        return;
      }
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      $this->setOutput(\sprintf('Failed to create the component: %s', $e->getMessage()));
      return;
    }
    $this->setStructuredOutput(['component_structure' => $output]);
    $this->setOutput('The component ' . $component_name . ' has been created successfully.');
  }

}
