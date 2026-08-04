<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One permission per transition of every Canvas workspace review workflow.
 *
 * Mirrors content_moderation's permission scheme so review steps defined as
 * workflows get transition-level access control: "use {workflow} transition
 * {transition}".
 *
 * @see \Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType
 * @see \Drupal\canvas\Workspace\WorkspaceReview
 */
final class WorkspaceReviewPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * The permission string gating one transition of one workflow.
   */
  public static function transitionPermission(string $workflow_id, string $transition_id): string {
    return "use $workflow_id transition $transition_id";
  }

  /**
   * Permission callback for canvas.permissions.yml.
   *
   * @return array<string, array<string, mixed>>
   */
  // @phpstan-ignore shipmonk.deadMethod (permission_callbacks in canvas.permissions.yml)
  public function transitions(): array {
    $permissions = [];
    if (!$this->entityTypeManager->hasDefinition('workflow')) {
      return $permissions;
    }
    /** @var \Drupal\workflows\WorkflowInterface $workflow */
    foreach ($this->entityTypeManager->getStorage('workflow')->loadMultiple() as $workflow) {
      if ($workflow->getTypePlugin()->getPluginId() !== WorkspaceReviewWorkflowType::PLUGIN_ID) {
        continue;
      }
      foreach ($workflow->getTypePlugin()->getTransitions() as $transition) {
        $permissions[self::transitionPermission((string) $workflow->id(), (string) $transition->id())] = [
          'title' => $this->t('%workflow workflow: use %transition transition', [
            '%workflow' => $workflow->label(),
            '%transition' => $transition->label(),
          ]),
          'dependencies' => [
            $workflow->getConfigDependencyKey() => [$workflow->getConfigDependencyName()],
          ],
        ];
      }
    }
    return $permissions;
  }

}
