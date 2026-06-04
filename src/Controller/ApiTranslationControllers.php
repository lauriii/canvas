<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

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
  ) {}

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
