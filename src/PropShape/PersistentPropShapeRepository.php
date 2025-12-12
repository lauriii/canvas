<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheCollector;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Update\UpdateKernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The repository of all discovered prop shapes.
 *
 * This centrally tracks all known prop shapes, which are used to describe
 * props in components of ComponentSources without a native UX for explicit
 * component inputs.
 *
 * The service makes use of both the cache collector and cache tag invalidator
 * patterns. This allows candidate storable prop shapes to be associated with
 * cache tags. We can associate each storable prop shape with a set of cache
 * tags and inform relevant source plugins when storable prop shapes have
 * changed.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent
 *
 * @internal
 */
class PersistentPropShapeRepository extends CacheCollector implements PropShapeRepositoryInterface, CacheTagsInvalidatorInterface {

  const string CID = 'canvas_prop_shape_repository';

  /**
   * Lookup of array shapes.
   *
   * Everything in a cache collector is keyed by a string; however, we want to
   * work with PropShape objects. Each prop shape object can be represented by
   * a unique prop schema key. We keep a lookup between key and prop shape so
   * we don't need to recreate a prop shape from the string. Whilst a prop shape
   * can be represented as a unique string, converting from the string to an
   * object requires type coercion for things like integers. Keeping a lookup
   * is more efficient.
   *
   * @var array<string, \Drupal\canvas\PropShape\PropShape>
   */
  protected array $lookup = [];

  /**
   * Cache created.
   *
   * @var ?int
   *
   * @phpstan-ignore-next-line property.phpDocType
   */
  protected $cacheCreated;

  /**
   * Lookup of storable prop shape to cache tags.
   *
   * So that we can force regeneration of a particular prop shape's storable
   * equivalent, we track the cache tags for each prop shape's unique schema
   * key.
   *
   * @var array<string, string[]>
   */
  protected array $tagLookup = [];

  /**
   * Prop shape keys that need to be resolved before the cache is written.
   *
   * When a cache tag is invalidated, we find any prop shape unique schema keys
   * that are associated with that cache tag. Before the service is destructed,
   * the prop shapes associated with those unique schema keys need to have their
   * storable prop shapes recalculated to reflect the cache invalidation. We
   * don't do this when the cache tag is invalidated because additional
   * processing may still be in progress at the time of invalidation. For
   * example, when a new MediaType is created — the `config:media_type_list`
   * cache tag is invalidated before the source field has been created. Waiting
   * until destruction ensures this happens at the latest possible time.
   *
   * @var string[]
   */
  protected array $resolveBeforeWrite = [];

  /**
   * TRUE if we're running in an update kernel.
   *
   * @var bool
   */
  protected readonly bool $isUpdateKernel;

  public function __construct(
    #[Autowire(service: EphemeralPropShapeRepository::class)]
    private readonly EphemeralPropShapeRepository $readOnlyPropShapeRepository,
    #[Autowire(service: 'cache.discovery')]
    CacheBackendInterface $cache,
    #[Autowire(service: 'lock')]
    LockBackendInterface $lock,
    private readonly ComponentSourceManager $componentSourceManager,
    #[Autowire(service: 'kernel')]
    DrupalKernel $kernel,
  ) {
    // Basic cacheability: the set of installed modules, which determines both:
    // 1. which `hook_canvas_storable_prop_shape_alter()` implementations exist
    // 2. which field type plugins are installed, because the field type plugin
    //    manager does not have/use a cache tag.
    // @see \Drupal\Core\Field\FieldTypePluginManager
    parent::__construct(self::CID, $cache, $lock, ['config:core.extension']);
    $this->isUpdateKernel = $kernel instanceof UpdateKernel;
  }

  /**
   * {@inheritdoc}
   */
  public function get($key): ?StorablePropShape {
    if (!\array_key_exists($key, $this->lookup)) {
      throw new \LogicException('Calling ' . __METHOD__ . ' without calling ::getStorablePropShape is not supported.');
    }
    /** @var array{storable: ?\Drupal\canvas\PropShape\StorablePropShape, tags: string[]} $storable */
    $storable = parent::get($key);
    return $storable['storable'];
  }

