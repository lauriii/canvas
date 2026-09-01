<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\EntityHandlers\PageVariantAccessControlHandler;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
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
    'access' => PageVariantAccessControlHandler::class,
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

  /**
   * Page variants are administered with the page template permission.
   */
  public const string ADMIN_PERMISSION = 'administer page template';

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
   * {@inheritdoc}
   *
   * Reconciles pending page selections when this variant is disabled.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    $original = $update ? $this->getOriginal() : NULL;
    if (!$original instanceof self || !$original->status() || $this->status()) {
      return;
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition(Page::ENTITY_TYPE_ID)) {
      return;
    }
    $page_storage = $entity_type_manager->getStorage(Page::ENTITY_TYPE_ID);
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    foreach ($auto_save_manager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE) as $entry) {
      $draft = $entry['entity'];
      if (!$draft instanceof Page || $draft->get('page_variant')->value !== $this->id()) {
        continue;
      }
      $persisted = $page_storage->loadUnchanged($entry['entity_id']);
      if ($persisted instanceof Page && $persisted->get('page_variant')->value === $this->id()) {
        continue;
      }
      // The selection is restored below, so discard only its stale errors.
      $remaining_form_violations = new EntityConstraintViolationList($draft);
      foreach ($auto_save_manager->getEntityFormViolations($draft) as $violation) {
        $property_path = $violation->getPropertyPath();
        if (\in_array($property_path, [
          'page_variant',
          'entity_form_fields.page_variant',
        ], TRUE)) {
          continue;
        }
        $remaining_form_violations->add($violation);
      }
      $draft->set('page_variant', $persisted instanceof Page ? $persisted->get('page_variant')->value : NULL);
      $auto_save_manager->saveEntity($draft);
      $auto_save_manager->saveEntityFormViolations(
        $draft,
        $remaining_form_violations->count() > 0 ? $remaining_form_violations : NULL,
      );
    }
  }

  /**
   * Allowed values callback for page variant selection fields.
   *
   * Called via setSetting('allowed_values_function', ...) in
   * Page::baseFieldDefinitions().
   *
   * Disabled variants keep rendering where they are already selected, but
   * cannot be selected anew, so they are omitted unless they are the given
   * entity's *persisted* selection. The persisted (not in-memory) value is
   * what keeps an existing page saveable after its variant was disabled,
   * without letting a new page sneak a disabled variant in.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface|null $definition
   *   The field storage definition, when called by the options module.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity holding the selection, when available.
   * @param bool $cacheable
   *   Always set to FALSE: the result depends on the given entity and on the
   *   variants' current statuses, but the options module statically caches
   *   per field rather than per entity.
   *
   * @return array<string, string>
   *   Page variant labels, keyed by machine name.
   *
   * @see \Drupal\canvas\Entity\Page::baseFieldDefinitions()
   * @see \Drupal\canvas\PageVariantResolver
   * @see options_allowed_values()
   */
  public static function allowedValues(?FieldStorageDefinitionInterface $definition = NULL, ?FieldableEntityInterface $entity = NULL, bool &$cacheable = TRUE): array {
    $cacheable = FALSE;
    $persisted_selection = NULL;
    if ($entity !== NULL && !$entity->isNew() && $entity->hasField('page_variant')) {
      $original = \Drupal::entityTypeManager()
        ->getStorage($entity->getEntityTypeId())
        ->loadUnchanged($entity->id());
      if ($original instanceof FieldableEntityInterface) {
        $persisted_selection = $original->get('page_variant')->value;
      }
    }
    return \array_map(
      static fn (PageVariant $variant): string => (string) $variant->label(),
      \array_filter(
        self::loadMultiple(),
        static fn (PageVariant $variant): bool => $variant->status() || $variant->id() === $persisted_selection,
      ),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Blocks deleting the site default variant, so that content and content
   * templates without an explicit selection always resolve to a real variant.
   * Set another variant as the default first. Config sync (import) and module
   * uninstall are exempt: core cascade-deletes dependents during uninstall
   * (marking them `isUninstalling()` while `isSyncing()` stays FALSE), and
   * blocking that would abort the uninstall mid-way.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    foreach ($entities as $entity) {
      \assert($entity instanceof self);
      if (!$entity->isSyncing() && !$entity->isUninstalling() && $entity->isSiteDefault()) {
        throw new ConfigException(\sprintf('The page variant "%s" cannot be deleted because it is the site default. Set another variant as the default first.', $entity->id()));
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Clears `page_variant` selections referencing the deleted variants, in both
   * persisted pages and their auto-saved drafts: the selection is an options
   * list, so a dangling value would fail validation on the page's next save
   * (and, for a draft, block publishing the whole changeset). Cleared pages
   * fall back to the site default.
   *
   * @see \Drupal\canvas\Entity\Page::baseFieldDefinitions()
   * @see \Drupal\canvas\Entity\PageVariant::allowedValues()
   * @see \Drupal\canvas\PageVariantResolver
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition(Page::ENTITY_TYPE_ID)) {
      return;
    }
    $deleted_ids = \array_keys($entities);
    $page_storage = $entity_type_manager->getStorage(Page::ENTITY_TYPE_ID);
    $page_ids = $page_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('page_variant', $deleted_ids, 'IN')
      ->execute();
    foreach ($page_storage->loadMultiple($page_ids) as $page) {
      \assert($page instanceof Page);
      $page->set('page_variant', NULL);
      $page->save();
    }

    // The query above only reaches persisted selections. An editor's selection
    // can live solely in a page's auto-saved draft, never persisted; that
    // dangling value survives here and later fails options validation, blocking
    // the draft's publish. Sweep auto-saved page drafts too, rewriting a
    // matching `page_variant` to NULL while preserving the rest of the draft.
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    foreach ($auto_save_manager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE) as $entry) {
      $draft = $entry['entity'];
      if (!$draft instanceof Page || !$draft->hasField('page_variant')) {
        continue;
      }
      if (\in_array($draft->get('page_variant')->value, $deleted_ids, TRUE)) {
        $draft->set('page_variant', NULL);
        $auto_save_manager->saveEntity($draft);
      }
    }
  }

}
