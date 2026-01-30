<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ExtensionType;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Defines a component source based on single-directory components.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Single-Directory Components'),
  supportsImplicitInputs: FALSE,
  discovery: SingleDirectoryComponentDiscovery::class,
  // @see \Drupal\Core\Theme\ComponentPluginManager::__construct()
  discoveryCacheTags: ['component_plugins'],
)]
final class SingleDirectoryComponent extends GeneratedFieldExplicitInputUxComponentSourceBase implements UrlRewriteInterface {

  public const SOURCE_PLUGIN_ID = 'sdc';

  protected ComponentPluginManager $componentPluginManager;
  protected ModuleHandlerInterface $moduleHandler;
  protected ThemeHandlerInterface $themeHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->componentPluginManager = $container->get(ComponentPluginManager::class);
    $instance->moduleHandler = $container->get(ModuleHandlerInterface::class);
    $instance->themeHandler = $container->get(ThemeHandlerInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    try {
      $this->getMetadata();
    }
    catch (ComponentNotFoundException) {
      return TRUE;
    }
    // @todo Check if the required props are the same in the plugin and the saved component.
    //   Consider returning an enum[] that could give more info for the
    //   developer, e.g. the multiple reasons that could make this as
    //   broken/invalid. See
    //   https://www.drupal.org/project/canvas/issues/3532514
    return FALSE;
  }

  public function determineDefaultFolder(): string {
    $plugin_definition = $this->getComponentPlugin()->getPluginDefinition();
    \assert(is_array($plugin_definition));
    // TRICKY: SDCs metadata specifies `group`, but gets exposed as `category`.
    // @see \Drupal\Core\Theme\ComponentPluginManager::processDefinitionCategory()
    \assert(!empty($plugin_definition['category']));

    return (string) $plugin_definition['category'];
  }

  /**
   * {@inheritdoc}
   */
  protected function getComponentPlugin(): ComponentPlugin {
    // @todo this should probably use DefaultSingleLazyPluginCollection
    if ($this->componentPlugin === NULL) {
      // Statically cache the loaded plugin.
      $this->componentPlugin = $this->componentPluginManager->find($this->getSourceSpecificComponentId());
    }
    return $this->componentPlugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'local_source_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    try {
      return $this->componentPluginManager->getDefinition($this->getSourceSpecificComponentId())['class'];
    }
    catch (PluginNotFoundException) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    $component = $this->getComponentPlugin();
    $provider = $component->getBaseId();
    if ($this->moduleHandler->moduleExists($provider)) {
      $dependencies['module'][] = $provider;
    }
    if ($this->themeHandler->themeExists($provider)) {
      $dependencies['theme'][] = $provider;
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    try {
      $component = $this->getComponentPlugin();
      return new TranslatableMarkup('Single-directory component: %name', [
        '%name' => $this->getMetadata()->name ?? $component->getPluginId(),
      ]);
    }
    catch (\Exception) {
      return new TranslatableMarkup('Invalid/broken Single-directory component');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    [$props, $props_cacheability] = self::getResolvedPropsAndCacheability($inputs[self::EXPLICIT_INPUT_NAME] ?? []);
    $build = [
      '#type' => 'component',
      '#component' => $this->getSourceSpecificComponentId(),
      '#props' => $props + [
        'canvas_uuid' => $componentUuid,
        'canvas_slot_ids' => \array_keys($slot_definitions),
        'canvas_is_preview' => $isPreview,
      ],
      '#attached' => [
        'library' => [
          'core/components.' . str_replace(':', '--', $this->getSourceSpecificComponentId()),
        ],
      ],
    ];
    $props_cacheability->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  /**
   * @todo Remove in clean-up follow-up; minimize non-essential changes.
   */
  public static function convertMachineNameToId(string $machine_name): string {
    return SingleDirectoryComponentDiscovery::getComponentConfigEntityId($machine_name);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceLabel(): TranslatableMarkup {
    $component_plugin = $this->getComponentPlugin();
    \assert(is_array($component_plugin->getPluginDefinition()));

    // The 'extension_type' key is guaranteed to be set.
    // @see \Drupal\Core\Theme\ComponentPluginManager::alterDefinition()
    $extension_type = $component_plugin->getPluginDefinition()['extension_type'];
    \assert($extension_type instanceof ExtensionType);
    return match ($extension_type) {
      ExtensionType::Module => $this->t('Module component'),
      ExtensionType::Theme => $this->t('Theme component'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function rewriteExampleUrl(string $url): GeneratedUrl {
    $parsed_url = parse_url($url);
    \assert(\is_array($parsed_url));
    if (array_intersect_key($parsed_url, array_flip(['scheme', 'host']))) {
      return (new GeneratedUrl())->setGeneratedUrl($url);
    }

    \assert(isset($parsed_url['path']));
    $path = ltrim($parsed_url['path'], '/');
    $template_path = $this->getComponentPlugin()->getTemplatePath();
    \assert(\is_string($template_path));
    $referenced_asset_path = Path::canonicalize(dirname($template_path) . '/' . $path);
    if (is_file($referenced_asset_path)) {
      // SDC example values pointing to assets included in the SDC.
      // For example, an "avatar" SDC that shows an image, and:
      // - the example value is `avatar.png`
      // - the SDC contains a file called `avatar.png`
      // - this returns `/path/to/drupal/path/to/sdc/avatar.png`, resulting in a
      //   working preview.
      return Url::fromUri('base:/' . $referenced_asset_path)
        ->toString(TRUE)
        // When the SDC is moved, the generated URL must be updated.
        ->addCacheTags($this->getPluginDefinition()['discoveryCacheTags']);
    }

    // SDC example values pointing to sample locations, not actual assets.
    // For example, a "call to action" SDC that points to a destination, and:
    // - the example value is `adopt-a-llama`
    // - this returns `/path/to/drupal/adopt-a-llama`, resulting in a
    //   reasonable preview, even though there is unlikely to be a page on the
    //   site with the `adapt-a-llama` path.
    return Url::fromUri('base:/' . $path)->toString(TRUE);
  }

}
