<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
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
    // Entity upcasting resolves the translation through the language fallback
    // chain, so a request naming a language with no stored translation can
    // silently hand this controller a *different* existing translation.
    // Refuse instead of destructively operating on a translation the client
    // never named.
    // @see \Drupal\Core\Entity\EntityRepository::getTranslationFromContext()
    $negotiated_langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    if ($canvas_page->language()->getId() !== $negotiated_langcode) {
      return new JsonResponse(
        ['message' => \sprintf('%s %s has no %s translation.', $canvas_page->getEntityTypeId(), $canvas_page->id(), $negotiated_langcode)],
        Response::HTTP_NOT_FOUND,
      );
    }
    if ($canvas_page->isDefaultTranslation()) {
      return new JsonResponse(
        ['message' => \sprintf('Cannot change the component tree fork state of the default translation for %s %s.', $canvas_page->getEntityTypeId(), $canvas_page->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    if (!$canvas_page->hasField(ComponentTreeTranslationFork::FIELD_NAME)) {
      return new JsonResponse(
        ['message' => \sprintf('Translation forks are not enabled for %s entities.', $canvas_page->getEntityTypeId())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    $draft = $this->autoSaveManager->getAutoSaveEntityForPreview($canvas_page);
    // Clone when falling back to the stored entity: it is the statically
    // cached route parameter, and the mutations below must not leak into
    // later loads within this request.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveEntityForPreview()
    $translation = $draft->isEmpty()
      ? (clone $canvas_page->getUntranslated())->getTranslation($canvas_page->language()->getId())
      : $draft->entity;
    \assert($translation instanceof ContentEntityInterface);
    $translation->set(ComponentTreeTranslationFork::FIELD_NAME, $forked);
    if (!$forked) {
      ComponentTreeTranslationFork::resyncFromDefaultTranslation($translation);
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
   * Forks a config entity translation: it starts owning its component tree.
   *
   * The translation's staged config override gets the complete translated
   * component tree (the base tree with the translation's translated inputs) as
   * its forked component tree, replacing its sparse translated inputs. Like
   * content forks, this lives in the drafts until published.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $config_entity
   *   The config entity; the translation is the negotiated `?language=`.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success (idempotent), 400 when targeting the default
   *   language, 404 when the language has no translation.
   *
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::$forked_component_tree
   */
  public function forkConfigTranslation(ConfigEntityInterface $config_entity): JsonResponse {
    return $this->setConfigForkState($config_entity, forked: TRUE);
  }

  /**
   * Unforks a config entity translation: it translates the base tree again.
   *
   * The forked component tree is dropped from the translation's staged config
   * override, keeping its translatable input values for component instances
   * that exist in the base tree. Fork-only components are discarded.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $config_entity
   *   The config entity; the translation is the negotiated `?language=`.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success (idempotent), 400 when targeting the default
   *   language, 404 when the language has no translation.
   */
  public function unforkConfigTranslation(ConfigEntityInterface $config_entity): JsonResponse {
    return $this->setConfigForkState($config_entity, forked: FALSE);
  }

  /**
   * Applies a fork state change to a config translation's staged override.
   */
  private function setConfigForkState(ConfigEntityInterface $config_entity, bool $forked): JsonResponse {
    \assert($config_entity instanceof ComponentTreeConfigEntityBase);
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    if ($langcode === $config_entity->language()->getId()) {
      return new JsonResponse(
        ['message' => \sprintf('Cannot change the component tree fork state of the default translation for %s %s.', $config_entity->getEntityTypeId(), $config_entity->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    if (!isset($config_entity->getTranslationLanguages(include_default: FALSE)[$langcode])) {
      return new JsonResponse(
        ['message' => \sprintf('%s %s has no %s translation.', $config_entity->getEntityTypeId(), $config_entity->id(), $langcode)],
        Response::HTTP_NOT_FOUND,
      );
    }
    // Operate on the base entity's draft, if any: it is what the translation's
    // tree is merged onto, and what the staged override publishes with.
    $draft = $this->autoSaveManager->getAutoSaveEntity($config_entity);
    $entity = $draft->isEmpty() ? $config_entity : $draft->entity;
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    if ($entity->isForkedTranslation($langcode) === $forked) {
      return new JsonResponse(status: Response::HTTP_NO_CONTENT);
    }
    $staged = $entity->getTranslation($langcode);
    $translated_tree = $entity->getTranslatedComponentTree($langcode);
    if ($forked) {
      $staged->setData(ComponentTreeConfigEntityBase::FORKED_COMPONENT_TREE, ComponentTreeConfigEntityBase::encodeForkedComponentTree($translated_tree));
      $staged->clearData('component_tree');
    }
    else {
      // Keep the fork's translated values of translatable inputs, for
      // component instances that exist in the base tree.
      $base_tree = $entity->getComponentTree();
      $sparse = [];
      foreach ($translated_tree as $item) {
        \assert($item instanceof ComponentTreeItem);
        $base_item = $base_tree->getComponentTreeItemByUuid($item->getUuid());
        if ($base_item === NULL) {
          continue;
        }
        $inputs_typed_data = $base_item->get('inputs');
        \assert($inputs_typed_data instanceof ComponentInputs);
        $base_inputs = $base_item->getInputs() ?? [];
        $translated = \array_filter(
          \array_intersect_key($item->getInputs() ?? [], \array_flip($inputs_typed_data->getTranslatableInputKeys())),
          static fn (mixed $value, string $key): bool => !\array_key_exists($key, $base_inputs) || $base_inputs[$key] !== $value,
          ARRAY_FILTER_USE_BOTH,
        );
        if ($translated !== []) {
          $sparse[$item->getUuid()]['inputs'] = $translated;
        }
      }
      $staged->clearData(ComponentTreeConfigEntityBase::FORKED_COMPONENT_TREE);
      if ($sparse === []) {
        $staged->clearData('component_tree');
      }
      else {
        $staged->setData('component_tree', $sparse);
      }
    }
    // Stage the base entity too: staged overrides are listed, published and
    // discarded with their base entity's draft.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::getTranslationGroupAutoSaves()
    $this->autoSaveManager->saveEntity($entity);
    $this->autoSaveManager->saveEntity($staged);
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
