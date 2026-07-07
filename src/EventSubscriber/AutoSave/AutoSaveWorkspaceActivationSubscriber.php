<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Activates the shared auto-save workspace for Canvas API requests.
 *
 * Runs after core WorkspaceRequestSubscriber (priority 33) so negotiators do
 * not replace the active workspace with Live before Canvas persists or
 * validates entities that are tracked only in the auto-save workspace.
 */
final class AutoSaveWorkspaceActivationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RequestStack $requestStack,
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?WorkspaceManagerInterface $workspaceManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 28],
      // After DeferredAutoSaveFlusher::onTerminate() (priority 0), which
      // needs the workspace active when flushing.
      KernelEvents::TERMINATE => ['onKernelTerminate', -10],
    ];
  }

  /**
   * Deactivates the auto-save workspace at the end of the request.
   *
   * The workspace manager is a container service: in long-running processes
   * (and kernel tests) an active workspace would otherwise leak into
   * subsequent requests or entity saves, silently turning live saves into
   * workspace-pending revisions.
   */
  public function onKernelTerminate(): void {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return;
    }
    if ($this->workspaceManager->getActiveWorkspace()?->id() === AutoSaveWorkspace::ID) {
      $this->workspaceManager->switchToLive();
    }
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $route = $this->requestStack->getCurrentRequest()?->attributes->get('_route');
    if (!\is_string($route) || !\str_starts_with($route, 'canvas.api.')) {
      return;
    }
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }
    if (!self::isCanvasAutoSaveUser($this->currentUser)) {
      return;
    }
    if ($this->workspaceManager === NULL) {
      return;
    }
    /** @var \Drupal\workspaces\WorkspaceInterface|null $workspace */
    $workspace = $this->entityTypeManager->getStorage('workspace')->load(AutoSaveWorkspace::ID);
    if (!$workspace) {
      return;
    }
    // Activate without persisting to a negotiator: the auto-save workspace
    // must not leak into the user's session. Core reads the $persist argument
    // via func_get_arg(); its parameter is still commented out in the
    // signature, so PHPStan sees only one declared parameter.
    // @phpstan-ignore arguments.count
    $this->workspaceManager->setActiveWorkspace($workspace, FALSE);
  }

  private static function isCanvasAutoSaveUser(AccountProxyInterface $account): bool {
    $permissions = [
      AutoSaveManager::PUBLISH_PERMISSION,
      'edit canvas_page',
      'create canvas_page',
      'administer components',
      'administer code components',
      'administer brand kit',
      'administer content templates',
    ];
    foreach ($permissions as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
