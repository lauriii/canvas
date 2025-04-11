<?php

declare(strict_types=1);

namespace Drupal\xb_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: "xb_test_block_input_unvalidatable",
  admin_label: new TranslatableMarkup("Test Block with settings, but does not meet requirements."),
)]
class XbTestBlockInputUnvalidatable extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['name' => 'XB'];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('Enter a name to display in the block.'),
      '#default_value' => $this->configuration['name'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['name'] = $form_state->getValue('name');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#markup' => $this->t('<div>Hello, :name!</div>', [':name' => $this->configuration['name']]),
    ];
  }

}
