<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\experience_builder\EntityHandlers\XbConfigEntityAccessControlHandler;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup("Page region"),
  label_singular: new TranslatableMarkup("page region"),
  label_plural: new TranslatableMarkup("page region"),
  label_collection: new TranslatableMarkup("Page region"),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    "access" => XbConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    "id" => "id",
    "status" => "status",
  ],
  config_export: [
    "id",
    "theme",
    "region",
    "component_tree",
  ],
  lookup_keys: [
    "theme",
  ],
  constraints: [
    'ImmutableProperties' => [
      'id',
      'theme',
      'region',
    ],
  ],
)]
final class PageRegion extends ConfigEntityBase implements ComponentTreeEntityInterface {

  public const string ENTITY_TYPE_ID = 'page_region';
  public const string ADMIN_PERMISSION = 'administer page template';
  use ComponentTreeItemListInstantiatorTrait;
  use ClientServerConversionTrait;

  /**
   * ID, composed of theme + region.
   *
   * @see \Drupal\experience_builder\Plugin\Validation\Constraint\StringPartsConstraint
   */
  protected string $id;

  /**
   * Region (in the theme referred to by $theme).
   */
  protected ?string $region;

  /**
   * The theme that this defines a XB Page Region for.
   */
  protected ?string $theme;

  /**
   * Component tree for this theme region.
   *
   * @var ?array<string, ComponentTreeItemArray>
   */
  protected ?array $component_tree;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type) {
    $non_existent_properties = array_keys(array_diff_key($values, get_class_vars(__CLASS__)));
    if (!empty($non_existent_properties)) {
      throw new \LogicException(sprintf(
        'Trying to set non-existent config entity properties: %s.',
        implode(', ', $non_existent_properties),
      ));
    }
    parent::__construct($values, $entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    assert(is_string($this->theme));
    $regions = system_region_list($this->theme);
    return new TranslatableMarkup('@region region', [
      '@region' => $regions[$this->get('region')],
    ]);
  }

  /**
   * Creates a page region instance for the given auto-save data.
   *
   * @param array $autoSaveData
   *   Auto-save data with 'layout' and 'model' keys.
   *
   * @return static
   *   New instance with given values.
   *
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   *   If violations exist and $throwOnViolations is TRUE.
   */
  public function forAutoSaveData(array $autoSaveData, bool $validate): static {
    // Ignore auto-saved regions that are no longer editable.
    if (!$this->status()) {
      // @todo Throw an exception instead. Better yet: wipe the stale auto-save
      // data!
      return static::create($this->toArray());
    }

    $items = self::clientToServerTree($autoSaveData['layout'], $autoSaveData['model'], NULL, $validate);

    $auto_saved_page_region = static::create([
      'component_tree' => $items,
    ] + $this->toArray());
    if (!$validate) {
      return $auto_saved_page_region;
    }
    $violations = $auto_saved_page_region->getTypedData()->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }
    return $auto_saved_page_region;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(): ComponentTreeItemList {
    assert(is_array($this->component_tree));

    $field_items = $this->createDanglingComponentTreeItemList();
    $field_items->setValue(\array_values($this->component_tree ?? []));

    return $field_items;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependency('theme', $this->theme);
    $this->addDependencies($this->getComponentTree()->calculateDependencies());
    return $this;
  }

  /**
   * Loads the editable page region entities for the active theme.
   *
   * @return PageRegion[]
   *   Page regions for the active theme.
   */
  public static function loadForActiveTheme(): array {
    $theme = \Drupal::service('theme.manager')->getActiveTheme()->getName();
    return self::loadForTheme($theme);
  }

  public static function loadForTheme(string $theme, bool $include_non_editable = FALSE): array {
    $properties = [
      'theme' => $theme,
    ];
    if (!$include_non_editable) {
      $properties['status'] = TRUE;
    }
    $regions = \Drupal::service('entity_type.manager')->getStorage(self::ENTITY_TYPE_ID)->loadByProperties($properties);

    return $regions;
  }

  /**
   * @return array<string, \Drupal\experience_builder\Entity\PageRegion>
   */
  public static function loadForActiveThemeByClientSideId(): array {
    $regions = self::loadForActiveTheme();
    return array_combine(
      array_map(fn(PageRegion $r) => $r->get('region'), $regions),
      $regions,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return $this->theme . '.' . $this->region;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    $this->id = $this->id();
    if ($this->region === XbPageVariant::MAIN_CONTENT_REGION) {
      throw new \LogicException('Attempted to save a PageRegion targeting the main content region, which is not allowed. (This means it bypassed validation.)');
    }
    parent::preSave($storage);
  }

  /**
   * Creates page region entities based on the block layout of a theme.
   *
   * @param string $theme
   *   The theme to use.
   *
   * @return array<string, self>
   *   An array of PageRegion config entities, one per theme region, except for
   *   the `content` region. Keys are the corresponding config entity IDs.
   *
   * @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant::MAIN_CONTENT_REGION
   */
  public static function createFromBlockLayout(string $theme): array {
    $theme_info = \Drupal::service('theme_handler')->getTheme($theme);
    $region_names = array_filter(
      array_keys($theme_info->info['regions']),
      // No PageRegion config entity is allowed for the `content` region.
      fn ($s) => $s !== XbPageVariant::MAIN_CONTENT_REGION,
    );

    $blocks = \Drupal::service('entity_type.manager')->getStorage('block')->loadByProperties(['theme' => $theme]);

    $regions = [];
    foreach ($blocks as $block) {
      $component_id = BlockComponent::componentIdFromBlockPluginId($block->getPluginId());
      if (!Component::load($component_id)) {
        // This block isn't supported by XB.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent::checkRequirements()
        continue;
      }
      $region_name = match ($block->getRegion()) {
        // Move from the `content` region to the first region in the theme.
        XbPageVariant::MAIN_CONTENT_REGION => reset($region_names),
        // Use the original region.
        default => $block->getRegion(),
      };
      $regions[$region_name][] = [
        'component_id' => $component_id,
        'component_version' => Component::load($component_id)->getActiveVersion(),
        'inputs' => \array_diff_key($block->get('settings'), \array_flip([
          // Remove these as they can be calculated and hence need not be
          // stored.
          'id',
          'provider',
        ])),
        'uuid' => $block->uuid(),
      ];
    }

    $region_instances = [];
    foreach ($region_names as $region_name) {
      $items = [];
      if (isset($regions[$region_name])) {
        $items = array_map(
          static fn(array $block) => \array_intersect_key($block, \array_flip([
            'component_id',
            'component_version',
            'uuid',
            'inputs',
          ])),
          $regions[$region_name],
        );
      }
      $page_region = static::create([
        'theme' => $theme,
        'region' => $region_name,
        'component_tree' => $items,
      ]);
      assert([] === iterator_to_array($page_region->getTypedData()->validate()));
      $region_instances[$page_region->id()] = $page_region;
    }

    return $region_instances;
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
