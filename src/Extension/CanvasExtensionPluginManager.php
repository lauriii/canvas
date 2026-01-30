<?php

declare(strict_types=1);

namespace Drupal\canvas\Extension;

use Drupal\canvas\Exception\ExtensionValidationException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Factory\ContainerFactory;
use Drupal\Core\Url;

/**
 * Provides a plugin manager for Canvas Extensions.
 *
 * Modules can define extensions in an MODULE_NAME.canvas_extension.yml file.
 * Each extension has the following structure:
 * @code
 *  "extension_name":
 *    name: STRING (required)
 *    description: STRING (required)
 *    icon: 'path-to-svg-file.svg' (optional, can be absolute or relative to the module, no leading "/")
 *    url: 'https://example.com/index.html' # Fully qualified URL of the extension
 *    # url: 'dist/index.html' # Relative to the module providing the extension, no leading "/"
 *    type: 'canvas' # Other option: 'code-editor'
 *    api_version: '1.0' # Canvas Extension API version
 *    permissions:
 *      - administer components
 *      - administer code components
 * @endcode
 */
final class CanvasExtensionPluginManager extends DefaultPluginManager implements CanvasExtensionPluginManagerInterface {

  protected $defaults = [
    'icon' => '',
    'type' => CanvasExtensionTypeEnum::Canvas->value,
  ];

  /**
   * Constructs the object.
   */
  public function __construct(CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    // Skip calling the parent constructor, since that assumes annotation-based
    // discovery.
    $this->moduleHandler = $module_handler;
    $this->factory = new ContainerFactory($this, CanvasExtensionInterface::class);
    $this->alterInfo('canvas_extension_info');
    $this->setCacheBackend($cache_backend, 'canvas_extension_plugins');
  }

  protected function getDiscovery() {
    // @phpstan-ignore isset.property
    if (!isset($this->discovery)) {
      $yaml_discovery = new YamlDiscovery('canvas_extension', $this->moduleHandler->getModuleDirectories());
      $yaml_discovery->addTranslatableProperty('name');
      $yaml_discovery->addTranslatableProperty('description');
      $this->discovery = $yaml_discovery;
    }
    return $this->discovery;
  }

  // @phpstan-ignore-next-line missingType.parameter
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    $module_path = $this->moduleHandler->getModule($definition['provider'])->getPath();
    if (empty($definition['api_version'])) {
      throw new ExtensionValidationException(\sprintf('The extension %s in module %s must define its api_version.', $definition['id'], $definition['provider']));
    }
    // Only prepend the path if it's a relative path without a leading slash
    foreach (['icon', 'url'] as $uri) {
      if (!empty($definition[$uri]) && str_starts_with($definition[$uri], '/')) {
        throw new ExtensionValidationException(\sprintf('The extension %s in module %s path %s cannot start with "/". Use an absolute url or a path relative to your module info.yml file.', $definition['id'], $definition['provider'], $uri));
      }
      if (!empty($definition[$uri]) && !str_starts_with($definition[$uri], '/') && !str_starts_with($definition[$uri], 'http')) {
        \assert(!str_starts_with($definition[$uri], '.'), 'The extension paths must not start with "."');
        $definition[$uri] = Url::fromUri('base://' . $module_path . '/' . $definition[$uri])->toString();
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions(): array {
    $definitions = parent::findDefinitions();
    array_walk($definitions, function (array &$definition, $key) {
      $definition = new CanvasExtension([], $definition['id'], $definition);
    });

    return $definitions;
  }

}
