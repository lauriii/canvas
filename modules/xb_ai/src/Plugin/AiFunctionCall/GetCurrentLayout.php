<?php

namespace Drupal\xb_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\xb_ai\XbAiTempStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Function call plugin to get the current layout.
 *
 * This plugin retrieves the current layout from the tempstore.
 * The layout information can be used by AI agents to understand and manipulate
 * the current page structure.
 *
 * @internal
 */
#[FunctionCall(
  id: 'xb_ai:get_current_layout',
  function_name: 'get_current_layout',
  name: 'Get Current Layout',
  description: 'Gets the current layout stored in the system.',
  group: 'information_tools',
)]
final class GetCurrentLayout extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The XB AI tempstore service.
   *
   * @var \Drupal\xb_ai\XbAiTempStore
   */
  protected XbAiTempStore $xbAiTempStore;

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
      new ContextDefinitionNormalizer(),
    );
    $instance->xbAiTempStore = $container->get('xb_ai.tempstore');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    // Make sure that the user has the right permissions.
    if (!$this->currentUser->hasPermission('use experience builder ai')) {
      throw new \Exception('The current user does not have the right permissions to run this tool.');
    }
    $current_layout = $this->xbAiTempStore->getData(XbAiTempStore::CURRENT_LAYOUT_KEY);
    $this->setOutput($current_layout ? (string) $current_layout : 'No layout currently stored.');
  }

}
