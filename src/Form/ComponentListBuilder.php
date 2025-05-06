<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\Component;

/**
 * @todo Figure out if UX of "create page builder component to see what components are available" is good enough. We might want to add "tree view" of all the components, with ability to quickly "add page builder component" definition for any of them.
 */
final class ComponentListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['component_source'] = $this->t('Component type');
    $header['component_label'] = $this->t('Component name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof Component);

    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $source = $entity->getComponentSource();
    $row['component_source'] = $source->getPluginDefinition()['label'];
    $row['component_label'] = $source->getComponentDescription();

    return $row + parent::buildRow($entity);
  }

  public function getOperations(EntityInterface $entity): array {
    return [
      'audit' => [
        'title' => $this->t('Audit'),
        'weight' => 10,
        'url' => Url::fromRoute('entity.component.audit', ['component' => $entity->id()]),
      ],
    ] + parent::getOperations($entity);
  }

}
