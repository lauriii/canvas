<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\CanvasAiComponentContextHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Function call plugin to get the details of specific components.
 *
 * Given a list of component ids, returns each component's description, props,
 * and slots so an agent can fetch full metadata only for the candidates it
 * shortlisted from the catalog returned by get_component_context.
 *
 * @todo This tool is intentionally not yet listed in any agent's tool list; wire it into canvas_page_builder_agent once its prompt is updated to use it. See https://git.drupalcode.org/project/canvas/-/work_items/3591777.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetComponentContext
 *
 * @internal
 */
#[FunctionCall(
  id: 'canvas_ai:get_component_details',
  function_name: 'get_component_details',
  name: 'Get Component Details',
  description: 'Returns the description, props, and slots of specific components identified by id. Use this to get the full metadata of components you have already shortlisted — e.g. after browsing the catalog with get_component_context — so you have the exact props and slots needed to place or configure them.',
  group: 'information_tools',
  context_definitions: [
    'component_ids_list' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component IDs"),
      description: new TranslatableMarkup("The ids of the components to fetch details for, e.g. sdc.canvas_test_sdc.my-hero."),
      required: TRUE,
      multiple: TRUE,
    ),
  ],
  module_dependencies: ['canvas_ai'],
)]
final class GetComponentDetails extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Canvas AI component context helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiComponentContextHelper
   */
  protected CanvasAiComponentContextHelper $componentContextHelper;

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
    $instance->componentContextHelper = $container->get('canvas_ai.component_context_helper');
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
    $component_ids = $this->getContextValue('component_ids_list');
    $this->setOutput($this->componentContextHelper->getComponentDetails($component_ids));
  }

}
