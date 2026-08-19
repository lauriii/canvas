<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DisplayVariant;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Display\PageVariantInterface;
use Drupal\Core\Display\VariantBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a page display variant that renders a Canvas page variant.
 *
 * The page variant's component tree replaces the active theme's
 * `page.html.twig`. Its "Page content" marker is replaced with the result of
 * the matched route's controller.
 * If the component tree does not contain a messages block, status messages are
 * prepended to the rendered page body.
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
final class CanvasPageVariant extends VariantBase implements PageVariantInterface, ContainerFactoryPluginInterface {

  public const string PLUGIN_ID = 'canvas';

  /**
   * The plugin configuration key whose value is the preview value.
   *
   * @var string
   */
  public const string PREVIEW_KEY = 'preview';

  /**
   * The plugin configuration key whose value is the page variant id to render.
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

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly AutoSaveManager $autoSaveManager, private readonly ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(AutoSaveManager::class),
      $container->get(ConfigFactoryInterface::class),
    );
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
    \assert(\is_string($variant_id));
    $variant = PageVariant::load($variant_id);
    if (!$variant instanceof PageVariant) {
      throw new \LogicException(\sprintf('The "%s" page variant does not exist.', $variant_id));
    }

    // In preview, render the auto-saved draft of the variant if one exists.
    if ($is_preview) {
      $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($variant);
      if (!$autoSaveData->isEmpty() && $autoSaveData->entity instanceof PageVariant) {
        // Auto-save drafts are written without validation, so an invalid draft
        // is an expected input here. Only render a draft that validates;
        // otherwise fall back to the published variant, degrading gracefully
        // instead of rendering broken chrome (or throwing) in every editor
        // preview of a page that uses this variant.
        // @see \Drupal\canvas\Controller\ApiLayoutController::updateEntity()
        $violations = $autoSaveData->entity->getTypedData()->validate();
        if (\count($violations) === 0) {
          $variant = $autoSaveData->entity;
        }
      }
    }

    \assert(!empty($this->mainContent));

    $component_tree = $variant->getComponentTree();

    // Track whether a block showing the messages is displayed.
    $messages_block_displayed = FALSE;

    // Render the variant's component tree in a PHP fiber so page-level
    // information can be injected into special Canvas Components: the title and
    // messages blocks receive their data, and the "Page content" marker is
    // replaced with the route's main content.
    // On preview, the tree renders in preview mode so its code components load
    // their auto-saved drafts, matching the draft global asset libraries
    // attached below. The "Page content" marker is still replaced with the
    // injected main content, so its edit placeholder never renders here.
    // @see \Drupal\Core\Display\PageVariantInterface
    // @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::renderComponent()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::renderComponent()
    // @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
    $fiber = new \Fiber(fn() => $component_tree->toRenderable($variant, $is_preview));
    $component_instance = $fiber->start();
    while ($fiber->isSuspended()) {
      $component_instance = match (TRUE) {
        // Page-level information: the title.
        $component_instance instanceof TitleBlockPluginInterface => (function () use ($component_instance, $fiber) {
          $component_instance->setTitle($this->title);
          return $fiber->resume();
        })(),
        $component_instance instanceof MessagesBlockPluginInterface => (function () use ($fiber, &$messages_block_displayed) {
          $messages_block_displayed = TRUE;
          return $fiber->resume();
        })(),
        // The "Page content" marker: inject the route's main content in place.
        $component_instance instanceof Marker => $fiber->resume($this->mainContent),
        // If the fiber was suspended in some other context (e.g. while loading
        // entities) resume it to continue component tree rendering.
        default => $fiber->resume(),
      };
    }
    \assert($fiber->isTerminated());
    $content = $fiber->getReturn();

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
    ];
    $build['#attached']['library'][] = 'canvas/asset_library.' . AssetLibrary::GLOBAL_ID .
      ($is_preview ? '.draft' : '');
    $build['#attached']['library'][] = 'canvas/brand_kit.' . BrandKit::GLOBAL_ID .
      ($is_preview ? '.draft' : '');
    CacheableMetadata::createFromObject($variant)
      // Which variant renders depends on the site default selection; a change
      // to it must invalidate the cached page.
      // @see \Drupal\canvas\PageVariantResolver
      ->addCacheableDependency($this->configFactory->get('canvas.settings'))
      ->applyTo($build);

    return $build;
  }

}
