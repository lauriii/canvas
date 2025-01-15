<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\experience_builder\AssetRenderer;
use Drupal\experience_builder\ClientSideRepresentation;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * @see \Drupal\experience_builder\ClientSideRepresentation
 */
final class ApiConfigControllers extends ApiControllerBase {

  use ClientServerConversionTrait;
  use ComponentTreeItemInstantiatorTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
    private readonly AssetRenderer $assetRenderer,
    #[Autowire(param: 'renderer.config')]
    private readonly array $rendererConfig,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * @see experience_builder.routing.yml
   */
  private static function ensureXbConfigEntityType(EntityTypeInterface $entity_type): void {
    if (!is_a($entity_type->getClass(), XbHttpApiEligibleConfigEntityInterface::class, TRUE)) {
      throw new \LogicException('The route definition must not be in sync with XB config entities that are eligible for use with the HTTP API, because this config entity type is not!');
    }
  }

  /**
   * Returns a list of enabled XB config entities in client representation.
   *
   * This controller provides a critical response for the XB UI. Therefore it
   * should hence be as fast and cacheable as possible. High-cardinality cache
   * contexts (such as 'user' and 'session') result in poor cacheability.
   * Fortunately, these cache contexts only are present for the markup used for
   * previewing XB Components. So XB chooses to sacrifice accuracy of the
   * preview slightly to be able to guarantee strong cacheability and fast
   * responses.
   */
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
    $normalizations = [];
    $normalizations_cacheability = new CacheableMetadata();
    foreach ($config_entities as $key => &$entity) {
      // Omit disabled Components, Patterns and other config entities: if they
      // have been disabled, it's to avoid new uses of them. Only in the server-
      // side admin UI should it be possible to see (and re-enable) them.
      if (!$entity->status()) {
        continue;
      }
      $representation = $this->normalize($entity);
      $normalizations[$key] = $representation->values;
      $normalizations_cacheability->addCacheableDependency($representation);
    }

    // Ignore the cache tags for individual XB config entities, because this
    // response lists them, so the list cache tag is sufficient and the rest is
    // pointless noise.
    // @see \Drupal\Core\Entity\EntityTypeInterface::getListCacheTags()
    $normalizations_cacheability->setCacheTags(array_filter(
      $normalizations_cacheability->getCacheTags(),
      fn (string $tag): bool => !str_starts_with($tag, 'config:experience_builder.' . $xb_config_entity_type_id),
    ));

    // Set a minimum cache time of one hour, because this is only a preview.
    // (Cache tag invalidations will still result in an immediate update.)
    $max_age = $normalizations_cacheability->getCacheMaxAge();
    if ($max_age !== Cache::PERMANENT) {
      $normalizations_cacheability->setCacheMaxAge(max($max_age, 3600));
    }

