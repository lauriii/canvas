<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * @covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 * @group canvas
 */
final class NodeTemplatesTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use ContentTypeCreationTrait;
  use CanvasFieldCreationTrait;
  use NodeCreationTrait;
  use CrawlerTrait;
  use UserCreationTrait;

  /**
   * @see core.services.yml
   */
  private const REQUIRED_CACHE_CONTEXTS = [
    'languages:language_interface',
    'theme',
    'user.permissions',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'system',
    'ckeditor5',
    'editor',
    'filter',
    'options',
    'text',
    'field',
    'image',
    'file',
    'user',
    'node',
    'datetime',
    'canvas_test_rendering',
    'canvas_test_sdc',
    'media',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['stark']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installConfig(['node', 'system', 'filter']);
    $this->installConfig(['canvas']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [
        'filter_html' => [
          'module' => 'filter',
          'status' => TRUE,
          'weight' => 10,
          'settings' => [
            'allowed_html' => '<p>',
          ],
        ],
      ],
    ])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  #[TestWith([
    TRUE,
    TRUE,
    [
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Cacheability of resolved props.
      'node:1',
      'config:filter.format.basic_html',
    ],
  ])]
  #[TestWith([
    FALSE,
    FALSE,
    [
      // Components in the component tree — minus the ones whose props failed to
      // resolve because they were inaccessible: DynamicPropSources populated by
      // the host entity.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // @see \Drupal\node\NodeAccessControlHandler::checkViewAccess()
      'node:1',
    ],
  ])]
  public function testOptContentTypeIntoCanvas(bool $node_is_published, bool $expected_entity_data_is_accessible, array $expected_node_component_tree_cache_tags): void {
    ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        // A static marker so we can easily tell if we're rendering with Canvas,
        // but simultaneously tests all currently supported dynamic ways of
        // populating props.
        [
          'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => 'a681ae184a8f6b7f',
          'inputs' => [
            // Tests static prop source end-to-end.
            // @see \Drupal\canvas\PropSource\StaticPropSource
            'heading' => 'Canvas is large and in charge!',
            // Tests adapted dynamic prop source end-to-end.
            // @see \Drupal\canvas\PropSource\DynamicPropSource::__construct(adapter)
            'subheading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝created␞␟value',
              'adapter' => 'unix_to_date',
            ],
            // Tests dynamic prop source end-to-end.
            // @see \Drupal\canvas\PropSource\DynamicPropSource
            'cta1' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            // Tests host entity URL prop source end-to-end.
            // @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
            'cta1href' => [
              'sourceType' => 'host-entity-url',
            ],
          ],
        ],
        // The node body, which needs to be using a dynamic prop source
        // because all content templates require at least one dynamic prop
        // source.
        [
          'uuid' => '6cf8297a-fc60-4019-be81-c336fd828c39',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝body␞␟processed',
            ],
          ],
        ],
      ],
    ])->save();
    $body = <<<HTML
