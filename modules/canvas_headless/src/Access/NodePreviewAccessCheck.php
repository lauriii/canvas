<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Access;

use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\node\Access\NodePreviewAccessCheck as CoreNodePreviewAccessCheck;
use Drupal\node\NodeInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks read-only access to unsaved node previews against the token's editor.
 *
 * Core requires create or update access to view a node form preview. Preview
 * tokens intentionally lack write permissions, so check their subject only
 * for this read, after core's converter has loaded that user's private draft.
 */
final class NodePreviewAccessCheck implements AccessInterface {

  public function __construct(
    private readonly CoreNodePreviewAccessCheck $inner,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Preserves core access checks without granting write access to the token.
   */
  public function access(AccountInterface $account, NodeInterface $node_preview): AccessResultInterface {
    $account = $account instanceof AccountProxyInterface ? $account->getAccount() : $account;
    if (
      !$this->requestStack->getCurrentRequest()?->attributes->has(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE) ||
      !PreviewTokenInspector::hasPreviewScope($account)
    ) {
      return $this->inner->access($account, $node_preview);
    }
    \assert($account instanceof TokenAuthUserInterface);
    $access_handler = $this->entityTypeManager->getAccessControlHandler('node');
    $access_handler->resetCache();
    $this->accountSwitcher->switchTo($account->getSubject());
    try {
      $access = $this->inner->access($account->getSubject(), $node_preview);
      \assert($access instanceof RefinableCacheableDependencyInterface);
      return $access
        ->addCacheContexts(['oauth2_scopes'])
        ->mergeCacheMaxAge(0);
    }
    finally {
      $this->accountSwitcher->switchBack();
      $access_handler->resetCache();
    }
  }

}
