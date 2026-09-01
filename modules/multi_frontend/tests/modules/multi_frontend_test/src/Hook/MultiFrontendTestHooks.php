<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Test hooks.
 */
final class MultiFrontendTestHooks {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_entity_field_access().
   *
   * Lets a test deny view access to the body field, so that a producer
   * reading it through ProducerContext can be shown to omit it.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view' && $field_definition->getName() === 'body' && $this->state->get('multi_frontend_test.deny_body', FALSE)) {
      return AccessResult::forbidden('Denied by multi_frontend_test.')
        ->addCacheTags(['multi_frontend_test.deny_body']);
    }
    return AccessResult::neutral();
  }

}
