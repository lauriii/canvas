<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;

/**
 * Migrates legacy page regions to a page variant.
 */
final class PageVariantMigration {

  /**
   * Creates (or returns) the intrinsic "Page content" marker component.
   *
   * The marker normally comes from Canvas's install configuration. Recipe-based
   * installs can omit that configuration, and existing sites do not import new
   * install configuration during updates, so migration paths must ensure it
   * exists before constructing a page variant.
   */
  public static function ensurePageContentMarker(): Component {
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    if ($marker instanceof Component) {
      return $marker;
    }

    $marker = Component::create([
      'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
      'label' => 'Page content',
      'provider' => 'canvas',
      'source' => Marker::SOURCE_PLUGIN_ID,
      'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
      'active_version' => '3b12c0b99a6caecc',
      'versioned_properties' => [
        'active' => [
          'settings' => [],
          'fallback_metadata' => ['slot_definitions' => []],
        ],
      ],
      'dependencies' => ['enforced' => ['module' => ['canvas']]],
    ]);
    $marker->save();
    return $marker;
  }

  /**
   * Returns enabled legacy page regions for a theme, keyed by region name.
   *
   * Loading all entities avoids relying on the lookup-key index during database
   * updates, where that index can lag config written directly to storage.
   *
   * @return \Drupal\canvas\Entity\PageRegion[]
   *   The enabled page regions, keyed by region machine name.
   */
  public static function getEnabledPageRegions(string $theme): array {
    $regions = [];
    foreach (PageRegion::loadMultiple() as $region) {
      \assert($region instanceof PageRegion);
      if ($region->get('theme') === $theme && $region->status()) {
        $regions[$region->get('region')] = $region;
      }
    }
    return $regions;
  }

