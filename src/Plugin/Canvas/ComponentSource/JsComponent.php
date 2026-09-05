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
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\Evaluator;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\NegotiatedLanguage;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Render\BubbleableMetadata;
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

  /**
   * Render-array metadata used to describe an external component.
   *
   * Without a fallback implementation, or while a headless frontend is
   * configured, the component renders as empty markup in Drupal. Consumers
   * that serialize component trees can use this metadata to preserve its
   * structure.
   *
   * @see \Drupal\canvas_headless\RenderConverter\JsComponentCanvasRenderConverter
   */
  public const EXTERNAL_RENDER_METADATA = '#canvas_external_component';

  public const EXAMPLE_VIDEO_HORIZONTAL = '/ui/assets/videos/mountain_wide.mp4';
  public const EXAMPLE_VIDEO_VERTICAL = '/ui/assets/videos/bird_vertical.mp4';
  public const EXAMPLE_DOCUMENT = '/ui/assets/documents/sample.pdf';

  /**
   * The root-relative example URLs that ship with the module.
   *
   * Only these exact strings are accepted as relative example URLs; every
   * other example URL must be fully qualified.
   *
   * @see self::validateExampleUrl()
   * @see self::rewriteExampleUrl()
   */
  public const EXAMPLE_URL_ALLOW_LIST = [
    self::EXAMPLE_VIDEO_HORIZONTAL,
    self::EXAMPLE_VIDEO_VERTICAL,
    self::EXAMPLE_DOCUMENT,
  ];

  protected ExtensionPathResolver $extensionPathResolver;
  protected AutoSaveManager $autoSaveManager;
  protected FileUrlGeneratorInterface $fileUrlGenerator;
  protected ?JavaScriptComponent $jsComponent = NULL;
  protected GlobalImports $globalImports;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected CodeComponentDataProvider $codeComponentDataProvider;
  protected ConfigFactoryInterface $configFactory;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->extensionPathResolver = $container->get(ExtensionPathResolver::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $instance->fileUrlGenerator = $container->get(FileUrlGeneratorInterface::class);
    $instance->globalImports = $container->get(GlobalImports::class);
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->codeComponentDataProvider = $container->get(CodeComponentDataProvider::class);
    $instance->configFactory = $container->get(ConfigFactoryInterface::class);
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
  public function getClientSideInfo(Component $component): array {
    $info = parent::getClientSideInfo($component);
    $js_component = $this->getJavaScriptComponent();
    return $js_component->isExternal()
      ? $info + [
        'type' => 'external',
        'hasFallbackImplementation' => $js_component->hasFallbackImplementation(),
      ]
      : $info;
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

    // The referenced entities must be read in the same language as the rest of
    // this component instance's props: the resolved host entity's language (or
    // the negotiated content language when there is no fieldable host).
    $language = NegotiatedLanguage::forReferenceHost($this->getFieldableHostEntity($item, $host_entity));

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
      $explicit_input['resolved'][$prop_name] = self::buildReferencePayload($referenced_entity, $expressions, $language);
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
   *   target selection is deferred until the referencer resolves to a concrete
   *   entity, because a multi-target-bundle reference (a
   *   `ReferencedBundleSpecificBranches` target) selects the branch matching
   *   the resolved entity's entity-type + bundle, which is only known at
   *   runtime — a resolved entity whose bundle has no matching branch (nor
   *   matching single-bundle target) yields a `__type`-only nested object;
   * - every entity object carries a `__type` set to the resolved entity's
   *   bundle, so code components can branch on it (including for
   *   multi-target-bundle references, where the bundle is only known at
   *   runtime).
   *
   * @param \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult $resolved_entity
   *   The resolved entity to evaluate the expressions against, wrapped in an
   *   EvaluationResult to carry the cacheability describing how this entity was
   *   loaded.
   * @param array<\Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface> $expressions
   *   The expressions, relative to $entity.
   * @param \Drupal\canvas\PropExpressions\StructuredData\NegotiatedLanguage $language
   *   The language to resolve the referenced entity (and any entities it
   *   references in turn) and read its field values in.
   *
   * @return \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult
   *   The payload object (always containing a `__type` key), wrapped in an
   *   EvaluationResult that carries the cacheability of every traversed entity.
   *
   * @see ::getExplicitInput()
   * @see \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult
   * @internal
   */
  public static function buildReferencePayload(EvaluationResult $resolved_entity, array $expressions, NegotiatedLanguage $language): EvaluationResult {
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

    // Resolve the referenced entity to $language before reading its field
    // values, so its fields (and those of any entities it references in turn)
    // are read in the negotiated language rather than the entity's own default.
    $entity = Evaluator::resolveTranslationForReference($entity, $language);

    $payload = ['__type' => $entity->bundle()];
    // The resulting payload must still describe the cacheability of how
    // $resolved_entity was loaded, the negotiated language it was resolved to,
    // plus the traversed entity itself: a `__type`-only payload (a bundle with
    // no matching branch or target) has no leaf pick to attach the entity's own
    // cache tags, so merge them here to keep the render invalidated when that
    // entity changes.
    $payload_cacheability = CacheableMetadata::createFromObject($resolved_entity)
      ->addCacheableDependency($entity)
      ->addCacheableDependency($language);

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
        $expression instanceof FieldPropExpression || $expression instanceof FieldObjectPropsExpression => NULL,
        default => throw new \UnhandledMatchError(\sprintf("'%s' is not a valid content-entity-reference prop expression.", (string) $expression)),
      };
      if ($reference_entries !== NULL) {
        foreach ($reference_entries as $reference) {
          \assert($reference instanceof ReferenceFieldPropExpression);
          // The target may be a `ReferencedBundleSpecificBranches`; it is kept
          // as-is because the concrete branch (or, for un-coalesced
          // cross-bundle picks, the matching single-bundle target) can only be
          // selected once the referencer resolves to a concrete entity below.
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
        language: $language,
      );
    }

    // Call recursively for each expression that follows a reference. Target
    // selection is deferred to here: only once the referencer resolves to a
    // concrete entity is its entity-type + bundle known, and one uniform
    // host-bundle matching rule then picks the concrete targets.
    foreach ($reference_groups as $key => $group) {
      $referenced = Evaluator::evaluate($entity, $group['referencer'],
        is_required: FALSE,
        language: $language,
      );
      $resolved_targets = [];
      if ($referenced->value instanceof FieldableEntityInterface) {
        $referenced_entity = $referenced->value;
        foreach ($group['targets'] as $target) {
          if ($target instanceof ReferencedBundleSpecificBranches) {
            // Select the branch matching the resolved entity, if any; the value
            // object owns the entity-type + bundle key format, mirroring
            // ReferenceFieldPropExpression::getTargetExpression().
            $branch = $target->getBranchForEntityOrNull($referenced_entity);
            if ($branch !== NULL) {
              $resolved_targets[] = $branch;
            }
            continue;
          }
          // A plain single-bundle target reached through a multi-bundle
          // referencer (un-coalesced cross-bundle picks) contributes only when
          // its host entity-type + bundle matches the resolved entity; a
          // bundle-less host definition matches on entity type alone. This
          // never evaluates a bundle-A expression against a bundle-B entity.
          // Through a single-bundle referencer targets match by construction.
          $host_definition = $target->getHostEntityDataDefinition();
          $host_bundles = $host_definition->getBundles();
          if ($host_definition->getEntityTypeId() === $referenced_entity->getEntityTypeId()
            && ($host_bundles === NULL || \in_array($referenced_entity->bundle(), $host_bundles, TRUE))) {
            $resolved_targets[] = $target;
          }
        }
      }
      // An empty resolved target list is the unmatched-bundle case: the
      // recursion returns a `__type`-only payload with the correct
      // cacheability. A NULL resolved entity likewise yields a NULL payload
      // (the target list is unused).
      $payload[$key] = self::buildReferencePayload($referenced, $resolved_targets, $language);
    }

    return new EvaluationResult($payload, $payload_cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    $component = $this->getJavaScriptComponent();
    $headless_settings = NULL;

    // Fetch the prop schemas early so color props can be resolved to rich
    // objects by getResolvedPropsAndBubbleableMetadata().
    // @todo Address in
    //   https://git.drupalcode.org/project/canvas/-/work_items/3591956.
    $prop_schemas = $this->getMetadata()->schema['properties'] ?? [];

    if ($component->isExternal()) {
      $headless_settings = $this->configFactory->get('canvas_headless.settings');
      $frontends = $headless_settings->get('frontends');
      $has_configured_frontend = \is_array($frontends) && $frontends !== [];

      if (!$component->hasFallbackImplementation() || $has_configured_frontend) {
        // The configured external application owns the implementation: it
        // renders external components itself, from its own codebase. Drupal
        // renders them as nothing — in previews and on live pages alike.
        // Expose their identity and resolved props as render-array metadata so
        // serializers can represent the app-owned component.
        [$props, $props_bubbleable_metadata] = self::getResolvedPropsAndBubbleableMetadata(
          \array_intersect_key(
            $inputs[self::EXPLICIT_INPUT_NAME] ?? [],
            $component->getProps() ?? [],
          ),
          $prop_schemas,
        );
        $build = [
          '#markup' => '',
          self::EXTERNAL_RENDER_METADATA => [
            'component_id' => self::componentIdFromJavascriptComponentId($component->id()),
            'component_uuid' => $componentUuid,
            'props' => $props,
          ],
        ];
        CacheableMetadata::createFromObject($component)
          ->addCacheableDependency($headless_settings)
          ->addCacheableDependency($props_bubbleable_metadata)
          ->applyTo($build);
        return $build;
      }
    }

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
      [$published_required_props, $published_required_props_bubbleable_metadata] = self::getResolvedPropsAndBubbleableMetadata(\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $published_required_props), $prop_schemas);
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

    [$props, $props_bubbleable_metadata] = self::getResolvedPropsAndBubbleableMetadata(\array_intersect_key($inputs[self::EXPLICIT_INPUT_NAME] ?? [], $valid_props), $prop_schemas);

    // Explicit inputs for required props for both the auto-saved version and
    // the live versions, including cacheability and attachments.
    if ($isPreview) {
      $props += $published_required_props;
      $props_bubbleable_metadata = $props_bubbleable_metadata->merge($published_required_props_bubbleable_metadata);
    }

    // Match SDC's developer-only validation of props.
    // @see \Drupal\Core\Template\ComponentsTwigExtension::validateProps()
    // Any InvalidComponentException propagates to RenderSafeComponentContainer
    // which in preview mode renders an empty container (no error text) so the
    // canvas-island stays visible while the user corrects the value.
    // @see \Drupal\canvas\Element\RenderSafeComponentContainer::handleComponentException()
    // @see https://www.drupal.org/project/canvas/issues/3583639
    $props_for_validation = $this->substituteColorPropsForValidation($props, $prop_schemas);
    \assert($this->componentValidator->validateProps($props_for_validation, $this->getComponentPlugin()));

    $bubbleable_metadata = BubbleableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($component)
      ->merge($props_bubbleable_metadata);
    if ($headless_settings !== NULL) {
      $bubbleable_metadata->addCacheableDependency($headless_settings);
    }
    // When this component reads `canvasData.v0.mainEntity`, its data embeds the
    // per-language translation list, derived from the enabled-language list and
    // the URL negotiation config. Rebuild it when either changes.
    if (\in_array('canvas/canvasData.v0.mainEntity', $component->getAssetLibraryDependencies(), TRUE)) {
      $bubbleable_metadata->addCacheTags(['config:configurable_language_list', 'config:language.negotiation']);
      // The data also embeds each language's name and per-translation view
      // access results. Their cacheability (per-language config cache tags,
      // and e.g. the `user.permissions` cache context) must be bubbled here:
      // the data itself is attached in hook_js_settings_alter(),
      // which runs during asset rendering, outside the render pipeline. The
      // returned data is discarded; it is recomputed there.
      // @see \Drupal\canvas\Hook\ComponentSourceHooks::jsSettingsAlter()
      $this->codeComponentDataProvider->getCanvasDataMainEntityV0($bubbleable_metadata);
    }
    $bubbleable_metadata->applyTo($build);

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
    if (\in_array($url, self::EXAMPLE_URL_ALLOW_LIST, TRUE)) {
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
