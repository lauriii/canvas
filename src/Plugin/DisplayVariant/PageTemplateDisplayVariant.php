<?php

namespace Drupal\experience_builder\Plugin\DisplayVariant;

use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Display\PageVariantInterface;
use Drupal\Core\Display\VariantBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides a page display variant decorating the main content with components.
 *
 * Uses the theme's `page.html.twig` and populates each region in that Twig
 * template with an Experience Builder component tree, which are defined in the
 * Experience Builder PageTemplate config entity for that theme.
 *
 * To ensure essential information is displayed, the validation constraints for
 * `type: experience_builder.page_template.*` ensure that:
 * 1. exactly one Experience Builder Component is placed that uses
 *    MainContentBlockPluginInterface
 * 2. exactly one Experience Builder Component is placed that uses
 *    TitleBlockPluginInterface
 * 3. exactly one Experience Builder Component is placed that uses
 *    MessagesBlockPluginInterface
 *
 * All MessagesBlockPluginInterface implementations use the global context; but
 * the two others need to receive the information from this page variant. To
 * achieve that without burdening all intermediary abstraction layers with the
 * need for additional parameters or exception handling, PHP fibers are used.
 *
 * @see docs/components.md
 * @see \Drupal\Core\Render\Element\Page
 * @see \Drupal\experience_builder\Entity\PageTemplate
 * @see \Drupal\Core\Block\MainContentBlockPluginInterface
 * @see \Drupal\Core\Block\TitleBlockPluginInterface
 * @see \Drupal\Core\Block\MessagesBlockPluginInterface
 *
 * @todo When implementing XB requirement `41. Conditional display of components`, also implement \Drupal\Core\Display\ContextAwareVariantInterface: https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122&range=B53
 */
#[PageDisplayVariant(
  id: 'experience_builder_page_template_display',
  admin_label: new TranslatableMarkup('Page with Experience Builder Components')
)]
final class PageTemplateDisplayVariant extends VariantBase implements PageVariantInterface {

  /**
   * The plugin configuration key whose value is the PageTemplate config entity.
   *
   * @var string
   */
  const PAGE_TEMPLATE_CONFIG_ENTITY_KEY = 'page_template';

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
    $page_template = $this->configuration[static::PAGE_TEMPLATE_CONFIG_ENTITY_KEY] ?? NULL;
    if (!$page_template instanceof PageTemplate) {
      throw new \LogicException('This page display variant needs an Experience Builder PageTemplate config entity.');
    }

    assert(!empty($this->title));
    assert(!empty($this->mainContent));

    $build = [];
    foreach ($page_template->getComponentTrees() as $region_name => $component_tree) {
      assert($component_tree instanceof ComponentTreeItem);

      // Render the component tree in a PHP fiber to allow injecting page-level
      // information (title + main content — both originating from the matched
      // route's controller) into special XB Components.
      // @see \Drupal\Core\Display\PageVariantInterface
      // @see \Drupal\Core\Block\MainContentBlockPluginInterface
      // @see \Drupal\Core\Block\TitleBlockPluginInterface
      // @see \Drupal\experience_builder\ComponentSource\ComponentSourceInterface::renderComponent()
      // @see \Drupal\block\Plugin\DisplayVariant\BlockPageVariant::build()
      $fiber = new \Fiber(fn() => $component_tree->toRenderable());
      $component_instance = $fiber->start();
      while ($fiber->isSuspended()) {
        $component_instance = match (TRUE) {
          // The first kind of page-level information: the main content.
          $component_instance instanceof MainContentBlockPluginInterface => (function () use ($component_instance, $fiber) {
            $component_instance->setMainContent($this->mainContent);
            return $fiber->resume();
          })(),
          // The second kind of page-level information: the title.
          $component_instance instanceof TitleBlockPluginInterface => (function () use ($component_instance, $fiber) {
            $component_instance->setTitle($this->title);
            return $fiber->resume();
          })(),
          // No other page-level information exists in Drupal at this time.
          default => new \LogicException(),
        };
      }
      assert($fiber->isTerminated());
      $build[$region_name] = $fiber->getReturn();
    }

    CacheableMetadata::createFromObject($page_template)->applyTo($build);

    return $build;
  }

}
