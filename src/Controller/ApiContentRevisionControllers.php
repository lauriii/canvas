<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * HTTP API for a page's history: its revisions, and restoring one.
 *
 * Restoring does not publish. It turns the revision into the page's draft, so
 * it is reviewed and published like any other change.
 *
 * @internal This HTTP API is intended only for the Canvas UI.
 *
 * @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types.
 */
final class ApiContentRevisionControllers extends ApiControllerBase {

  /**
   * How many revisions the history lists.
   */
  private const int LIMIT = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Lists the revisions of a page in the language being edited, newest first.
   */
  public function list(Page $canvas_page): JsonResponse {
    $storage = $this->storage();
    $langcode = $this->langcode();
    $entity_type = $storage->getEntityType();
    $id_key = $entity_type->getKey('id');
    $langcode_key = $entity_type->getKey('langcode');
    $revision_key = $entity_type->getKey('revision');
    \assert(\is_string($id_key) && \is_string($langcode_key) && \is_string($revision_key));
    $id = $canvas_page->id();
    \assert($id !== NULL);
    // Only revisions that changed this translation: others look identical to
    // their predecessor in this language.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition($id_key, $id)
      ->condition($langcode_key, $langcode)
      ->condition('revision_translation_affected', TRUE, '=', $langcode)
      ->sort($revision_key, 'DESC')
      ->range(0, self::LIMIT)
      ->execute();
    $live_revision_id = (int) $storage->load($id)?->getRevisionId();

    $revisions = [];
    foreach ($storage->loadMultipleRevisions(\array_keys($ids)) as $revision) {
      \assert($revision instanceof Page);
      $translation = $revision->getTranslation($langcode);
      \assert($translation instanceof Page);
      $revisions[] = [
        'id' => (int) $revision->getRevisionId(),
        'created' => (int) $revision->getRevisionCreationTime(),
        'author' => $revision->getRevisionUser()?->getDisplayName(),
        'message' => \in_array($revision->getRevisionLogMessage(), ['', NULL], TRUE) ? NULL : $revision->getRevisionLogMessage(),
        'title' => (string) $translation->label(),
        'published' => $translation->isPublished(),
        'live' => (int) $revision->getRevisionId() === $live_revision_id,
      ];
    }
    // Newest first.
    \usort($revisions, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
    return new JsonResponse([
      'revisions' => $revisions,
      'draft' => $this->draft($canvas_page, $langcode),
      'canRestore' => $canvas_page->access('update'),
    ]);
  }

  /**
   * The page's unpublished changes in a language, if it has any.
   *
   * They are on top of the live revision; restoring another replaces them.
   *
   * @return array{updated: int, author: string|null}|null
   *   When they were last saved, and by whom.
   */
  private function draft(Page $page, string $langcode): ?array {
    if (!$page->hasTranslation($langcode)) {
      return NULL;
    }
    $key = AutoSaveManager::getAutoSaveKey($page->getTranslation($langcode));
    $entry = $this->autoSaveManager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$key] ?? NULL;
    if ($entry === NULL) {
      return NULL;
    }
    $owner = $entry['owner'] > 0 ? $this->entityTypeManager->getStorage('user')->load($entry['owner']) : NULL;
    return [
      'updated' => (int) $entry['updated'],
      'author' => $owner === NULL ? NULL : (string) $owner->label(),
    ];
  }

  /**
   * Makes a revision of a page its draft, in the language being edited.
   *
   * Replaces the page's current draft in that language, if it has one.
   */
  public function restore(Page $canvas_page, Page $canvas_page_revision): JsonResponse {
    if ($canvas_page_revision->id() !== $canvas_page->id()) {
      throw new NotFoundHttpException();
    }
    if (!$canvas_page->access('update')) {
      throw new AccessDeniedHttpException();
    }
    $langcode = $this->langcode();
    if (!$canvas_page_revision->hasTranslation($langcode)) {
      throw new UnprocessableEntityHttpException('This version has no translation in the language being edited.');
    }
    $source = $canvas_page_revision->getTranslation($langcode);

    // Start from the stored page, as editing does, and take the revision's
    // content. What only says who saved it and when, the page's identity, and
    // whether it is published stay as they are now.
    $id = $canvas_page->id();
    \assert($id !== NULL);
    $stored = $this->storage()->loadUnchanged($id);
    \assert($stored instanceof Page);
    $draft = $stored->hasTranslation($langcode) ? $stored->getTranslation($langcode) : $stored->addTranslation($langcode);
    $entity_type = $stored->getEntityType();
    $kept_keys = ['id', 'revision', 'uuid', 'langcode', 'published', 'owner'];
    $keep = [
      ...\array_values(\array_filter(\array_intersect_key($entity_type->getKeys(), \array_flip($kept_keys)))),
      ...\array_values($entity_type->getRevisionMetadataKeys()),
      'default_langcode',
      'revision_translation_affected',
    ];
    foreach ($source->getFields(FALSE) as $field_name => $items) {
      $definition = $items->getFieldDefinition();
      if (\in_array($field_name, $keep, TRUE) || $definition->getType() === 'changed') {
        continue;
      }
      $draft->set($field_name, $items->getValue());
    }
    // Canvas drops the draft when it matches what is stored.
    $this->autoSaveManager->saveEntity($draft);
    return new JsonResponse([
      'hasDraft' => !$this->autoSaveManager->getAutoSaveEntity($draft, bypass_cache: TRUE)->isEmpty(),
    ]);
  }

  /**
   * The page storage.
   */
  private function storage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
    \assert($storage instanceof RevisionableStorageInterface);
    return $storage;
  }

  /**
   * The language being edited.
   */
  private function langcode(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

}