<p>Hey this is allowed</p>
<script>alert('hi mum')</script>
HTML;

    $node = $this->createNode([
      'type' => 'article',
      'title' => 'This is a node whose structured data is rendered using a Canvas content template!',
      'created' => 1764872657,
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
      'status' => $node_is_published,
      'uid' => 1,
    ]);
    self::assertSame($node_is_published, $node->isPublished());
    self::assertFalse($node->isNew());
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    // The content type has not been opted into Canvas, so it should not be using
    // Canvas for rendering.
    self::assertCount(0, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount(0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount(0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    self::assertEqualsCanonicalizing([
      'config:filter.format.basic_html',
      'user:1',
      'user_view',
      // TRICKY: this cache tag is present because the config entity does exist,
      // but is disabled. It was assessed whether it should be used, hence its
      // cache tag is present.
      'config:canvas.content_template.node.article.full',
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'timezone',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      (string) $node->id(),
      'full',
      'without-canvas',
    ], $build['#cache']['keys']);

    // Confirm although we've opted in the status of the template is false so
    // will not be used.
    $template = ContentTemplate::load('node.article.full');
    \assert($template instanceof ContentTemplate);
    self::assertFalse($template->status());
    self::assertCount(0, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount(0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount(0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));

    // Updated the status of the template to true.
    $template->setStatus(TRUE)->save();

    // Reload the node now that the field definitions have changed.
    self::assertNotNull($node->id());
    $node = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged($node->id());
    \assert($node instanceof NodeInterface);
    // Set up a logger so we can tell if
    // canvas_test_rendering_entity_display_build_alter() gets invoked.
    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('canvas_test')
      ->addLogger($logger);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    $html = $crawler->html();

    self::assertTrue($template->status());
    self::assertStringContainsString('Canvas is large and in charge!', $html);
    self::assertCount(1, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    self::assertEqualsCanonicalizing([
      'config:canvas.content_template.node.article.full',
      ...$expected_node_component_tree_cache_tags,
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'url.site',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      (string) $node->id(),
      'full',
      'with-canvas',
    ], $build['#cache']['keys']);

    // Confirm that hook_entity_display_build_alter() was not invoked.
    // @see canvas_test_rendering_entity_display_build_alter()
    $this->assertFalse($logger->hasRecordThatContains("hook_entity_display_build_alter for node {$node->id()} in full view mode"));

    $output = $viewBuilder->view($node, 'teaser');
    $crawler = $this->crawlerForRenderArray($output);
    // Confirm that the template is NOT used when viewing the node as a teaser,
    // even though the content type is opted into Canvas.
    self::assertCount(0, $crawler->filter(\sprintf('a[href="%s/node/1"]:contains("Canvas is large and in charge!")', $GLOBALS['base_url'])));
    // TRICKY: note that entity access is NOT checked by the EntityViewBuilder,
    // that is up to the caller! The above is specifically testing Canvas
    // ContentTemplates' render arrays. Those are populated by field properties
    // on the host entity, which is why for ContentTemplates, this test can
    // expect access to be denied when needed.
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    $this->assertTrue($logger->hasRecordThatContains("hook_entity_display_build_alter for node {$node->id()} in teaser view mode"));
  }

  /**
   * @covers \Drupal\canvas\Entity\ContentTemplate::build
   * @covers \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
   */
  public function testExposedSlotsAreFilledByEntity(): void {
    $this->createComponentTreeField('node', 'article', 'field_component_tree');

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        // A simple SDC that will show the node's title, and has a slot
        // we can expose.
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'custom_content' => [
          'component_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    // Create an article that fills in the template's exposed slot.
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'The Real Deal',
      'field_component_tree' => [
        [
          'uuid' => '6ea0de84-858a-4f00-9ef5-de02525c8865',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => "Now we're cooking with gas!",
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'slot' => 'the_body',
          'parent_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
        ],
        // If the entity is targeting a slot that doesn't exist in the template,
        // or is not exposed, it shouldn't be an error.
        // @todo This should actually be purged when the entity is saved, so
        //   implement that in https://www.drupal.org/i/3520517.
        [
          'uuid' => '9a1ec750-e016-44fb-9bd2-9a7acb497bd7',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'slot' => 'ignore_me',
          'parent_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => "This won't show up.",
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('h1:contains("The Real Deal")'));
    self::assertCount(1, $crawler->filter('h1:contains("Now we\'re cooking with gas!")'));
    self::assertStringNotContainsString("This won't show up.", $crawler->text());
    self::assertEqualsCanonicalizing([
      'config:canvas.content_template.node.article.full',
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.props-slots',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Dynamic prop sources that pulled data from the entity should propagate the entity's
      // cache tags.
      'node:1',
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing(self::REQUIRED_CACHE_CONTEXTS, $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      '1',
      'full',
      'with-canvas',
    ], $build['#cache']['keys']);

    // Although the node targeting a nonexistent slot doesn't break rendering,
    // it DOES mean the entity isn't valid.
    $violations = $node->validate();
    self::assertCount(1, $violations);
    $violation = $violations->get(0);
    self::assertSame('field_component_tree.1.slot', $violation->getPropertyPath());
    self::assertSame('Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.props-slots</em>: <em class="placeholder">ignore_me</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.', (string) $violation->getMessage());

    // If we delete the field item, all good!
    $node->get('field_component_tree')->removeItem(1);
    self::assertCount(0, $node->validate());
  }

}