  /**
   * Migrates the default theme's legacy regions to the site default variant.
   *
   * @return \Drupal\canvas\Entity\PageVariant|null
   *   The migrated variant, or NULL when migration is unavailable.
   */
  public static function migrateDefaultTheme(): ?PageVariant {
    if (\Drupal::config('canvas.settings')->get(PageVariant::DEFAULT_SETTING) !== NULL) {
      return NULL;
    }

    $theme = (string) \Drupal::config('system.theme')->get('default');
    $regions = self::getEnabledPageRegions($theme);
    if ($regions === []) {
      return NULL;
    }

    $variant = self::createFromPageRegions($theme, $regions);
    if (!$variant instanceof PageVariant) {
      return NULL;
    }
    if (!$variant->status()) {
      $variant->enable()->save();
    }
    \Drupal::configFactory()->getEditable('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, $variant->id())
      ->save();
    return $variant;
  }

  /**
   * Creates (or returns) the page variant representing a theme's block layout.
   *
   * The variant wraps the theme page template component, with the "Page
   * content" marker in its content slot and each region's components in the
   * matching slot. An existing variant for the theme is returned unchanged, so
   * content edited through the variant editor survives re-enabling Canvas.
   *
   * @param string $theme
   *   The theme machine name.
   * @param \Drupal\canvas\Entity\PageRegion[] $regions
   *   The theme's PageRegion config entities, keyed by region machine name.
   *
   * @return \Drupal\canvas\Entity\PageVariant|null
   *   The theme's page variant, or NULL when the theme page template component
   *   cannot be generated (the theme is not installed).
   */
  public static function createFromPageRegions(string $theme, array $regions): ?PageVariant {
    $variant_id = 'theme_' . \preg_replace('/[^a-z0-9_]/', '_', $theme);
    $existing = PageVariant::load($variant_id);
    if ($existing instanceof PageVariant) {
      return $existing;
    }

    // The theme page template component reproduces the theme's original markup.
    if (!\Drupal::moduleHandler()->moduleExists('canvas_page_template_component')) {
      \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_page_template_component']);
    }
    $template = canvas_page_template_component_ensure_component($theme);
    if (!$template instanceof Component) {
      return NULL;
    }
    $marker = self::ensurePageContentMarker();

    // A stored PageRegion can name a region the theme's info.yml no longer
    // declares, while the template component's slots come from the current
    // regions. Items re-homed into such a nonexistent slot would only fail at
    // publish-time validation, so stale regions are dropped here instead — and
    // deleted outright: a stale region can never render or be re-enabled, and
    // its config permanently fails validation-based health checks.
    $stale_regions = \array_diff_key($regions, $template->getSlotDefinitions());
    $regions = \array_diff_key($regions, $stale_regions);
    foreach ($stale_regions as $stale_region) {
      \assert($stale_region instanceof PageRegion);
      $stale_region->delete();
    }

    $uuid = \Drupal::service('uuid');
    $template_uuid = $uuid->generate();
    $marker_uuid = $uuid->generate();
    // Assembles the variant's full-page tree: the theme page template component
    // wrapping the "Page content" marker, with each region's components
    // re-parented into the matching slot.
    $build_tree = static function (array $region_trees) use ($template, $marker, $template_uuid, $marker_uuid): array {
      $tree = [
        [
          'uuid' => $template_uuid,
          'component_id' => $template->id(),
          'component_version' => $template->getActiveVersion(),
          'inputs' => [],
        ],
        [
          'uuid' => $marker_uuid,
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'parent_uuid' => $template_uuid,
          'slot' => CanvasPageVariant::MAIN_CONTENT_REGION,
          'inputs' => [],
        ],
      ];
      foreach ($region_trees as $region_name => $items) {
        if ($region_name === CanvasPageVariant::MAIN_CONTENT_REGION) {
          continue;
        }
        foreach ($items as $item) {
          if (empty($item['parent_uuid'])) {
            // Re-parent this region's root components into the template slot.
            $item['parent_uuid'] = $template_uuid;
            $item['slot'] = $region_name;
          }
          $tree[] = $item;
        }
      }
      return $tree;
    };

    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $published_trees = [];
    $draft_trees = [];
    foreach ($regions as $region_name => $region) {
      \assert($region instanceof PageRegion);
      $published_trees[$region_name] = $region->getComponentTree()->getValue();
      $draft = $auto_save_manager->getAutoSaveEntity($region)->entity;
      if ($draft instanceof PageRegion) {
        $draft_trees[$region_name] = $draft->getComponentTree()->getValue();
      }
    }

    $variant = PageVariant::create([
      'id' => $variant_id,
      'label' => \ucfirst($theme) . ' theme',
      'component_tree' => $build_tree($published_trees),
    ]);
    $variant->save();

    // Pending region drafts are preserved: the same slot placement, applied to
    // each region's draft tree where one exists (published tree otherwise),
    // becomes the variant's auto-saved draft. The live site keeps rendering the
    // published state and the draft stays publishable through the normal flow.
    if ($draft_trees !== []) {
      $draft_variant = clone $variant;
      $draft_variant->setComponentTree($build_tree(\array_replace($published_trees, $draft_trees)));
      $auto_save_manager->saveEntity($draft_variant);
    }
    // The consumed region entries in the auto-save store no longer drive
    // rendering or publishing.
    foreach ($regions as $region) {
      $auto_save_manager->delete($region);
    }

    // Config translation overrides on the regions carry over unchanged: the
    // conversion preserves item UUIDs, which are the keys of `component_tree`
    // overrides, so each language's per-region overrides merge into one
    // override on the new variant.
    $config_storage = \Drupal::service(StorageCacheInterface::class);
    \assert($config_storage instanceof StorageInterface);
    foreach ($config_storage->getAllCollectionNames() as $collection) {
      if (!\str_starts_with($collection, 'language.')) {
        continue;
      }
      $collection_storage = $config_storage->createCollection($collection);
      $tree_overrides = [];
      foreach (\array_keys($regions) as $region_name) {
        $override = $collection_storage->read('canvas.page_region.' . $theme . '.' . $region_name);
        if (\is_array($override)) {
          $tree_overrides += $override['component_tree'] ?? [];
        }
      }
      if ($tree_overrides !== []) {
        $collection_storage->write('canvas.page_variant.' . $variant_id, ['component_tree' => $tree_overrides]);
      }
    }

    return $variant;
  }

}
