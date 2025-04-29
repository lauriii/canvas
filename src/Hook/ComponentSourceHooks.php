<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Theme\ThemeCommonElements;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Plugin\ComponentPluginManager;

/**
 * @file
 * Hook implementations that make Component Sources work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Component+sources
 * @see docs/components.md
 */
class ComponentSourceHooks {

  public function __construct(
    private readonly BlockManagerInterface $blockManager,
    private readonly ComponentPluginManager $componentPluginManager,
  ) {
  }

  /**
   * Implements hook_rebuild().
   */
  #[Hook('rebuild')]
  public function rebuild(): void {
    // The module installer cleared all plugin caches. Create/update Component
    // config entities for all XB Component source plugins.
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
    $this->blockManager->getDefinitions();
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
    $this->componentPluginManager->getDefinitions();
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing) {
      return;
    }
    $this->rebuild();
  }

  /**
   * Implements hook_config_schema_info_alter().
   *
   * For block components.
   *
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    // This allows these blocks to be placed for now, but really the schema
    // should actually be FullyValidatable.
    // @todo Fix this in core or solve this another way in https://www.drupal.org/project/experience_builder/issues/3484666
    $types = [
      'block.settings.system_menu_block:*',
      'block.settings.views_block:*',
    ];
    foreach ($types as $type) {
      if (isset($definitions[$type])) {
        $definitions[$type]['constraints']['FullyValidatable'] = \NULL;
      }
    }
  }

  /**
   * Implements hook_page_attachments().
   *
   * For code components.
   *
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$page): void {
    $page['#attached']['library'][] = 'experience_builder/asset_library.' . AssetLibrary::GLOBAL_ID;
  }

  /**
   * Implements hook_theme().
   *
   * For "block override" code components.
   * ⚠️ This is highly experimental and *will* be refactored.
   *
   * @todo Remove/refactor in https://www.drupal.org/project/experience_builder/issues/3519737
   */
  #[Hook('theme')]
  public function theme(): array {
    $common_elements = ThemeCommonElements::commonElements();
    return [
      'block__system_menu_block__as_js_component' => [
        'base hook' => 'block',
        'template' => 'just-children',
      ],
      'menu__as_js_component' => [
        'base hook' => 'menu',
        'template' => 'just-children',
        'variables' => $common_elements['menu']['variables'] + ['rendering_context' => \NULL],
      ],
      'block__system_branding_block__as_js_component' => [
        'base hook' => 'block',
        'template' => 'just-children',
      ],
      'block__system_breadcrumb_block__as_js_component' => [
        'base hook' => 'block',
        'template' => 'just-children',
      ],
      'breadcrumb__as_js_component' => [
        'base hook' => 'breadcrumb',
        'template' => 'just-children',
        'variables' => $common_elements['breadcrumb']['variables'] + ['rendering_context' => \NULL],
      ],
    ];
  }

}
