<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiPublishAllController extends ApiControllerBase {

  use NotTheGoodAutoSaveTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientDataToEntityConverter $clientDataToEntityConverter,
  ) {}

  public function __invoke(): JsonResponse {
    $all_auto_saves = $this->getAllAutoSaveList();
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
      // @phpstan-ignore-next-line
      $data = json_decode(json_encode($auto_save['data']), TRUE);
      $violations = $this->clientDataToEntityConverter->convert($data, $entity);
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
      $this->removeAutoSave($entity);
    }
    return new JsonResponse(data: ['message' => new PluralTranslatableMarkup(\count($all_auto_saves), 'Successfully published 1 item.', 'Successfully published @count items.')], status: 200);
  }

}
