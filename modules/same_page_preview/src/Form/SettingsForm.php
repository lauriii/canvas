<?php

namespace Drupal\same_page_preview\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Same Page Preview settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $entity_type_storage;

  /**
   * @inheritDoc
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityStorageInterface $entity_type_storage) {
    parent::__construct($config_factory);
    $this->entity_type_storage = $entity_type_storage;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')->getStorage('node_type')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'same_page_preview_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['same_page_preview.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Settings in personalization are bound by admin.js.
    $form['personalization'] = [
      '#type' => 'details',
      '#title' => $this->t('Personalization settings'),
      '#open' => TRUE,
    ];

    $form['personalization']['toggle_preview_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('On by default'),
    ];

    $form['content_type_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Type settings'),
      '#open' => TRUE,
    ];

    foreach ($this->entity_type_storage->loadMultiple() as $type) {
      $setting = $type->getThirdPartySetting('same_page_preview', 'active', TRUE);
      $form['content_type_settings'][$type->id()] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable for @label content types', ['@label' => $type->label()]),
        '#default_value' => $setting,
      ];
    }

    $form['#attached']['library'][] = 'same_page_preview/admin';
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach ($this->entity_type_storage->loadMultiple() as $type) {
      $type->setThirdPartySetting('same_page_preview', 'active', $form_state->getValue($type->id()));
      $type->save();
    }
  }


}
