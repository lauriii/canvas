<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\WorkflowType;

use Drupal\canvas\Form\WorkspaceReviewStateForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workflows\Attribute\WorkflowType;
use Drupal\workflows\Plugin\WorkflowTypeBase;

/**
 * The workflow type driving the Canvas workspace review process.
 *
 * Sites define the review steps as an ordinary core workflow: states carry
 * an "approved for publishing" flag (the publish gate), and one state is the
 * initial state that staged writes demote to. Canvas ships a default
 * workflow (draft → in review → approved) of this type.
 *
 * @see \Drupal\canvas\Workspace\WorkspaceReview
 */
#[WorkflowType(
  id: 'canvas_workspace_review',
  label: new TranslatableMarkup('Canvas workspace review'),
  forms: [
    'state' => WorkspaceReviewStateForm::class,
  ],
)]
class WorkspaceReviewWorkflowType extends WorkflowTypeBase {

  public const string PLUGIN_ID = 'canvas_workspace_review';

  /**
   * The ID of the workflow Canvas ships and uses by default.
   */
  public const string DEFAULT_WORKFLOW_ID = 'canvas_workspace_review';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'states' => [],
      'initial_state' => 'draft',
    ];
  }

  /**
   * Whether a state counts as approved for publishing.
   */
  public function isApprovedState(string $state_id): bool {
    return !empty($this->configuration['states'][$state_id]['approved_for_publish']);
  }

  /**
   * The state staged writes demote to, and the state new workspaces start in.
   */
  public function getInitialStateId(): string {
    $initial = $this->configuration['initial_state'] ?? 'draft';
    if (\is_string($initial) && $this->hasState($initial)) {
      return $initial;
    }
    // Fall back to the first state by weight so a misconfigured workflow
    // still yields a working state machine.
    $first = \array_key_first($this->getStates());
    return $first === NULL ? 'draft' : (string) $first;
  }

  /**
   * {@inheritdoc}
   */
  // @phpstan-ignore shipmonk.deadMethod (called by the workflows UI forms)
  public function deleteState($state_id) {
    parent::deleteState($state_id);
    unset($this->configuration['states'][$state_id]);
    if (($this->configuration['initial_state'] ?? NULL) === $state_id) {
      $this->configuration['initial_state'] = NULL;
    }
    return $this;
  }

}
