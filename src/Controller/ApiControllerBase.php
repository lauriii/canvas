<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\EventSubscriber\ApiExceptionSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 */
class ApiControllerBase {

  /**
   * Creates a JSON:API-style error response from a set of entity violations.
   *
   * @param \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave
   *   Auto save manager, to allow associating a validation error with an auto-save entry.
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface ...$violationSets
   *   The violations sets.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   A JSON:API-style error response, with a top-level `errors` member that
   *   contains an array of `error` objects.
   *
   * @see https://jsonapi.org/format/#document-top-level
   * @see https://jsonapi.org/format/#error-objects
   */
  protected static function createJsonResponseFromViolationSets(AutoSaveManager $autoSave, ConstraintViolationListInterface ...$violationSets): ?JsonResponse {
    $violationSets = \array_filter($violationSets, static fn (ConstraintViolationListInterface $violationList): bool => $violationList->count() > 0);
    if (\count($violationSets) === 0) {
      return NULL;
    }

    return new JsonResponse(status: 422, data: [
      'errors' => \array_reduce($violationSets, static fn(array $carry, ConstraintViolationListInterface $violationList): array => [
        ...$carry,
        ...\array_map(static fn(ConstraintViolationInterface $violation) => ApiExceptionSubscriber::violationToJsonApiStyleErrorObject(
          $violation,
          $violationList instanceof EntityConstraintViolationListInterface ? $violationList->getEntity() : NULL,
          $autoSave
        ), \iterator_to_array($violationList)),
      ], []),
    ]);
  }

}
