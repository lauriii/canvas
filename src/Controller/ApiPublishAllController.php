<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiPublishAllController extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientDataToEntityConverter $clientDataToEntityConverter,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  private static function validateExpectedAutoSaves(array $expected_auto_saves, array $all_auto_saves): ?JsonResponse {
    $unexpected_keys = \array_diff_key($expected_auto_saves, $all_auto_saves);
    $missing_keys = \array_diff_key($all_auto_saves, $expected_auto_saves);
    if ($unexpected_keys || $missing_keys) {
      $errors = [];
      foreach (\array_keys($unexpected_keys) as $key) {
        $errors[] = [
          'detail' => ErrorCodesEnum::UnexpectedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
        ];
      }
      foreach ($missing_keys as $key => $item) {
        $errors[] = [
          'detail' => ErrorCodesEnum::MissingItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::MissingItemInPublishRequest->value,
          'meta' => \array_intersect_key($item, \array_flip([
            'entity_type',
            'entity_id',
            'label',
          ])),
        ];
      }
      return new JsonResponse(data: ['errors' => $errors], status: Response::HTTP_CONFLICT);
    }
    // Check the data hashes.
    $unmatched_keys = \array_filter(\array_keys($expected_auto_saves), function ($key) use ($expected_auto_saves, $all_auto_saves) {
      return !\hash_equals($expected_auto_saves[$key]['data_hash'], $all_auto_saves[$key]['data_hash']);
    });
    if ($unmatched_keys) {
      return new JsonResponse(data: [
        'errors' => \array_map(static fn(string $key) => [
          'detail' => ErrorCodesEnum::UnmatchedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::UnmatchedItemInPublishRequest->value,
          'meta' => \array_intersect_key($all_auto_saves[$key], \array_flip([
            'entity_type',
            'entity_id',
            'label',
          ])),
        ], $unmatched_keys),
      ], status: Response::HTTP_CONFLICT);
    }
    return NULL;
  }

  public function __invoke(Request $request): JsonResponse {
    $expected_auto_saves = \json_decode($request->getContent(), TRUE);
    \assert(\is_array($expected_auto_saves));
    $all_auto_saves = $this->autoSaveManager->getAllAutoSaveList();
    if ($difference_response = self::validateExpectedAutoSaves($expected_auto_saves, $all_auto_saves)) {
      return $difference_response;
    }

    if (\count($all_auto_saves) === 0) {
      return new JsonResponse(data: ['message' => 'No items to publish.'], status: Response::HTTP_NO_CONTENT);
    }

    // We keep these in an array instead of making use of a collection like
    // ConstraintViolationList so we can keep violations grouped by each entity.
    $violationSets = [];
    $entities = [];
    foreach ($all_auto_saves as $auto_save) {
      $entity = $this->entityTypeManager->getStorage($auto_save['entity_type'])->load($auto_save['entity_id']);
      assert($entity instanceof FieldableEntityInterface);
      $violations = $this->clientDataToEntityConverter->convert($auto_save['data'], $entity);
      if ($violations->count() > 0) {
        $violationSets[] = $violations;
      }
      $entities[] = $entity;
    }
    if (\count($violationSets) > 0) {
      $validation_errors_response = self::createJsonResponseFromViolationSets(...$violationSets);
      if ($validation_errors_response !== NULL) {
        return $validation_errors_response;
      }
    }
    foreach ($entities as $entity) {
      $entity->save();
      $this->autoSaveManager->delete($entity);
    }
    return new JsonResponse(data: ['message' => new PluralTranslatableMarkup(\count($all_auto_saves), 'Successfully published 1 item.', 'Successfully published @count items.')], status: 200);
  }

}
