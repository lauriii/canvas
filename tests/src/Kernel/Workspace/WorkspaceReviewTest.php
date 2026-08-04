<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType;
use Drupal\canvas\Workspace\WorkspaceEntityLockedException;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\canvas\Workspace\WorkspaceReviewAccessException;
use Drupal\canvas\Workspace\WorkspaceScheduledPublish;
use Drupal\canvas\WorkspaceReviewPermissions;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\workflows\Entity\Workflow;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;

/**
 * The workspace review workflow, scheduling, and cross-workspace locks.
 *
 * @coversDefaultClass \Drupal\canvas\Workspace\WorkspaceReview
 */
#[Group('canvas')]
#[Group('canvas_auto_save')]
final class WorkspaceReviewTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'field',
    'entity_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    // Workspaces wraps the alias manager, so path_alias storage must exist
    // for entity saves inside workspaces.
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->installEntitySchema('canvas_auto_save_snapshot');

    $admin = $this->createUser([
      'administer workspaces',
      self::transitionPermission('submit_for_review'),
      self::transitionPermission('approve'),
      self::transitionPermission('send_back'),
      // The publish pipeline checks per-item update access against the
      // publishing account, which the scheduled publish resolves to this
      // user; entity_test updates require this permission.
      'administer entity_test content',
    ]);
    self::assertInstanceOf(User::class, $admin);
    $this->setCurrentUser($admin);

    Workspace::create([
      'id' => AutoSaveWorkspace::ID,
      'label' => AutoSaveWorkspace::LABEL,
      'uid' => (int) $admin->id(),
    ])->save();
    Workspace::create([
      'id' => 'campaign',
      'label' => 'Campaign',
      'uid' => (int) $admin->id(),
      'canvas_require_review' => TRUE,
    ])->save();
  }

  private static function transitionPermission(string $transition_id): string {
    return WorkspaceReviewPermissions::transitionPermission(WorkspaceReviewWorkflowType::DEFAULT_WORKFLOW_ID, $transition_id);
  }

  private function review(): WorkspaceReview {
    $review = $this->container->get(WorkspaceReview::class);
    self::assertInstanceOf(WorkspaceReview::class, $review);
    return $review;
  }

  private function campaign(): Workspace {
    $workspace = $this->container->get('entity_type.manager')->getStorage('workspace')->loadUnchanged('campaign');
    self::assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Named workspaces require review by default; the Main workspace does not.
   */
  public function testRequireReviewDefaults(): void {
    $main = Workspace::load(AutoSaveWorkspace::ID);
    self::assertNotNull($main);
    self::assertFalse($this->review()->requiresReview($main));
    self::assertFalse($this->review()->isPublishBlocked($main));

    $named = Workspace::create(['id' => 'implicit', 'label' => 'Implicit']);
    $named->save();
    self::assertTrue($this->review()->requiresReview($named));
    self::assertTrue($this->review()->isPublishBlocked($named));
  }

  /**
   * Transitions are permission-gated and follow the workflow's state machine.
   */
  public function testTransitions(): void {
    $review = $this->review();
    $submitter = $this->createUser([self::transitionPermission('submit_for_review')]);
    $approver = $this->createUser([
      self::transitionPermission('approve'),
      self::transitionPermission('send_back'),
    ]);
    self::assertInstanceOf(User::class, $submitter);
    self::assertInstanceOf(User::class, $approver);

    // A transition the workflow does not define is rejected.
    try {
      $review->transition($this->campaign(), 'promote', $approver);
      $this->fail('An unknown transition must be rejected.');
    }
    catch (\InvalidArgumentException) {
    }

    // "approve" does not apply to the draft state.
    try {
      $review->transition($this->campaign(), 'approve', $approver);
      $this->fail('draft → approved must be rejected.');
    }
    catch (\InvalidArgumentException) {
    }

    // Each transition requires its own permission; the available set is
    // filtered accordingly.
    self::assertSame(['submit_for_review'], \array_keys($review->getAvailableTransitions($this->campaign(), $submitter)));
    self::assertSame([], \array_keys($review->getAvailableTransitions($this->campaign(), $approver)));
    try {
      $review->transition($this->campaign(), 'submit_for_review', $approver);
      $this->fail('Submitting without the transition permission must be rejected.');
    }
    catch (WorkspaceReviewAccessException) {
    }
    $review->transition($this->campaign(), 'submit_for_review', $submitter);
    self::assertSame(WorkspaceReview::STATUS_IN_REVIEW, $review->getStatus($this->campaign()));
    self::assertSame('In review', $review->getStatusLabel($this->campaign()));

    // Approving requires the approve transition's permission.
    try {
      $review->transition($this->campaign(), 'approve', $submitter);
      $this->fail('Approving without the transition permission must be rejected.');
    }
    catch (WorkspaceReviewAccessException) {
    }
    $review->transition($this->campaign(), 'approve', $approver);
    self::assertSame(WorkspaceReview::STATUS_APPROVED, $review->getStatus($this->campaign()));
    self::assertTrue($review->isApproved($this->campaign()));
    self::assertFalse($review->isPublishBlocked($this->campaign()));

    // Sending back to draft leaves the approved state, cancelling any
    // schedule.
    $workspace = $this->campaign();
    $workspace->set('canvas_scheduled_publish_at', \time() + 1000);
    $workspace->save();
    $review->transition($this->campaign(), 'send_back', $approver);
    self::assertSame(WorkspaceReview::STATUS_DRAFT, $review->getStatus($this->campaign()));
    self::assertNull($this->campaign()->get('canvas_scheduled_publish_at')->value);
  }

  /**
   * A site-defined workflow of the review type drives the review process.
   */
  public function testCustomWorkflow(): void {
    Workflow::create([
      'id' => 'legal',
      'label' => 'Legal review',
      'type' => WorkspaceReviewWorkflowType::PLUGIN_ID,
      'type_settings' => [
        'states' => [
          'open' => ['label' => 'Open', 'weight' => 0, 'approved_for_publish' => FALSE],
          'signed_off' => ['label' => 'Signed off', 'weight' => 1, 'approved_for_publish' => TRUE],
        ],
        'transitions' => [
          'sign_off' => ['label' => 'Sign off', 'from' => ['open'], 'to' => 'signed_off', 'weight' => 0],
          'reopen' => ['label' => 'Reopen', 'from' => ['signed_off'], 'to' => 'open', 'weight' => 1],
        ],
        'initial_state' => 'open',
      ],
    ])->save();
    $workspace = $this->campaign();
    $workspace->set('canvas_review_workflow', 'legal');
    $workspace->save();

    $review = $this->review();
    // The stored "draft" state is not a state of the legal workflow, so the
    // workspace resolves to the workflow's initial state.
    self::assertSame('open', $review->getStatus($this->campaign()));
    self::assertTrue($review->isInitialState($this->campaign()));
    self::assertSame('Open', $review->getStatusLabel($this->campaign()));
    self::assertTrue($review->isPublishBlocked($this->campaign()));

    $signer = $this->createUser([WorkspaceReviewPermissions::transitionPermission('legal', 'sign_off')]);
    self::assertInstanceOf(User::class, $signer);
    $review->transition($this->campaign(), 'sign_off', $signer);
    self::assertSame('signed_off', $review->getStatus($this->campaign()));
    self::assertSame('Signed off', $review->getStatusLabel($this->campaign()));
    self::assertTrue($review->isApproved($this->campaign()));
    self::assertFalse($review->isPublishBlocked($this->campaign()));

    // A staged-write demotion returns to the custom workflow's initial state.
    $review->demoteOnStagedWrite($this->campaign());
    self::assertSame('open', $review->getStatus($this->campaign()));
  }

  /**
   * A staged write demotes the workspace and cancels its schedule.
   */
  public function testDemoteOnStagedWrite(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();

    $workspace = $this->campaign();
    $workspace->set('canvas_workspace_status', WorkspaceReview::STATUS_APPROVED);
    $workspace->set('canvas_scheduled_publish_at', \time() + 1000);
    $workspace->save();

    /** @var \Drupal\workspaces\WorkspaceManagerInterface $workspace_manager */
    $workspace_manager = $this->container->get('workspaces.manager');
    $workspace_manager->executeInWorkspace('campaign', function () use ($entity): void {
      $draft = clone $entity;
      $draft->set('name', 'campaign draft');
      $auto_save_manager = $this->container->get(AutoSaveManager::class);
      self::assertInstanceOf(AutoSaveManager::class, $auto_save_manager);
      $auto_save_manager->saveEntity($draft, immediateWorkspacePersist: TRUE);
    });

    self::assertSame(WorkspaceReview::STATUS_DRAFT, $this->review()->getStatus($this->campaign()));
    self::assertNull($this->campaign()->get('canvas_scheduled_publish_at')->value);
  }

  /**
   * Cron publishes due approved workspaces; failures cancel the schedule.
   */
  public function testScheduledPublish(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $workspace_manager */
    $workspace_manager = $this->container->get('workspaces.manager');
    $workspace_manager->executeInWorkspace('campaign', function () use ($entity): void {
      $draft = clone $entity;
      $draft->set('name', 'scheduled draft');
      $auto_save_manager = $this->container->get(AutoSaveManager::class);
      self::assertInstanceOf(AutoSaveManager::class, $auto_save_manager);
      $auto_save_manager->saveEntity($draft, immediateWorkspacePersist: TRUE);
    });

    $scheduler = $this->container->get(WorkspaceScheduledPublish::class);
    self::assertInstanceOf(WorkspaceScheduledPublish::class, $scheduler);

    // Not scheduled: nothing publishes.
    self::assertSame(0, $scheduler->publishDue());

    // The due check compares against the request time, which in kernel tests
    // is the (much earlier) process start time, not the wall clock.
    $due = $this->container->get('datetime.time')->getRequestTime() - 10;

    // Scheduled but demoted (draft): the review gate cancels the schedule
    // and records the error instead of publishing.
    $workspace = $this->campaign();
    $workspace->set('canvas_scheduled_publish_at', $due);
    $workspace->set('canvas_scheduled_publish_by', $this->container->get('current_user')->id());
    $workspace->save();
    self::assertSame(0, $scheduler->publishDue());
    self::assertNull($this->campaign()->get('canvas_scheduled_publish_at')->value);
    self::assertNotNull($this->campaign()->get('canvas_scheduled_publish_error')->value);

    // Approved and due: the workspace publishes and the schedule is
    // consumed.
    $workspace = $this->campaign();
    $workspace->set('canvas_workspace_status', WorkspaceReview::STATUS_APPROVED);
    $workspace->set('canvas_scheduled_publish_at', $due);
    $workspace->set('canvas_scheduled_publish_by', $this->container->get('current_user')->id());
    $workspace->save();
    self::assertSame(1, $scheduler->publishDue());
    $live = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub')->loadUnchanged((string) $entity->id());
    self::assertInstanceOf(EntityTestMulRevPub::class, $live);
    self::assertSame('scheduled draft', $live->get('name')->value);
    // Publishing completes a named workspace: it is deleted.
    self::assertNull($this->container->get('entity_type.manager')->getStorage('workspace')->loadUnchanged('campaign'));
  }

  /**
   * A staged write for an entity owned by another workspace is rejected.
   */
  public function testCrossWorkspaceLock(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $workspace_manager */
    $workspace_manager = $this->container->get('workspaces.manager');
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $auto_save_manager);

    $workspace_manager->executeInWorkspace('campaign', function () use ($entity, $auto_save_manager): void {
      $draft = clone $entity;
      $draft->set('name', 'campaign draft');
      $auto_save_manager->saveEntity($draft, immediateWorkspacePersist: TRUE);
    });

    // The same entity cannot be staged in the Main workspace while the
    // campaign owns it.
    try {
      $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($entity, $auto_save_manager): void {
        $draft = clone $entity;
        $draft->set('name', 'main draft');
        $auto_save_manager->saveEntity($draft, immediateWorkspacePersist: TRUE);
      });
      $this->fail('A staged write for an entity owned by another workspace must throw.');
    }
    catch (WorkspaceEntityLockedException $e) {
      self::assertSame('campaign', $e->workspaceId);
      self::assertSame('Campaign', $e->workspaceLabel);
    }

    // Discarding the owning workspace's staging releases the entity.
    $workspace_manager->executeInWorkspace('campaign', static function () use ($entity, $auto_save_manager): void {
      $auto_save_manager->delete($entity);
    });
    $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($entity, $auto_save_manager): void {
      $draft = clone $entity;
      $draft->set('name', 'main draft');
      $auto_save_manager->saveEntity($draft, immediateWorkspacePersist: TRUE);
    });
    $main_staged = $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, static fn () => $auto_save_manager->getAutoSaveEntity($entity));
    self::assertFalse($main_staged->isEmpty());
  }

}
