<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Converges symmetric (non-translatable) field columns across translations.
 *
 * The presave hook of content_translation is the single synchronization pass
 * that runs when an entity is saved. Canvas validates before saving — on the
 * edit path and on the publish path — and both must evaluate the converged
 * state, or ComponentTreeSymmetricalTranslationConstraint rejects an
 * intermediate state that ::save() converges anyway. (ADR 0012: the
 * synchronizer converges at save time; constraints validate the result.)
 *
 * TRICKY: an entity that will subsequently be saved must NEVER be synchronized
 * here. FieldTranslationSynchronizer::synchronizeItems() maps pre-edit to
 * post-edit deltas from the source translation and applies that map to the
 * target translations assuming they still hold the pre-edit item order. Running
 * it on an already-synchronized target after a structural (reorder/insert) edit
 * pulls inputs from whatever component instance now occupies each pre-edit
 * delta, corrupting the target translation's inputs. Synchronize a throwaway
 * copy, or an entity that is only auto-saved (auto-save entries capture the
 * edited translation alone, so converged siblings are never persisted).
 *
 * @see \Drupal\content_translation\Hook\ContentTranslationHooks::entityPresave()
 * @see \Drupal\content_translation\FieldTranslationSynchronizer::synchronizeItems()
 * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer
 */
trait SymmetricalTranslationSynchronizationTrait {

  /**
   * Checks whether the given entity's translations can be converged.
   *
   * @param \Drupal\content_translation\FieldTranslationSynchronizerInterface|null $synchronizer
   *   The synchronizer, or NULL when content_translation is not installed.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if ::synchronizeTranslations() would synchronize this entity.
   */
  private static function canSynchronizeTranslations(?FieldTranslationSynchronizerInterface $synchronizer, EntityInterface $entity): bool {
    return $synchronizer !== NULL
      && $entity instanceof ContentEntityInterface
      && !$entity->isNew()
      // Synchronization sources from the default translation, so the entity in
      // hand must BE the default translation: a non-default translation object
      // is itself a synchronization target, and converging it would silently
      // overwrite the very edit that is about to be validated.
      && $entity->isDefaultTranslation()
      && $entity->isTranslatable()
      && \count($entity->getTranslationLanguages()) > 1;
  }

  /**
   * Converges the given entity's symmetric columns, in place.
   *
   * No-op when ::canSynchronizeTranslations() does not hold, and for asymmetric
   * translations: the decorated synchronizer only acts on fields in symmetric
   * mode.
   *
   * @param \Drupal\content_translation\FieldTranslationSynchronizerInterface|null $synchronizer
   *   The synchronizer, or NULL when content_translation is not installed.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to converge. Must not subsequently be saved via ::save().
   */
  private static function synchronizeTranslations(?FieldTranslationSynchronizerInterface $synchronizer, EntityInterface $entity): void {
    if (!self::canSynchronizeTranslations($synchronizer, $entity)) {
      return;
    }
    \assert($synchronizer instanceof FieldTranslationSynchronizerInterface);
    \assert($entity instanceof ContentEntityInterface);
    $synchronizer->synchronizeFields($entity, $entity->getUntranslated()->language()->getId());
  }

}
