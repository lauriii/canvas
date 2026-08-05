<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\PermissionCheckerInterface;
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
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
  public function testPreviewRendersTranslatedAutoSave(): void {
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
    $this->container->get('kernel')->rebuildContainer();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $response = $this->renderContentPath('/fr/page/' . $page->id());
    $data = self::responseData($response);

    self::assertSame('French draft title', $data['head']['title']);
    self::assertSame(
      'fr',
      $data['route']['entity']['langcode'],
    );
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
  private function createTokenAccount(bool $with_preview_scope): TokenAuthUser {
    $token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->editor->id(),
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
   */
  private function renderContentPath(string $request_uri): CacheableJsonResponse {
    $request = Request::create(
      '/canvas/content-api?' . http_build_query(['requestUri' => $request_uri]),
    );
    $response = $this->request($request);
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