  /**
   * {@inheritdoc}
   */
  protected function lazyLoadCache(): void {
    $cacheWasLoaded = $this->cacheLoaded;
    parent::lazyLoadCache();
    if (!$cacheWasLoaded && $this->cacheLoaded) {
      // After retrieving the cache entry, reconstruct the tag lookup and lookup
      // maps.
      /** @var array{storable: ?\Drupal\canvas\PropShape\StorablePropShape, tags: string[]} $value */
      foreach ($this->storage as $key => $value) {
        $this->tagLookup[$key] = $value['tags'];
        if ($value['storable'] instanceof StorablePropShape) {
          $this->lookup[$key] = $value['storable']->shape;
        }
      }
      ksort($this->lookup);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return array{storable: ?\Drupal\canvas\PropShape\StorablePropShape, tags: string[]}
   */
  protected function resolveCacheMiss($key): ?array {
    try {
      if (!\array_key_exists($key, $this->lookup)) {
        throw new \LogicException('Calling ' . __METHOD__ . ' without calling ::getStorablePropShape is not supported.');
      }
      // Get the corresponding prop shape for this unique schema key.
      $shape = $this->lookup[$key];

      $alterable = $this->readOnlyPropShapeRepository->getCandidateStorablePropShape($shape);
      $cacheTags = $alterable->getCacheTags();
      // Update the cache tags for the cache entry to include any new tags.
      $this->tags = \array_unique(\array_merge($this->tags, $cacheTags));
      // Store the association between this unique schema key and its tags.
      $this->tagLookup[$key] = $cacheTags;

      // We store both the storable prop shape and the tags associated with it.
      // That way when we need to invalidate tags, we can trigger recalculation
      // of a particular prop shape.
      $this->storage[$key] = [
        'storable' => $alterable->toStorablePropShape(),
        'tags' => $cacheTags,
      ];
      $this->persist($key);
      return $this->storage[$key];
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUniquePropShapes(): array {
    $this->lazyLoadCache();
    return $this->lookup;
  }

  /**
   * {@inheritdoc}
   */
  public function getStorablePropShape(PropShape $shape): ?StorablePropShape {
    $key = $shape->uniquePropSchemaKey();
    // Store the association between this shape and its unique schema key so
    // we can retrieve it from `::resolveCacheMiss` if needed.
    $this->lookup[$key] = $shape;
    ksort($this->lookup);
    /** @var ?\Drupal\canvas\PropShape\StorablePropShape */
    return $this->get($key);
  }

  protected function updateCache($lock = TRUE): void {
    if ($this->isUpdateKernel) {
      parent::updateCache($lock);
      return;
    }
    // Re-resolve any items that were invalidated by cache tag invalidation.
    foreach ($this->resolveBeforeWrite as $key) {
      $this->resolveCacheMiss($key);
    }
    if (!isset($this->cacheCreated)) {
      // On a cold cache we don't want to trigger new component updates as that
      // could invalidate additional cache tags and lead to invalidation of our
      // cache entries as soon as they are written.
      parent::updateCache($lock);
      return;
    }
    if (!empty($this->keysToPersist)) {
      // Component source manager needs to generate the components.
      // It will call the discovery if any for each component source, compute
      // its new component settings and create/update with a new version the
      // component config as needed.
      // @todo Optimization #1: update this in https://www.drupal.org/project/canvas/issues/3561272 to only regenerate Components for ComponentSource plugins that extend GeneratedFieldExplicitInputUxComponentSourceBase
      // @todo Optimization #2: in https://www.drupal.org/project/canvas/issues/3561493 to only regenerate affected Components: those with PropShapes whose StorablePropShapes depended on the invalidated cache tag(s)
      $this->componentSourceManager->generateComponents();
    }
    parent::updateCache($lock);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    $this->lazyLoadCache();
    if (\in_array('config:core.extension', $tags, TRUE)) {
      // When a module has been installed/uninstalled, we need to recalculate
      // all prop shapes.
      $this->resolveBeforeWrite = \array_keys($this->tagLookup);
      return;
    }
    foreach ($this->tagLookup as $key => $itemTags) {
      if (\count(\array_intersect($tags, $itemTags)) > 0) {
        // Mark this key as needing to be re-resolved before we update the
        // cache.
        $this->resolveBeforeWrite[$key] = $key;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    parent::reset();
    $this->tagLookup = [];
    $this->resolveBeforeWrite = [];
    $this->lookup = [];
  }

}
