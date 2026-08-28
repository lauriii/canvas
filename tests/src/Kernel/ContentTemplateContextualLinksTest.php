<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that entities rendered by a ContentTemplate still get contextual links.
 *
 * Canvas replaces the entity's own theme hook, but it must not remove it: that
 * would take the entity out of the theme layer, and contextual links are added
 * by a preprocess function.
 *
 * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 */
#[Group('canvas')]
final class ContentTemplateContextualLinksTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use CrawlerTrait;
  use GenerateComponentConfigTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'contextual',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: ['access content', 'access contextual links']);
  }

  /**
   * Tests contextual links on an entity rendered by a content template.
   */
  public function testContextualLinksArePresent(): void {
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'A node rendered by Canvas',
      'status' => TRUE,
    ]);
    // The contextual links placeholder for this node. It must live inside an
    // element marked as a contextual region: those are the two halves of what
    // \Drupal\contextual\Hook\ContextualThemeHooks::preprocess() contributes,
    // and both are required for the contextual links JavaScript to work.
    $placeholder = \sprintf('[data-contextual-id^="node:node=%s:"]', $node->id());

    $view_builder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $view_builder);

    // Without a content template, Drupal's own `node` theme hook is used, so
    // the node gets its contextual links, on the `<article>` that
    // `node.html.twig` renders.
    $build = $view_builder->view($node, 'full');
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('article.contextual-region ' . $placeholder));

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => 'a3d1cf9d-5f74-4c5a-a2a2-4a0dd88f4a3f',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
    ])->setStatus(TRUE)->save();

    // Opting the bundle into Canvas must not cost it its contextual links.
    $build = $view_builder->view($node, 'full');
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('h1:contains("A node rendered by Canvas")'));
    self::assertCount(1, $crawler->filter('div.contextual-region ' . $placeholder));
    // The node's own template must still be bypassed: Canvas owns the markup.
    // Without this, simply not replacing `#theme` at all would satisfy the
    // assertion above, because `node.html.twig` renders `title_suffix` inside
    // its own `<article class="contextual-region">`.
    self::assertCount(0, $crawler->filter('article'));
  }

}
