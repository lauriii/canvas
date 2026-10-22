<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the get props types function.
 */
#[FunctionCall(
  id: 'ai_agent:get_props_type',
  function_name: 'ai_agent_get_props_type',
  name: 'Get Props Type',
  description: 'This method gets the props type in Drupal Canvas.',
  group: 'information_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'derived_proptypes' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Prop Type"),
      description: new TranslatableMarkup("The prop type for Drupal Canvas."),
      required: TRUE
    ),
  ],
)]
final class GetPropsType extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The prop types information.
   *
   * @var string
   */
  protected string $derivedProptypes = '';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
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
    $this->derivedProptypes = html_entity_decode($this->getContextValue('derived_proptypes'));
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->derivedProptypes;
  }

}
