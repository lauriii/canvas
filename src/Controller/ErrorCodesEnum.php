<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

enum ErrorCodesEnum: int {

  case UnexpectedItemInPublishRequest = 1;
  case MissingItemInPublishRequest = 2;
  case UnmatchedItemInPublishRequest = 3;

  public function getMessage(): string {
    return match($this) {
      self::UnexpectedItemInPublishRequest =>
        'An unexpected item was found in the publish request. Please refresh your page and try again.',

      self::MissingItemInPublishRequest =>
        'A required item is missing from the publish request. Please refresh your page and try again.',

      self::UnmatchedItemInPublishRequest =>
        'An item in the publish request did not match the expected format or value. Please refresh your page and try again.',
    };
  }

}
