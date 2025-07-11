<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\XbAssetInterface;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ApiConfigAutoSaveControllers extends ApiControllerBase {

  use AutoSaveValidateTrait;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function get(XbHttpApiEligibleConfigEntityInterface $xb_config_entity): CacheableJsonResponse {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($xb_config_entity);
    \assert($auto_save->entity === NULL || $auto_save->entity instanceof XbHttpApiEligibleConfigEntityInterface);
    return (new CacheableJsonResponse(
      data: [
        'data' => $auto_save->entity?->normalizeForClientSide()->values,
        'autoSaves' => $this->getAutoSaveHashes([$xb_config_entity]),
      ],
      status: Response::HTTP_OK,
    ))->addCacheableDependency($auto_save)
      // The `autoSaveStartingPoint` value in `autoSaves` is computed using the
      // config entity.
      ->addCacheableDependency($xb_config_entity);

  }

  public function getCss(XbAssetInterface $xb_config_entity): Response {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($xb_config_entity);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof XbAssetInterface);
      $xb_config_entity = $auto_save->entity;
    }
    $response = new Response($xb_config_entity->getCss(), Response::HTTP_OK, [
      'Content-Type' => 'text/css; charset=utf-8',
    ]);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  public function getJs(XbAssetInterface $xb_config_entity): Response {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($xb_config_entity);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof XbAssetInterface);
      $xb_config_entity = $auto_save->entity;
    }
    $response = new Response($xb_config_entity->getJs(), Response::HTTP_OK, [
      'Content-Type' => 'text/javascript; charset=utf-8',
    ]);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  public function patch(Request $request, XbHttpApiEligibleConfigEntityInterface $xb_config_entity): JsonResponse {
    $decoded = self::decode($request);
    if (!\array_key_exists('data', $decoded)) {
      throw new BadRequestHttpException('Missing data');
    }
    if (!\array_key_exists('autoSaves', $decoded)) {
      throw new BadRequestHttpException('Missing autoSaves');
    }
    if (!\array_key_exists('clientInstanceId', $decoded)) {
      throw new BadRequestHttpException('Missing clientInstanceId');
    }
    $this->validateAutoSaves([$xb_config_entity], $decoded['autoSaves'], $decoded['clientInstanceId']);

    $auto_save_entity = $xb_config_entity::create($xb_config_entity->toArray());
    $auto_save_entity->updateFromClientSide($decoded['data']);
    $this->autoSaveManager->saveEntity($auto_save_entity, $decoded['clientInstanceId']);
    return new JsonResponse(data: ['autoSaves' => $this->getAutoSaveHashes([$xb_config_entity])], status: Response::HTTP_OK);
  }

}
