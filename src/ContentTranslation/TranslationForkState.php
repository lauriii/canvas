<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

/**
 * A translation's component tree fork state, as the Canvas editor sees it.
 *
 * Forking and unforking happen on the translation's auto-save draft and become
 * permanent when the draft is published, so the state combines the stored
 * fork flag with the draft's.
 *
 * @see \Drupal\canvas\ContentTranslation\TranslationForkStateResolver
 */
enum TranslationForkState {

  // Not forked in storage, nor in a draft.
  case Symmetric;
  // Forked in storage, and in the draft if there is one.
  case Forked;
  // Forked in the draft only. Includes translations that do not exist in
  // storage yet: they exist only in their draft until it is published.
  case PendingFork;
  // Forked in storage, while the draft reverts it to symmetric.
  case PendingUnfork;

  /**
   * Whether the translation owns its component tree in the editor.
   *
   * The draft decides: a pending fork is edited, a pending unfork previewed.
   */
  public function isForked(): bool {
    return $this === self::Forked || $this === self::PendingFork;
  }

}