    return (new CacheableJsonResponse($normalizations))
      ->addCacheableDependency($query_cacheability)
      ->addCacheableDependency($normalizations_cacheability);
  }

  public function get(Request $request, XbHttpApiEligibleConfigEntityInterface $xb_config_entity): CacheableJsonResponse {
    $xb_config_entity_type = $xb_config_entity->getEntityType();
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    $representation = $this->normalize($xb_config_entity);
    return (new CacheableJsonResponse(status: 200, data: $representation->values))
      ->addCacheableDependency($xb_config_entity)
      ->addCacheableDependency($representation);
  }

  public function post(string $xb_config_entity_type_id, Request $request): JsonResponse {
    $xb_config_entity_type = $this->entityTypeManager->getDefinition($xb_config_entity_type_id);
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    self::ensureXbConfigEntityType($xb_config_entity_type);

    // Decode, then denormalize.
    $decoded = self::decode($request);
    $denormalized = $this->denormalize($xb_config_entity_type_id, $decoded);

    // Create an in-memory config entity and validate it.
    $xb_config_entity = $this->entityTypeManager
      ->getStorage($xb_config_entity_type_id)
      ->create($denormalized);
    assert($xb_config_entity instanceof XbHttpApiEligibleConfigEntityInterface);
    try {
      $this->validate($xb_config_entity);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths([
        'component_tree.props' => 'model',
        'component_tree' => 'layout',
      ]);
    }

    // Save the XB config entity, respond with a 201.
    $xb_config_entity->save();
    $representation = $this->normalize($xb_config_entity);
    return new JsonResponse(status: 201, data: $representation->values, headers: [
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
    $denormalized = $this->denormalize($xb_config_entity->getEntityTypeId(), $decoded);

    // Modify the loaded entity using the denormalized data and validate it.
    foreach ($denormalized as $property_name => $property_value) {
      $xb_config_entity->set($property_name, $property_value);
    }
    try {
      $this->validate($xb_config_entity);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths([
        'component_tree.props' => 'model',
        'component_tree' => 'layout',
      ]);
    }

    // Save the XB config entity, respond with a 200.
    $xb_config_entity->save();
    $xb_config_entity_type = $xb_config_entity->getEntityType();
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);
    $representation = $this->normalize($xb_config_entity);
    return new JsonResponse(status: 200, data: $representation->values);
  }

  private function validate(XbHttpApiEligibleConfigEntityInterface $xb_config_entity): void {
    $violations = $xb_config_entity->getTypedData()->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }
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

  private function denormalize(string $xb_config_entity_type_id, array $data): array {
    return match ($xb_config_entity_type_id) {
      'pattern' => $this->denormalizePattern($data),
      default => $data,
    };
  }

  /**
   * @todo Move to \Symfony\Component\Serializer\Normalizer\DenormalizerInterface implementation.
   */
  private function denormalizePattern(array $data): array {
    ['layout' => $layout, 'model' => $model, 'name' => $label] = $data;
    ['tree' => $tree, 'props' => $props] = $this->convertClientToServer($layout, $model);

    return [
      'label' => $label,
      'component_tree' => [
        'tree' => $tree,
        'props' => $props,
      ],
    ];
  }

  /**
   * Normalizes this config entity, ensuring strong cacheability.
   *
   * Strong cacheability is "ensured" by accepting imperfect previews, when
   * those previews are highly dynamic.
   */
  private function normalize(XbHttpApiEligibleConfigEntityInterface $entity): ClientSideRepresentation {
    // TRICKY: some components may (erroneously!) bubble cacheability even
    // when just constructing a render array. For maximum ecosystem
    // compatibility, account for this, and catch the bubbled cacheability.
    // @see \Drupal\views\Plugin\Block\ViewsBlock::build()
    $get_representation = function (XbHttpApiEligibleConfigEntityInterface $entity): ClientSideRepresentation {
      $context = new RenderContext();
      $representation = $this->renderer->executeInRenderContext(
        $context,
        fn () => $entity->normalizeForClientSide()->renderPreviewIfAny($this->renderer, $this->assetRenderer),
      );
      assert($representation instanceof ClientSideRepresentation);
      if (!$context->isEmpty()) {
        $leaked_cacheability = $context->pop();
        $representation->addCacheableDependency($leaked_cacheability);
      }
      return $representation;
    };

    $representation = $get_representation($entity);

    // Use core's `renderer.config` container parameter to determine which cache
    // contexts are considered poorly cacheable.
    $problematic_cache_contexts = array_intersect(
      $representation->getCacheContexts(),
      $this->rendererConfig['auto_placeholder_conditions']['contexts']
    );

    // If problematic cache contexts are present or if the markup is empty,
    // attempt to re-render in a way that the Component preview is strongly
    // cacheable while still sufficiently accurate.
    if (!empty($problematic_cache_contexts) || empty($representation->values['default_markup'])) {
      $ignorable_cache_contexts = ['session', 'user'];

      if (array_diff($problematic_cache_contexts, $ignorable_cache_contexts)) {
        throw new \LogicException(sprintf('No PHP API exists yet to allow specifying a technique to avoid the `%s` cache context(s) while still generating an acceptable preview', implode(',', $problematic_cache_contexts)));
      }

      try {
        $this->accountSwitcher->switchTo(new AnonymousUserSession());
        $representation = $get_representation($entity);
        // Ignore these cache contexts if they still exist, because it's been
        // re-rendered as the anonymous user. If they still exist, they are safe
        // to ignore for preview purposes.
        $representation->removeCacheContexts($ignorable_cache_contexts);
      }
      finally {
        $this->accountSwitcher->switchBack();
      }
    }

    return $representation;
  }

  /**
   * Converts server side data shape into client side data shape.
   *
   * @param \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem $item
   *
   * @return array{'layout': array{'uuid': string, 'nodeType': 'component', 'type': 'string', 'slots': array}, 'model': array<string, array>}
   *
   * @todo Follow up issue to extract this logic into a trait: https://www.drupal.org/project/experience_builder/issues/3499632
   */
  public static function convertComponentTreeItemToLayoutModel(ComponentTreeItem $item): array {
    assert($item instanceof ComponentTreeItem);
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $hydrated = $item->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);

    $layout = [];
    $model = [];
    $decoded_tree = json_decode($tree->getValue(), TRUE);

    self::buildLayoutAndModel($layout, $model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID], $hydrated->getValue()->getTree()[ComponentTreeStructure::ROOT_UUID]);

    return [
      'layout' => $layout,
      'model' => $model,
    ];
  }

  private static function buildLayoutAndModel(array &$layout, array &$model, ComponentTreeItem $item, array $tree_tier, array $hydrated): void {
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $full_tree = json_decode($tree->getValue(), TRUE);
    foreach ($tree_tier as ['uuid' => $component_instance_uuid, 'component' => $component_type]) {
      $component_instance = [
        'uuid' => $component_instance_uuid,
        'nodeType' => 'component',
        'type' => $component_type,
        'slots' => [],
      ];
      if (isset($hydrated[$component_instance_uuid])) {
        $model[$component_instance_uuid] = $hydrated[$component_instance_uuid]['props'] ?? [];
      }
      if (isset($full_tree[$component_instance_uuid])) {
        foreach ($full_tree[$component_instance_uuid] as $slot_name => $slot_children) {
          $component_instance_slot = [
            'id' => $component_instance_uuid . '/' . $slot_name,
            'name' => $slot_name,
            'nodeType' => 'slot',
            'components' => [],
          ];
          self::buildLayoutAndModel($component_instance_slot['components'], $model, $item, $slot_children, $hydrated[$component_instance_uuid]['slots'][$slot_name]);
          $component_instance['slots'][] = $component_instance_slot;
        }
      }
      $layout[] = $component_instance;
    }
  }

}
