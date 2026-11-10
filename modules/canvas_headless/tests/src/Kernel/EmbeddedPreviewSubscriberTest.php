<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_headless\EventSubscriber\EmbeddedPreviewSubscriber;
use Drupal\canvas_headless\Plugin\DisplayVariant\EmbeddedHeadlessPreviewPageVariant;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Render\MainContent\HtmlRenderer;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests selection of the headless preview page display variant.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class EmbeddedPreviewSubscriberTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'breakpoint',
    'field',
    'node',
    'serialization',
    'consumers',
    'simple_oauth',
    'toolbar',
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installConfig(['node', 'canvas_headless']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    $this->config('canvas_headless.settings')
      ->set('frontends', [[
        'url' => 'https://frontend.example/app',
        'components' => [],
      ],
      ])
      ->save();
    $this->setUpCurrentUser(permissions: [
      'access toolbar',
      PreviewUrlGeneratorInterface::PREVIEW_PERMISSION,
    ]);
  }

  /**
   * Tests replacing an entity-owned component tree with the iframe variant.
   */
  public function testEntityComponentTreeSelectsPreview(): void {
    $page = Page::create([
      'title' => 'Canvas page',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    $event = $this->selectVariant($page);
    self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $event->getPluginId());
    self::assertContains('user.permissions', $event->getCacheContexts());
    self::assertContains('user.roles:authenticated', $event->getCacheContexts());
    self::assertSame([
      'can_manage_frontends' => FALSE,
      'content_api_path' => '/canvas/content-api',
      'drupal_base_path' => '',
      'frontend_base_path' => '/app',
      'frontend_origin' => 'https://frontend.example',
      'preview_url' => 'https://frontend.example/app/page/' . $page->id(),
    ], $event->getPluginConfiguration());

    $plugin = $this->container
      ->get('plugin.manager.display_variant')
      ->createInstance($event->getPluginId(), $event->getPluginConfiguration());
    self::assertInstanceOf(EmbeddedHeadlessPreviewPageVariant::class, $plugin);
    $plugin->setMainContent(['#markup' => 'Original Canvas tree']);
    $plugin->setTitle('Canvas page');
    $build = $plugin->build();
    self::assertSame('canvas_page_variant', $build['#theme']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame(
      ['canvas_headless/headless.preview'],
      $build['#content']['canvas_headless_preview']['#attached']['library'],
    );
    $this->container->get('messenger')->addStatus('Canvas page saved.');
    $this->container->get('messenger')->addWarning('Review the published content.');
    $html = (string) $this->container
      ->get(RendererInterface::class)
      ->renderInIsolation($build['#content']);
    self::assertStringContainsString('class="canvas-headless-preview"', $html);
    self::assertStringContainsString('src="about:blank"', $html);
    self::assertStringContainsString('messages messages--error', $html);
    self::assertStringContainsString('class="messages__title"', $html);
    self::assertStringContainsString('class="messages__content"', $html);
    self::assertStringContainsString('The headless frontend is not responding', $html);
    self::assertStringNotContainsString('Manage headless frontends', $html);
    self::assertStringNotContainsString('Original Canvas tree', $html);
    self::assertStringContainsString('Canvas page saved.', $html);
    self::assertStringContainsString('Review the published content.', $html);
    self::assertLessThan(strpos($html, '<iframe'), strpos($html, 'Canvas page saved.'));
    self::assertLessThan(strpos($html, '<iframe'), strpos($html, 'Review the published content.'));
    self::assertStringContainsString('data-drupal-messages-fallback', $html);

    $html = ['page' => $build];
    $html_renderer = $this->container->get('main_content_renderer.html');
    self::assertInstanceOf(HtmlRenderer::class, $html_renderer);
    $html_renderer->buildPageTopAndBottom($html);
    self::assertArrayHasKey('toolbar', $html['page_top']);
  }

  /**
   * Tests that frontend administrators receive a configuration link.
   */
  public function testUnavailableMessageLinksToFrontendConfiguration(): void {
    $this->setUpCurrentUser(permissions: [
      PreviewUrlGeneratorInterface::PREVIEW_PERMISSION,
      'administer canvas headless frontends',
    ]);
    $page = Page::create([
      'title' => 'Canvas page',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    $event = $this->selectVariant($page);
    self::assertTrue($event->getPluginConfiguration()['can_manage_frontends']);
    $plugin = $this->container
      ->get('plugin.manager.display_variant')
      ->createInstance($event->getPluginId(), $event->getPluginConfiguration());
    self::assertInstanceOf(EmbeddedHeadlessPreviewPageVariant::class, $plugin);
    $html = (string) $this->container
      ->get(RendererInterface::class)
      ->renderInIsolation($plugin->build()['#content']);

    self::assertStringContainsString('Manage headless frontends', $html);
    self::assertStringContainsString('href="/canvas/headless/"', $html);
    self::assertStringContainsString('button button--primary', $html);
  }

  /**
   * Tests that an enabled full content template opts a node route in.
   */
  public function testEnabledContentTemplateSelectsPreview(): void {
    $node = self::createPublishedNode();
    self::assertSame('simple_page', $this->selectVariant($node)->getPluginId());

    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => FALSE,
    ]);
    $template->save();
    self::assertSame('simple_page', $this->selectVariant($node)->getPluginId());

    $template->setStatus(TRUE)->save();
    $event = $this->selectVariant($node, dispatch: TRUE);
    self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $event->getPluginId());
    foreach (['entity.node.preview', 'entity.node.revision'] as $route_name) {
      $node->setUnpublished();
      $event = $this->selectVariant($node, $route_name);
      self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $event->getPluginId());
      self::assertSame(0, $event->getCacheMaxAge());
      $plugin = $this->container->get('plugin.manager.display_variant')->createInstance($event->getPluginId(), $event->getPluginConfiguration());
      self::assertInstanceOf(EmbeddedHeadlessPreviewPageVariant::class, $plugin);
      // Core attaches the controls through hook_page_top() before Drupal 11.4,
      // and directly to the main content in Drupal 11.4 and later.
      foreach ([
        'page_top controls' => ['page_top' => ['node_preview' => ['#markup' => 'Preview controls']]],
        'preview library only' => ['library' => ['node/drupal.node.preview']],
        'both attachments' => [
          'page_top' => ['node_preview' => ['#markup' => 'Preview controls']],
          'library' => ['node/drupal.node.preview'],
        ],
      ] as $case => $attachments) {
        $plugin->setMainContent(['#attached' => $attachments]);
        $build = $plugin->build();
        self::assertSame(['node/drupal.node.preview'], $build['#attached']['library'] ?? [], $case);
      }
      // Other main-content attachments do not enable node preview behaviors.
      $plugin->setMainContent(['#attached' => ['library' => ['core/drupal']]]);
      self::assertSame([], $plugin->build()['#attached']['library'] ?? []);
    }
    // A view mode without a Canvas template retains core's preview.
    self::assertSame('simple_page', $this->selectVariant($node, 'entity.node.preview', view_mode: 'teaser')->getPluginId());
    $new_node = Node::create(['type' => 'article', 'title' => 'Unsaved', 'status' => FALSE]);
    self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $this->selectVariant($new_node, 'entity.node.preview')->getPluginId());
    $template->disable()->save();
    self::assertSame('simple_page', $this->selectVariant($node, 'entity.node.preview')->getPluginId());

    $this->enableModules(['taxonomy', 'canvas_headless_test']);
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['taxonomy']);
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    ContentTemplate::create([
      'id' => 'taxonomy_term.topics.full',
      'content_entity_type_id' => 'taxonomy_term',
      'content_entity_type_bundle' => 'topics',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ])->save();
    $term = Term::create(['vid' => 'topics', 'name' => 'Unpublished term', 'status' => FALSE]);
    // An enabled template also needs a template-aware view builder.
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $entity_type_manager->clearCachedDefinitions();
    $entity_type_manager->getDefinition('taxonomy_term')->setViewBuilderClass(EntityViewBuilder::class);
    self::assertSame('simple_page', $this->selectVariant($term, 'entity.taxonomy_term.preview')->getPluginId());
    $entity_type_manager->clearCachedDefinitions();
    foreach (['preview', 'revision', 'latest_version'] as $operation) {
      $event = $this->selectVariant($term, 'entity.taxonomy_term.' . $operation);
      self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $event->getPluginId());
      self::assertSame(0, $event->getCacheMaxAge());
    }
    self::assertSame('simple_page', $this->selectVariant($term, 'entity.taxonomy_term.revision_delete_form')->getPluginId());
  }

  /**
   * Tests that page variants require Canvas content and headless compatibility.
   */
  public function testPageVariantRequiresCanvasContent(): void {
    $node = self::createPublishedNode();
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);
    $variant = PageVariant::create([
      'id' => 'site_default',
      'label' => 'Site default',
      'component_tree' => [[
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'component_version' => $marker->getActiveVersion(),
        'inputs' => [],
      ],
      ],
    ]);
    $variant->save();
    $this->config('canvas.settings')
      ->set('default_page_variant', 'site_default')
      ->save();

    $event = $this->selectVariant($node);
    self::assertSame('simple_page', $event->getPluginId());
    self::assertContains('config:' . $variant->getConfigDependencyName(), $event->getCacheTags());

    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => FALSE,
    ]);
    $template->save();
    self::assertSame('simple_page', $this->selectVariant($node)->getPluginId());
    $template->enable()->save();
    self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $this->selectVariant($node)->getPluginId());

    $page = Page::create(['title' => 'Canvas page', 'status' => TRUE, 'components' => []]);
    $page->save();
    self::assertSame(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID, $this->selectVariant($page)->getPluginId());

    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_page_template_component']);
    $theme_template = Component::load('theme_page_template.stark');
    self::assertInstanceOf(Component::class, $theme_template);
    $template_uuid = $this->container->get('uuid')->generate();
    $variant->setComponentTree([
      [
        'uuid' => $template_uuid,
        'component_id' => $theme_template->id(),
        'component_version' => $theme_template->getActiveVersion(),
        'inputs' => [],
      ],
      [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $marker->id(),
        'component_version' => $marker->getActiveVersion(),
        'parent_uuid' => $template_uuid,
        'slot' => 'content',
        'inputs' => [],
      ],
    ]);
    $variant->save();
    foreach ([$node, $page] as $entity) {
      $event = $this->selectVariant($entity);
      self::assertSame('simple_page', $event->getPluginId());
      self::assertContains('config:' . $variant->getConfigDependencyName(), $event->getCacheTags());
      self::assertFalse($event->isPropagationStopped());
    }
  }

  /**
   * Tests that ineligible requests retain the previous page variant.
   */
  public function testIneligibleRequestsAreNotReplaced(): void {
    $page = Page::create([
      'title' => 'Unpublished Canvas page',
      'status' => FALSE,
      'components' => [],
    ]);
    $page->save();
    self::assertSame('simple_page', $this->selectVariant($page)->getPluginId());

    $page->setPublished()->save();
    $subrequest = $this->selectVariant($page, is_main_request: FALSE);
    self::assertSame('simple_page', $subrequest->getPluginId());
    self::assertSame([], $subrequest->getPluginConfiguration());
    self::assertFalse($subrequest->isPropagationStopped());

    self::assertSame(
      'simple_page',
      $this->selectVariant($page, 'entity.canvas_page.edit_form')->getPluginId(),
    );
    self::assertSame(
      'simple_page',
      $this->selectVariant($page, is_content_api_request: TRUE)->getPluginId(),
    );

    $node = self::createPublishedNode();
    ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ])->save();

    $authenticated_account = $this->setUpCurrentUser();
    foreach ([$authenticated_account, new AnonymousUserSession()] as $account) {
      $this->container->get(AccountProxyInterface::class)->setAccount($account);
      foreach ([$page, $node] as $entity) {
        $event = $this->selectVariant($entity);
        self::assertSame('simple_page', $event->getPluginId());
        self::assertContains('user.permissions', $event->getCacheContexts());
        self::assertContains('user.roles:authenticated', $event->getCacheContexts());

        foreach (['preview', 'revision', 'latest_version'] as $operation) {
          self::assertSame('simple_page', $this->selectVariant($entity, 'entity.' . $entity->getEntityTypeId() . '.' . $operation)->getPluginId());
        }

        $entity->setUnpublished();
        self::assertSame('simple_page', $this->selectVariant($entity)->getPluginId());
        $entity->setPublished();
      }
    }

    // Preview credentials require an authenticated user even if the anonymous
    // role has been granted the preview permission.
    $anonymous_role = Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous user']);
    self::assertInstanceOf(Role::class, $anonymous_role);
    $anonymous_role->grantPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)->save();
    $anonymous_account = new AnonymousUserSession();
    self::assertTrue($anonymous_account->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION));
    $this->container->get(AccountProxyInterface::class)->setAccount($anonymous_account);
    foreach (['canonical', 'preview', 'revision', 'latest_version'] as $operation) {
      self::assertSame('simple_page', $this->selectVariant($node, 'entity.node.' . $operation)->getPluginId());
    }
  }

  /**
   * Creates a published article node.
   */
  private static function createPublishedNode(): Node {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Article',
      'status' => TRUE,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Runs the page display variant subscriber for an entity request.
   */
  private function selectVariant(
    ContentEntityInterface $entity,
    ?string $route_name = NULL,
    bool $is_content_api_request = FALSE,
    bool $dispatch = FALSE,
    string $view_mode = 'full',
    bool $is_main_request = TRUE,
  ): PageDisplayVariantSelectionEvent {
    $entity_type_id = $entity->getEntityTypeId();
    $parameter_name = $entity_type_id === Page::ENTITY_TYPE_ID ? 'canvas_page' : $entity_type_id;
    $route_name ??= 'entity.' . $entity_type_id . '.canonical';
    $parameter_name = match (TRUE) {
      str_ends_with($route_name, '.preview') => $entity_type_id . '_preview',
      str_ends_with($route_name, '.revision') => $entity_type_id . '_revision',
      default => $parameter_name,
    };
    $route = new Route('/' . $parameter_name . '/{' . $parameter_name . '}' . (str_ends_with($route_name, '.preview') ? '/{view_mode_id}' : ''));
    $route_match = new RouteMatch(
      $route_name,
      $route,
      [$parameter_name => $entity, 'view_mode_id' => $view_mode],
      [$parameter_name => (string) $entity->id()],
    );
    $path = $entity_type_id === Page::ENTITY_TYPE_ID
      ? '/page/' . $entity->id()
      : '/node/' . $entity->id();
    $path = match ($route_name) {
      'entity.node.preview' => '/node/preview/' . $entity->uuid() . '/' . $view_mode,
      'entity.node.revision' => '/node/' . $entity->id() . '/revisions/' . $entity->getRevisionId() . '/view',
      default => $path,
    };
    $request = Request::create('https://drupal.example' . $path);
    if ($is_content_api_request) {
      $request->attributes->set(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE, $path);
    }
    $request_stack = $this->container->get(RequestStack::class);
    // Replace the kernel test's bootstrap request to simulate a main request.
    $previous_request = $is_main_request ? $request_stack->pop() : NULL;
    $request_stack->push($request);

    try {
      $event = new PageDisplayVariantSelectionEvent('simple_page', $route_match);
      if ($dispatch) {
        $this->container->get(EventDispatcherInterface::class)->dispatch(
          $event,
          RenderEvents::SELECT_PAGE_DISPLAY_VARIANT,
        );
      }
      else {
        $subscriber = $this->container->get(EmbeddedPreviewSubscriber::class);
        self::assertInstanceOf(EmbeddedPreviewSubscriber::class, $subscriber);
        $subscriber->onSelectPageDisplayVariant($event);
      }
      return $event;
    }
    finally {
      self::assertSame($request, $request_stack->pop());
      if ($previous_request !== NULL) {
        $request_stack->push($previous_request);
      }
    }
  }

}
