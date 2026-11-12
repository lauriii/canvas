<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves a translation's fork state from storage and its auto-save draft.
 *
 * The single place that combines both: callers that only hold one of them
 * (an in-memory entity being saved, synchronized or validated) use
 * ComponentTreeTranslationFork::isForkedTranslation() on it instead.
 */
final readonly class TranslationForkStateResolver {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Resolves the fork state of one translation of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Any translation of the entity, stored or reconstructed from a draft.
   * @param string $langcode
   *   The translation's language. It need not exist in storage.
   */
  public function resolve(ContentEntityInterface $entity, string $langcode): TranslationForkState {
    $draft = $this->autoSaveManager->getTranslationAutoSaveEntity($entity, $langcode)->entity;
    return $this->getState(
      $entity,
      $langcode,
      $draft instanceof ContentEntityInterface ? ComponentTreeTranslationFork::isForkedTranslation($draft) : NULL,
    );
  }

  /**
   * Resolves the fork state of the translation an auto-save draft belongs to.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $draft
   *   A translation reconstructed from its auto-save draft.
   */
  public function resolveDraft(ContentEntityInterface $draft): TranslationForkState {
    return $this->getState($draft, $draft->language()->getId(), ComponentTreeTranslationFork::isForkedTranslation($draft));
  }

  /**
   * @param bool|null $draft_forked
   *   The draft's fork flag, or NULL without a draft.
   */
  private function getState(ContentEntityInterface $entity, string $langcode, ?bool $draft_forked): TranslationForkState {
    $stored = $entity->id() === NULL
      ? NULL
      : $this->entityTypeManager->getStorage($entity->getEntityTypeId())->load($entity->id());
    $stored_forked = $stored instanceof ContentEntityInterface
      && $stored->hasTranslation($langcode)
      && ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation($langcode));
    return match (TRUE) {
      $draft_forked === NULL => $stored_forked ? TranslationForkState::Forked : TranslationForkState::Symmetric,
      $stored_forked && $draft_forked => TranslationForkState::Forked,
      $stored_forked => TranslationForkState::PendingUnfork,
      $draft_forked => TranslationForkState::PendingFork,
      default => TranslationForkState::Symmetric,
    };
  }

}
