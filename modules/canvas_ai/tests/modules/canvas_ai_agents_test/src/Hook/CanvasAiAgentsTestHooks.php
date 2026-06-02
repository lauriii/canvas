<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_agents_test\Hook;

use Drupal\canvas\Entity\Component;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations for canvas_ai_agents_test.
 */
final class CanvasAiAgentsTestHooks {

  /**
   * Implements hook_component_access().
   */
  #[Hook('component_access')]
  public function canvasAiAgentsTestComponentAccess(Component $entity, string $operation, AccountInterface $account): AccessResultInterface {
    if ($entity->get('provider') !== 'canvas_test_sdc') {
      return AccessResult::forbidden();
    }
    return AccessResult::neutral();
  }

}
