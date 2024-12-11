<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

enum ErrorCodesEnum: int {

  case UnexpectedItemInPublishRequest = 1;
  case MissingItemInPublishRequest = 2;
  case UnmatchedItemInPublishRequest = 3;

  public function getMessage(): string {
    return match($this) {
      self::UnexpectedItemInPublishRequest => 'This item is unexpected.',
        self::MissingItemInPublishRequest => 'Expected item not present.',
        self::UnmatchedItemInPublishRequest => 'Item did not match expected value.',
    };
  }

}
