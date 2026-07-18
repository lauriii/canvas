<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP API for interacting with Canvas entity translations.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiTranslationControllers extends ApiControllerBase {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Forks a translation: it starts owning an independent component tree.
   *
   * Only the `canvas_component_tree_fork` flag flips on the translation's
   * auto-save draft: symmetric mode already stores identical tree rows plus
   * the translation's own translated inputs, so the current state is the fork
   * seed and no tree copy is needed. The fork becomes permanent only when the
   * draft is published; discarding the draft reverts it.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   The translation to fork, resolved from the `?language=` query parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success (idempotent), 400 when targeting the default
   *   translation.
   */
  public function fork(ContentEntityInterface $canvas_page): JsonResponse {
    return $this->setForkState($canvas_page, forked: TRUE);
  }

  /**
   * Unforks a translation: destructively re-syncs it from the default one.
   *
   * On the translation's auto-save draft, the flag flips back and the
   * translation's component trees are replaced by the default translation's
   * current draft trees, re-applying the translation's translatable input
   * values for component instances that survive. Fork-only components are
   * discarded. Like fork, this lives in the draft until published.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   The translation to unfork, resolved from the `?language=` query
   *   parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success (idempotent), 400 when targeting the default
   *   translation.
   */
  public function unfork(ContentEntityInterface $canvas_page): JsonResponse {
    return $this->setForkState($canvas_page, forked: FALSE);
  }

  /**
   * Applies a fork state change to a translation's auto-save draft.
   */
  private function setForkState(ContentEntityInterface $canvas_page, bool $forked): JsonResponse {
    if ($canvas_page->isDefaultTranslation()) {
      return new JsonResponse(
        ['message' => \sprintf('Cannot change the component tree fork state of the default translation for %s %s.', $canvas_page->getEntityTypeId(), $canvas_page->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    if (!$canvas_page->hasField(ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME)) {
      return new JsonResponse(
        ['message' => \sprintf('Translation forks are not enabled for %s entities.', $canvas_page->getEntityTypeId())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    $draft = $this->autoSaveManager->getAutoSaveEntityForPreview($canvas_page);
    $translation = $draft->isEmpty() ? $canvas_page : $draft->entity;
    \assert($translation instanceof ContentEntityInterface);
    $translation->set(ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME, $forked);
    if (!$forked) {
      ComponentTreeFieldSymmetricalTranslationSynchronizer::resyncFromDefaultTranslation($translation);
    }
    $this->autoSaveManager->saveEntity($translation);
    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

  /**
   * Deletes a single translation of a canvas_page entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   The entity whose translation should be deleted.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success, 400 if attempting to delete the default
   *   translation.
   */
  public static function delete(ContentEntityInterface $canvas_page): JsonResponse {
    // Guard: cannot delete the default (original/untranslated) language via
    // this endpoint. Callers should use the full entity delete route instead.
    // @see \Drupal\canvas\Controller\ApiContentControllers::delete()
    if ($canvas_page->isDefaultTranslation()) {
      return new JsonResponse(
        ['message' => \sprintf('Cannot delete the default translation for %s %s.', $canvas_page->getEntityTypeId(), $canvas_page->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    $untranslated = $canvas_page->getUntranslated();
    $untranslated->removeTranslation($canvas_page->language()->getId());
    $untranslated->save();
    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

  /**
   * Deletes the language config override (translation) for a config entity.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $config_entity
   *   The Canvas config entity whose translation should be deleted.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success, 400 if no translation exists for the current
   *   language.
   */
  public function deleteConfigTranslation(ConfigEntityInterface $config_entity): JsonResponse {
    $lang_id = $this->languageManager->getCurrentLanguage()->getId();
    $config_name = $config_entity->getConfigDependencyName();
    \assert($this->languageManager instanceof ConfigurableLanguageManagerInterface);
    $override = $this->languageManager->getLanguageConfigOverride($lang_id, $config_name);
    if ($override->isNew()) {
      return new JsonResponse(
        ['message' => \sprintf('No %s translation found for %s %s.', $lang_id, $config_entity->getEntityTypeId(), $config_entity->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    $override->delete();
    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

}
