<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\experience_builder\AutoSave\AutoSaveManager;

final class AutoSaveData implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  public function __construct(public readonly ?array $data, public readonly ?string $hash) {
    $this->cacheTags = [AutoSaveManager::CACHE_TAG];
  }

  public function isEmpty(): bool {
    return $this->data === NULL;
  }

}
