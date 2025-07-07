<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Asset\AttachedAssetsInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeCommonElements;
use Drupal\experience_builder\CodeComponentDataProvider;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * @file
 * Hook implementations that make Component Sources work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Component+sources
 * @see docs/components.md
 */
readonly final class ComponentSourceHooks implements ContainerInjectionInterface {

  public function __construct(
    private RouteMatchInterface $routeMatch,
    private CodeComponentDataProvider $codeComponentDataProvider,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('current_route_match'),
      $container->get(CodeComponentDataProvider::class),
    );
  }

  /**
   * Implements hook_rebuild().
   */
  #[Hook('rebuild')]
  public function rebuild(): void {
    // The module installer cleared all plugin caches. Create/update Component
    // config entities for all XB Component source plugins.
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
    // @phpstan-ignore-next-line
    \Drupal::service(BlockManagerInterface::class)->getDefinitions();
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
    // @phpstan-ignore-next-line
    \Drupal::service(ComponentPluginManager::class)->getDefinitions();
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
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    // @todo Fix upstream.
    $definitions['field.value.boolean']['mapping']['value']['type'] = 'boolean';
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
    $route = $this->routeMatch->getRouteObject();
    assert($route instanceof Route);
    $is_preview = $route->getOption('_xb_use_template_draft') === TRUE;
    // @phpstan-ignore-next-line
    $page['#attached']['library'][] = AssetLibrary::load(AssetLibrary::GLOBAL_ID)->getAssetLibrary($is_preview);
  }

  /**
   * Implements hook_js_settings_build().
   */
  #[Hook('js_settings_build')]
  public function jsSettingsBuild(array &$settings, AttachedAssetsInterface $assets): void {
    if (isset($settings[CodeComponentDataProvider::XB_DATA_KEY])) {
      $settings = $this->codeComponentDataProvider->getPartialXbDataFromSettingsV0($settings);
    }
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
