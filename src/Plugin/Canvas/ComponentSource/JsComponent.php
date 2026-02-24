<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component source based on Canvas JavaScript Component config entities.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Code Components'),
  supportsImplicitInputs: FALSE,
  discovery: JsComponentDiscovery::class,
  updater: GeneratedFieldExplicitInputUxComponentInstanceUpdater::class,
  // @see \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave()
  discoveryCacheTags: ['config:js_component_list'],
)]
final class JsComponent extends GeneratedFieldExplicitInputUxComponentSourceBase implements UrlRewriteInterface {

  public const SOURCE_PLUGIN_ID = 'js';

  public const EXAMPLE_VIDEO_HORIZONTAL = '/ui/assets/videos/mountain_wide.mp4';
  public const EXAMPLE_VIDEO_VERTICAL = '/ui/assets/videos/bird_vertical.mp4';

  protected ExtensionPathResolver $extensionPathResolver;
  protected AutoSaveManager $autoSaveManager;
  protected FileUrlGeneratorInterface $fileUrlGenerator;
  protected ?JavaScriptComponent $jsComponent = NULL;
  protected GlobalImports $globalImports;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extensionPathResolver = $container->get(ExtensionPathResolver::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $instance->fileUrlGenerator = $container->get(FileUrlGeneratorInterface::class);
    $instance->globalImports = $container->get(GlobalImports::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    // Code components are powered by config entities. Config entities'
    // dependencies SHOULD make breaking such components impossible. But it is
    // possible to bypass both `delete` access checks and config system
    // integrity checks, so perform the necessary confirmation.
    $js_component_storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    \assert($js_component_storage instanceof ConfigEntityStorageInterface);
    return $js_component_storage->load($this->getSourceSpecificComponentId()) === NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getComponentPlugin(): ComponentPlugin {
    if ($this->componentPlugin === NULL) {
      // Statically cache the loaded plugin.
      $this->componentPlugin = JsComponentDiscovery::buildEphemeralSdcPluginInstance($this->getJavaScriptComponent(), $this->configuration['prop_field_definitions']);
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
    // This component source doesn't use plugin classes.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getJavaScriptComponent(): JavaScriptComponent {
    if ($this->jsComponent === NULL) {
      $js_component_storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
      \assert($js_component_storage instanceof ConfigEntityStorageInterface);
      $id = $this->getSourceSpecificComponentId();
      $js_component = $js_component_storage->load($id);
      if (!$js_component instanceof JavaScriptComponent) {
        throw new ComponentNotFoundException(\sprintf('The JavaScript Component with ID `%s` does not exist.', $id));
      }
      $this->jsComponent = $js_component;
    }
    return $this->jsComponent;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    // @todo Add the global asset library in https://www.drupal.org/project/canvas/issues/3499933.
    $dependencies['config'][] = $this->getJavaScriptComponent()->getConfigDependencyName();
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    try {
      $js_component = $this->getJavaScriptComponent();
      return new TranslatableMarkup('Code component: %name', [
        '%name' => $js_component->label(),
      ]);
    }
    catch (\Exception) {
      return new TranslatableMarkup('Invalid/broken code component');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    $component = $this->getJavaScriptComponent();

    // Rendering will validate the props against the version that is published,
    // but on preview the auto-saved version will be used for those components
    // whose implementation lives in config entities (e.g. for JS Components,
    // but not SDCs). This means that required props might be mismatched, so we
    // need to ensure there are explicit inputs for the set of props that are
    // required in any of those.
    if ($isPreview) {
      $published_required_props = $this->getDefaultExplicitInput(only_required: TRUE);
      \assert(Inspector::assertAllHaveKey($published_required_props, 'value'));
      $published_required_props = \array_map(fn(array $prop_source) => new EvaluationResult($prop_source['value']), $published_required_props);
      [$published_required_props, $published_required_props_cacheability] = self::getResolvedPropsAndCacheability(\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $published_required_props));
    }

    $autoSave = $this->loadAutoSaveEntity($component, $isPreview);
    $component_url = $component->getComponentUrl($this->fileUrlGenerator, $isPreview);

    $build = [];
    $base_path = \base_path();
    $build['#attached']['library'][] = $component->getAssetLibrary($isPreview);

    $canvas_path = $this->extensionPathResolver->getPath('module', 'canvas');
    // Build base import map.
    $import_maps[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS] = $this->globalImports->getGlobalImports();
    // For scoped dependencies we don't need cache-busting query strings, as
    // those are already busted by its content-dependent filename: when the
    // code component changes, so does the filename.
    // @see \Drupal\canvas\Entity\CanvasAssetLibraryTrait::getJsPath()
    $scoped_map = $this->getScopedDependencies($component, $autoSave, $isPreview);
    if (count($scoped_map) > 0) {
      $import_maps[ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS] = $scoped_map;
    }

    $build['#attached']['library'] = \array_merge($build['#attached']['library'], $this->getDependencyLibraries($component, $autoSave, $isPreview));

    if (\count($build['#attached']['library']) === 0) {
      unset($build['#attached']['library']);
    }
    // Resource hints.
    $resource_hints = [
      'preact/signals' => \sprintf('%s%s/packages/astro-hydration/dist/signals.module.js', $base_path, $canvas_path),
      '@/lib/preload-helper' => \sprintf('%s%s/packages/astro-hydration/dist/preload-helper.js', $base_path, $canvas_path),
    ];
    foreach ($resource_hints as $url) {
      $build['#attached']['html_head_link'][] = [
        [
          'rel' => 'modulepreload',
          'fetchpriority' => 'high',
          'href' => $url . '?' . $this->globalImports->getQueryString(),
        ],
      ];
    }
    if ($isPreview && !$autoSave->isEmpty()) {
      \assert($autoSave->entity instanceof JavaScriptComponent);
      $component = $autoSave->entity;
    }
    if ($isPreview) {
      $build['#cache']['tags'][] = AutoSaveManager::CACHE_TAG;
      // Always attach the draft asset library when loading the preview: avoid
      // race conditions; let the controller handle it for us.
      // @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getCss()
      $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID . '.draft';
    }
    else {
      $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID;
    }

    $valid_props = $component->getProps() ?? [];

    [$props, $props_cacheability] = self::getResolvedPropsAndCacheability(\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $valid_props));

    // Explicit inputs for required props for both the auto-saved version and
    // the live versions, including cacheability.
    if ($isPreview) {
      $props += $published_required_props;
      $props_cacheability->merge($published_required_props_cacheability);
    }

    // Match SDC's developer-only validation of props.
    // @see \Drupal\Core\Template\ComponentsTwigExtension::validateProps()
    \assert($this->componentValidator->validateProps($props, $this->getComponentPlugin()));
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($component)
      ->addCacheableDependency($props_cacheability)
      ->applyTo($build);

    return $build + [
      '#type' => 'astro_island',
      '#uuid' => $componentUuid,
      '#import_maps' => $import_maps,
      '#name' => $component->label(),
      '#component_url' => $component_url,
      '#props' => $props + [
        'canvas_uuid' => $componentUuid,
        'canvas_slot_ids' => \array_keys($slot_definitions),
        'canvas_is_preview' => $isPreview,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  /**
   * Returns the source label for this component.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The source label.
   */
  protected function getSourceLabel(): TranslatableMarkup {
    return $this->t('Code component');
  }

  /**
   * @todo Remove in clean-up follow-up; minimize non-essential changes.
   */
  public static function componentIdFromJavascriptComponentId(string $javaScriptComponentId): string {
    return JsComponentDiscovery::getComponentConfigEntityId($javaScriptComponentId);
  }

  /**
   * {@inheritdoc}
   */
  public function rewriteExampleUrl(string $url): GeneratedUrl {
    // Allow any fully qualified URL.
    $parsed_url = parse_url($url);
    \assert(\is_array($parsed_url));
    if (array_intersect_key($parsed_url, array_flip(['scheme', 'host']))) {
      return (new GeneratedUrl())->setGeneratedUrl($url);
    }

    // Allow the example URL to be one of the hardcoded relative URLs, and
    // rewrite them to operational root-relative URLs.
    // Only allow precise matches for both DX and security reasons.
    $example_videos = [
      self::EXAMPLE_VIDEO_HORIZONTAL,
      self::EXAMPLE_VIDEO_VERTICAL,
    ];
    if (in_array($url, $example_videos, TRUE)) {
      $file_path = $this->extensionPathResolver->getPath('module', 'canvas') . $url;
      return Url::fromUri('base:/' . $file_path)->toString(TRUE);
    }

    throw new \InvalidArgumentException('Default images for Javascript Components must be a fully-qualified URL with both scheme and host.');
  }

  private function getScopedDependencies(JavaScriptComponent $component, AutoSaveEntity $autoSave, bool $isPreview, array $seen = []): array {
    $scoped_dependencies = [];
    $component_url = $component->getComponentUrl($this->fileUrlGenerator, $isPreview);
    foreach ($component->getComponentDependencies($autoSave, $isPreview) as $js_component_dependency_name => $js_component_dependency) {
      if (\in_array($js_component_dependency_name, $seen, TRUE)) {
        // Recursion or already processed by another dependency.
        continue;
      }
      $seen[] = $js_component_dependency_name;
      \assert($js_component_dependency instanceof JavaScriptComponent);
      $dependencyAutoSave = $this->loadAutoSaveEntity($js_component_dependency, $isPreview);
      $dependency_component_url = $js_component_dependency->getComponentUrl($this->fileUrlGenerator, $isPreview);
      $scoped_dependencies[$component_url]["@/components/{$js_component_dependency_name}"] = $js_component_dependency->getComponentUrl($this->fileUrlGenerator, $isPreview);
      $scoped_dependencies = array_merge($scoped_dependencies, $this->getScopedDependencies($js_component_dependency, $dependencyAutoSave, $isPreview, $seen));
      if (isset($scoped_dependencies[$dependency_component_url])) {
        // The dependencies of my dependencies are also my dependencies, so says
        // the logic.
        $scoped_dependencies[$component_url] = array_merge($scoped_dependencies[$component_url], $scoped_dependencies[$dependency_component_url]);
      }
    }
    return $scoped_dependencies;
  }

  private function getDependencyLibraries(JavaScriptComponent $component, AutoSaveEntity $autoSave, bool $isPreview, array $seen = []): array {
    $libraries = [];
    foreach ($component->getComponentDependencies($autoSave, $isPreview) as $js_component_dependency_name => $js_component_dependency) {
      if (\in_array($js_component_dependency_name, $seen, TRUE)) {
        // Recursion or already processed by another dependency.
        continue;
      }
      $seen[] = $js_component_dependency_name;
      \assert($js_component_dependency instanceof JavaScriptComponent);
      $dependencyAutoSave = $this->loadAutoSaveEntity($js_component_dependency, $isPreview);
      $libraries[] = $js_component_dependency->getAssetLibrary($isPreview);
      $libraries = array_merge($libraries, $this->getDependencyLibraries($js_component_dependency, $dependencyAutoSave, $isPreview, $seen));
    }
    return $libraries;
  }

  public function setJavaScriptComponent(?JavaScriptComponent $jsComponent): static {
    $this->jsComponent = $jsComponent;
    return $this;
  }

  private function loadAutoSaveEntity(EntityInterface $entity, bool $isPreview): AutoSaveEntity {
    // If we are not previewing then we never need to load the auto-save data.
    return $isPreview ? $this->autoSaveManager->getAutoSaveEntity($entity) : AutoSaveEntity::empty();
  }

}
