<?php

namespace Drupal\experience_builder\Plugin\DisplayVariant;

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

    // @todo Blocked on Blocks-as-XB-Components: https://www.drupal.org/project/experience_builder/issues/3475584
    // Algorithm:
    // 1. in the foreach-loop below, iterate over the components in each tree to
    //    find where 2 of the 3 special block plugins are placed
    //    (config validation will already guarantee their presence)
    // 2. call ::setMainContent($this->mainContent) and ::setTitle($this->title)
    //    (no special treatment is necessary for the messages block: it gets its
    //    information from global state)

    $build = [];
    foreach ($page_template->getComponentTrees() as $region_name => $component_tree) {
      assert($component_tree instanceof ComponentTreeItem);
      $build[$region_name] = $component_tree->toRenderable();
    }

    CacheableMetadata::createFromObject($page_template)->applyTo($build);

    // @todo Remove these once the ::setMainContent() call is present. All are added by the route controller calling \Drupal\user\Form\UserLoginForm.
    $build['#cache']['tags'][] = 'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form';
    $build['#cache']['tags'][] = 'config:system.site';
    $build['#cache']['contexts'][] = 'url.path';
    $build['#cache']['contexts'][] = 'url.query_args';

    return $build;
  }

}
