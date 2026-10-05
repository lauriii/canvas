<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DisplayVariant;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Display\PageVariantInterface;
use Drupal\Core\Display\VariantBase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a page display variant that renders Canvas page chrome.
 *
 * The page variant's component tree replaces the active theme's
 * `page.html.twig`. Its "Page content" marker is replaced with the result of
 * the matched route's controller.
 * If the component tree does not contain a messages block, status messages are
 * prepended to the rendered page body.
 * When no page variant resolves, legacy global regions render for backward
 * compatibility until their migration creates a default page variant.
 *
 * @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber::onSelectPageDisplayVariant()
 * @see ::MAIN_CONTENT_REGION
 *
 * All MessagesBlockPluginInterface implementations use the global context; but
 * TitleBlockPluginInterface implementations need to receive the information
 * from this page variant. To achieve that without burdening all intermediary
 * abstraction layers with the need for additional parameters or exception
 * handling, PHP fibers are used.
 *
 * Finally, MainContentBlockPluginInterface implementations are prevented from
 * being made available as Canvas Components.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::checkRequirements()
 *
 * @see docs/components.md
 * @see \Drupal\Core\Render\Element\Page
 * @see \Drupal\Core\Block\MainContentBlockPluginInterface
 * @see \Drupal\Core\Block\TitleBlockPluginInterface
 * @see \Drupal\Core\Block\MessagesBlockPluginInterface
 *
 * @todo When implementing Canvas requirement `41. Conditional display of components`, also implement \Drupal\Core\Display\ContextAwareVariantInterface: https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122&range=B53
 */
#[PageDisplayVariant(
  id: self::PLUGIN_ID,
  admin_label: new TranslatableMarkup('Page with Drupal Canvas Components')
)]
final class CanvasPageVariant extends VariantBase implements PageVariantInterface, ContainerFactoryPluginInterface, TrustedCallbackInterface {

  public const string PLUGIN_ID = 'canvas';

  /**
   * Placeholder marking where the #main-content anchor may be inserted.
   *
   * A page variant may already contain an `id="main-content"` element (for
   * example the theme page template component renders the theme's
   * page.html.twig, which core ships with that anchor). Emitting a second one
   * would create a duplicate id, so the anchor is resolved after rendering:
   * ::buildPageVariant() prefixes this placeholder to the main content (which
   * renders at the "Page content" marker) and a #post_render callback replaces
   * it with the anchor only when the rendered page has none, and removes it
   * otherwise.
   *
   * @see ::buildPageVariant()
   * @see ::resolveMainContentAnchor()
   */
  private const string MAIN_CONTENT_ANCHOR_PLACEHOLDER = '<!--canvas-main-content-anchor-->';

  /**
   * The #main-content anchor the theme's "Skip to main content" link targets.
   *
   * @see core/modules/system/templates/html.html.twig
   * @see core/modules/system/templates/page.html.twig
   */
  private const string MAIN_CONTENT_ANCHOR = '<a id="main-content" tabindex="-1"></a>';

  /**
   * The plugin configuration key whose value is the preview value.
   *
   * @var string
   */
  public const string PREVIEW_KEY = 'preview';

  /**
   * The plugin configuration key whose value is the page variant id to render.
   *
   * When omitted, the display variant renders legacy global regions.
   */
  public const string VARIANT_ID_KEY = 'page_variant';

  /**
   * The (machine) name of the only theme region required to exist.
   *
   * @see \Drupal\system\Controller\SystemController::themesPage()
   */
  public const string MAIN_CONTENT_REGION = 'content';

  /**
   * The render array representing the main page content.
   *
   * @var array
   */
  private $mainContent = [];

