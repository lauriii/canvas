<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\ComponentPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\ConfigFormBaseTrait;
use Drupal\Core\Form\FormStateInterface;

class ComponentEditForm extends EntityForm implements ContainerInjectionInterface {
  use ConfigFormBaseTrait;

  public function __construct(
    protected readonly ComponentPluginManager $pluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.sdc')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['component.component'];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    assert($this->entity instanceof Component);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->entity->label(),
      '#description' => $this->t("Example: 'Image component'."),
      '#required' => TRUE,
    ];

    $components = $this->pluginManager->getAllComponents();
    $options = [];
    foreach ($components as $component) {
      assert($component instanceof ComponentPlugin);
      if (Component::loadByComponentMachineName($component->getPluginId()) instanceof Component) {
        continue;
      }
      if (is_array($component->getPluginDefinition()) && array_key_exists('name', $component->getPluginDefinition())) {
        $value = $component->getPluginDefinition()['name'];
      }
      else {
        $value = $component->getDerivativeId();
      }
      $options[$component->getBaseId()][Component::convertMachineNameToId($component->getPluginId())] = $value;
    }

    if ($this->entity->isNew()) {
      $form['component'] = [
        '#type' => 'select',
        '#title' => $this->t('Component'),
        '#description' => $this->t("Component to be used in Experience Builder"),
        '#options' => $options,
        '#required' => TRUE,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    assert($this->entity instanceof Component);
    $status = $this->entity->save();
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    if ($status == SAVED_UPDATED) {
      $this->messenger()->addStatus($this->t('Component %label has been updated.', ['%label' => $this->entity->label()]));
      $this->logger('experience_builder')->notice('Component %label has been updated.', ['%label' => $this->entity->label()]);
      return $status;
    }
    $this->messenger()->addStatus($this->t('Component %label has been added.', ['%label' => $this->entity->label()]));
    $this->logger('experience_builder')->notice('Component %label has been added.', ['%label' => $this->entity->label()]);

    return $status;
  }

}
