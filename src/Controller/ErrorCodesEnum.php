<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

enum ErrorCodesEnum: int {

  case UnexpectedItemInPublishRequest = 1;
  case UnmatchedItemInPublishRequest = 2;
  case GlobalAssetNotPublished = 3;
  case ItemEntityUpdatedExternally = 4;
  case NoActiveConflictMatchingConflictId = 5;
  case AutoSaveItemNotFound = 6;

  public function getMessage(): string {
    return match($this) {
      self::UnexpectedItemInPublishRequest =>
        'An unexpected item was found in the publish request. Please refresh your page and try again.',
      self::UnmatchedItemInPublishRequest =>
        'An item in the publish request did not match the expected format or value. Please refresh your page and try again.',
      self::GlobalAssetNotPublished =>
        'When publishing components you must also publish the Global CSS and any pending Brand kit changes. Please select them and retry.',
      self::ItemEntityUpdatedExternally => 'Conflict detected.',
      // Race condition for resolving the conflict: somebody else resolved the
      // conflict, but the auto-save still exists.
      self::NoActiveConflictMatchingConflictId => 'No active conflict matching this conflict id found.',
      // Race condition for auto-save item being removed if:
      // - somebody else resolved the conflict + published the auto-save
      // - somebody else deleted the auto-save
      self::AutoSaveItemNotFound => 'No auto-save item found to resolve a conflict on.',
    };
  }

}