  /**
   * The page title: a string (plain title) or a render array (formatted title).
   *
   * @var string|array
   */
  private $title = '';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly AutoSaveManager $autoSaveManager, private readonly PageVariantResolver $pageVariantResolver, private readonly ConfigFactoryInterface $configFactory, private readonly LanguageManagerInterface $languageManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(AutoSaveManager::class),
      $container->get(PageVariantResolver::class),
      $container->get(ConfigFactoryInterface::class),
      $container->get(LanguageManagerInterface::class),
    );
  }

  /**
   * Returns the component tree to render for a preview of this entity.
   *
   * In preview, the entity may be an auto-save draft, which is always base
   * (untranslated) data: drafts are stored outside the config system, so the
   * language's LanguageConfigOverride is never applied to them. When
   * previewing in a language that has a translation override, merge it in, so
   * the preview chrome matches what the front end serves for that language.
   */
  private function getPreviewComponentTree(ComponentTreeConfigEntityBase $entity): ComponentTreeItemList {
    // ::getTranslationLanguages() already excludes the site default language
    // and any language without a stored override, so membership alone decides
    // whether an override applies.
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    if (\array_key_exists($langcode, $entity->getTranslationLanguages(include_default: FALSE))) {
      return $entity->getTranslatedComponentTree($langcode);
    }
    return $entity->getComponentTree();
  }

  /**
   * {@inheritdoc}
   */
  public function setMainContent(array $main_content) {
    $this->mainContent = $main_content;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    \assert(\is_bool($this->configuration[self::PREVIEW_KEY]) || \is_null($this->configuration[self::PREVIEW_KEY]));
    $is_preview = $this->configuration[self::PREVIEW_KEY] === TRUE;

    $variant_id = $this->configuration[self::VARIANT_ID_KEY] ?? NULL;
    if ($variant_id === NULL) {
      $build = $this->buildLegacyPageRegions($is_preview);
    }
    else {
      \assert(\is_string($variant_id));
      $build = $this->buildPageVariant($variant_id, $is_preview);
    }

    $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID .
      ($is_preview ? '.draft' : '');
    $build['#attached']['library'][] = 'canvas/brand_kit.' . BrandKit::GLOBAL_ID .
      ($is_preview ? '.draft' : '');
    // Both rendering paths depend on the site default selection. A change to
    // it must invalidate the cached page.
    // @see \Drupal\canvas\PageVariantResolver
    $cacheability = CacheableMetadata::createFromObject($this->configFactory->get('canvas.settings'));
    if ($is_preview) {
      // A preview renders the negotiated language's translation override, so
      // its output varies by interface language. The override itself needs no
      // cache tag of its own: it shares the base config object's tag, already
      // present via the rendered entity's cacheability.
      // @see \Drupal\language\Config\LanguageConfigOverride::save()
      // @see self::getPreviewComponentTree()
      $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_INTERFACE]);
    }
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * Builds a page variant's component tree.
   */
  private function buildPageVariant(string $variant_id, bool $is_preview): array {
    $variant = PageVariant::load($variant_id);
    if (!$variant instanceof PageVariant) {
      throw new \LogicException(\sprintf('The "%s" page variant does not exist.', $variant_id));
    }

    if ($is_preview) {
      $variant = $this->pageVariantResolver->resolvePreviewVariant($variant);
    }

    \assert(!empty($this->mainContent));

    // Track whether a block showing the messages is displayed.
    $messages_block_displayed = FALSE;

    // Prefix the main content with the #main-content anchor placeholder, which
    // ::resolveMainContentAnchor() resolves once the whole variant has
    // rendered.
    // @see ::resolveMainContentAnchor()
    // @see \Drupal\canvas_headless\CanvasContentEntityRenderer
    $main_content = [
      'main_content_anchor' => ['#markup' => Markup::create(self::MAIN_CONTENT_ANCHOR_PLACEHOLDER)],
      'main_content' => $this->mainContent,
    ];

    $content = self::renderComponentTree(
      $is_preview ? $this->getPreviewComponentTree($variant) : $variant->getComponentTree(),
      $variant,
      $is_preview,
      $messages_block_displayed,
      $main_content,
      $this->title,
    );

    // If no block displays status messages, still render them, above the page.
    if (!$messages_block_displayed) {
      $content = [
        'messages' => [
          '#weight' => -1000,
          '#type' => 'status_messages',
          '#include_fallback' => TRUE,
        ],
        'content' => $content,
      ];
    }

    // The variant tree is the whole page body: render it through the bare page
    // template, replacing the theme's page.html.twig.
    // @see \Drupal\canvas\Hook\ModuleHooks::theme()
    $build = [
      '#theme' => 'canvas_page_variant',
      '#content' => $content,
      // Resolve the #main-content anchor placeholder once the whole variant
      // (including any theme page template component) has rendered.
      // @see ::resolveMainContentAnchor()
      '#post_render' => [[self::class, 'resolveMainContentAnchor']],
    ];
    CacheableMetadata::createFromObject($variant)
      ->applyTo($build);

    return $build;
  }

  /**
   * Builds legacy global regions for backward compatibility.
   */
  private function buildLegacyPageRegions(bool $is_preview): array {
    $build = [];

    $regions = PageRegion::loadForActiveTheme();
    if ($regions === []) {
      throw new \LogicException('The active theme has no enabled PageRegion entities.');
    }

    \assert(!empty($this->title));
    \assert(!empty($this->mainContent));

    $messages_block_displayed = FALSE;
    foreach ($regions as $region) {
      if ($is_preview) {
        $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($region);
        if (!$autoSaveData->isEmpty() && $autoSaveData->entity instanceof PageRegion) {
          $violations = $autoSaveData->entity->getTypedData()->validate();
          if (\count($violations) === 0) {
            $region = $autoSaveData->entity;
          }
        }
      }

      $build[$region->get('region')] = self::renderComponentTree(
        $is_preview ? $this->getPreviewComponentTree($region) : $region->getComponentTree(),
        $region,
        $is_preview,
        $messages_block_displayed,
        $this->mainContent,
        $this->title,
      );
    }

    $build[self::MAIN_CONTENT_REGION]['system_main'] = $this->mainContent;
    if (!$messages_block_displayed) {
      $build[self::MAIN_CONTENT_REGION]['messages'] = [
        '#weight' => -1000,
        '#type' => 'status_messages',
        '#include_fallback' => TRUE,
      ];
    }

    return $build;
  }

  /**
   * Renders a component tree with page-level values injected through a fiber.
   *
   * The preview flag is passed to the component tree so component sources can
   * render their auto-saved drafts. Title and messages blocks receive their
   * page-level data. The "Page content" marker is replaced with the given main
   * content, exactly as passed: callers that need the #main-content anchor
   * prefix it themselves before calling, so other callers (for example the
   * headless content API) are unaffected.
   *
   * @see ::buildPageVariant()
   * @see \Drupal\Core\Display\PageVariantInterface
   * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::renderComponent()
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::renderComponent()
   */
  public static function renderComponentTree(ComponentTreeItemList $component_tree, ComponentTreeEntityInterface $entity, bool $is_preview, bool &$messages_block_displayed, array $main_content, mixed $title): array {
    $fiber = new \Fiber(fn() => $component_tree->toRenderable($entity, $is_preview));
    $component_instance = $fiber->start();
    while ($fiber->isSuspended()) {
      $component_instance = match (TRUE) {
        // Page-level information.
        $component_instance instanceof TitleBlockPluginInterface => (function () use ($component_instance, $fiber, $title) {
          $component_instance->setTitle($title);
          return $fiber->resume();
        })(),
        $component_instance instanceof MessagesBlockPluginInterface => (function () use ($fiber, &$messages_block_displayed) {
          $messages_block_displayed = TRUE;
          return $fiber->resume();
        })(),
        // The "Page content" marker: inject the route's main content in place.
        $component_instance instanceof Marker => $fiber->resume($main_content),
        // If the fiber was suspended in some other context (e.g. while loading
        // entities) resume it to continue component tree rendering.
        default => $fiber->resume(),
      };
    }
    \assert($fiber->isTerminated());
    return $fiber->getReturn();
  }

  /**
   * Resolves the #main-content anchor placeholder in the rendered variant.
   *
   * The theme's html.html.twig renders a "Skip to main content" link targeting
   * `#main-content`. A variant may contain the anchor (for example, the theme
   * page template component renders the theme's page.html.twig), so emitting
   * one unconditionally would create a duplicate id. The placeholder sits where
   * the route's main content is injected; here it becomes the anchor only when
   * the rendered page has none, and is removed otherwise.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $children
   *   The rendered children of the page variant.
   * @param array $element
   *   The render array being rendered.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   The rendered children with the placeholder resolved.
   *
   * @see core/modules/system/templates/html.html.twig
   * @see core/modules/system/templates/page.html.twig
   */
  public static function resolveMainContentAnchor(string|MarkupInterface $children, array $element): string|MarkupInterface {
    $html = (string) $children;
    if (!\str_contains($html, self::MAIN_CONTENT_ANCHOR_PLACEHOLDER)) {
      return $children;
    }
    // Only the absence of an existing id="main-content" warrants an anchor. The
    // attribute name is matched case-insensitively (HTML attribute names are),
    // but the id value is matched case-sensitively and bounded: fragment
    // navigation to the skip link's `#main-content` is case-sensitive and must
    // not match `Main-Content`, and `main-content-foo` is a different id.
    // @see core/modules/system/templates/html.html.twig
    $has_anchor = \preg_match('/(?<![-\w])(?i:id)\s*=\s*(?:"main-content"|\'main-content\'|main-content(?![\w-]))/', $html) === 1;
    $replacement = $has_anchor ? '' : self::MAIN_CONTENT_ANCHOR;
    return Markup::create(\str_replace(self::MAIN_CONTENT_ANCHOR_PLACEHOLDER, $replacement, $html));
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['resolveMainContentAnchor'];
  }

}
