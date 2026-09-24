<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

// cspell:ignore unroutable francaise Btag Anglais

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\canvas_headless\PreviewLanguageRedirectResponse;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\PermissionCheckerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests routed Canvas content and scoped auto-save previews.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class CanvasContentControllerTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string COMPONENT_ID = 'js.canvas_headless_test';

  private const string COMPONENT_UUID = '2c6e91ae-23ac-433d-9bb8-687144464b34';

  private const string LOCAL_COMPONENT_ID = 'js.canvas_headless_local_test';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'field',
    'node',
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
  ];

  private UserInterface $editor;

  private Consumer $consumer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['language', 'simple_oauth', 'canvas_headless']);
    $this->installConfig(['node']);
    $this->installSchema('node', ['node_access']);
    $dir = $this->siteDirectory . '/keys';
    mkdir($dir, 0777, TRUE);
    $resource = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($resource);
    openssl_pkey_export($resource, $private_key);
    $details = openssl_pkey_get_details($resource);
    self::assertNotFalse($details);
    file_put_contents($dir . '/private.key', $private_key);
    file_put_contents($dir . '/public.key', $details['key']);
    $this->config('simple_oauth.settings')
      ->set('private_key', $dir . '/private.key')
      ->set('public_key', $dir . '/public.key')
      ->save();
    $this->config('system.site')
      ->set('uuid', 'c7f2e9a4-3b1d-4e8f-9a6c-5d0b2f8e1a37')
      ->save();
    $component = JavaScriptComponent::create([
      'machineName' => 'canvas_headless_test',
      'name' => 'Canvas Headless test',
      'status' => TRUE,
      'type' => 'external',
      'props' => [
        'heading' => [
          'type' => 'string',
          'title' => 'Heading',
          'examples' => ['Example heading'],
        ],
      ],
      'required' => [],
      'slots' => [],
      'dataDependencies' => [],
    ]);
    self::assertEntityIsValid($component);
    $component->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->consumer = Consumer::create([
      'client_id' => PreviewAssertionFactory::CLIENT_ID,
      'label' => 'Canvas Headless preview',
      'confidential' => FALSE,
      'is_default' => FALSE,
      'third_party' => FALSE,
      'grant_types' => ['canvas_headless_preview_assertion'],
      'access_token_expiration' => 900,
    ]);
    $this->consumer->save();

    // Burn uid 1, which bypasses access checks.
    $this->createUser();
    $editor = $this->createUser([
      'access content',
      PageVariant::ADMIN_PERMISSION,
    ]);
    \assert($editor instanceof UserInterface);
    $this->editor = $editor;
  }

  /**
   * Tests that only a Canvas preview token selects the auto-save.
   */
  public function testAutoSaveRequiresPreviewToken(): void {
    $page = $this->createPage();
    $this->saveAutoSave($page, title: 'Auto-saved title');

    foreach ([
      $this->editor,
      $this->createTokenAccount(with_preview_scope: FALSE),
    ] as $account) {
      $this->setCurrentAccount($account);
      $result = $this->renderPage($page);
      self::assertSame('Stored title', self::responseData($result)['head']['title']);
      self::assertNotContains(AutoSaveManager::CACHE_TAG, $result->getCacheableMetadata()->getCacheTags());
      self::assertContains('oauth2_scopes', $result->getCacheableMetadata()->getCacheContexts());
    }

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $result = $this->renderPage($page);
    self::assertSame('Auto-saved title', self::responseData($result)['head']['title']);
    self::assertContains(AutoSaveManager::CACHE_TAG, $result->getCacheableMetadata()->getCacheTags());
    self::assertContains('oauth2_scopes', $result->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Tests the Canvas-owned endpoint response without Lupus services.
   */
  public function testCanvasContentResponse(): void {
    $page = $this->createPage();
    $page->setComponentTree([
      ...$page->getComponentTree()->getValue(),
      [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => self::COMPONENT_ID,
        'inputs' => ['heading' => 'Second component heading'],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $this->setCurrentAccount($this->editor);
    $response = $this->renderPage($page);
    $data = self::responseData($response);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);

    self::assertIsArray($data);
    self::assertSame(200, $response->getStatusCode());
    self::assertIsArray($data['content']);
    self::assertSame('renderless-container', $data['content']['element']);
    self::assertCount(2, $data['content']['slots']['default']);
    $first_component = strpos($content, 'Stored component heading');
    $second_component = strpos($content, 'Second component heading');
    self::assertNotFalse($first_component);
    self::assertNotFalse($second_component);
    self::assertTrue($first_component < $second_component);
    self::assertSame(0, \substr_count($content, '"element":"drupal-markup"'));
    self::assertSame(2, \substr_count($content, '"element":"js-canvas-headless-test"'));
    self::assertSame(['title' => 'Stored title'], $data['head']);
    self::assertSame([
      'name' => 'entity.canvas_page.canonical',
      'requestUri' => '/page/' . $page->id(),
      'params' => ['canvas_page' => (string) $page->id()],
      'managedByCanvas' => TRUE,
      'entity' => [
        'entityType' => 'canvas_page',
        'bundle' => 'canvas_page',
        'id' => (string) $page->id(),
        'uuid' => $page->uuid(),
        'langcode' => 'en',
      ],
      'negotiatedLanguage' => 'en',
      'translations' => [],
    ], $data['route']);
    self::assertContains('canvas_page:' . $page->id(), $response->getCacheableMetadata()->getCacheTags());
    self::assertContains('canvas_page_view', $response->getCacheableMetadata()->getCacheTags());
    self::assertContains('url', $response->getCacheableMetadata()->getCacheContexts());

    $page->setComponentTree([])->save();
    $empty_response = $this->renderPage($page);
    $empty_data = self::responseData($empty_response);
    self::assertSame(200, $empty_response->getStatusCode());
    self::assertNull($empty_data['content']);
    self::assertTrue($empty_data['route']['managedByCanvas']);
  }

  /**
   * Tests that the selected page variant wraps routed Canvas content.
   */
  public function testCanvasPageVariantResponse(): void {
    $this->enableModules(['canvas_headless_test']);
    $this->container->get(RouteBuilderInterface::class)->rebuild();
    $variant = $this->createPageVariant(
      'headless',
      'Before page content',
      'After page content',
    );
    $this->config('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, $variant->id())
      ->save();

    $page = $this->createPage();
    $this->setCurrentAccount($this->editor);
    $response = $this->renderPage($page);
    $data = self::responseData($response);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);

    self::assertTrue($data['route']['managedByCanvas']);
    self::assertSame(3, \substr_count($content, '"element":"js-canvas-headless-test"'));
    $before = \strpos($content, 'Before page content');
    $page_content = \strpos($content, 'Stored component heading');
    $after = \strpos($content, 'After page content');
    self::assertNotFalse($before);
    self::assertNotFalse($page_content);
    self::assertNotFalse($after);
    self::assertTrue($before < $page_content);
    self::assertTrue($page_content < $after);
    self::assertStringNotContainsString('canvas-preview-content-region', $content);
    self::assertContains(
      'config:canvas.page_variant.headless',
      $response->getCacheableMetadata()->getCacheTags(),
    );
    self::assertContains(
      'config:canvas.settings',
      $response->getCacheableMetadata()->getCacheTags(),
    );

    // The editor preview composes both a pending page variant selection and
    // that newly selected variant's own auto-saved component tree.
    $selected = $this->createPageVariant(
      'selected_in_preview',
      'Published selected chrome',
      'After selected page content',
    );
    $selected_id = $selected->id();
    \assert(\is_string($selected_id));
    $draft = clone $selected;
    $draft_tree = $draft->getComponentTree()->getValue();
    $draft_tree[0]['inputs']['heading'] = 'Draft page chrome';
    $draft->setComponentTree($draft_tree);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $page_draft = clone $page;
    $page_draft->set('page_variant', $selected_id);
    $this->container->get(AutoSaveManager::class)->saveEntity($page_draft);

    // The page variant selector is private preview context. A normal request
    // cannot use it to render a config entity draft.
    $public_variant_preview = $this->renderContentPath(
      '/page/' . $page->id(),
      [CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY => $selected_id],
    );
    $public_variant_preview_content = \json_encode(
      self::responseData($public_variant_preview)['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringContainsString('Before page content', $public_variant_preview_content);
    self::assertStringNotContainsString('Draft page chrome', $public_variant_preview_content);

    /** @var \Drupal\user\UserInterface $restricted_user */
    $restricted_user = $this->createUser(['access content']);
    $this->setCurrentAccount($this->createTokenAccount(
      with_preview_scope: TRUE,
      user: $restricted_user,
    ));
    try {
      $this->renderContentPath(
        '/page/' . $page->id(),
        [CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY => $selected_id],
      );
      self::fail('A preview token must not expose page variants its user cannot view.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertContains('oauth2_scopes', $exception->getCacheContexts());
      self::assertContains('user.permissions', $exception->getCacheContexts());
    }

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    // Editing a page variant previews that variant's own auto-saved tree. It
    // does not require a canonical URL or inject the routed entity's content.
    $variant_preview = $this->renderContentPath(
      '/page/' . $page->id(),
      [CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY => $selected_id],
    );
    $variant_preview_data = self::responseData($variant_preview);
    $variant_preview_content = \json_encode(
      $variant_preview_data['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertTrue($variant_preview_data['route']['managedByCanvas']);
    self::assertStringContainsString('Draft page chrome', $variant_preview_content);
    self::assertStringContainsString('canvas--page-content-marker-placeholder', $variant_preview_content);
    self::assertStringNotContainsString('Stored component heading', $variant_preview_content);
    self::assertStringNotContainsString('Published selected chrome', $variant_preview_content);

    // A page variant has no canonical URL, so its frontend entry URI is the
    // site root. Detached preview routing must not inherit the front page's
    // format requirement.
    $this->config('system.site')
      ->set('page.front', '/canvas-headless-test/html-only')
      ->save();
    $front_page_preview = $this->renderContentPath(
      '/',
      [CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY => $selected_id],
    );
    $front_page_preview_data = self::responseData($front_page_preview);
    self::assertSame(200, $front_page_preview->getStatusCode());
    self::assertSame('canvas_headless.content', $front_page_preview_data['route']['name']);
    self::assertSame('/', $front_page_preview_data['route']['requestUri']);
    self::assertTrue($front_page_preview_data['route']['managedByCanvas']);
    self::assertStringContainsString(
      'Draft page chrome',
      \json_encode($front_page_preview_data['content'], JSON_THROW_ON_ERROR),
    );

    $empty_page = Page::create([
      'title' => 'Empty page',
      'owner' => $this->editor->id(),
      'status' => TRUE,
      'components' => [],
    ]);
    self::assertEntityIsValid($empty_page);
    $empty_page->save();
    $empty_preview = $this->renderPage($empty_page);
    $empty_preview_content = \json_encode(
      self::responseData($empty_preview)['content'],
      JSON_THROW_ON_ERROR,
    );
    $before = \strpos($empty_preview_content, 'Before page content');
    $content_region = \strpos($empty_preview_content, 'canvas-preview-content-region');
    $after = \strpos($empty_preview_content, 'After page content');
    self::assertNotFalse($before);
    self::assertNotFalse($content_region);
    self::assertNotFalse($after);
    self::assertTrue($before < $content_region);
    self::assertTrue($content_region < $after);

    $preview = $this->renderPage($page);
    $preview_content = \json_encode(self::responseData($preview)['content'], JSON_THROW_ON_ERROR);
    self::assertStringContainsString('Draft page chrome', $preview_content);
    self::assertStringNotContainsString('Before page content', $preview_content);
    self::assertStringNotContainsString('Published selected chrome', $preview_content);
    self::assertStringContainsString('canvas-preview-content-region', $preview_content);
    self::assertContains(
      AutoSaveManager::CACHE_TAG,
      $preview->getCacheableMetadata()->getCacheTags(),
    );

    // A content template's selection overrides the site default, including
    // an auto-saved template selection during a draft preview.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Content template page variant',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    $node->save();
    $this->setCurrentAccount($this->editor);

    $response = $this->renderContentPath('/node/' . $node->id());
    $data = self::responseData($response);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);
    self::assertTrue($data['route']['managedByCanvas']);
    self::assertStringContainsString('Before page content', $content);
    self::assertStringContainsString('"element":"drupal-markup"', $content);

    $template_variant = $this->createPageVariant(
      'content_template',
      'Published template chrome',
      'After published template content',
    );
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => 'Published template content'],
        ],
      ],
      'page_variant' => $template_variant->id(),
      'status' => TRUE,
    ]);
    self::assertEntityIsValid($template);
    $template->save();

    $response = $this->renderContentPath('/node/' . $node->id());
    $content = \json_encode(
      self::responseData($response)['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringContainsString('Published template chrome', $content);
    self::assertStringContainsString('Published template content', $content);
    self::assertStringNotContainsString('Before page content', $content);

    $template_draft = clone $template;
    $template_draft->set('page_variant', $selected_id);
    $template_draft_tree = $template_draft->getComponentTree()->getValue();
    $template_draft_tree[0]['inputs']['heading'] = 'Draft template content';
    $template_draft->setComponentTree($template_draft_tree);
    $this->container->get(AutoSaveManager::class)->saveEntity($template_draft);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $preview = $this->renderContentPath('/node/' . $node->id());
    $preview_content = \json_encode(
      self::responseData($preview)['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringContainsString('Draft page chrome', $preview_content);
    self::assertStringContainsString('Draft template content', $preview_content);
    self::assertStringNotContainsString('Published template chrome', $preview_content);
    self::assertStringNotContainsString('Published template content', $preview_content);
  }

  /**
   * Tests that headless ignores a theme-backed page variant.
   */
  public function testThemePageVariantResponse(): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get(ModuleInstallerInterface::class)
      ->install(['canvas_page_template_component']);

    $theme_template = Component::load('theme_page_template.stark');
    self::assertInstanceOf(Component::class, $theme_template);
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);

    $template_uuid = $this->container->get('uuid')->generate();
    $variant = PageVariant::create([
      'id' => 'theme_stark',
      'label' => 'Stark theme',
      'component_tree' => [
        [
          'uuid' => $template_uuid,
          'component_id' => $theme_template->id(),
          'component_version' => $theme_template->getActiveVersion(),
          'inputs' => [],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'parent_uuid' => $template_uuid,
          'slot' => 'header',
          'inputs' => ['heading' => 'Theme header'],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $marker->id(),
          'component_version' => $marker->getActiveVersion(),
          'parent_uuid' => $template_uuid,
          'slot' => 'content',
          'inputs' => [],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'parent_uuid' => $template_uuid,
          'slot' => 'footer',
          'inputs' => ['heading' => 'Theme footer'],
        ],
      ],
    ]);
    self::assertEntityIsValid($variant);
    $variant->save();
    $this->config('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, $variant->id())
      ->save();

    $this->setCurrentAccount($this->editor);
    $response = $this->renderPage($this->createPage());
    $data = self::responseData($response);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);
    self::assertTrue($data['route']['managedByCanvas']);
    self::assertSame('js-canvas-headless-test', $data['content']['element']);
    self::assertStringContainsString('Stored component heading', $content);
    self::assertStringNotContainsString('Theme header', $content);
    self::assertStringNotContainsString('Theme footer', $content);
    self::assertContains(
      'config:canvas.page_variant.theme_stark',
      $response->getCacheableMetadata()->getCacheTags(),
    );
    self::assertContains(
      'config:canvas.settings',
      $response->getCacheableMetadata()->getCacheTags(),
    );

    $node = Node::create([
      'type' => 'article',
      'title' => 'Drupal-owned main content',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    $node->save();
    $response = $this->renderContentPath('/node/' . $node->id());
    $data = self::responseData($response);
    self::assertNull($data['content']);
    self::assertFalse($data['route']['managedByCanvas']);
    self::assertContains(
      'config:canvas.page_variant.theme_stark',
      $response->getCacheableMetadata()->getCacheTags(),
    );

    // Compatibility is based on the valid auto-saved variant during preview,
    // rather than on the published variant that contains the theme template.
    $draft_source = $this->createPageVariant(
      'headless_draft_source',
      'Draft headless chrome',
      'After draft headless content',
    );
    $draft = clone $variant;
    $draft->setComponentTree($draft_source->getComponentTree()->getValue());
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $preview = $this->renderPage($this->createPage());
    $preview_data = self::responseData($preview);
    $preview_content = \json_encode(
      $preview_data['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertTrue($preview_data['route']['managedByCanvas']);
    self::assertStringContainsString('Draft headless chrome', $preview_content);
    self::assertStringContainsString('Stored component heading', $preview_content);
    self::assertStringNotContainsString('Theme header', $preview_content);
  }

  /**
   * Tests rendering one external component with its resolved defaults.
   */
  public function testExternalComponentPreview(): void {
    $page = $this->createPage();
    $page_uri = '/page/' . $page->id() . '?' . http_build_query([
      CanvasContentApiRequest::COMPONENT_PREVIEW_QUERY => 'route-owned-value',
    ]);
    $component_preview_context = [
      CanvasContentApiRequest::COMPONENT_PREVIEW_QUERY => self::COMPONENT_ID,
      // Component previews attached to a page variant carry that editor's
      // context too. The requested component must still win.
      CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY => 'must_not_win',
    ];

    // The API selector is inert outside an authenticated headless preview.
    $this->setCurrentAccount($this->editor);
    $stored_content = \json_encode(
      self::responseData($this->renderContentPath($page_uri, $component_preview_context))['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringContainsString('Stored component heading', $stored_content);

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    // A route-owned componentId query parameter must not select a preview.
    $route_content = \json_encode(
      self::responseData($this->renderContentPath($page_uri))['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringContainsString('Stored component heading', $route_content);

    $response = $this->renderContentPath($page_uri, $component_preview_context);
    $data = self::responseData($response);
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);

    self::assertSame('js-canvas-headless-test', $data['content']['element']);
    self::assertSame('Example heading', $data['content']['props']['heading']);
    self::assertSame($component->uuid(), $data['content']['props']['canvasUuid']);
    self::assertTrue($data['route']['managedByCanvas']);
    self::assertSame($page_uri, $data['route']['requestUri']);
    self::assertContains(
      'config:canvas.component.' . self::COMPONENT_ID,
      $response->getCacheableMetadata()->getCacheTags(),
    );
    self::assertContains(
      'url.query_args:' . CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
      $response->getCacheableMetadata()->getCacheContexts(),
    );
  }

  /**
   * Tests SDC, block, and local JavaScript component rendering.
   */
  public function testCanvasContentResponseForOtherComponentTypes(): void {
    $this->config('system.site')
      ->set('name', 'Canvas Headless block test')
      ->set('slogan', 'Rendered by a block component')
      ->save();
    $this->generateComponentConfig();
    $local_component = JavaScriptComponent::create([
      'machineName' => 'canvas_headless_local_test',
      'name' => 'Canvas Headless local test',
      'status' => TRUE,
      'props' => [
        'heading' => [
          'type' => 'string',
          'title' => 'Heading',
          'examples' => ['Example heading'],
        ],
      ],
      'required' => [],
      'slots' => [],
      'js' => [
        'original' => 'console.log("Canvas Headless local test component")',
        'compiled' => 'console.log("Canvas Headless local test component")',
      ],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    self::assertEntityIsValid($local_component);
    $local_component->save();

    $page = Page::create([
      'title' => 'Other component types',
      'owner' => $this->editor->id(),
      'status' => TRUE,
      'components' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => ['heading' => 'Rendered by an SDC'],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => 'block.system_branding_block',
          'inputs' => [
            'use_site_logo' => FALSE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
            'label_display' => '0',
            'label' => '',
          ],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => self::LOCAL_COMPONENT_ID,
          'inputs' => ['heading' => 'Rendered by a local JavaScript component'],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $this->setCurrentAccount($this->editor);

    $response = $this->renderPage($page);
    $content = \json_encode(self::responseData($response)['content'], JSON_THROW_ON_ERROR);

    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('Rendered by an SDC', $content);
    self::assertStringContainsString('Canvas Headless block test', $content);
    self::assertStringContainsString('Rendered by a block component', $content);
    self::assertStringContainsString('Rendered by a local JavaScript component', $content);
    self::assertSame(2, \substr_count($content, '"element":"drupal-markup"'));
    self::assertSame(1, \substr_count($content, '"element":"js-canvas-headless-local-test"'));
  }

  /**
   * Tests routed content rendered by an enabled content template.
   */
  public function testCanvasContentTemplateResponse(): void {
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Template-backed content',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    $node->save();
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => 'Published template heading'],
        ],
      ],
      'status' => TRUE,
    ]);
    $template->save();
    ContentTemplate::create([
      'id' => 'node.article.teaser',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => 'Teaser template heading'],
        ],
      ],
      'status' => TRUE,
    ])->save();
    $this->setCurrentAccount($this->editor);

    $response = $this->renderContentPath('/node/' . $node->id());
    $data = self::responseData($response);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('js-canvas-headless-test', $data['content']['element']);
    self::assertTrue($data['route']['managedByCanvas']);
    self::assertStringContainsString('Published template heading', $content);
    self::assertContains(
      'config:canvas.content_template.node.article.full',
      $response->getCacheableMetadata()->getCacheTags(),
    );

    $template->disable()->save();
    $without_canvas_content = $this->renderContentPath('/node/' . $node->id());
    $without_canvas_content_data = self::responseData($without_canvas_content);
    self::assertSame(200, $without_canvas_content->getStatusCode());
    self::assertNull($without_canvas_content_data['content']);
    self::assertFalse($without_canvas_content_data['route']['managedByCanvas']);
    self::assertSame('Template-backed content', $without_canvas_content_data['head']['title']);
    self::assertSame(
      '/node/' . $node->id(),
      $without_canvas_content_data['route']['requestUri'],
    );
    self::assertContains(
      'config:canvas.content_template.node.article.full',
      $without_canvas_content->getCacheableMetadata()->getCacheTags(),
    );

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $page_variant = $this->createPageVariant(
      'teaser_default',
      'Page chrome must not wrap teasers',
      'After page content',
    );
    $this->config('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, $page_variant->id())
      ->save();
    $teaser_preview = $this->renderContentPath(
      '/node/' . $node->id(),
      ['viewMode' => 'teaser'],
    );
    $teaser_preview_content = \json_encode(
      self::responseData($teaser_preview)['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringContainsString('Teaser template heading', $teaser_preview_content);
    self::assertStringNotContainsString('Page chrome must not wrap teasers', $teaser_preview_content);
    self::assertContains(
      'url.query_args:' . CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
      $teaser_preview->getCacheableMetadata()->getCacheContexts(),
    );
    $this->config('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, NULL)
      ->save();
    $stored_preview = $this->renderContentPath('/node/' . $node->id());
    $stored_preview_content = \json_encode(
      self::responseData($stored_preview)['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertSame(200, $stored_preview->getStatusCode());
    self::assertStringContainsString('Published template heading', $stored_preview_content);
    self::assertContains(
      AutoSaveManager::CACHE_TAG,
      $stored_preview->getCacheableMetadata()->getCacheTags(),
    );

    $draft = clone $template;
    $draft_tree = $draft->getComponentTree()->getValue();
    $draft_tree[0]['inputs']['heading'] = 'Draft template heading';
    $draft->setComponentTree($draft_tree);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);

    $preview = $this->renderContentPath('/node/' . $node->id());
    $preview_content = \json_encode(
      self::responseData($preview)['content'],
      JSON_THROW_ON_ERROR,
    );
    self::assertSame(200, $preview->getStatusCode());
    self::assertStringContainsString('Draft template heading', $preview_content);
    self::assertStringNotContainsString('Published template heading', $preview_content);
    self::assertContains(
      AutoSaveManager::CACHE_TAG,
      $preview->getCacheableMetadata()->getCacheTags(),
    );

    $draft->setComponentTree([]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $empty_preview = $this->renderContentPath('/node/' . $node->id());
    $empty_preview_data = self::responseData($empty_preview);
    self::assertSame(200, $empty_preview->getStatusCode());
    self::assertNull($empty_preview_data['content']);
    self::assertTrue($empty_preview_data['route']['managedByCanvas']);
  }

  /**
   * Tests inbound aliases while retaining the requested frontend URI.
   */
  public function testCanvasContentAlias(): void {
    $page = $this->createPage();
    PathAlias::create([
      'path' => '/page/' . $page->id(),
      'alias' => '/about',
      'langcode' => 'en',
    ])->save();
    $this->setCurrentAccount($this->editor);

    $response = $this->renderContentPath('/about');
    $data = self::responseData($response);

    self::assertSame('/about', $data['route']['requestUri']);
    self::assertSame(['canvas_page' => (string) $page->id()], $data['route']['params']);
    self::assertContains('route_match', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Tests validation and rejection of non-content paths.
   */
  public function testCanvasContentPathValidation(): void {
    $missing_path = $this->request(
      Request::create('/canvas/content-api'),
    );
    self::assertSame(400, $missing_path->getStatusCode());
    self::assertSame(
      'application/problem+json',
      $missing_path->headers->get('Content-Type'),
    );
    self::assertSame([
      'type' => 'about:blank',
      'title' => 'Bad Request',
      'status' => 400,
      'detail' => 'The requestUri query parameter must be a site-relative URI without a fragment.',
    ], self::decodeResponse($missing_path));

    $without_entity = $this->renderContentPath('/user/login');
    self::assertSame(200, $without_entity->getStatusCode());
    self::assertSame([
      'content' => NULL,
      'head' => ['title' => 'Log in'],
      'route' => [
        'name' => 'user.login',
        'requestUri' => '/user/login',
        'params' => [],
        'managedByCanvas' => FALSE,
        'entity' => NULL,
        'negotiatedLanguage' => 'en',
        'translations' => [],
      ],
    ], self::responseData($without_entity));

    $node = Node::create([
      'type' => 'article',
      'title' => 'Not rendered by Canvas',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    $node->save();
    $this->setCurrentAccount($this->editor);

    $without_canvas_content = $this->renderContentPath('/node/' . $node->id());
    $without_canvas_content_data = self::responseData($without_canvas_content);
    self::assertSame(200, $without_canvas_content->getStatusCode());
    self::assertNull($without_canvas_content_data['content']);
    self::assertFalse($without_canvas_content_data['route']['managedByCanvas']);
    self::assertSame('Not rendered by Canvas', $without_canvas_content_data['head']['title']);
    self::assertSame('entity.node.canonical', $without_canvas_content_data['route']['name']);
    self::assertSame(
      [
        'entityType' => 'node',
        'bundle' => 'article',
        'id' => (string) $node->id(),
        'uuid' => $node->uuid(),
        'langcode' => 'en',
      ],
      $without_canvas_content_data['route']['entity'],
    );
    self::assertContains(
      'config:content_template_list',
      $without_canvas_content->getCacheableMetadata()->getCacheTags(),
    );
    self::assertContains(
      'node:' . $node->id(),
      $without_canvas_content->getCacheableMetadata()->getCacheTags(),
    );
  }

  /**
   * Tests that Drupal's target route access is enforced before rendering.
   */
  public function testCanvasContentRouteAccess(): void {
    $page = $this->createPage();
    $page->set('status', FALSE)->save();
    $this->setCurrentAccount(new AnonymousUserSession());

    try {
      $this->renderPage($page);
      self::fail('An inaccessible routed entity must not render.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertContains('user.permissions', $exception->getCacheContexts());
    }
  }

  /**
   * Tests that scoped previews render auto-saved fields and component trees.
   */
  public function testPreviewRendersAutoSaveAndCacheability(): void {
    $page = $this->createPage();
    $draft_components = [[
      'uuid' => self::COMPONENT_UUID,
      'component_id' => self::COMPONENT_ID,
      'inputs' => ['heading' => 'Draft component heading'],
    ],
    ];
    $this->saveAutoSave($page, 'Auto-saved title', $draft_components);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $result = $this->renderPage($page);
    $data = self::responseData($result);

    self::assertSame('Auto-saved title', $data['head']['title']);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);
    self::assertStringContainsString('Draft component heading', $content);
    self::assertStringNotContainsString('Stored component heading', $content);
    self::assertContains(AutoSaveManager::CACHE_TAG, $result->getCacheableMetadata()->getCacheTags());
    self::assertContains('canvas_page_view', $result->getCacheableMetadata()->getCacheTags());
    self::assertContains('oauth2_scopes', $result->getCacheableMetadata()->getCacheContexts());
    self::assertContains('user.permissions', $result->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Tests preview cacheability before the first auto-save is created.
   */
  public function testPreviewWithoutAutoSaveRendersStoredEntity(): void {
    $page = $this->createPage();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $result = $this->renderPage($page);

    self::assertSame('Stored title', self::responseData($result)['head']['title']);
    self::assertContains(AutoSaveManager::CACHE_TAG, $result->getCacheableMetadata()->getCacheTags());
    self::assertContains('oauth2_scopes', $result->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Tests that an inaccessible auto-save produces a cacheable denial.
   */
  public function testInaccessibleAutoSaveIsDenied(): void {
    $page = $this->createPage();
    $draft = clone $page;
    $draft->set('status', FALSE);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    try {
      $this->renderPage($page);
      self::fail('An inaccessible auto-save must not render stored content.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertContains(AutoSaveManager::CACHE_TAG, $exception->getCacheTags());
      self::assertContains('oauth2_scopes', $exception->getCacheContexts());
      self::assertContains('user.permissions', $exception->getCacheContexts());
    }
  }

  /**
   * Tests translated routes render the corresponding translation auto-save.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('previewLanguageNegotiationCases')]
  public function testPreviewRendersTranslatedAutoSave(string $path_prefix, bool $session): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $page = $this->createPage();
    $page_fr = $page->addTranslation('fr', [
      'title' => 'Stored French title',
      'components' => $page->get('components')->getValue(),
      'status' => TRUE,
    ]);
    $page_fr->save();

    $page->set('title', 'English auto-save');
    $this->container->get(AutoSaveManager::class)->saveEntity($page);
    $page_fr->set('title', 'French draft title');
    $this->container->get(AutoSaveManager::class)->saveEntity($page_fr);
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->save();
    if ($session) {
      $this->config('language.negotiation')->set('session.parameter', 'content_language')->save();
      $this->config('language.types')
        ->set('negotiation.language_content.enabled', ['language-session' => -10, 'language-selected' => 12])
        ->set('negotiation.language_interface.enabled', ['language-session' => -10, 'language-selected' => 12])
        ->save();
    }
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $response = $this->renderContentPath($path_prefix . '/page/' . $page->id(), ['language' => 'fr']);
    $data = self::responseData($response);

    self::assertSame('French draft title', $data['head']['title']);
    self::assertSame(
      'fr',
      $data['route']['entity']['langcode'],
    );
  }

  /**
   * Tests draft trees plus translation overrides for both template kinds.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('previewLanguageNegotiationCases')]
  public function testTranslatedTemplateDrafts(string $path_prefix, bool $session): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => 'en', 'fr' => 'fr'])->save();
    if ($session) {
      $this->config('language.negotiation')->set('session.parameter', 'content_language')->save();
      $this->config('language.types')
        ->set('negotiation.language_content.enabled', ['language-session' => -10, 'language-selected' => 12])
        ->set('negotiation.language_interface.enabled', ['language-session' => -10, 'language-selected' => 12])->save();
    }
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $variant = $this->createPageVariant('translated', 'Stored chrome', 'Stored footer');
    $tree = $variant->getComponentTree()->getValue();
    $tree[2]['inputs']['heading'] = 'Draft footer';
    $variant->setComponentTree($tree);
    $this->container->get(AutoSaveManager::class)->saveEntity($variant);
    $language_manager->getLanguageConfigOverride('fr', $variant->getConfigDependencyName())
      ->set('component_tree', [$tree[0]['uuid'] => ['inputs' => ['heading' => 'French chrome']]])->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, $variant->id())->save();

    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [$tree[0]],
      'status' => TRUE,
    ]);
    $template->save();
    $dynamic = $tree[0];
    $dynamic['uuid'] = $this->container->get('uuid')->generate();
    $dynamic['inputs']['heading'] = [
      'sourceType' => 'entity-field',
      'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
    ];
    $template->setComponentTree([$tree[0], $tree[2], $dynamic]);
    $this->container->get(AutoSaveManager::class)->saveEntity($template);
    $language_manager->getLanguageConfigOverride('fr', $template->getConfigDependencyName())
      ->set('component_tree', [$tree[0]['uuid'] => ['inputs' => ['heading' => 'French template']]])->save();
    $node = Node::create(['type' => 'article', 'title' => 'English article', 'status' => TRUE]);
    $node->addTranslation('fr', ['title' => 'French article', 'status' => TRUE]);
    $node->save();
    $page = $this->createPage();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    foreach ([
      ['/page/' . $page->id(), [], 'Stored component heading'],
      ['/node/' . $node->id(), ['viewMode' => 'full'], 'French template'],
      ['/', ['pageVariant' => 'translated'], 'French chrome'],
      ['/unroutable', ['pageVariant' => 'translated'], 'French chrome'],
      ['/en/unroutable', ['pageVariant' => 'translated'], 'French chrome'],
    ] as [$path, $context, $expected]) {
      $data = self::responseData($this->renderContentPath($path_prefix . $path, ['language' => 'fr'] + $context));
      $content = json_encode($data['content'], JSON_THROW_ON_ERROR);
      self::assertStringContainsString($expected, $content);
      self::assertStringContainsString('French chrome', $content);
      self::assertStringContainsString('Draft footer', $content);
      self::assertStringNotContainsString('Stored footer', $content);
      if ($path === '/node/' . $node->id()) {
        self::assertSame('French article', $data['head']['title']);
        self::assertStringContainsString('French article', $content);
      }
    }
  }

  /**
   * Tests aliases, missing languages, access, and cacheable translation links.
   */
  public function testTranslationLinks(): void {
    $this->installConfig(['user']);
    $role = Role::load('anonymous');
    self::assertInstanceOf(Role::class, $role);
    $this->grantPermissions($role, ['access content']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => TRUE])->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr', 'de' => 'de'])
      ->set('session.parameter', 'locale')->save();
    foreach (['en' => '/hello', 'fr' => '/bonjour'] as $langcode => $alias) {
      PathAlias::create(['path' => '/page/' . $page->id(), 'alias' => $alias, 'langcode' => $langcode])->save();
    }
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount(new AnonymousUserSession());
    // Session negotiation is disabled: its configured selector is unrelated
    // query data here and must not be rewritten.
    $response = $this->renderContentPath('/fr/bonjour?locale=fr');
    $route = self::responseData($response)['route'];
    self::assertSame('fr', $route['negotiatedLanguage']);
    self::assertSame([
      ['langcode' => 'en', 'name' => 'English', 'nativeName' => 'English', 'url' => '/hello?locale=fr', 'translationAvailable' => TRUE, 'current' => FALSE, 'external' => FALSE],
      ['langcode' => 'fr', 'name' => 'French', 'nativeName' => 'Français', 'url' => '/fr/bonjour?locale=fr', 'translationAvailable' => TRUE, 'current' => TRUE, 'external' => FALSE],
      ['langcode' => 'de', 'name' => 'German', 'nativeName' => 'Deutsch', 'url' => '/de/page/' . $page->id() . '?locale=fr', 'translationAvailable' => FALSE, 'current' => FALSE, 'external' => FALSE],
    ], $route['translations']);
    foreach ($route['translations'] as $translation) {
      if ($translation['translationAvailable']) {
        $target = self::responseData($this->renderContentPath($translation['url']))['route'];
        self::assertSame($translation['langcode'], $target['entity']['langcode']);
      }
    }
    self::assertContains('languages:language_interface', $response->getCacheableMetadata()->getCacheContexts());
    self::assertContains('config:language.entity.fr', $response->getCacheableMetadata()->getCacheTags());
    self::assertContains('languages:language_content', $response->getCacheableMetadata()->getCacheContexts());
    foreach (['config:configurable_language_list', 'config:language.types', 'config:language.negotiation'] as $tag) {
      self::assertContains($tag, $response->getCacheableMetadata()->getCacheTags());
    }
    $fallback = self::responseData($this->renderContentPath('/de/page/' . $page->id()))['route'];
    self::assertSame('de', $fallback['negotiatedLanguage']);
    self::assertSame('en', $fallback['entity']['langcode']);
    self::assertFalse($fallback['translations'][0]['current']);
    self::assertTrue($fallback['translations'][2]['current']);
    self::assertFalse($fallback['translations'][2]['translationAvailable']);
    self::assertCount(3, $fallback['translations']);
    self::assertSame([], array_filter($fallback['translations'], static fn (array $entry): bool => $entry['translationAvailable'] && $entry['current']));

    $page->getTranslation('fr')->set('status', FALSE)->save();
    $this->container->get(EntityTypeManagerInterface::class)->getAccessControlHandler('canvas_page')->resetCache();
    $response = $this->renderContentPath('/hello');
    $entries = self::responseData($response)['route']['translations'];
    self::assertSame(['en', 'fr', 'de'], array_column($entries, 'langcode'));
    self::assertSame([TRUE, FALSE, FALSE], array_column($entries, 'translationAvailable'));
    // Keep the established language-specific fallback URL, even when denied.
    self::assertSame('/fr/bonjour', $entries[1]['url']);
    // Availability is not a route-access bypass: this established fallback URL
    // still resolves to the denied translation, rather than viewable English.
    try {
      $this->renderContentPath($entries[1]['url']);
      self::fail('An unavailable link must not bypass translation access.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertContains('user.permissions', $exception->getCacheContexts());
    }
    self::assertContains('user.permissions', $response->getCacheableMetadata()->getCacheContexts());
    self::assertContains('canvas_page:' . $page->id(), $response->getCacheableMetadata()->getCacheTags());
    $editor = $this->createUser(['access content', Page::EDIT_PERMISSION]);
    self::assertInstanceOf(UserInterface::class, $editor);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE, user: $editor));
    $preview = self::responseData($this->renderContentPath('/hello'))['route'];
    self::assertSame(['en', 'fr', 'de'], array_column($preview['translations'], 'langcode'));
    self::assertSame([TRUE, TRUE, FALSE], array_column($preview['translations'], 'translationAvailable'));
  }

  /**
   * Compares real API outputs while their request context is still active.
   */
  #[DataProvider('translationContractCases')]
  public function testTranslationContractParity(string $requested, bool $french_published, bool $preview): void {
    $this->installConfig(['user']);
    $role = Role::load('anonymous');
    self::assertInstanceOf(Role::class, $role);
    $this->grantPermissions($role, ['access content']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'fr' => 'fr', 'de' => 'de'])->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => $french_published])->save();
    $editor = $this->createUser(['access content', Page::EDIT_PERMISSION]);
    self::assertInstanceOf(UserInterface::class, $editor);
    $this->container->get('kernel')->rebuildContainer();
    $account = $preview ? $this->createTokenAccount(with_preview_scope: TRUE, user: $editor) : new AnonymousUserSession();
    $this->setCurrentAccount($account);

    $captured = [];
    $listener = function (ResponseEvent $event) use (&$captured, $account): void {
      if (!$event->isMainRequest() || !$event->getRequest()->attributes->has(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE)) {
        return;
      }
      // Calling the provider after renderContentPath() would instead see the
      // restored test request. Capture it here, on the actual routed request.
      self::assertSame($event->getRequest(), $this->container->get(RequestStack::class)->getCurrentRequest());
      self::assertSame($account, $this->container->get(AccountProxyInterface::class)->getAccount());
      $captured[] = $this->container->get(CodeComponentDataProvider::class)->getCanvasDataMainEntityV0();
    };
    $dispatcher = $this->container->get(EventDispatcherInterface::class);
    $dispatcher->addListener(KernelEvents::RESPONSE, $listener);
    try {
      // No auto-saves: only access differs between public and preview cases.
      $response = $this->renderContentPath(($requested === 'en' ? '' : '/' . $requested) . '/page/' . $page->id());
    }
    finally {
      $dispatcher->removeListener(KernelEvents::RESPONSE, $listener);
    }
    self::assertSame(200, $response->getStatusCode());
    self::assertCount(1, $captured);
    $main_entity = $captured[0][CodeComponentDataProvider::V0]['mainEntity'];
    $route = self::responseData($response)['route'];
    self::assertSame($requested, $route['negotiatedLanguage']);
    self::assertSame($requested === 'de' ? 'en' : $requested, $route['entity']['langcode']);
    self::assertSame($main_entity['uuid'], $route['entity']['uuid']);
    self::assertSame($main_entity['requestedLanguage'], $route['negotiatedLanguage']);
    self::assertSame($main_entity['renderedLanguage'], $route['entity']['langcode']);
    self::assertSame(['en', 'fr', 'de'], array_column($route['translations'], 'langcode'));
    self::assertSame([TRUE, $french_published || $preview, FALSE], array_column($route['translations'], 'translationAvailable'));

    // Strip only the deliberate URL differences, not an allowlist of shared
    // fields: unexpected fields, types, and ordering must fail this comparison.
    $headless_entries = \array_map(static function (array $entry): array {
      unset($entry['url'], $entry['external']);
      return $entry;
    }, $route['translations']);
    $component_entries = \array_map(static function (array $entry): array {
      unset($entry['url']);
      return $entry;
    }, $main_entity['translations']);
    self::assertSame($component_entries, $headless_entries);
  }

  /**
   * Provides available, missing, denied and preview-accessible translations.
   */
  public static function translationContractCases(): iterable {
    yield 'available requested' => ['fr', TRUE, FALSE];
    yield 'missing requested with fallback' => ['de', TRUE, FALSE];
    yield 'denied for public' => ['en', FALSE, FALSE];
    yield 'viewable in authorized preview' => ['en', FALSE, TRUE];
  }

  /**
   * Tests language names follow interface-language configuration overrides.
   */
  public function testTranslationLinkNames(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $page = $this->createPage();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'fr' => 'fr'])->save();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $language_manager->getLanguageConfigOverride('fr', 'language.entity.en')->set('label', 'Anglais')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    foreach (['' => 'English', '/fr' => 'Anglais'] as $prefix => $name) {
      $response = $this->renderContentPath($prefix . '/page/' . $page->id());
      $entries = self::responseData($response)['route']['translations'];
      self::assertSame($name, $entries[0]['name']);
      self::assertSame('English', $entries[0]['nativeName']);
      self::assertContains('languages:language_interface', $response->getCacheableMetadata()->getCacheContexts());
      self::assertContains('config:language.entity.en', $response->getCacheableMetadata()->getCacheTags());
    }
  }

  /**
   * Tests session negotiation URIs are explicit and do not expose preview data.
   */
  public function testQueryTranslationLinks(): void {
    $this->installConfig(['user']);
    $role = Role::load('anonymous');
    self::assertInstanceOf(Role::class, $role);
    $this->grantPermissions($role, ['access content']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => TRUE])->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-session' => 0, 'language-selected' => 1])
      ->save();
    $this->config('language.negotiation')->set('session.parameter', 'locale')->save();
    $this->container->get('kernel')->rebuildContainer();
    foreach ([new AnonymousUserSession(), $this->createTokenAccount(with_preview_scope: TRUE)] as $account) {
      $this->setCurrentAccount($account);
      $path = '/page/' . $page->id();
      $route = self::responseData($this->renderContentPath($path . '?locale=fr', ['viewMode' => 'full']))['route'];
      self::assertSame('fr', $route['negotiatedLanguage']);
      self::assertSame([
        ['langcode' => 'en', 'name' => 'English', 'nativeName' => 'English', 'url' => $path . '?locale=en', 'translationAvailable' => TRUE, 'current' => FALSE, 'external' => FALSE],
        ['langcode' => 'fr', 'name' => 'French', 'nativeName' => 'Français', 'url' => $path . '?locale=fr', 'translationAvailable' => TRUE, 'current' => TRUE, 'external' => FALSE],
      ], $route['translations']);
      foreach ($route['translations'] as $translation) {
        $target = self::responseData($this->renderContentPath($translation['url']))['route'];
        self::assertSame($translation['langcode'], $target['entity']['langcode']);
      }
    }
  }

  /**
   * A configured but absent translation falls back; unknown languages do not.
   */
  public function testPreviewLanguageFallbackAndValidation(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'fr' => 'fr'])->save();
    $page = $this->createPage();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->editor);
    $data = self::responseData($this->renderContentPath('/page/' . $page->id(), ['language' => 'unknown']));
    self::assertSame('en', $data['route']['entity']['langcode']);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $data = self::responseData($this->renderContentPath('/page/' . $page->id(), ['language' => 'fr']));
    self::assertSame('en', $data['route']['entity']['langcode']);
    self::assertSame('Stored title', $data['head']['title']);
    $this->expectException(NotFoundHttpException::class);
    $this->renderContentPath('/page/' . $page->id(), ['language' => 'unknown']);
  }

  /**
   * Access is checked on the requested translation, including its auto-save.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('inaccessibleTranslationCases')]
  public function testInaccessiblePreviewTranslation(bool $draft): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'fr' => 'fr'])->save();
    $page = $this->createPage();
    $translation = $page->addTranslation('fr', ['title' => 'Private French title', 'status' => $draft]);
    $page->save();
    if ($draft) {
      $translation->set('status', FALSE);
      $this->container->get(AutoSaveManager::class)->saveEntity($translation);
    }
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $this->expectException(AccessDeniedHttpException::class);
    $this->renderContentPath('/page/' . $page->id(), ['language' => 'fr']);
  }

  public static function inaccessibleTranslationCases(): iterable {
    yield 'stored translation' => [FALSE];
    yield 'auto-saved translation' => [TRUE];
  }

  /**
   * Transport redirects retain context and queries without duplicating the base.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('previewInstallationBases')]
  public function testLanguageRedirectAliasesAndBasePath(string $base, string $script): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => 'en', 'fr' => 'fr'])->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French alias page', 'status' => TRUE]);
    $page->save();
    foreach (['en' => '/english-page', 'fr' => '/page-francaise'] as $langcode => $alias) {
      PathAlias::create(['path' => '/page/' . $page->id(), 'alias' => $alias, 'langcode' => $langcode])->save();
    }
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $server = ['SCRIPT_NAME' => $script, 'SCRIPT_FILENAME' => '/var/www' . $script];
    $context = ['language' => 'fr', 'viewMode' => 'full'];
    $request = Request::create($base . '/canvas/content-api?' . http_build_query([
      'requestUri' => '/en/english-page?language=route-owned&viewMode=route-owned&filter%5Btag%5D=one&destination=/user/login',
      ...$context,
    ]), server: $server);
    $response = $this->request($request);
    self::assertInstanceOf(PreviewLanguageRedirectResponse::class, $response);
    self::assertSame(302, $response->getStatusCode());
    self::assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    self::assertStringStartsWith($base . '/canvas/content-api?', $response->getTargetUrl());
    $redirect_request = Request::create($response->getTargetUrl(), server: $server);
    $redirect_query = $redirect_request->query->all();
    $redirected_uri = $redirect_request->query->getString('requestUri');
    self::assertSame('fr', $redirect_query['language']);
    self::assertSame('full', $redirect_query['viewMode']);
    self::assertSame('1', $redirect_query[CanvasContentApiRequest::LANGUAGE_REDIRECT_QUERY]);
    self::assertSame('/fr/page-francaise', parse_url($redirected_uri, PHP_URL_PATH));
    parse_str((string) parse_url($redirected_uri, PHP_URL_QUERY), $route_query);
    self::assertSame(['language' => 'route-owned', 'viewMode' => 'route-owned', 'filter' => ['tag' => 'one'], 'destination' => '/user/login'], $route_query);
    $result = $this->request($redirect_request);
    self::assertInstanceOf(CacheableJsonResponse::class, $result);
    self::assertSame('French alias page', self::responseData($result)['head']['title']);
  }

  public static function previewInstallationBases(): iterable {
    yield 'root' => ['', '/index.php'];
    yield 'subdirectory' => ['/drupal', '/drupal/index.php'];
    yield 'front controller' => ['/drupal/index.php', '/drupal/index.php'];
  }

  /**
   * A site that cannot negotiate the hint must not redirect indefinitely.
   */
  public function testLanguageRedirectStopsAfterOneHop(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.types')
      ->set('negotiation.language_content.enabled', ['language-selected' => 12])
      ->set('negotiation.language_interface.enabled', ['language-selected' => 12])->save();
    $page = $this->createPage();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('The requested preview language could not be negotiated.');
    $this->renderContentPath('/page/' . $page->id(), ['language' => 'fr']);
  }

  /**
   * Domain negotiation must not send the preview credential to another host.
   */
  public function testLanguageRedirectRejectsForeignOrigin(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')->set('url.source', 'domain')
      ->set('url.domains', ['en' => 'localhost', 'fr' => 'french.example'])->save();
    $page = $this->createPage();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('Cross-domain preview language negotiation is not supported.');
    $this->renderContentPath('/page/' . $page->id(), ['language' => 'fr']);
  }

  public static function previewLanguageNegotiationCases(): iterable {
    yield 'prefix' => ['', FALSE];
    yield 'already prefixed' => ['/fr', FALSE];
    yield 'session' => ['', TRUE];
  }

  /**
   * Tests switch links follow configured content negotiation precedence.
   */
  #[DataProvider('languageSwitchPrecedence')]
  public function testContentLanguageSwitchPrecedence(bool $session_first): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => TRUE])->save();
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_content.enabled', $session_first
        ? ['language-session' => 0, 'language-interface' => 1]
        : ['language-interface' => 0, 'language-session' => 1])
      ->set('negotiation.language_interface.enabled', ['language-url' => 0, 'language-selected' => 1])
      ->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->set('session.parameter', 'locale')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    // Make the two methods disagree, so their order determines the language.
    $path = '/page/' . $page->id();
    $route = self::responseData($this->renderContentPath('/fr' . $path . '?locale=en'))['route'];
    self::assertSame($session_first ? 'en' : 'fr', $route['negotiatedLanguage']);
    self::assertSame($session_first ? 'en' : 'fr', $route['entity']['langcode']);
    self::assertSame([
      ['langcode' => 'en', 'name' => 'English', 'nativeName' => 'English', 'url' => $path . '?locale=en', 'translationAvailable' => TRUE, 'current' => $session_first, 'external' => FALSE],
      ['langcode' => 'fr', 'name' => 'French', 'nativeName' => 'Français', 'url' => '/fr' . $path . '?locale=fr', 'translationAvailable' => TRUE, 'current' => !$session_first, 'external' => FALSE],
    ], $route['translations']);
    foreach ($route['translations'] as $translation) {
      $target = self::responseData($this->renderContentPath($translation['url']))['route'];
      self::assertSame($translation['langcode'], $target['entity']['langcode']);
    }
  }

  /**
   * Provides both orderings of content session negotiation and UI delegation.
   */
  public static function languageSwitchPrecedence(): iterable {
    yield 'session before interface' => [TRUE];
    yield 'interface before session' => [FALSE];
  }

  /**
   * Tests mixed URL/session negotiation links round-trip without session state.
   */
  #[DataProvider('mixedLanguageNegotiation')]
  public function testMixedLanguageTranslationLinks(bool $session_first, bool $delegate, bool $preview): void {
    $this->installConfig(['user']);
    $role = Role::load('anonymous');
    self::assertInstanceOf(Role::class, $role);
    $this->grantPermissions($role, ['access content']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => TRUE])->save();
    $methods = $session_first
      ? ['language-session' => 0, 'language-url' => 1, 'language-selected' => 2]
      : ['language-url' => 0, 'language-session' => 1, 'language-selected' => 2];
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_content.enabled', $delegate ? ['language-interface' => 0] : $methods)
      ->set('negotiation.language_interface.enabled', $delegate ? $methods : ['language-url' => 0, 'language-selected' => 1])
      ->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr', 'de' => 'de'])
      ->set('session.parameter', 'locale')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($preview ? $this->createTokenAccount(with_preview_scope: TRUE) : new AnonymousUserSession());
    $path = '/page/' . $page->id();
    foreach ([
      [$path, 'en', 'en'],
      [$path . '?locale=fr', 'fr', 'fr'],
      ['/fr' . $path . '?locale=en', $session_first ? 'en' : 'fr', $session_first ? 'en' : 'fr'],
      [$path . '?locale=de', 'de', 'en'],
    ] as [$uri, $negotiated, $rendered]) {
      $uri .= str_contains($uri, '?') ? '&campaign=test' : '?campaign=test';
      $route = self::responseData($this->renderContentPath($uri, ['viewMode' => 'full']))['route'];
      self::assertSame($negotiated, $route['negotiatedLanguage']);
      self::assertSame($rendered, $route['entity']['langcode']);
      self::assertSame(['en', 'fr', 'de'], array_column($route['translations'], 'langcode'));
      foreach ($route['translations'] as $translation) {
        $langcode = $translation['langcode'];
        self::assertSame($langcode === $negotiated, $translation['current']);
        self::assertSame($langcode !== 'de', $translation['translationAvailable']);
        self::assertFalse($translation['external']);
        self::assertSame(($langcode === 'en' ? '' : '/' . $langcode) . $path, parse_url($translation['url'], PHP_URL_PATH));
        $query_string = parse_url($translation['url'], PHP_URL_QUERY);
        self::assertIsString($query_string);
        parse_str($query_string, $query);
        self::assertEquals(['locale' => $langcode, 'campaign' => 'test'], $query);
        $target = self::responseData($this->renderContentPath($translation['url']))['route'];
        self::assertSame($langcode, $target['negotiatedLanguage']);
        self::assertSame($langcode === 'de' ? 'en' : $langcode, $target['entity']['langcode']);
      }
    }
  }

  /**
   * Provides both priorities, content/UI negotiation, and public/preview access.
   */
  public static function mixedLanguageNegotiation(): iterable {
    foreach ([FALSE, TRUE] as $session_first) {
      foreach ([FALSE, TRUE] as $delegate) {
        foreach ([FALSE, TRUE] as $preview) {
          yield ($session_first ? 'session first' : 'URL first') . ', ' . ($delegate ? 'interface' : 'content') . ', ' . ($preview ? 'preview' : 'anonymous') => [$session_first, $delegate, $preview];
        }
      }
    }
  }

  /**
   * Tests domain URLs are retained and explicitly flagged as external.
   */
  public function testDomainTranslationLinks(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => TRUE])->save();
    $this->config('language.negotiation')
      ->set('url.source', 'domain')
      ->set('url.domains', ['en' => 'localhost', 'fr' => 'fr.example.com'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $route = self::responseData($this->renderPage($page))['route'];
    self::assertSame([
      ['langcode' => 'en', 'name' => 'English', 'nativeName' => 'English', 'url' => '/page/' . $page->id(), 'translationAvailable' => TRUE, 'current' => TRUE, 'external' => FALSE],
      ['langcode' => 'fr', 'name' => 'French', 'nativeName' => 'Français', 'url' => 'http://fr.example.com/page/' . $page->id(), 'translationAvailable' => TRUE, 'current' => FALSE, 'external' => TRUE],
    ], $route['translations']);
  }

  /**
   * Tests generated translation URIs exclude the Drupal installation path.
   */
  public function testTranslationLinksInSubdirectory(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $page = $this->createPage();
    $page->addTranslation('fr', ['title' => 'French', 'status' => TRUE])->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'fr' => 'fr'])->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $request = Request::create(
      'http://localhost/drupal/canvas/content-api?' . http_build_query(['requestUri' => '/fr/page/' . $page->id()]),
      server: [
        'SCRIPT_NAME' => '/drupal/index.php',
        'SCRIPT_FILENAME' => '/var/www/drupal/index.php',
        'PHP_SELF' => '/drupal/index.php',
      ],
    );
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $route = self::responseData($response)['route'];
    self::assertSame([
      ['langcode' => 'en', 'name' => 'English', 'nativeName' => 'English', 'url' => '/page/' . $page->id(), 'translationAvailable' => TRUE, 'current' => FALSE, 'external' => FALSE],
      ['langcode' => 'fr', 'name' => 'French', 'nativeName' => 'Français', 'url' => '/fr/page/' . $page->id(), 'translationAvailable' => TRUE, 'current' => TRUE, 'external' => FALSE],
    ], $route['translations']);
  }

  /**
   * Creates a stored page with a component distinguishable from its draft.
   */
  private function createPage(): Page {
    $page = Page::create([
      'title' => 'Stored title',
      'owner' => $this->editor->id(),
      'status' => TRUE,
      'components' => [[
        'uuid' => self::COMPONENT_UUID,
        'component_id' => self::COMPONENT_ID,
        'inputs' => ['heading' => 'Stored component heading'],
      ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    return $page;
  }

  /**
   * Creates a page variant with distinguishable chrome around its marker.
   */
  private function createPageVariant(
    string $id,
    string $before_heading,
    string $after_heading,
  ): PageVariant {
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);
    $variant = PageVariant::create([
      'id' => $id,
      'label' => $id,
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => $before_heading],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $marker->id(),
          'component_version' => $marker->getActiveVersion(),
          'inputs' => [],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => $after_heading],
        ],
      ],
    ]);
    self::assertEntityIsValid($variant);
    $variant->save();
    return $variant;
  }

  /**
   * Stores an auto-save without changing the persisted page.
   */
  private function saveAutoSave(Page $page, string $title, ?array $components = NULL): void {
    $draft = clone $page;
    $draft->set('title', $title);
    if ($components !== NULL) {
      $draft->set('components', $components);
    }
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
  }

  /**
   * Creates a user-bound OAuth account, optionally carrying the preview scope.
   */
  private function createTokenAccount(bool $with_preview_scope, ?UserInterface $user = NULL): TokenAuthUser {
    $user ??= $this->editor;
    $token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $user->id(),
      'client' => $this->consumer->id(),
      'scopes' => $with_preview_scope
        ? [['scope_id' => PreviewAssertionGrant::SCOPE]]
        : [],
      'value' => $this->randomMachineName(),
    ]);
    return new TokenAuthUser(
      $this->container->get(PermissionCheckerInterface::class),
      $token,
      $this->container->get(HttpMessageFactoryInterface::class),
      $this->container->get(RequestStack::class),
    );
  }

  /**
   * Sets the account used by content rendering and entity access checks.
   */
  private function setCurrentAccount(AccountInterface $account): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($account);
  }

  /**
   * Renders a page through the public kernel boundary.
   */
  private function renderPage(Page $page): CacheableJsonResponse {
    return $this->renderContentPath('/page/' . $page->id());
  }

  /**
   * Renders a routed entity through the public kernel boundary.
   *
   * @param array{viewMode?: string, componentId?: string, pageVariant?: string} $preview_context
   *   Optional editor preview context.
   */
  private function renderContentPath(string $request_uri, array $preview_context = []): CacheableJsonResponse {
    $request = Request::create(
      '/canvas/content-api?' . http_build_query([
        'requestUri' => $request_uri,
        ...$preview_context,
      ]),
    );
    $response = $this->request($request);
    if ($response instanceof PreviewLanguageRedirectResponse) {
      self::assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
      self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
      self::assertStringStartsWith('/canvas/content-api?', $response->getTargetUrl());
      $response = $this->request(Request::create($response->getTargetUrl()));
    }
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    return $response;
  }

  /**
   * Decodes a rendered-content response.
   */
  private static function responseData(CacheableJsonResponse $response): array {
    $data = \json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    \assert(\is_array($data));
    return $data;
  }

}
