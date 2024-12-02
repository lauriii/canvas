<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 */
class ApiControllerBase {

  /**
   * Creates a JSON:API-style error response from a list of violations.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface $violations
   *   The violations list.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   A JSON:API-style error response, with a top-level `errors` member that
   *   contains an array of `error` objects.
   *
   * @see https://jsonapi.org/format/#document-top-level
   * @see https://jsonapi.org/format/#error-objects
   */
  protected static function createJsonResponseFromViolations(ConstraintViolationListInterface $violations): ?JsonResponse {
    if ($violations->count() === 0) {
      return NULL;
    }

    return new JsonResponse(status: 422, data: [
      'errors' => array_map(
        fn($violation) => self::violationToJsonApiStyleErrorObject($violation),
        iterator_to_array($violations)
      ),
    ]);
  }

  /**
   * Creates a JSON:API-style error response from a set of entity violations.
   *
   * @param \Drupal\Core\Entity\EntityConstraintViolationListInterface $violationSets
   *   The violations sets.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   A JSON:API-style error response, with a top-level `errors` member that
   *   contains an array of `error` objects.
   *
   * @see https://jsonapi.org/format/#document-top-level
   * @see https://jsonapi.org/format/#error-objects
   */
  protected static function createJsonResponseFromViolationSets(EntityConstraintViolationListInterface ...$violationSets): ?JsonResponse {
    $violationSets = \array_filter($violationSets, static fn (EntityConstraintViolationListInterface $violationList): bool => $violationList->count() > 0);
    if (\count($violationSets) === 0) {
      return NULL;
    }

    return new JsonResponse(status: 422, data: [
      'errors' => \array_reduce($violationSets, static fn(array $carry, EntityConstraintViolationListInterface $violationList): array => [
        ...$carry,
        ...\array_map(static fn(ConstraintViolationInterface $violation) => self::violationToJsonApiStyleErrorObject($violation, $violationList->getEntity()), \iterator_to_array($violationList)),
      ], []),
    ]);
  }

  /**
   * Transforms a constraint violation to a JSON:API-style error object.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationInterface $violation
   *   A validation constraint violation.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   An associated entity if appropriate.
   *
   * @return array{'detail': string, 'source': array{'pointer': string}}
   *   A subset of a JSON:API error object.
   *
   * @see https://jsonapi.org/format/#error-objects
   * @see \Drupal\jsonapi\Normalizer\UnprocessableHttpEntityExceptionNormalizer
   */
  private static function violationToJsonApiStyleErrorObject(
    ConstraintViolationInterface $violation,
    ?FieldableEntityInterface $entity = NULL,
  ): array {
    $meta = [];
    if ($entity !== NULL) {
      $meta = [
        'meta' => [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => $entity->id(),
          'label' => $entity->label(),
        ],
      ];
    }
    return [
      'detail' => (string) $violation->getMessage(),
      'source' => [
        // @todo Correctly convert to a JSON pointer.
        'pointer' => $violation->getPropertyPath(),
      ],
    ] + $meta;
  }

}
