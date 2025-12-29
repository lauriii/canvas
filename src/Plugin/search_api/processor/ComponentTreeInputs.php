<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\search_api\processor;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\ComponentTreeInputExtractor;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\SearchApiException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin that processes the component tree structure for search indexing.
 */
#[SearchApiProcessor(
  id: 'canvas_component_tree_inputs',
  label: new TranslatableMarkup('Canvas component tree inputs'),
  description: new TranslatableMarkup('Processes the component tree inputs for search indexing.'),
  stages: [
    'add_properties' => 0,
  ],
  locked: TRUE,
  hidden: TRUE,
)]
final class ComponentTreeInputs extends ProcessorPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    private readonly ComponentTreeInputExtractor $componentTreeInputExtractor,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ComponentTreeInputExtractor::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'ignored_prop_names' => ['id', 'class', 'cssClasses', 'extraClasses'],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['ignored_prop_names'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ignored prop names'),
      '#description' => $this->t('Enter one prop name per line. These props will be excluded from search indexing.'),
      '#default_value' => implode("\n", $this->configuration['ignored_prop_names']),
      '#rows' => 6,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $ignored_prop_names = $form_state->getValue('ignored_prop_names');
    // Split by lines and filter out empty values.
    $ignored_prop_names = array_values(array_filter(
      array_map('trim', explode("\n", $ignored_prop_names)),
      static fn($name) => $name !== ''
    ));
    $this->setConfiguration(['ignored_prop_names' => $ignored_prop_names]);
  }

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index): bool {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() === Page::ENTITY_TYPE_ID) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    $properties = [];
    if (!$datasource) {
      $definition = [
        'label' => $this->t('Canvas component tree inputs'),
        'description' => $this->t('The component tree inputs for the indexed item.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['canvas_component_tree_inputs'] = new ProcessorProperty($definition);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    try {
      $entity = $item->getOriginalObject()?->getValue();
    }
    catch (SearchApiException) {
      return;
    }
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    try {
      $inputs = $this->componentTreeInputExtractor->extract($entity, $this->getConfiguration()['ignored_prop_names']);
    }
    catch (\LogicException) {
      return;
    }

    $fields = $item->getFields();
    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($fields, NULL, 'canvas_component_tree_inputs');
    foreach ($fields as $field) {
      foreach ($inputs as $component_props) {
        foreach ($component_props as $input) {
          $field->addValue($input);
        }
      }
    }
  }

}
