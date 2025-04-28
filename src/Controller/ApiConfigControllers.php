<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\experience_builder\AssetRenderer;
use Drupal\experience_builder\ClientSideRepresentation;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    // Load the queried config entities: a list of all of them.
    $storage = $this->entityTypeManager->getStorage($xb_config_entity_type_id);
    $query = $storage->getQuery()->accessCheck(TRUE);
    // Entity types can opt in to have disabled entities included in lists.
    if (!$xb_config_entity_type->get('xb_visible_when_disabled')) {
      $query->condition('status', TRUE);
    }

    $query_cacheability = (new CacheableMetadata())
      ->addCacheContexts($xb_config_entity_type->getListCacheContexts())
      ->addCacheTags($xb_config_entity_type->getListCacheTags());
    $xb_config_entity_type->getClass()::refineListQuery($query, $query_cacheability);
    /** @var array<\Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface> $config_entities */
    $config_entities = $storage->loadMultiple($query->execute());
    // As config entities do not use sql-storage, we need explicit access check
    // per https://www.drupal.org/node/3201242.
    $config_entities = array_filter($config_entities, fn(XbHttpApiEligibleConfigEntityInterface $config_entity) => $config_entity->access('view'));

    $normalizations = [];
    $normalizations_cacheability = new CacheableMetadata();
    foreach ($config_entities as $key => &$entity) {
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

    // Create an in-memory config entity.
    $decoded = self::decode($request);
    try {
      $xb_config_entity = $xb_config_entity_type->getClass()::createFromClientSide($decoded);
      assert($xb_config_entity instanceof XbHttpApiEligibleConfigEntityInterface);
      $this->validate($xb_config_entity);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths([
        'component_tree.inputs' => 'model',
        'component_tree' => 'layout',
      ]);
    }

    // Save the XB config entity, respond with a 201 if success. Else 409.
    try {
      $xb_config_entity->save();
    }
    catch (EntityStorageException $e) {
      throw new ConflictHttpException($e->getMessage());
    }

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
    // @todo First validate that there is no other entity depending on this. If there is, respond with a 400, 409, 412 or 422 (TBD).
    // @todo Permissions take into account config dependencies, but we might have content dependencies depending on it too. See https://www.drupal.org/project/experience_builder/issues/3516839
    // @see https://www.drupal.org/project/drupal/issues/3423459
    $xb_config_entity->delete();
    return new JsonResponse(status: 204, data: NULL);
  }

  public function patch(Request $request, XbHttpApiEligibleConfigEntityInterface $xb_config_entity): JsonResponse {
    $decoded = self::decode($request);
    $xb_config_entity->updateFromClientSide($decoded);
    try {
      $this->validate($xb_config_entity);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths([
        'component_tree.inputs' => 'model',
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
    $layout = [];
    $model = [];
    $decoded_tree = json_decode($item->get('tree')->getValue(), TRUE);

    self::buildLayoutAndModel($layout, $model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID]);

    return [
      'layout' => $layout,
      'model' => $model,
    ];
  }

  private static function buildLayoutAndModel(array &$layout, array &$model, ComponentTreeItem $item, array $tree_tier): void {
    $tree = $item->get('tree');
    $full_tree = json_decode($tree->getValue(), TRUE);
    foreach ($tree_tier as ['uuid' => $component_instance_uuid, 'component' => $component_type]) {
      $component_instance = [
        'uuid' => $component_instance_uuid,
        'nodeType' => 'component',
        'type' => $component_type,
        'slots' => [],
      ];

      // Use ComponentSourceInterface::inputToClientModel() to map the server-
      // stored `inputs` data to the client-side `model`.
      // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentInputs
      // @see SimpleComponent type-script definition.
      // @see ComponentModel type-script definition.
      // @see PropSourceComponent type-script definition.
      // @see EvaluatedComponentModel type-script definition.
      $source = $tree->getComponentSource($component_instance_uuid);
      \assert($source instanceof ComponentSourceInterface);
      if ($source->requiresExplicitInput()) {
        $model[$component_instance_uuid] = $source->inputToClientModel($source->getExplicitInput($component_instance_uuid, $item));
      }

      // TRICKY: the server-side implementation (for storage efficiency) forbids
      // - empty component subtrees
      // - empty slots
      // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
      // But the client expects all *available* slots to get a `slot node`: in
      // every `component node` for every slot of that component, even the slot
      // is empty.
      // @see docs/data-model.md#3.4.1
      $known_slot_names_for_component = match ($source instanceof ComponentSourceWithSlotsInterface) {
        FALSE => [],
        TRUE => array_keys($source->getSlotDefinitions()),
      };
      foreach ($known_slot_names_for_component as $slot_name) {
        $slot_children = $full_tree[$component_instance_uuid][$slot_name] ?? [];
        $component_instance_slot = [
          'id' => $component_instance_uuid . '/' . $slot_name,
          'name' => $slot_name,
          'nodeType' => 'slot',
          'components' => [],
        ];
        self::buildLayoutAndModel($component_instance_slot['components'], $model, $item, $slot_children);
        $component_instance['slots'][] = $component_instance_slot;
      }
      $layout[] = $component_instance;
    }
  }

}
