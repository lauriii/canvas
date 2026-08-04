<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflows\Plugin\WorkflowTypeStateFormBase;
use Drupal\workflows\StateInterface;

/**
 * Per-state settings for the Canvas workspace review workflow type.
 *
 * @see \Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType
 */
final class WorkspaceReviewStateForm extends WorkflowTypeStateFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?StateInterface $state = NULL) {
    $state = $form_state->get('state');
    \assert($this->workflowType instanceof WorkspaceReviewWorkflowType);

    $form = [];
    $form['approved_for_publish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Approved for publishing'),
      '#description' => $this->t('Workspaces in this state may be published (and scheduled). Any staged write returns the workspace to the initial state.'),
      '#default_value' => $state instanceof StateInterface && $this->workflowType->isApprovedState($state->id()),
    ];
    return $form;
  }

}
