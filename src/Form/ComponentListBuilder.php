<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @todo Figure out if UX of "create page builder component to see what components are available" is good enough. We might want to add "tree view" of all the components, with ability to quickly "add page builder component" definition for any of them.
 */
final class ComponentListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly ComponentPluginManager $pluginManagerSdc,
  ) {
    parent::__construct($entity_type, $storage);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('plugin.manager.sdc'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['component'] = $this->t('Component identifier');
    $header['component_label'] = $this->t('Component name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof Component);
    $component_plugin = $this->pluginManagerSdc->find($entity->getComponentMachineName());
    assert($component_plugin instanceof ComponentPlugin);

    // Human-readable name of component is not a required property, so we use derivative id as fallback value.
    // @see https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json
    if (is_array($component_plugin->getPluginDefinition()) && array_key_exists('name', $component_plugin->getPluginDefinition())) {
      $component_plugin_label = $component_plugin->getPluginDefinition()['name'];
    }
    else {
      $component_plugin_label = $component_plugin->getDerivativeId();
    }

    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $row['component'] = $entity->getComponentMachineName();
    $row['component_label'] = $component_plugin_label;

    return $row + parent::buildRow($entity);
  }

}
