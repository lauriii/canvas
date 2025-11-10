<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation to get node fields.
 */
#[FunctionCall(
  id: 'ai_agent:get_node_fields',
  function_name: 'ai_agent_get_node_fields',
  name: 'Get node fields',
  description: 'This method allows to get node fields.',
  group: 'information_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'node_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Node Type"),
      description: new TranslatableMarkup("The node type for which you want to get fields."),
      required: TRUE
    ),
  ],
)]
final class GetNodeFields extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The current user service.
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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->currentUser = $container->get('current_user');
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

    $node_type = $this->getContextValue('node_type');

    // Check if node type exists.
    if (is_null($this->entityTypeManager->getStorage('node_type')->load($node_type))) {
      $this->setOutput('Node type with name "' . $node_type . '" does not exist.');
      return;
    }

    $node_fields = [];
    $entity_reference_fields = [];
    $fields = $this->entityTypeManager->getStorage('field_config')->loadMultiple();
    foreach ($fields as $field) {
      if ($field->getTargetBundle() === $node_type) {
        if ($field->getType() === 'entity_reference') {
          $entity_reference_fields[] = $field;
        }
        else {
          $node_fields[$field->getName()]['field_type'] = $field->getType();
          $node_fields[$field->getName()]['field_settings'] = $field->getSettings();
          $node_fields[$field->getName()]['cardinality'] = $field->getFieldStorageDefinition()->getCardinality();
        }
      }
    }
    $entity_reference_field_names = [];
    foreach ($entity_reference_fields as $entity_reference_field) {
      $entity_reference_field_cardinality = $entity_reference_field->getFieldStorageDefinition()->getCardinality();
      $entity_reference_field_handler_setting = $entity_reference_field->getSetting('handler_settings');
      if ($entity_reference_field_handler_setting !== NULL && $entity_reference_field_handler_setting['target_bundles'] !== NULL) {
        foreach ($entity_reference_field_handler_setting['target_bundles'] as $bundle) {
          foreach ($fields as $field) {
            if ($field->getTargetEntityTypeId() === $entity_reference_field->getSettings()['target_type'] && $field->getTargetBundle() === $bundle) {
              $entity_reference_field_names[$entity_reference_field->getName() . '.' . $field->getName()]['cardinality'] = $entity_reference_field_cardinality;
            }
          }
        }
      }
      $entity_reference_field_names[$entity_reference_field->getName()]['cardinality'] = $entity_reference_field_cardinality;
    }
    $this->setOutput(Yaml::dump([
      'fields' => $node_fields,
      'reference_entity_fields' => $entity_reference_field_names,
    ], 10, 2));
  }

}
