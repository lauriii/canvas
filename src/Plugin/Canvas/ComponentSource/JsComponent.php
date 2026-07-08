<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\Evaluator;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\NegotiatedLanguage;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component source based on Canvas JavaScript Component config entities.
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Code Components'),
  supportsImplicitInputs: FALSE,
  discovery: JsComponentDiscovery::class,
  updater: JsonSchemaPropsComponentInstanceUpdater::class,
  inputs_config_schema_generator: JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::class,
  // @see \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave()
  discoveryCacheTags: ['config:js_component_list'],
)]
final class JsComponent extends JsonSchemaPropsComponentSourceBase implements UrlRewriteInterface {

  public const SOURCE_PLUGIN_ID = 'js';

  public const EXAMPLE_VIDEO_HORIZONTAL = '/ui/assets/videos/mountain_wide.mp4';
  public const EXAMPLE_VIDEO_VERTICAL = '/ui/assets/videos/bird_vertical.mp4';

  protected ExtensionPathResolver $extensionPathResolver;
  protected AutoSaveManager $autoSaveManager;
  protected FileUrlGeneratorInterface $fileUrlGenerator;
  protected ?JavaScriptComponent $jsComponent = NULL;
  protected GlobalImports $globalImports;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected CodeComponentDataProvider $codeComponentDataProvider;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extensionPathResolver = $container->get(ExtensionPathResolver::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $instance->fileUrlGenerator = $container->get(FileUrlGeneratorInterface::class);
    $instance->globalImports = $container->get(GlobalImports::class);
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->codeComponentDataProvider = $container->get(CodeComponentDataProvider::class);
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
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    $explicit_input = parent::getExplicitInput($uuid, $item, $host_entity);

    $js_component = $this->getJavaScriptComponent();
    $content_entity_reference_prop_names = \array_keys($js_component->getContentEntityReferenceProps());

    // Nothing extra to do if this code component has no
    // content-entity-reference props.
    if (empty($content_entity_reference_prop_names)) {
      return $explicit_input;
    }

    // Nothing extra to do if none of this code component's content-entity-
    // reference props are populated in the stored inputs.
    $raw_inputs = $item->getInputs() ?? [];
    $populated_content_entity_reference_props = \array_intersect(
      $content_entity_reference_prop_names,
      \array_keys($raw_inputs)
    );
    if (empty($populated_content_entity_reference_props)) {
      return $explicit_input;
    }

    // Resolve all content-entity-reference props, by evaluating their entity
    // field expressions on the referenced entities. The parent already parsed
    // each prop's PropSource and evaluated it against the host entity (with the
    // tree-root fallback for missing $host_entity), so reuse those resolved
    // EvaluationResults instead of re-parsing and re-evaluating here.
    foreach ($populated_content_entity_reference_props as $prop_name) {
      \assert(isset($explicit_input['resolved']) && \is_array($explicit_input['resolved']));
      $referenced_entity = $explicit_input['resolved'][$prop_name] ?? NULL;

      // Evaluate every entity field expression declared for this prop against
      // the resolved entity, and assemble a nested payload that mirrors the
      // reference structure of the expressions.
      $expression_strings = $js_component->getEntityFieldExpressions($prop_name);
      if (empty($expression_strings)) {
        continue;
      }
      // Entity field expressions for content-entity-reference props must be
      // entity-field-based (validated at save time): the schema restricts
      // `entityFields.*.*` to a FieldPropExpression, a
      // FieldObjectPropsExpression, or a ReferenceFieldPropExpression.
      // @see canvas.schema.yml (canvas.js_component.*: dataDependencies.entityFields)
      // @see \Drupal\canvas\Plugin\Validation\Constraint\ValidStructuredDataPropExpressionConstraintValidator
      $expressions = \array_map(function (string $expression_string): EntityFieldBasedPropExpressionInterface {
        $expression = StructuredDataPropExpression::fromString($expression_string);
        \assert($expression instanceof EntityFieldBasedPropExpressionInterface);
        return $expression;
      }, $expression_strings);

      // buildReferencePayload() returns an EvaluationResult whose cacheability
      // is composed (by EvaluationResult) from every entity it traverses into.
      // Add the prop source's cacheability: the host entity and reference field
      // that resolved $referenced_entity.
      \assert(isset($explicit_input['resolved']) && \is_array($explicit_input['resolved']));
      $explicit_input['resolved'][$prop_name] = self::buildReferencePayload($referenced_entity, $expressions);
    }

    return $explicit_input;
  }

