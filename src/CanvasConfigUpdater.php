<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Attribute\ComponentPreSaveUpdate;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\Utility\ComponentMetadataHelper;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
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

  public static function needsIntermediateDependenciesComponentUpdate((ComponentTreeEntityInterface&ConfigEntityInterface)|FieldConfig $entity): bool {
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

  public static function needsTrackingPropsRequiredFlag(Component $component): bool {
    $component_source = $component->getComponentSource();
    // @see `type: canvas.json_schema_props`
    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
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
      \assert(\array_key_exists('prop_field_definitions', $settings));
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

  #[ComponentPreSaveUpdate(postUpdate: 'canvas_post_update_0009_unset_category_property_on_components')]
  public function unsetComponentCategoryProperty(Component $component): bool {
    if (!\is_null($component->get('category'))) {
      $component->set('category', NULL);
      $deprecations_triggered = &$this->triggeredDeprecations['3549726'][$component->id()];
      if ($this->deprecationsEnabled && !$deprecations_triggered) {
        $deprecations_triggered = TRUE;
        // phpcs:ignore
        @trigger_error(\sprintf('%s with ID %s provides a category that will be ignored, this is deprecated in canvas:1.0.2 and will be removed in canvas:2.0.0. See https://www.drupal.org/node/3557215', $component->getEntityType()->getLabel(), $component->id()), E_USER_DEPRECATED);
      }
      return TRUE;
    }
    return FALSE;
  }

  #[ComponentPreSaveUpdate(postUpdate: 'canvas_post_update_0001_track_props_have_required_flag_in_components')]
  public function updatePropFieldDefinitionsWithRequiredFlag(Component $component) : bool {
    if (!$this->needsTrackingPropsRequiredFlag($component)) {
      return FALSE;
    }

    $updated_versions = [];

    // Get the list of required props from the component metadata.
    $component_source = $component->getComponentSource();
    \assert($component_source instanceof JsonSchemaPropsComponentSourceBase);
    $metadata = $component_source->getMetadata();
    \assert(\is_array($metadata->schema));
    \assert(\array_key_exists('properties', $metadata->schema));
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
    \assert(\array_key_exists('prop_field_definitions', $settings));
    $active_version_updated = FALSE;
    foreach ($settings['prop_field_definitions'] as $prop_name => &$prop_field_definition) {
      if (!isset($prop_field_definition['required'])) {
        $prop_field_definition['required'] = \in_array($prop_name, $required_props, TRUE);
        $active_version_updated = TRUE;
        $updated_versions[] = $component->getActiveVersion();
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
      \assert($source_for_new_version instanceof JsonSchemaPropsComponentSourceBase);
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
      \assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as $prop_name => &$prop_field_definition) {
        if (!isset($prop_field_definition['required'])) {
          $prop_field_definition['required'] = \in_array($prop_name, $required_props, TRUE);
          $past_version_updated = TRUE;
          $updated_versions[] = $version;
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

    $deprecations_triggered = &$this->triggeredDeprecations['3550334'][\sprintf('%s:%s', $component->getEntityTypeId(), $component->id())];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      // phpcs:ignore
      @trigger_error(\sprintf('%s with ID %s has one or more versions (%s) that had `prop_field_definitions` without `required` metadata - this is deprecated in canvas:1.0.0-rc1 and will be removed in canvas:2.0.0. See https://www.drupal.org/node/3556444', $component->getEntityType()->getLabel(), $component->id(), implode(', ', $updated_versions)), E_USER_DEPRECATED);
    }

    return $active_version_updated || $past_version_updated;
  }

  public static function needsUpdatingPropFieldDefinitionsUsingTextValue(Component $component): bool {
    $component_source = $component->getComponentSource();
    // @see `type: canvas.json_schema_props`
    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
      return FALSE;
    }

    // Track the originally loaded version to enable avoiding side effects.
    $originally_loaded_version = $component->getLoadedVersion();

    // Any versions of the Component config entity cannot have a
    // `FieldTypePropExpression('text', 'value')` nor
    // `FieldTypePropExpression('text_long', 'value')`.
    // Note: Start with the oldest version, because it is most likely they have
    // one of those. (Sites updated to `1.0.0-beta2` might have this fixed for
    // new versions, but not for old versions: it lacked an update path.)
    $needs_updating = FALSE;
    foreach (array_reverse($component->getVersions()) as $version) {
      $component->loadVersion($version);
      $settings = $component->getSettings();
      \assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as $prop_field_definition) {
        \assert(isset($prop_field_definition['expression']) && isset($prop_field_definition['field_type']));
        $expression = ComponentPropExpression::fromString($prop_field_definition['expression']);
        $needs_updating = match(TRUE) {
          $prop_field_definition['field_type'] === 'text' && $expression->propName === 'value' => TRUE,
          $prop_field_definition['field_type'] === 'text_long' && $expression->propName === 'value' => TRUE,
          default => FALSE,
        };
        if ($needs_updating) {
          break 2;
        }
      }
    }

    // Avoid side effects: ensure the given Component still has the same version
    // loaded. (Not strictly necessary, just a precaution.)
    $component->loadVersion($originally_loaded_version);
    return $needs_updating;
  }

  #[ComponentPreSaveUpdate(postUpdate: 'canvas_post_update_0005_use_processed_for_text_props_in_components')]
  public function updatePropFieldDefinitionsUsingTextValue(Component $component) : bool {
    if (!$this->needsUpdatingPropFieldDefinitionsUsingTextValue($component)) {
      return FALSE;
    }

    $updated_versions = [];

    // Get the list of required props from the component metadata.
    $component_source = $component->getComponentSource();
    \assert($component_source instanceof JsonSchemaPropsComponentSourceBase);
    $metadata = $component_source->getMetadata();
    \assert(\is_array($metadata->schema));
    \assert(\array_key_exists('properties', $metadata->schema));

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
    $new_past_version = $component->getActiveVersion();
    $component->loadVersion($new_past_version);
    $settings = $component->getSettings();
    \assert(\array_key_exists('prop_field_definitions', $settings));
    $active_version_updated = FALSE;
    foreach ($settings['prop_field_definitions'] as &$prop_field_definition) {
      \assert(isset($prop_field_definition['expression']) && isset($prop_field_definition['field_type']));
      $expression = ComponentPropExpression::fromString($prop_field_definition['expression']);
      $needs_updating = match(TRUE) {
        $prop_field_definition['field_type'] === 'text' && $expression->propName === 'value' => TRUE,
        $prop_field_definition['field_type'] === 'text_long' && $expression->propName === 'value' => TRUE,
        default => FALSE,
      };
      if ($needs_updating) {
        $prop_field_definition['expression'] = (string) (new FieldTypePropExpression($prop_field_definition['field_type'], 'processed'));
        $active_version_updated = TRUE;
        $updated_versions[] = $component->getActiveVersion();
      }
    }
    // >=1 expression was changed. The active version is validated against
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
      \assert($source_for_new_version instanceof JsonSchemaPropsComponentSourceBase);
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
      \assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as &$prop_field_definition) {
        \assert(isset($prop_field_definition['expression']) && isset($prop_field_definition['field_type']));
        $expression = ComponentPropExpression::fromString($prop_field_definition['expression']);
        $needs_updating = match(TRUE) {
          $prop_field_definition['field_type'] === 'text' && $expression->propName === 'value' => TRUE,
          $prop_field_definition['field_type'] === 'text_long' && $expression->propName === 'value' => TRUE,
          default => FALSE,
        };
        if ($needs_updating) {
          $prop_field_definition['expression'] = (string) (new FieldTypePropExpression($prop_field_definition['field_type'], 'processed'));
          $past_version_updated = TRUE;
          $updated_versions[] = $version;
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

    $deprecations_triggered = &$this->triggeredDeprecations['3550334'][\sprintf('%s:%s', $component->getEntityTypeId(), $component->id())];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      // phpcs:ignore
      @trigger_error(\sprintf('%s with ID %s has one or more versions (%s) that use the "text" field type and is erroneously using the `value` instead of the `processed` field property - this is deprecated in canvas:1.0.0-rc1 and will be removed in canvas:2.0.0. See https://www.drupal.org/node/3556442', $component->getEntityType()->getLabel(), $component->id(), implode(', ', $updated_versions)), E_USER_DEPRECATED);
    }

    return $active_version_updated || $past_version_updated;
  }

  public static function needsPropReordering(Component $component): bool {
    $component_source = $component->getComponentSource();
    // @see `type: canvas.json_schema_props`
    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
      return FALSE;
    }

    // Track the originally loaded version to enable avoiding side effects.
    $originally_loaded_version = $component->getLoadedVersion();

    // Only the active version needs its prop order corrected, potentially.
    $component->resetToActiveVersion();

    $settings = $component->getSettings();
    \assert(\array_key_exists('prop_field_definitions', $settings));
    $stored_prop_order = \array_keys($settings['prop_field_definitions']);

    $metadata = $component_source->getMetadata();
    $actual_prop_order = \array_keys(ComponentMetadataHelper::getNonAttributeComponentProperties($metadata));

    // Avoid side effects: ensure the given Component still has the same version
    // loaded. (Not strictly necessary, just a precaution.)
    $component->loadVersion($originally_loaded_version);
    return $stored_prop_order !== $actual_prop_order;
  }

  #[ComponentPreSaveUpdate(postUpdate: 'canvas_post_update_0007_respect_prop_ordering')]
  public function updatePropOrder(Component $component) : bool {
    if (!$this->needsPropReordering($component)) {
      return FALSE;
    }

    $component_source = $component->getComponentSource();
    \assert($component_source instanceof JsonSchemaPropsComponentSourceBase);
    $metadata = $component_source->getMetadata();
    $actual_prop_order = \array_keys(ComponentMetadataHelper::getNonAttributeComponentProperties($metadata));

    // Reorder the prop field definitions to match the actual prop order.
    $settings = $component->getSettings();
    $settings['prop_field_definitions'] = array_replace(
      array_flip($actual_prop_order),
      $settings['prop_field_definitions']
    );
    // If new props appeared, or they didn't have a proper definition match,
    // this is not the right time to include them.
    $settings['prop_field_definitions'] = array_filter($settings['prop_field_definitions'], function ($value) {
      return \is_array($value);
    });
    $component->setSettings($settings);

    // ⚠️ Reordering props does not cause a new version to be created!
    // @see \Drupal\canvas\ComponentSource\ComponentSourceBase::generateVersionHash()

    return TRUE;
  }

  /**
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @internal
   */
  private static function expressionUsesDeprecatedReference(FieldTypeBasedPropExpressionInterface $expr): bool {
    return match (TRUE) {
      // Case 1: Multi-bundle reference field type prop expressions. These need
      // branching. (This made following references only for a specific bundle
      // impossible!).
      // @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches
      // Example:
      // - obsolete: `ℹ︎entity_reference␟entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image_1|field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value`
      // - successor: `ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]`
      $expr instanceof ReferenceFieldTypePropExpression && $expr->needsMultiBundleReferencePropExpressionUpdate() => TRUE,
      // Case 2: Field type object prop expression containing multi-bundle
      // reference field type prop expressions. These need both lifting of the
      // reference and then branching at the start of the expression.
      // @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches
      // Example:
      // - obsolete: `ℹ︎entity_reference␟{src↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟alt,width↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟width,height↝entity␜␜entity:media:baby_photos|vacation_photos␝field_media_image|field_media_image_1␞␟height}`
      // - successor: `ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:vacation_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}]`
      $expr instanceof FieldTypeObjectPropsExpression && $expr->needsMultiBundleReferencePropExpressionUpdate() => TRUE,
      // Case 3: Field type object prop expressions containing single-bundle
      // reference field type prop expressions. These need only lifting of the
      // reference.
      // Example:
      // - obsolete: `ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}`
      // - successor: `ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}`
      $expr instanceof FieldTypeObjectPropsExpression && $expr->needsLiftedReferencePropExpressionUpdate() => TRUE,
      // All other prop expressions remain unchanged.
      default => FALSE,
    };
  }

  /**
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @internal
   */
  public static function needsMultiBundleReferencePropExpressionUpdate(Component $component): bool {
    $component_source = $component->getComponentSource();
    // @see `type: canvas.json_schema_props`
    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
      return FALSE;
    }

    // Track the originally loaded version to enable avoiding side effects.
    $originally_loaded_version = $component->getLoadedVersion();

    // Any versions of the Component config entity cannot use a multi-bundle
    // FieldPropExpression.
    $needs_updating = FALSE;
    foreach (array_reverse($component->getVersions()) as $version) {
      $component->loadVersion($version);
      $settings = $component->getSettings();
      \assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as $prop_field_definition) {
        \assert(isset($prop_field_definition['expression']) && isset($prop_field_definition['field_type']));
        $expression = StructuredDataPropExpression::fromString($prop_field_definition['expression']);
        \assert($expression instanceof FieldTypeBasedPropExpressionInterface);
        $needs_updating = self::expressionUsesDeprecatedReference($expression);
        if ($needs_updating) {
          break 2;
        }
      }
    }

    // Avoid side effects: ensure the given Component still has the same version
    // loaded. (Not strictly necessary, just a precaution.)
    $component->loadVersion($originally_loaded_version);
    return $needs_updating;
  }

  #[ComponentPreSaveUpdate(postUpdate: 'canvas_post_update_0011_multi_bundle_reference_prop_expressions')]
  public function updateMultiBundleReferencePropExpressionToMultiBranch(Component $component) : bool {
    if (!$this->needsMultiBundleReferencePropExpressionUpdate($component)) {
      return FALSE;
    }

    $updated_versions = [];

    // Get the list of required props from the component metadata.
    $component_source = $component->getComponentSource();
    \assert($component_source instanceof JsonSchemaPropsComponentSourceBase);
    $metadata = $component_source->getMetadata();
    \assert(\is_array($metadata->schema));
    \assert(\array_key_exists('properties', $metadata->schema));

    // This must update Component versions from newest to oldest. The newest
    // is called the "active" version. It:
    // - DOES NOT need updating for components that do not have any affected
    //   expressions
    //   that release shipped with the logic, but without the update path.
    // - DOES need updating in all other scenarios
    // Note that in the "DOES" case, a new version will be created, which means
    // there will be one new "past version".
    // If this would update oldest to newest, it'd fail to update the newly
    // created past version.
    $new_past_version = $component->getActiveVersion();
    $component->loadVersion($new_past_version);
    $settings = $component->getSettings();
    \assert(\array_key_exists('prop_field_definitions', $settings));
    $active_version_updated = FALSE;
    foreach ($settings['prop_field_definitions'] as &$prop_field_definition) {
      \assert(isset($prop_field_definition['expression']) && isset($prop_field_definition['field_type']));
      $expression = StructuredDataPropExpression::fromString($prop_field_definition['expression']);
      \assert($expression instanceof FieldTypeBasedPropExpressionInterface);
      $needs_updating = self::expressionUsesDeprecatedReference($expression);
      if ($needs_updating) {
        \assert($expression instanceof ReferenceFieldTypePropExpression || $expression instanceof FieldTypeObjectPropsExpression);
        $prop_field_definition['expression'] = match ($expression::class) {
          FieldTypeObjectPropsExpression::class => (string) $expression->liftReferenceAndCreateBranchesIfNeeded(),
          ReferenceFieldTypePropExpression::class => (string) $expression->generateBundleSpecificBranches(),
        };
        $active_version_updated = TRUE;
        $updated_versions[] = $component->getActiveVersion();
      }
    }
    // >=1 expression was changed. The active version is validated against
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
      \assert($source_for_new_version instanceof JsonSchemaPropsComponentSourceBase);
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
      \assert(\array_key_exists('prop_field_definitions', $settings));
      foreach ($settings['prop_field_definitions'] as &$prop_field_definition) {
        \assert(isset($prop_field_definition['expression']) && isset($prop_field_definition['field_type']));
        $expression = StructuredDataPropExpression::fromString($prop_field_definition['expression']);
        \assert($expression instanceof FieldTypeBasedPropExpressionInterface);
        $needs_updating = self::expressionUsesDeprecatedReference($expression);
        if ($needs_updating) {
          \assert($expression instanceof ReferenceFieldTypePropExpression || $expression instanceof FieldTypeObjectPropsExpression);
          $prop_field_definition['expression'] = match ($expression::class) {
            FieldTypeObjectPropsExpression::class => (string) $expression->liftReferenceAndCreateBranchesIfNeeded(),
            ReferenceFieldTypePropExpression::class => (string) $expression->generateBundleSpecificBranches(),
          };
          $past_version_updated = TRUE;
          $updated_versions[] = $version;
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

    $deprecations_triggered = &$this->triggeredDeprecations['3563451'][\sprintf('%s:%s', $component->getEntityTypeId(), $component->id())];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      // phpcs:ignore
      @trigger_error(\sprintf('%s with ID %s has one or more versions (%s) that use a "multi-bundle expression". It must be updated to use bundle-specific branches. This is deprecated in canvas:1.0.0-rc1 and will be removed in canvas:2.0.0. See https://www.drupal.org/node/3563451', $component->getEntityType()->getLabel(), $component->id(), implode(', ', $updated_versions)), E_USER_DEPRECATED);
    }

    return $active_version_updated || $past_version_updated;
  }

  /**
   * Checks if a code-defined component tree contains >=1 JSON blob `inputs`.
   *
   * @return bool
   */
  public function needsConfigEntityWithComponentTreeInputsAsArrays(ComponentTreeEntityInterface|FieldConfig $entity): bool {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \LogicException('This update path applies only to config-defined component trees.');
    }
    if ($entity instanceof FieldConfig && $entity->getType() !== ComponentTreeItem::PLUGIN_ID) {
      return FALSE;
    }
    $config_defined_component_tree = match (TRUE) {
      $entity instanceof ComponentTreeEntityInterface => $entity->get('component_tree') ?? [],
      $entity instanceof FieldConfig => $entity->get('default_value') ?? [],
    };

    // If >=1 component instance in this config-defined component tree has a
    // JSON blob `inputs`, it needs updating.
    $has_inputs_json_blob = \array_reduce(
      $config_defined_component_tree,
      fn (bool $carry, array $component_instance) => $carry || (\array_key_exists('inputs', $component_instance) && \is_string($component_instance['inputs'])),
      FALSE,
    );
    if (!$has_inputs_json_blob) {
      return FALSE;
    }
    $deprecations_triggered = &$this->triggeredDeprecations['3582478'][\sprintf('%s:%s', $entity->getEntityTypeId(), $entity->id())];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      // phpcs:ignore
      @trigger_error(\sprintf('%s with ID %s has a config-defined component tree with JSON-encoded input values - this is deprecated in canvas:1.4.0 and will be removed in canvas:2.0.0. See https://www.drupal.org/node/3586291', $entity->getEntityType()->getLabel(), $entity->id()), E_USER_DEPRECATED);
    }
    return $has_inputs_json_blob;
  }

  /**
   * Checks if a config-defined component tree uses non-UUID sequence keys.
   *
   * TRICKY: unlike for needsConfigEntityWithComponentTreeInputsAsArrays(), this
   * does NOT target FieldConfig config entities. Because its `default_value` is
   * always zero-indexed, just like FieldItemList.
   *
   * @return bool
   *
   * @see ::needsConfigEntityWithComponentTreeInputsAsArrays()
   * @see \canvas_post_update_0016_component_tree_field_default_value_inputs()
   */
  public function needsConfigEntityWithComponentTreeSequenceKeysUpdate(ComponentTreeEntityInterface $entity): bool {
    \assert($entity instanceof ConfigEntityInterface);
    $component_tree = $entity->get('component_tree') ?? [];
    if (empty($component_tree)) {
      return FALSE;
    }
    foreach ($component_tree as $key => $component_instance) {
      \assert(\array_key_exists('uuid', $component_instance));
      if ($key !== $component_instance['uuid']) {
        $deprecations_triggered = &$this->triggeredDeprecations['3582464'][\sprintf('%s:%s', $entity->getEntityTypeId(), $entity->id())];
        if ($this->deprecationsEnabled && !$deprecations_triggered) {
          $deprecations_triggered = TRUE;
          // phpcs:ignore
          @trigger_error(\sprintf('%s with ID %s has a config-defined component tree with non-UUID sequence keys — this is deprecated in canvas:1.4.0 and will be removed in canvas:2.0.0. See https://www.drupal.org/node/3586291', $entity->getEntityType()->getLabel(), $entity->id()), E_USER_DEPRECATED);
        }
        return TRUE;
      }
    }
    return FALSE;
  }

  public function updateConfigEntityWithComponentTreeInputsAsArrays(ComponentTreeEntityInterface|FieldConfig $entity): bool {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \LogicException('This update path applies only to config-defined component trees.');
    }
    if (!$this->needsConfigEntityWithComponentTreeInputsAsArrays($entity)) {
      return FALSE;
    }
    if ($entity instanceof ComponentTreeEntityInterface) {
      // ::setComponentTree() automatically calls
      // ::componentTreeInstancesInputsMustBeArrays().
      $entity->setComponentTree($entity->get('component_tree'));
      return TRUE;
    }
    // For FieldConfig entities, explicitly convert.
    $entity->set('default_value', ComponentTreeConfigEntityBase::componentTreeInstancesInputsMustBeArrays($entity->get('default_value')));
    return TRUE;
  }

  /**
   * Whether a Component's `list_float` default broke its active version hash.
   *
   * A `list_float` default (e.g. `2`) hashes as the native int `2` in PHP but
   * as the string `"2"` after a config round-trip, so a pre-fix
   * `active_version` no longer matches the recomputed hash.
   *
   * Deliberately narrow — detected via a `list_float` prop field definition,
   * not a catch-all hash comparison: other casting mismatches are distinct bugs
   * that each need their own update path, and a post-update never runs twice to
   * apply one.
   *
   * @see \canvas_post_update_0019_recompute_list_float_component_version_hashes()
   * @see \Drupal\canvas\ComponentSource\ComponentSourceBase::generateVersionHash()
   */
  public static function needsComponentVersionHashRecomputationForListFloatDefaultValue(Component $component): bool {
    // The fallback version is never hash-validated.
    // @see \Drupal\canvas\Entity\Component::validateActiveVersion()
    if ($component->getActiveVersion() === ComponentInterface::FALLBACK_VERSION) {
      return FALSE;
    }
    $component->resetToActiveVersion();
    // Only components with a `list_float` prop can be affected by this bug.
    if (!self::hasListFloatPropFieldDefinition($component)) {
      return FALSE;
    }
    try {
      $expected_version = $component->getComponentSource()->generateVersionHash();
    }
    catch (\Exception) {
      // Something more serious is wrong with this component (e.g. a missing
      // SDC); leave it to existing validation to surface.
      return FALSE;
    }
    return $component->getActiveVersion() !== $expected_version;
  }

  /**
   * Whether the active version has any `list_float` prop field definition.
   */
  private static function hasListFloatPropFieldDefinition(Component $component): bool {
    $settings = $component->getSettings();
    if (!\array_key_exists('prop_field_definitions', $settings)) {
      return FALSE;
    }
    foreach ($settings['prop_field_definitions'] as $prop_field_definition) {
      if (($prop_field_definition['field_type'] ?? NULL) === 'list_float') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Recomputes the active version hash of a Component whose `list_float` broke.
   *
   * Thin, list_float-specific entry point: it only decides *whether* this is
   * the known list_float bug, then delegates the actual recomputation to the
   * generic ::recomputeActiveVersionHash(). A future "core changed the casting
   * of field type X" bug should add its own narrow `needs…` check and a sibling
   * wrapper here, reusing the same generic helper.
   *
   * @see ::needsComponentVersionHashRecomputationForListFloatDefaultValue()
   * @see ::recomputeActiveVersionHash()
   */
  #[ComponentPreSaveUpdate(postUpdate: 'canvas_post_update_0019_recompute_list_float_component_version_hashes')]
  public function updateListFloatComponentVersionHash(Component $component): bool {
    if (!self::needsComponentVersionHashRecomputationForListFloatDefaultValue($component)) {
      return FALSE;
    }
    $this->recomputeActiveVersionHash($component);
    return TRUE;
  }

  /**
   * Recomputes a Component's active version hash, preserving the stale one.
   *
   * Generic and reason-agnostic: given a Component whose stored
   * `active_version` no longer matches the hash its (unchanged) settings now
   * generate, this re-derives the hash and records it as a new active version.
   * The previous, stale hash is kept as a past version so existing component
   * instances that reference it keep resolving. The caller is responsible for
   * deciding that a recomputation is actually warranted.
   */
  private function recomputeActiveVersionHash(Component $component): void {
    $component->resetToActiveVersion();
    $settings = $component->getSettings();
    // Recompute the active version hash from the (unchanged) settings.
    $source = $this->componentSourceManager->createInstance(
      $component->getComponentSource()->getPluginId(),
      [
        'local_source_id' => $component->get('source_local_id'),
        ...$settings,
      ],
    );
    \assert($source instanceof ComponentSourceInterface);
    $new_version = $source->generateVersionHash();

    // Create a new active version with the corrected hash. The settings are
    // identical; only the hash differs. Creating a new version (rather than
    // overwriting `active_version` in place) preserves the previous, incorrect
    // hash as a past version, so existing component instances that reference it
    // keep resolving.
    $component->createVersion($new_version)->setSettings($settings);
  }

}
