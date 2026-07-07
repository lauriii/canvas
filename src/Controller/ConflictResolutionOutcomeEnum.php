<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outcome of attempting to resolve an auto-save item's conflict.
 *
 * Each case carries its HTTP status and, for error outcomes, a structured
 * JSON:API-style error response via ::toResponse().
 *
 * @see \Drupal\canvas\AutoSave\AutoSaveManager::resolveConflict()
 * @see \Drupal\canvas\Controller\ApiContentAutoSaveControllers::patch()
 * @internal
 */
enum ConflictResolutionOutcomeEnum {

  // The active conflict matched the resolved_conflict_id and was resolved.
  case Resolved;

  // No auto-save item exists for the entity, so there is nothing to resolve.
  case NoAutoSaveItem;

  // An auto-save item exists, but it has no active conflict to resolve.
  case NoActiveConflict;

  // The active conflict does not match the one the request tried to resolve.
  case ConflictMismatch;

  /**
   * Returns the HTTP status code for this outcome.
   */
  private function httpStatus(): int {
    return match($this) {
      self::Resolved => Response::HTTP_NO_CONTENT,
      self::NoAutoSaveItem => Response::HTTP_NOT_FOUND,
      self::NoActiveConflict => Response::HTTP_UNPROCESSABLE_ENTITY,
      self::ConflictMismatch => Response::HTTP_CONFLICT,
    };
  }

  /**
   * Returns the error code for this outcome, or NULL for a successful outcome.
   */
  private function errorCode(): ?ErrorCodesEnum {
    return match($this) {
      self::Resolved => NULL,
      self::NoAutoSaveItem => ErrorCodesEnum::AutoSaveItemNotFound,
      self::NoActiveConflict => ErrorCodesEnum::NoActiveConflictMatchingConflictId,
      self::ConflictMismatch => ErrorCodesEnum::ItemEntityUpdatedExternally,
    };
  }

  /**
   * Builds a JSON response for this outcome.
   *
   * Returns a 204 No Content response for the Resolved case. For error
   * outcomes, returns a structured JSON:API-style error response.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity the conflict resolution targeted.
   */
  public function toResponse(ContentEntityInterface $entity): JsonResponse {
    $status = $this->httpStatus();
    $code = $this->errorCode();

    if ($code === NULL) {
      return new JsonResponse(status: $status);
    }

    $auto_save_key = AutoSaveManager::getAutoSaveKey($entity);
    $meta = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'label' => (string) $entity->label(),
      'api_auto_save_key' => $auto_save_key,
    ];
    if ($this === self::ConflictMismatch) {
      $meta['conflict_id'] = AutoSaveManager::getConflictId($entity);
    }

    return new JsonResponse(data: [
      'errors' => [
        [
          'detail' => $code->getMessage(),
          'source' => [
            'pointer' => $auto_save_key,
          ],
          'code' => $code->value,
          'meta' => $meta,
        ],
      ],
    ], status: $status);
  }

}
