<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\AttachedAssetsInterface;
use Drupal\Core\Asset\LibraryDependencyResolverInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeCommonElements;
use Drupal\Core\Theme\ThemeManagerInterface;
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
    private LibraryDependencyResolverInterface $libraryDependencyResolver,
    private ThemeManagerInterface $themeManager,
    private ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('current_route_match'),
      $container->get(CodeComponentDataProvider::class),
      $container->get(LibraryDependencyResolverInterface::class),
      $container->get(ThemeManagerInterface::class),
      $container->get(ConfigFactoryInterface::class),
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
    // @todo Remove this when https://www.drupal.org/project/drupal/issues/3534717 lands.
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
    // Early return when on a page that does not use the default theme.
    // TRICKY: no cacheability metadata needed for `system.theme` because it has
    // special handling.
    // @see \Drupal\system\SystemConfigSubscriber::onConfigSave()
    $page['#cache']['contexts'][] = 'theme';
    $default_theme = $this->configFactory->get('system.theme')->get('default');
    if ($this->themeManager->getActiveTheme($this->routeMatch)->getName() !== $default_theme) {
      return;
    }

    $route = $this->routeMatch->getRouteObject();
    assert($route instanceof Route);
    $is_preview = $route->getOption('_xb_use_template_draft') === TRUE;
    // TRICKY: the `route` cache context varies also by route parameters, that
    // is unnecessary here, because this only varies by route definition.
    $page['#cache']['contexts'][] = 'route.name';
    // @phpstan-ignore-next-line
    $page['#attached']['library'][] = AssetLibrary::load(AssetLibrary::GLOBAL_ID)->getAssetLibrary($is_preview);
  }

  /**
   * Implements hook_js_settings_alter().
   */
  #[Hook('js_settings_alter')]
  public function jsSettingsAlter(array &$settings, AttachedAssetsInterface $assets): void {
    $path = [CodeComponentDataProvider::XB_DATA_KEY, CodeComponentDataProvider::V0];
    $xbData = $settings[CodeComponentDataProvider::XB_DATA_KEY] ?? [];

    // This is an oversight in core infra; this should not be necessary.
    $all_attached_asset_libraries = $this->libraryDependencyResolver->getLibrariesWithDependencies($assets->getLibraries());

    $all = in_array('experience_builder/xbData.v0', $all_attached_asset_libraries, TRUE);
    if ($all || in_array('experience_builder/xbData.v0.baseUrl', $all_attached_asset_libraries, TRUE)) {
      // Allow overrides: only set if still NULL.
      if (NestedArray::getValue($settings, [...$path, 'baseUrl']) === NULL) {
        $xbData = array_replace_recursive($xbData, $this->codeComponentDataProvider->getXbDataBaseUrlV0());
      }
    }
    if ($all || in_array('experience_builder/xbData.v0.branding', $all_attached_asset_libraries, TRUE)) {
      // Allow overrides: only set if still NULL.
      if (NestedArray::getValue($settings, [...$path, 'branding', 'homeUrl']) === NULL) {
        $xbData = array_replace_recursive($xbData, $this->codeComponentDataProvider->getXbDataBrandingV0());
      }
    }
    if ($all || in_array('experience_builder/xbData.v0.breadcrumbs', $all_attached_asset_libraries, TRUE)) {
      // Allow overrides: only set if still NULL.
      if (NestedArray::getValue($settings, [...$path, 'breadcrumbs']) === NULL) {
        $xbData = array_replace_recursive($xbData, $this->codeComponentDataProvider->getXbDataBreadcrumbsV0());
      }
    }
    if ($all || in_array('experience_builder/xbData.v0.pageTitle', $all_attached_asset_libraries, TRUE)) {
      // Allow overrides: only set if still NULL.
      if (NestedArray::getValue($settings, [...$path, 'pageTitle']) === NULL) {
        $xbData = array_replace_recursive($xbData, $this->codeComponentDataProvider->getXbDataPageTitleV0());
      }
    }
    if ($all || in_array('experience_builder/xbData.v0.jsonapiSettings', $all_attached_asset_libraries, TRUE)) {
      // Allow overrides: only set if still NULL.
      if (NestedArray::getValue($settings, [...$path, 'jsonapiSettings']) === NULL) {
        $xbData = array_replace_recursive($xbData, $this->codeComponentDataProvider->getXbDataJsonApiSettingsV0());
      }
    }
    if (!empty($xbData)) {
      ksort($xbData[CodeComponentDataProvider::V0]);
      $settings[CodeComponentDataProvider::XB_DATA_KEY] = $xbData;
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