  /**
   * Builds the nested developer-facing payload for one resolved entity.
   *
   * Each entity field expression declared for a content-entity-reference
   * prop is placed into a JSON object mirroring the expression's reference
   * structure:
   * - scalar leaves, and mixed objects (a directly-picked `↠` leaf alongside
   *   any follow-reference entries), become a key on the current entity object
   *   (keyed by the field/entity-key name, e.g. `label` or `title`);
   * - reference expressions — and reference-only objects, the coalesced form of
   *   several references through one field — descend into the referenced
   *   entity, producing a nested object (those sharing a referencer merge);
   * - every entity object carries a `__type` set to the resolved entity's
   *   bundle, so code components can branch on it (including for
   *   multi-target-bundle references, where the bundle is only known at
   *   runtime).
   *
   * @param \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult $resolved_entity
   *   The resolved entity to evaluate the expressions against, wrapped in an
   *   EvaluationResult to carry the cacheability describing how this entity was
   *   loaded.
   * @param array<\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface> $expressions
   *   The entity field expressions, relative to $entity.
   *
   * @return \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult
   *   The payload object (always containing a `__type` key), wrapped in an
   *   EvaluationResult that carries the cacheability of every traversed entity.
   *
   * @see ::getExplicitInput()
   * @see \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult
   */
  private static function buildReferencePayload(EvaluationResult $resolved_entity, array $expressions): EvaluationResult {
    if (!$resolved_entity->value instanceof FieldableEntityInterface) {
      if ($resolved_entity->value === NULL) {
        // Either:
        // - no entity was selected
        // - the selected entity has been deleted
        // The payload must hence be NULL, while retaining cacheability.
        return new EvaluationResult(NULL, CacheableMetadata::createFromObject($resolved_entity));
      }
      throw new \LogicException('Expected a fieldable entity wrapped in an EvaluationResult to convey how this entity was retrieved.');
    }
    $entity = $resolved_entity->value;

    $payload = ['__type' => $entity->bundle()];
    // The resulting payload must still describe the cacheability of how
    // $resolved_entity was loaded.
    $payload_cacheability = CacheableMetadata::createFromObject($resolved_entity);

    // References sharing a referencer field are descended into once, with
    // their target sub-expressions merged into a single nested object.
    $reference_groups = [];
    foreach ($expressions as $expression) {
      // A reference, or its coalesced form (a FieldObjectPropsExpression whose
      // entries are all follow-references), descends into a referenced entity:
      // decompose the object into the same reference groups as the equivalent
      // separate references so it yields an identical nested object (`__type`).
      // A mixed object (any `↠` leaf) stays a flat object leaf below.
      $reference_entries = match (TRUE) {
        $expression instanceof ReferenceFieldPropExpression => [$expression],
        $expression instanceof FieldObjectPropsExpression && $expression->isReferenceOnly() => \array_values($expression->objectPropsToFieldProps),
        default => NULL,
      };
      if ($reference_entries !== NULL) {
        foreach ($reference_entries as $reference) {
          \assert($reference instanceof ReferenceFieldPropExpression);
          // @todo Multi-target-bundle references (a `ReferencedBundleSpecificBranches` target) are deferred; add support in https://git.drupalcode.org/project/canvas/-/work_items/3591656.
          if (!$reference->referenced instanceof EntityFieldBasedPropExpressionInterface) {
            throw new \LogicException(\sprintf('Multi-target-bundle content entity references are not yet supported, but the expression `%s` targets bundle-specific branches.', (string) $reference));
          }
          $key = $reference->referencer->getDeveloperFacingKey();
          $reference_groups[$key]['referencer'] ??= $reference->referencer;
          $reference_groups[$key]['targets'][] = $reference->referenced;
        }
        continue;
      }
      // Scalar or object leaf: its EvaluationResult (value + cacheability) is
      // hoisted by the EvaluationResult returned below.
      $payload[$expression->getDeveloperFacingKey()] = Evaluator::evaluate($entity, $expression,
        is_required: FALSE,
        language: NegotiatedLanguage::matchEntity($entity),
      );
    }

    // Call recursively for each expression that follows a reference.
    foreach ($reference_groups as $key => $group) {
      $referenced = Evaluator::evaluate($entity, $group['referencer'],
        is_required: FALSE,
        language: NegotiatedLanguage::matchEntity($entity),
      );
      $payload[$key] = self::buildReferencePayload($referenced, $group['targets']);
    }

    return new EvaluationResult($payload, $payload_cacheability);
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
    $import_maps = $this->globalImports->getImportMap();
    // For scoped dependencies we don't need cache-busting query strings, as
    // those are already busted by its content-dependent filename: when the
    // code component changes, so does the filename.
    // @see \Drupal\canvas\Entity\CanvasAssetLibraryTrait::getJsPath()
    $scoped_map = $this->getScopedDependencies($component, $autoSave, $isPreview);
    $import_maps[ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS] = \array_merge($import_maps[ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS], $scoped_map);

    $build['#attached']['library'] = \array_merge($build['#attached']['library'], $this->getDependencyLibraries($component, $autoSave, $isPreview));

    if (\count($build['#attached']['library']) === 0) {
      unset($build['#attached']['library']);
    }

    $global_brand_kit = $this->getActiveGlobalBrandKit($isPreview);
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
    if ($global_brand_kit instanceof BrandKit) {
      foreach ($this->getFontPreloadLinks($global_brand_kit) as $font_preload) {
        $build['#attached']['html_head_link'][] = [
          $font_preload,
        ];
      }
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
      $build['#attached']['library'][] = 'canvas/brand_kit.' . BrandKit::GLOBAL_ID . '.draft';
    }
    else {
      $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID;
      $build['#attached']['library'][] = 'canvas/brand_kit.' . BrandKit::GLOBAL_ID;
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
    // Any InvalidComponentException propagates to RenderSafeComponentContainer
    // which in preview mode renders an empty container (no error text) so the
    // canvas-island stays visible while the user corrects the value.
    // @see \Drupal\canvas\Element\RenderSafeComponentContainer::handleComponentException()
    // @see https://www.drupal.org/project/canvas/issues/3583639
    \assert($this->componentValidator->validateProps($props, $this->getComponentPlugin()));
    $cacheability = CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($component)
      ->addCacheableDependency($props_cacheability);
    // When this component reads `canvasData.v0.mainEntity`, its data embeds the
    // per-language translation list, derived from the enabled-language list and
    // the URL negotiation config. Rebuild it when either changes.
    if (\in_array('canvas/canvasData.v0.mainEntity', $component->getAssetLibraryDependencies(), TRUE)) {
      $cacheability->addCacheTags(['config:configurable_language_list', 'config:language.negotiation']);
      // The data also embeds per-translation view access results. Their
      // cacheability (e.g. the `user.permissions` cache context) must be
      // bubbled here: the data itself is attached in hook_js_settings_alter(),
      // which runs during asset rendering, outside the render pipeline. The
      // returned data is discarded; it is recomputed there.
      // @see \Drupal\canvas\Hook\ComponentSourceHooks::jsSettingsAlter()
      $this->codeComponentDataProvider->getCanvasDataMainEntityV0($cacheability);
    }
    $cacheability->applyTo($build);

    return $build + [
      '#type' => 'astro_island',
      '#uuid' => $componentUuid,
      '#import_maps' => $import_maps,
      '#name' => $component->label(),
      '#machine_name' => $component->id(),
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

  private function getActiveGlobalBrandKit(bool $isPreview): ?BrandKit {
    $brand_kit_storage = $this->entityTypeManager->getStorage(BrandKit::ENTITY_TYPE_ID);
    \assert($brand_kit_storage instanceof ConfigEntityStorageInterface);
    $brand_kit = $brand_kit_storage->load(BrandKit::GLOBAL_ID);
    if (!$brand_kit instanceof BrandKit) {
      return NULL;
    }

    if (!$isPreview) {
      return $brand_kit;
    }

    $auto_save = $this->autoSaveManager->getAutoSaveEntity($brand_kit);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof BrandKit);
      return $auto_save->entity;
    }

    return $brand_kit;
  }

  /**
   * @param \Drupal\canvas\Entity\BrandKit $brand_kit
   *
   * @return list<array{
   *   rel: string,
   *   as: string,
   *   type: string,
   *   href: string,
   *   crossorigin: string
   *   }>
   */
  private function getFontPreloadLinks(BrandKit $brand_kit): array {
    $links = [];
    foreach ($brand_kit->getFonts() as $font) {
      $href = $this->fileUrlGenerator->generateString($font['uri']);
      $links[$href] = [
        'rel' => 'preload',
        'as' => 'font',
        'type' => self::getFontMimeType($font['format']),
        'href' => $href,
        'crossorigin' => 'anonymous',
      ];
    }

    return array_values($links);
  }

  private static function getFontMimeType(string $format): string {
    return match ($format) {
      'woff2' => 'font/woff2',
      'woff' => 'font/woff',
      'ttf' => 'font/ttf',
      'otf' => 'font/otf',
      default => 'font/woff2',
    };
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
    self::validateExampleUrl($url);

    // Allow any fully qualified URL.
    $parsed_url = parse_url($url);
    \assert(\is_array($parsed_url));
    if (array_intersect_key($parsed_url, array_flip(['scheme', 'host']))) {
      return (new GeneratedUrl())->setGeneratedUrl($url);
    }

    // Allow the example URL to be one of the hardcoded relative URLs, and
    // rewrite them to operational root-relative URLs.
    $file_path = $this->extensionPathResolver->getPath('module', 'canvas') . $url;
    return Url::fromUri('base:/' . $file_path)->toString(TRUE);
  }

  /**
   * Validates an example URL value against this component source's contract.
   *
   * @param string $url
   *   The example URL to validate.
   *
   * @throws \InvalidArgumentException
   *   When the URL cannot be used as an example value at runtime.
   */
  public static function validateExampleUrl(string $url): void {
    $parsed_url = parse_url($url);
    if (\is_array($parsed_url) && \array_intersect_key($parsed_url, array_flip(['scheme', 'host']))) {
      return;
    }
    // Only allow precise matches for both DX and security reasons.
    if ($url === self::EXAMPLE_VIDEO_HORIZONTAL || $url === self::EXAMPLE_VIDEO_VERTICAL) {
      return;
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
