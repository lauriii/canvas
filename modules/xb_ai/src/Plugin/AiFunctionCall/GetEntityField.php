<?php

namespace Drupal\xb_ai\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the get entity fields function.
 */
#[FunctionCall(
  id: 'ai_agent:get_entity_field',
  function_name: 'ai_agent_get_entity_field',
  name: 'Get entity field',
  description: 'This method gets the field and its value for a given entity.',
  group: 'information_tools',
  module_dependencies: ['experience_builder'],
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Entity Type"),
      description: new TranslatableMarkup("The entity type for which content needs to be generated or updated."),
      required: TRUE
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup("Entity Id"),
      description: new TranslatableMarkup("The entity id for which content needs to be generated or updated."),
      required: TRUE,
    ),
    'field_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Field Name"),
      description: new TranslatableMarkup("The machine name of the field for which content needs to be generated or updated."),
      required: FALSE,
    ),
  ],
)]
final class GetEntityField extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      new ContextDefinitionNormalizer(),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * The entity fields information.
   *
   * @var string
   */
  protected string $information = "";

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $entity_id = $this->getContextValue('entity_id');
    $entity_type = $this->getContextValue('entity_type');
    $field_name = $this->getContextValue('field_name');

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if (!$entity || !($entity instanceof FieldableEntityInterface)) {
      $this->information = "The entity does not exist or is not fieldable.";
      return;
    }

    if ($field_name) {
      if (!$entity->hasField($field_name)) {
        $this->information = "The field '$field_name' does not exist for this entity.";
        return;
      }
      $field = $entity->get($field_name);
      $field_value = $field->isEmpty() ? NULL : $field->getValue();
      $this->information = Yaml::dump([$field_name => $field_value[0]['value']], 10, 2);
    }
    else {
      $this->information = "No field provided.";
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->information;
  }

}
