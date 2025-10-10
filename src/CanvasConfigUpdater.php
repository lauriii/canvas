<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\field\Entity\FieldConfig;

class CanvasConfigUpdater {

  use ComponentTreeItemListInstantiatorTrait;

  public function __construct(
    private readonly ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * Flag determining whether deprecations should be triggered.
   *
   * @var bool
   */
  protected bool $deprecationsEnabled = TRUE;

  /**
   * Stores which deprecations were triggered.
   *
   * @var array
   */
  protected array $triggeredDeprecations = [];

  /**
   * Sets the deprecations enabling status.
   *
   * @param bool $enabled
   *   Whether deprecations should be enabled.
   */
  public function setDeprecationsEnabled(bool $enabled): void {
    $this->deprecationsEnabled = $enabled;
  }

  public function updateJavaScriptComponent(JavaScriptComponent $javaScriptComponent): bool {
    $map = [
      'getSiteData' => [
        'v0.baseUrl',
        'v0.branding',
      ],
      'getPageData' => [
        'v0.breadcrumbs',
        'v0.pageTitle',
      ],
      '@drupal-api-client/json-api-client' => [
        'v0.baseUrl',
        'v0.jsonapiSettings',
      ],
    ];

    $changed = FALSE;
    if ($this->needsDataDependenciesUpdate($javaScriptComponent)) {
      $settings = [];
      $jsCode = $javaScriptComponent->getJs();
      foreach ($map as $var => $neededSetting) {
        if (str_contains($jsCode, $var)) {
          $settings = \array_merge($settings, $neededSetting);
        }
      }
      if (\count($settings) > 0) {
        $current = $javaScriptComponent->get('dataDependencies');
        $current['drupalSettings'] = \array_unique(\array_merge($current['drupalSettings'] ?? [], $settings));
        $javaScriptComponent->set('dataDependencies', $current);
      }
      else {
        $javaScriptComponent->set('dataDependencies', []);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Checks if the code component still misses the 'dataDependencies' property.
   *
   * @return bool
   */
  public function needsDataDependenciesUpdate(JavaScriptComponent $javaScriptComponent): bool {
    if ($javaScriptComponent->get('dataDependencies') !== NULL) {
      return FALSE;
    }

    $deprecations_triggered = &$this->triggeredDeprecations['3533458'][$javaScriptComponent->id()];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      @trigger_error('JavaScriptComponent config entities without "dataDependencies" property is deprecated in canvas:1.0.0 and will be removed in canvas:1.0.0. See https://www.drupal.org/node/3538276', E_USER_DEPRECATED);
    }
    return TRUE;
  }

  public function updateConfigEntityWithComponentTreeInputs(ComponentTreeEntityInterface|FieldConfig $entity): bool {
    \assert($entity instanceof ConfigEntityInterface);
    if (!$this->needsComponentInputsCollapsed($entity)) {
      return FALSE;
    }
    $tree = self::getComponentTreeForEntity($entity);
    self::optimizeTreeInputs($tree);
    if ($entity instanceof ComponentTreeEntityInterface) {
      $entity->setComponentTree($tree->getValue());
      return TRUE;
    }
    $entity->set('default_value', $tree->getValue());
    return TRUE;
  }

  public function needsComponentInputsCollapsed(ComponentTreeEntityInterface|FieldConfig $entity): bool {
    if ($entity instanceof FieldConfig && $entity->getType() !== ComponentTreeItem::PLUGIN_ID) {
      return FALSE;
    }
    $tree = self::getComponentTreeForEntity($entity);
    $before_hash = self::getInputHash($tree);
    self::optimizeTreeInputs($tree);
    $after_hash = self::getInputHash($tree);
    if ($before_hash === $after_hash) {
      return FALSE;
    }
    $deprecations_triggered = &$this->triggeredDeprecations['3538487'][\sprintf('%s:%s', $entity->getEntityTypeId(), $entity->id())];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      // phpcs:ignore
      @trigger_error(\sprintf('%s with ID %s has a component tree without collapsed input values - this is deprecated in canvas:1.0.0 and will be removed in canvas:1.0.0. See https://www.drupal.org/node/3539207', $entity->getEntityType()->getLabel(), $entity->id()), E_USER_DEPRECATED);
    }
    return TRUE;
  }

  private static function getComponentTreeForEntity(ComponentTreeEntityInterface|FieldConfig $entity): ComponentTreeItemList {
    if ($entity instanceof ComponentTreeEntityInterface) {
      return $entity->getComponentTree();
    }
    // @phpstan-ignore-next-line PHPStan correctly
    \assert($entity instanceof FieldConfig);
    $field_default_value_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $field_default_value_tree->setValue($entity->get('default_value') ?? []);
    return $field_default_value_tree;
  }

  private static function getInputHash(ComponentTreeItemList $tree): string {
    // @phpstan-ignore-next-line
    return \implode(':', \array_map(function (ComponentTreeItem $item): string {
      try {
        $inputs = $item->getInputs();
      }
      catch (\UnexpectedValueException | MissingComponentInputsException) {
        $inputs = [];
      }
      return \hash('xxh64', \json_encode($inputs, \JSON_THROW_ON_ERROR));
    }, \iterator_to_array($tree)));

  }

  private static function optimizeTreeInputs(ComponentTreeItemList $tree): void {
    foreach ($tree as $item) {
      \assert($item instanceof ComponentTreeItem);
      $item->optimizeInputs();
    }
  }

  public function needsIntermediateDependenciesComponentUpdate((ComponentTreeEntityInterface&ConfigEntityInterface)|FieldConfig $entity): bool {
    if ($entity instanceof FieldConfig && $entity->getType() !== ComponentTreeItem::PLUGIN_ID) {
      return FALSE;
    }
    $component_tree = self::getComponentTreeForEntity($entity);
    $has_reference_expression = function (ComponentTreeItem $item): bool {
      $inputs = $item->get('inputs');
      \assert($inputs instanceof ComponentInputs);
      return !empty($inputs->getPropSourcesUsingExpressionClass(ReferenceFieldPropExpression::class))
        ||
        !empty($inputs->getPropSourcesUsingExpressionClass(ReferenceFieldTypePropExpression::class));
    };
    return !empty($component_tree->componentTreeItemsIterator($has_reference_expression));
  }

  public function needsTrackingPropsRequiredFlag(Component $component): bool {
    $component_source = $component->getComponentSource();
    // @see `type: canvas.generated_field_explicit_input_ux`
    if (!$component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
      return FALSE;
    }

    // Track the originally loaded version to enable avoiding side effects.
    $originally_loaded_version = $component->getLoadedVersion();

    // All versions of the Component config entity must have a `required` flag
    // for every prop field definition.
    // Note: Start with the oldest version, because it is least likely to have
    // `required` set. (Sites that have updated to `1.0.0-beta2` would have set
    // `required` for new versions, but not for old versions: it lacked an
    // update path.)
    $needs_updating = FALSE;
    foreach (array_reverse($component->getVersions()) as $version) {
      $component->loadVersion($version);
      $settings = $component->getSettings();
      assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as $prop_field_definition) {
        if (!isset($prop_field_definition['required'])) {
          $needs_updating = TRUE;
          break 2;
        }
      }
    }

    // Avoid side effects: ensure the given Component still has the same version
    // loaded. (Not strictly necessary, just a precaution.)
    $component->loadVersion($originally_loaded_version);
    return $needs_updating;
  }

  public function updatePropFieldDefinitionsWithRequiredFlag(Component $component) : bool {
    if (!$this->needsTrackingPropsRequiredFlag($component)) {
      return FALSE;
    }

    // Get the list of required props from the component metadata.
    $component_source = $component->getComponentSource();
    \assert($component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    $metadata = $component_source->getMetadata();
    assert(\is_array($metadata->schema));
    assert(\array_key_exists('properties', $metadata->schema));
    $required_props = $metadata->schema['required'] ?? [];

    // This must update Component versions from newest to oldest. The newest
    // is called the "active" version. It:
    // - DOES NOT need updating for sites that previously updated to
    //   `1.0.0-beta2` AND rediscovered their SDCs and code components. Because
    //   that release shipped with the logic, but without the update path.
    // - DOES need updating in all other scenarios
    // Note that in the "DOES" case, a new version will be created, which means
    // there will be one new "past version".
    // If this would update oldest to newest, it'd fail to update the newly
    // created past version.
    $component->loadVersion($component->getActiveVersion());
    $settings = $component->getSettings();
    assert(\array_key_exists('prop_field_definitions', $settings));
    $active_version_updated = FALSE;
    foreach ($settings['prop_field_definitions'] as $prop_name => &$prop_field_definition) {
      if (!isset($prop_field_definition['required'])) {
        $prop_field_definition['required'] = in_array($prop_name, $required_props, TRUE);
        $active_version_updated = TRUE;
      }
    }
    // >=1 missing `required` was added. The active version is validated against
    // `type: canvas.component.versioned.active.*`, which means a new version is
    // required — otherwise the version hash will not match, triggering a
    // validation error.
    if ($active_version_updated) {
      $source_for_new_version = $this->componentSourceManager->createInstance(
        $component_source->getPluginId(),
        [
          'local_source_id' => $component->get('source_local_id'),
          ...$settings,
        ],
      );
      \assert($source_for_new_version instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
      $version = $source_for_new_version->generateVersionHash();
      $component->createVersion($version)
        ->setSettings($settings);
    }

    // Now update all past versions. These won't require generating new versions
    // because they are validated against `type: canvas.component.versioned.*.*`
    // which uses `type: ignore` for `settings`.
    $past_version_updated = FALSE;
    foreach ($component->getVersions() as $version) {
      if ($version === $component->getActiveVersion()) {
        // The active version has already been updated above.
        continue;
      }
      $component->loadVersion($version);
      \assert(!$component->isLoadedVersionActiveVersion());
      $settings = $component->getSettings();
      assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as $prop_name => &$prop_field_definition) {
        if (!isset($prop_field_definition['required'])) {
          $prop_field_definition['required'] = in_array($prop_name, $required_props, TRUE);
          $past_version_updated = TRUE;
        }
      }
      if ($past_version_updated) {
        // Pretend to be syncing; otherwise changing settings of past versions
        // is forbidden.
        $component->setSyncing(TRUE)
          ->setSettings($settings)
          ->setSyncing(FALSE);
      }
    }

    // Typically, the active version is loaded, unless otherwise requested.
    $component->resetToActiveVersion();

    return $active_version_updated || $past_version_updated;
  }

}
