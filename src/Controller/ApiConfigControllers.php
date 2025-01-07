<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Controllers exposing HTTP API for interacting with XB's Config entity types.
 *
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 *
 * @see \Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface
 */
final class ApiConfigControllers extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @see experience_builder.routing.yml
   */
  private static function ensureXbConfigEntityType(EntityTypeInterface $entity_type): void {
    if (!is_a($entity_type->getClass(), XbHttpApiEligibleConfigEntityInterface::class, TRUE)) {
      throw new \LogicException('The route definition must not be in sync with XB config entities that are eligible for use with the HTTP API, because this config entity type is not!');
    }
  }

  public function list(string $xb_config_entity_type_id): CacheableJsonResponse {
    $xb_config_entity_type = $this->entityTypeManager->getDefinition($xb_config_entity_type_id);
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    self::ensureXbConfigEntityType($xb_config_entity_type);

    // Load the queried config entities: a list of all of them.
    $query_cacheability = (new CacheableMetadata())
      ->addCacheContexts($xb_config_entity_type->getListCacheContexts())
      ->addCacheTags($xb_config_entity_type->getListCacheTags());
    /** @var array<\Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface> $config_entities */
    $config_entities = $this->entityTypeManager->getStorage($xb_config_entity_type_id)->loadMultiple();

    $normalizations = self::normalizeConfigEntities($xb_config_entity_type, $config_entities);

    // Ensure each normalized config entity is identical to the response at the
    // "individual" config entity XB HTTP API route, but point to it.
    $individual_urls = array_map(
      fn (string $entity) => Url::fromRoute('experience_builder.api.config.get', [
        'xb_config_entity_type_id' => $xb_config_entity_type_id,
        'xb_config_entity' => $entity,
      ])
        ->toString(TRUE),
      array_keys($normalizations),
    );
    $urls_cacheability = new CacheableMetadata();
    array_reduce(
      $individual_urls,
      fn (CacheableMetadata $cacheability, GeneratedUrl $url) => $cacheability->addCacheableDependency($url),
      $urls_cacheability
    );

    return (new CacheableJsonResponse(array_combine(
      array_map(fn (GeneratedUrl $url): string => $url->getGeneratedUrl(), $individual_urls),
      $normalizations,
    )))
      ->addCacheableDependency($query_cacheability)
      ->addCacheableDependency($urls_cacheability);
  }

  public function get(Request $request, XbHttpApiEligibleConfigEntityInterface $xb_config_entity): CacheableJsonResponse {
    $xb_config_entity_type = $xb_config_entity->getEntityType();
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    $normalization = self::normalizeConfigEntities($xb_config_entity_type, [$xb_config_entity])[0];
    return (new CacheableJsonResponse(status: 200, data: $normalization))
      ->addCacheableDependency($xb_config_entity);
  }

  public function post(string $xb_config_entity_type_id, Request $request): JsonResponse {
    $xb_config_entity_type = $this->entityTypeManager->getDefinition($xb_config_entity_type_id);
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    self::ensureXbConfigEntityType($xb_config_entity_type);

    // Decode, then denormalize.
    $decoded = self::decode($request);
    // ⚠️ For now, there's no denormalization. This may change in the future.
    $denormalized = $decoded;

    // Create an in-memory config entity and validate it.
    $xb_config_entity = $this->entityTypeManager
      ->getStorage($xb_config_entity_type_id)
      ->create($denormalized);
    assert($xb_config_entity instanceof XbHttpApiEligibleConfigEntityInterface);
    $this->validate($xb_config_entity);

    // Save the XB config entity, respond with a 201.
    $xb_config_entity->save();
    $normalization = self::normalizeConfigEntities($xb_config_entity_type, [$xb_config_entity])[0];
    return new JsonResponse(status: 201, data: $normalization, headers: [
      'Location' => Url::fromRoute(
        'experience_builder.api.config.get',
        [
          'xb_config_entity_type_id' => $xb_config_entity->getEntityTypeId(),
          'xb_config_entity' => $xb_config_entity->id(),
        ])
        ->toString(TRUE)
        ->getGeneratedUrl(),
    ]);
  }

  public function delete(XbHttpApiEligibleConfigEntityInterface $xb_config_entity): JsonResponse {
    // @todo First validate that there is no other config depending on this. If there is, respond with a 400, 409, 412 or 422 (TBD).
    // @see https://www.drupal.org/project/drupal/issues/3423459
    $xb_config_entity->delete();
    return new JsonResponse(status: 204, data: NULL);
  }

  public function patch(Request $request, XbHttpApiEligibleConfigEntityInterface $xb_config_entity): JsonResponse {
    // Decode, then denormalize.
    $decoded = self::decode($request);
    // ⚠️ For now, there's no denormalization. This may change in the future.
    $denormalized = $decoded;

    // Modify the loaded entity using the denormalized data and validate it.
    foreach ($denormalized as $property_name => $property_value) {
      $xb_config_entity->set($property_name, $property_value);
    }
    $this->validate($xb_config_entity);

    // Save the XB config entity, respond with a 200.
    $xb_config_entity->save();
    $xb_config_entity_type = $xb_config_entity->getEntityType();
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    $normalization = self::normalizeConfigEntities($xb_config_entity_type, [$xb_config_entity])[0];
    return new JsonResponse(status: 200, data: $normalization);
  }

  private function validate(XbHttpApiEligibleConfigEntityInterface $xb_config_entity): void {
    $violations = $xb_config_entity->getTypedData()->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }
  }

  /**
   * Normalizes all config entities of a given config entity type.
   *
   * Associates the config entity type's list cache contexts and tags, because
   * the given list of config entities is assumed to be the complete list.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $config_entity_type
   *   The config entity type whose config entities are being normalized.
   * @param \Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface[] $config_entities
   *   All config entities stored for the given config entity type.
   *
   * @return array
   *   An array containing the normalization of each config entity, in the same
   *   order, with the same keys.
   */
  private static function normalizeConfigEntities(ConfigEntityTypeInterface $config_entity_type, array $config_entities): array {
    assert(Inspector::assertAll(fn ($v) => get_class($v) === $config_entity_type->getClass(), $config_entities));
    // All exportable config entity properties should be present in the
    // normalization because they may be edited, with the exception of the
    // immutable properties.
    $editable_config_entity_properties = array_diff(
      $config_entity_type->get('config_export'),
      $config_entity_type->getConstraints()['ImmutableProperties'],
    );

    $cacheability = new CacheableMetadata();
    $normalizations = array_map(
    // Exclude not only `_core`, but really everything that is not part of the
    // explicit export. For example: `dependencies` should not be listed here,
    // because it is not a concern for the XB UI to create/edit/delete
    // PageTemplate config entities.
    // @see \Drupal\serialization\Normalizer\ConfigEntityNormalizer
      fn (XbHttpApiEligibleConfigEntityInterface $c) => array_intersect_key(
        $c->toArray(),
        array_flip($editable_config_entity_properties)
      ),
      $config_entities
    );
    $cacheability
      ->addCacheContexts($config_entity_type->getListCacheContexts())
      ->addCacheTags($config_entity_type->getListCacheTags());

    return $normalizations;
  }

  /**
   * Decodes a request whose body contains JSON.
   *
   * @return array
   *   The parsed JSON from the request body.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown if the request body cannot be decoded, or when no request body was
   *   provided with a POST or PATCH request.
   * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
   *   Thrown if the request body cannot be denormalized.
   *
   * @todo Introduce a custom Content-Type and validate that request header too, see \Drupal\jsonapi\JsonapiServiceProvider for inspiration.
   */
  private static function decode(Request $request): array {
    $body = (string) $request->getContent();

    if (empty($body)) {
      throw new BadRequestHttpException('Empty request body.');
    }

    $data = json_decode($body, TRUE);
    if ($data === NULL) {
      throw new UnprocessableEntityHttpException('Request body contains invalid JSON.');
    }

    return $data;
  }

}
