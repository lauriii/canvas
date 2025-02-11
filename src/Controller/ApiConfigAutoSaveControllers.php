<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiConfigAutoSaveControllers extends ApiControllerBase {

  public function __construct(private readonly AutoSaveManager $autoSaveManager) {}

  public function get(XbHttpApiEligibleConfigEntityInterface $xb_config_entity): CacheableJsonResponse {
    $auto_save = $this->autoSaveManager->getAutoSaveData($xb_config_entity);
    return (new CacheableJsonResponse(
      data: $auto_save->data,
      status: $auto_save->data === NULL ? Response::HTTP_NO_CONTENT : Response::HTTP_OK,
    ))->addCacheableDependency($auto_save);
  }

  public function patch(Request $request, XbHttpApiEligibleConfigEntityInterface $xb_config_entity): JsonResponse {
    $decoded = self::decode($request);

    $this->autoSaveManager->save($xb_config_entity, $decoded);
    return new JsonResponse(status: Response::HTTP_OK);
  }

}
