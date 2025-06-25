<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;

final class AutoSaveEntity implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  public function __construct(public readonly ?EntityInterface $entity, public readonly ?string $hash) {
    $this->cacheTags = [AutoSaveManager::CACHE_TAG];
  }

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

}
