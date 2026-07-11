<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\EntityHandlers\CanvasConfigEntityAccessControlHandler;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a named, theme-independent, full-page component tree.
 *
 * A page variant renders the entire page: the route's main content is injected
 * where a "Page content" marker component is placed in the tree. Pages and
 * content templates select which variant renders them; an unset selection falls
 * back to the site default variant (`canvas.settings:default_page_variant`).
 *
 * Unlike a PageRegion, a page variant carries no `theme` or theme-region
 * dependency, so it survives theme switches. Theme coupling can only enter a
 * variant through components placed in its tree (see the optional
 * `canvas_page_template_component` module).
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Page variant'),
  label_singular: new TranslatableMarkup('page variant'),
  label_plural: new TranslatableMarkup('page variants'),
  label_collection: new TranslatableMarkup('Page variants'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => CanvasConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'component_tree',
  ],
  constraints: [
    'ImmutableProperties' => [
      'properties' => ['id'],
    ],
  ],
)]
final class PageVariant extends ComponentTreeConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, EmptyTargetEntityProviderInterface {

  public const string ENTITY_TYPE_ID = 'page_variant';

  public const string ADMIN_PERMISSION = 'administer page variants';

  /**
   * The `canvas.settings` key naming the site default page variant.
   */
  public const string DEFAULT_SETTING = 'default_page_variant';

  use ClientServerConversionTrait;

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human-readable label.
   */
  protected ?string $label = NULL;

  /**
   * An optional description shown in the variant management UI.
   */
  protected ?string $description = NULL;

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(): ComponentTreeItemList {
    $field_items = $this->createDanglingComponentTreeItemList($this);
    $field_items->setValue(\array_values($this->component_tree ?? []));
    return $field_items;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();
    $this->addDependencies($this->getComponentTree()->calculateDependencies());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id(),
        'label' => $this->label(),
        'description' => $this->description,
        'status' => $this->status(),
        'component_tree' => $this->getComponentTree()->getValue(),
      ],
      preview: NULL,
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function createFromClientSide(array $data): static {
    $values = [];
    foreach (['id', 'label', 'description'] as $key) {
      if (\array_key_exists($key, $data)) {
        $values[$key] = $data[$key];
      }
    }
    $entity = static::create($values);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function updateFromClientSide(array $data): void {
    foreach (['label', 'description'] as $key) {
      if (\array_key_exists($key, $data)) {
        $this->set($key, $data[$key]);
      }
    }
    if (\array_key_exists('component_tree', $data)) {
      $this->setComponentTree($data['component_tree'] ?? []);
    }
    if (\array_key_exists('status', $data)) {
      $this->setStatus($data['status']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Page variants are theme-independent, so the full list is always relevant.
  }

  /**
   * Whether this variant is the configured site default.
   */
  public function isSiteDefault(): bool {
    return \Drupal::config('canvas.settings')->get(self::DEFAULT_SETTING) === $this->id();
  }

  /**
   * {@inheritdoc}
   *
   * Page variant trees have no host entity: their component inputs are static,
   * so any fieldable entity satisfies the field widgets. Use an empty canvas
   * page, which is always available.
   */
  public function createEmptyTargetEntity(): FieldableEntityInterface {
    $entity = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->create([]);
    \assert($entity instanceof FieldableEntityInterface);
    return $entity;
  }

  /**
   * Allowed values callback for page variant selection fields.
   *
   * Called via setSetting('allowed_values_function', ...) in
   * Page::baseFieldDefinitions().
   *
   * @return array<string, string>
   *   Page variant labels, keyed by machine name.
   *
   * @see \Drupal\canvas\Entity\Page::baseFieldDefinitions()
   */
  // @phpstan-ignore shipmonk.deadMethod
  public static function allowedValues(): array {
    return \array_map(
      static fn (PageVariant $variant): string => (string) $variant->label(),
      self::loadMultiple(),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Blocks deleting the site default variant, so that content and content
   * templates without an explicit selection always resolve to a real variant.
   * Set another variant as the default first. Config sync (import, module
   * uninstall) is exempt.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    foreach ($entities as $entity) {
      \assert($entity instanceof self);
      if (!$entity->isSyncing() && $entity->isSiteDefault()) {
        throw new ConfigException(\sprintf('The page variant "%s" cannot be deleted because it is the site default. Set another variant as the default first.', $entity->id()));
      }
    }
  }

}
