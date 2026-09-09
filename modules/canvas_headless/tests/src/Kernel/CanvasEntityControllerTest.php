<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

// cspell:ignore Contenu

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_headless\Controller\CanvasEntityController;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\PermissionCheckerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests direct entity rendering through the Canvas entity API.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class CanvasEntityControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  private const string COMPONENT_ID = 'js.canvas_headless_test';

  private const string COMPONENT_UUID = '2c6e91ae-23ac-433d-9bb8-687144464b34';

  private UserInterface $editor;

  private Consumer $consumer;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'language',
    'node',
    'path_alias',
    'serialization',
    'consumers',
    'simple_oauth',
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
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['simple_oauth', 'canvas_headless']);
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
    $editor = $this->createUser(['access content']);
    \assert($editor instanceof UserInterface);
    $this->editor = $editor;
  }

  /**
   * Tests direct rendering of a content template view mode.
   */
  public function testEntityRenderForSpecificViewMode(): void {
    $node = $this->createTemplateBackedNode();
    $this->setCurrentAccount($this->editor);

    $response = $this->renderEntity($node, 'teaser');
    $data = self::responseData($response);
    $content = \json_encode($data['content'], JSON_THROW_ON_ERROR);

    self::assertSame(200, $response->getStatusCode());
    self::assertTrue($data['managedByCanvas']);
    self::assertStringContainsString('Teaser template heading', $content);
    self::assertStringNotContainsString('Published template heading', $content);
    self::assertSame([
      'entityType' => 'node',
      'bundle' => 'article',
      'id' => (string) $node->id(),
      'uuid' => $node->uuid(),
      'langcode' => 'en',
    ], $data['entity']);
  }

  /**
   * Tests that full view mode returns only entity content, not page chrome.
   */
  public function testEntityRenderDoesNotIncludePageVariantChrome(): void {
    $node = $this->createTemplateBackedNode();
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);
    $variant = PageVariant::create([
      'id' => 'entity_api_variant',
      'label' => 'Entity API variant',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => self::COMPONENT_ID,
          'component_version' => $component->getActiveVersion(),
          'inputs' => ['heading' => 'Variant chrome heading'],
        ],
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'inputs' => [],
        ],
      ],
    ]);
    self::assertEntityIsValid($variant);
    $variant->save();
    $template = ContentTemplate::load('node.article.full');
    self::assertInstanceOf(ContentTemplate::class, $template);
    $template->set('page_variant', $variant->id())->save();

    $this->setCurrentAccount($this->editor);
    $response = $this->renderEntity($node);
    $content = \json_encode(self::responseData($response)['content'], JSON_THROW_ON_ERROR);

    self::assertStringContainsString('Published template heading', $content);
    self::assertStringNotContainsString('canvas-preview-content-region', $content);
    self::assertStringNotContainsString('Variant chrome heading', $content);
  }

  /**
   * Tests that a language-prefixed request renders the negotiated translation.
   */
  public function testEntityRenderUsesRequestTranslation(): void {
    $this->installConfig(['language']);
    $this->installEntitySchema('configurable_language');
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $node = $this->createTemplateBackedNode();
    $translation = $node->addTranslation('fr', ['title' => 'Contenu français']);
    $translation->save();

    $this->setCurrentAccount($this->editor);
    $request = Request::create(
      '/fr' . CanvasEntityController::API_PATH . '?' . http_build_query([
        'type' => 'node',
        'id' => (string) $node->id(),
        'viewMode' => 'teaser',
      ]),
    );
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $data = self::responseData($response);

    self::assertSame(200, $response->getStatusCode());
    // The negotiated translation's langcode is reported, not the entity's
    // default language.
    self::assertSame('fr', $data['entity']['langcode']);
    self::assertSame((string) $node->id(), $data['entity']['id']);
    self::assertSame($node->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests unmanaged entities return null content while preserving identity.
   */
  public function testUnmanagedEntityReturnsNullContent(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'No template',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    self::assertEntityIsValid($node);
    $node->save();
    $this->setCurrentAccount($this->editor);

    $response = $this->renderEntity($node, 'teaser');
    $data = self::responseData($response);

    self::assertNull($data['content']);
    self::assertFalse($data['managedByCanvas']);
    self::assertSame((string) $node->id(), $data['entity']['id']);
  }

  /**
   * Tests preview tokens select auto-saved entity content.
   */
  public function testPreviewUsesEntityAutoSave(): void {
    $page = Page::create([
      'title' => 'Stored page title',
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
    $draft = clone $page;
    $draft->set('title', 'Auto-saved title');
    $draft->set('components', [[
      'uuid' => self::COMPONENT_UUID,
      'component_id' => self::COMPONENT_ID,
      'inputs' => ['heading' => 'Auto-saved component heading'],
    ],
    ]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $response = $this->renderPageEntity($page);
    $content = \json_encode(self::responseData($response)['content'], JSON_THROW_ON_ERROR);
    self::assertContains(
      AutoSaveManager::CACHE_TAG,
      $response->getCacheableMetadata()->getCacheTags(),
    );
    self::assertStringContainsString('Auto-saved component heading', $content);
    self::assertStringNotContainsString('Published template heading', $content);
  }

  /**
   * Tests preview tokens select the auto-saved content template.
   */
  public function testPreviewUsesTemplateAutoSave(): void {
    $node = $this->createTemplateBackedNode();
    $template = ContentTemplate::load('node.article.teaser');
    self::assertInstanceOf(ContentTemplate::class, $template);

    // Auto-save a modified version of the teaser template.
    $draft = clone $template;
    $draft->set('component_tree', [[
      'uuid' => self::COMPONENT_UUID,
      'component_id' => self::COMPONENT_ID,
      'inputs' => ['heading' => 'Auto-saved teaser heading'],
    ],
    ]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $response = $this->renderEntity($node, 'teaser');
    $content = \json_encode(self::responseData($response)['content'], JSON_THROW_ON_ERROR);

    // The auto-saved template is used for preview-scoped requests.
    self::assertStringContainsString('Auto-saved teaser heading', $content);
    self::assertStringNotContainsString('Teaser template heading', $content);
  }

  /**
   * Tests invalid parameters and access failures.
   */
  public function testErrors(): void {
    $this->setCurrentAccount($this->editor);

    try {
      $this->request(Request::create(CanvasEntityController::API_PATH));
      self::fail('Expected a bad request error when type is missing.');
    }
    catch (BadRequestHttpException $exception) {
      self::assertSame('The type query parameter is required.', $exception->getMessage());
    }

    try {
      $this->request(Request::create(
        CanvasEntityController::API_PATH . '?' . http_build_query([
          'type' => 'node',
          'id' => '999',
        ]),
      ));
      self::fail('Expected a not found error for a missing entity.');
    }
    catch (NotFoundHttpException $exception) {
      self::assertSame('The entity does not exist.', $exception->getMessage());
    }

    try {
      $this->request(Request::create(
        CanvasEntityController::API_PATH . '?' . http_build_query([
          'type' => 'no_such_type',
          'id' => '1',
        ]),
      ));
      self::fail('Expected a bad request error for an unknown entity type.');
    }
    catch (BadRequestHttpException $exception) {
      self::assertSame('Unknown entity type.', $exception->getMessage());
    }

    $author = $this->createUser();
    \assert($author instanceof UserInterface);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Private article',
      'uid' => $author->id(),
      'status' => FALSE,
    ]);
    self::assertEntityIsValid($node);
    $node->save();
    $viewer = $this->createUser(['access content']);
    \assert($viewer instanceof UserInterface);
    $this->setCurrentAccount($viewer);
    try {
      $this->request(Request::create(
        CanvasEntityController::API_PATH . '?' . http_build_query([
          'type' => 'node',
          'id' => (string) $node->id(),
        ]),
      ));
      self::fail('Expected access denied for an unpublished node.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertContains('user.permissions', $exception->getCacheContexts());
    }
  }

  /**
   * Creates a node with full and teaser content templates.
   */
  private function createTemplateBackedNode(): Node {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Template-backed content',
      'uid' => $this->editor->id(),
      'status' => TRUE,
    ]);
    $node->save();
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);

    ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [[
        'uuid' => self::COMPONENT_UUID,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => ['heading' => 'Published template heading'],
      ],
      ],
      'status' => TRUE,
    ])->save();
    ContentTemplate::create([
      'id' => 'node.article.teaser',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => [[
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => ['heading' => 'Teaser template heading'],
      ],
      ],
      'status' => TRUE,
    ])->save();

    return $node;
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
   * Renders an entity through the public kernel boundary.
   */
  private function renderEntity(Node $node, string $view_mode = 'full'): CacheableJsonResponse {
    $request = Request::create(
      CanvasEntityController::API_PATH . '?' . http_build_query([
        'type' => 'node',
        'id' => (string) $node->id(),
        'viewMode' => $view_mode,
      ]),
    );
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    return $response;
  }

  /**
   * Renders a page entity through the public kernel boundary.
   */
  private function renderPageEntity(Page $page, string $view_mode = 'full'): CacheableJsonResponse {
    $request = Request::create(
      CanvasEntityController::API_PATH . '?' . http_build_query([
        'type' => Page::ENTITY_TYPE_ID,
        'id' => (string) $page->id(),
        'viewMode' => $view_mode,
      ]),
    );
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    return $response;
  }

  /**
   * Decodes an entity-render response.
   */
  private static function responseData(CacheableJsonResponse $response): array {
    $data = \json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    \assert(\is_array($data));
    return $data;
  }

}
