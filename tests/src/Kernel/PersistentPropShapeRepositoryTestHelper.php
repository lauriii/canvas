<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\PropShape\PersistentPropShapeRepository;

/**
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
class PersistentPropShapeRepositoryTestHelper extends PersistentPropShapeRepository {

  /**
   * Helper for being able to persist the prop shape repository in tests.
   *
   * @param int|null $cache_created
   *   A unix timestamp for ensuring the cache can be persisted. NULL if you
   *   want the default behavior.
   */
  public function setCacheCreated(?int $cache_created = 1): void {
    $this->cacheCreated = $cache_created;
  }

}
