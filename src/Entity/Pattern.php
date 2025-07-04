<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Component\Utility\Random;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\experience_builder\ClientSideRepresentation;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\EntityHandlers\ContentCreatorVisibleXbConfigEntityAccessControlHandler;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Pattern'),
  label_singular: new TranslatableMarkup('pattern'),
  label_plural: new TranslatableMarkup('patterns'),
  label_collection: new TranslatableMarkup('Patterns'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => ContentCreatorVisibleXbConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'component_tree',
  ],
)]

final class Pattern extends ConfigEntityBase implements XbHttpApiEligibleConfigEntityInterface, ComponentTreeEntityInterface {

  public const string ENTITY_TYPE_ID = 'pattern';
  public const string ADMIN_PERMISSION = 'administer patterns';

  use ComponentTreeItemListInstantiatorTrait;
  use ClientServerConversionTrait;

  /**
   * Pattern entity ID.
   */
  protected string $id;

  /**
   * The human-readable label of the Experience Builder Pattern.
   */
  protected ?string $label;

  /**
   * Component tree.
   *
   * @var ?array<string, ComponentTreeItemArray>
   */
  protected ?array $component_tree;

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(): ComponentTreeItemList {
    $component_tree = $this->createDanglingComponentTreeItemList();
    $component_tree->setValue(\array_values($this->component_tree ?? []));
    return $component_tree;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependencies($this->getComponentTree()->calculateDependencies());
    return $this;
  }

  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    if (!array_key_exists('id', $values)) {
      $values['id'] = self::generateId($values['label']);
    }
    parent::preCreate($storage, $values);
  }

  /**
   * Generates a valid ID from the given label.
   */
  private static function generateId(string $label): string {
    $id = mb_strtolower($label);

    $id = preg_replace('@[^a-z0-9_.]+@', '', $id);
    assert(is_string($id));
    // Furthermore remove any characters that are not alphanumerical from the
    // beginning and end of the transliterated string.
    $id = preg_replace('@^([^a-z0-9]+)|([^a-z0-9]+)$@', '', $id);
    assert(is_string($id));
    if (strlen($id) > 23) {
      $id = substr($id, 0, 23);
    }

    $query = \Drupal::entityTypeManager()->getStorage('pattern')->getQuery()->accessCheck(FALSE);
    $ids = $query->execute();
    $id_exists = in_array($id, $ids, TRUE);
    if ($id_exists) {
      $id = $id . '_' . (new Random())->machineName(8);
    }

    return $id;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `PatternPreview` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    $items = $this->getComponentTree();
    return ClientSideRepresentation::create(
      values: [
        'layoutModel' => $items->getClientSideRepresentation(),
        'name' => $this->label(),
        'id' => $this->id(),
      ],
      preview: $items->toRenderable($this, TRUE),
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Pattern` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    $values = [];
    if (isset($data['id'])) {
      $values['id'] = $data['id'];
    }
    if (isset($data['name'])) {
      $values['label'] = $data['name'];
    }
    $entity = static::create($values);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Pattern` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    if (isset($data['layout']) && isset($data['model'])) {
      $this->set('component_tree', self::convertClientToServer($data['layout'], $data['model']));
    }

    foreach (array_diff_key($data, array_flip(['layout', 'model'])) as $key => $value) {
      if ($key == 'name') {
        $key = 'label';
      }
      $this->set($key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function set($property_name, $value): self {
    if ($property_name === 'component_tree') {
      // Ensure predictable order of tree items.
      $value = self::generateComponentTreeKeys($value);
    }
    return parent::set($property_name, $value);
  }

}
