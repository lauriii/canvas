<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_headless\Controller\AutoSavePreviewController;
use Drupal\canvas_headless\EventSubscriber\AutoSavePreviewControllerSubscriber;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\PermissionCheckerInterface;
use Drupal\custom_elements\CustomElement;
use Drupal\custom_elements\CustomElementGenerator;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\lupus_ce_renderer\Controller\CustomElementsController;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests Canvas auto-save selection for scoped headless CE previews.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class AutoSavePreviewTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'token',
    'metatag',
    'lupus_ce_renderer',
    'canvas_headless',
  ];

  private UserInterface $editor;

  private Consumer $consumer;

  private int $pushedRequests = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['language', 'simple_oauth', 'canvas_headless']);
    $this->generateComponentConfig();

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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $request_stack = $this->container->get(RequestStack::class);
    while ($this->pushedRequests > 0) {
      $request_stack->pop();
      $this->pushedRequests--;
    }
    parent::tearDown();
  }

  /**
   * Tests that only a Canvas preview token selects the preview controller.
   */
  public function testControllerSelectionRequiresPreviewToken(): void {
    $page = $this->createPage();
    $this->saveAutoSave($page, title: 'Auto-saved title');

    $this->setCurrentAccount(new AnonymousUserSession());
    $event = $this->createControllerEvent($page);
    $this->getSubscriber()->onKernelController($event);
    $controller = $event->getController();
    self::assertIsArray($controller);
    self::assertInstanceOf(CustomElementsController::class, $controller[0]);
    self::assertNotContains(
      AutoSaveManager::CACHE_TAG,
      $controller('full')->getCacheTags(),
    );

    $this->setCurrentAccount($this->editor);
    $event = $this->createControllerEvent($page);
    $this->getSubscriber()->onKernelController($event);
    $controller = $event->getController();
    self::assertIsArray($controller);
    self::assertInstanceOf(CustomElementsController::class, $controller[0]);

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: FALSE));
    $event = $this->createControllerEvent($page);
    $this->getSubscriber()->onKernelController($event);
    $controller = $event->getController();
    self::assertIsArray($controller);
    self::assertInstanceOf(CustomElementsController::class, $controller[0]);

    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));
    $event = $this->createControllerEvent($page);
    $this->getSubscriber()->onKernelController($event);
    $controller = $event->getController();
    self::assertIsArray($controller);
    self::assertInstanceOf(AutoSavePreviewController::class, $controller[0]);
  }

  /**
   * Tests creation, update, and deletion invalidating tagged CE previews.
   */
  public function testAutoSaveLifecycleInvalidatesPreviewCacheTag(): void {
    $page = $this->createPage();
    $cache = $this->container->get('cache.default');
    $cid = 'canvas_headless_auto_save_preview';

    $cache->set($cid, 'before creation', tags: [AutoSaveManager::CACHE_TAG]);
    $this->saveAutoSave($page, 'First auto-save');
    self::assertFalse($cache->get($cid));

    $cache->set($cid, 'before update', tags: [AutoSaveManager::CACHE_TAG]);
    $this->saveAutoSave($page, 'Updated auto-save');
    self::assertFalse($cache->get($cid));

    $cache->set($cid, 'before deletion', tags: [AutoSaveManager::CACHE_TAG]);
    $this->container->get(AutoSaveManager::class)->delete($page);
    self::assertFalse($cache->get($cid));
  }

  /**
   * Tests that scoped previews render auto-saved fields and component trees.
   */
  public function testPreviewRendersAutoSaveAndCacheability(): void {
    $page = $this->createPage();
    $draft_components = [[
      'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
      'component_id' => 'sdc.canvas_test_sdc.props-slots',
      'inputs' => ['heading' => 'Draft component heading'],
    ],
    ];
    $this->saveAutoSave($page, 'Auto-saved title', $draft_components);
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $captured_entity = NULL;
    $controller = $this->createPreviewController($captured_entity);
    $event = $this->createControllerEvent($page);
    $this->getSubscriber($controller)->onKernelController($event);
    $result = ($event->getController())('full');

    self::assertInstanceOf(ContentEntityInterface::class, $captured_entity);
    self::assertSame('Auto-saved title', $captured_entity->label());
    $component_values = \json_encode($captured_entity->get('components')->getValue(), JSON_THROW_ON_ERROR);
    self::assertStringContainsString('Draft component heading', $component_values);
    self::assertStringNotContainsString('Stored component heading', $component_values);
    self::assertContains(AutoSaveManager::CACHE_TAG, $result->getCacheTags());
    self::assertContains('canvas_page_view', $result->getCacheTags());
    self::assertContains('oauth2_scopes', $result->getCacheContexts());
    self::assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Tests that the first auto-save invalidates earlier preview output.
   */
  public function testPreviewWithoutAutoSaveRendersStoredEntity(): void {
    $page = $this->createPage();
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $captured_entity = NULL;
    $controller = $this->createPreviewController($captured_entity);
    $event = $this->createControllerEvent($page);
    $this->getSubscriber($controller)->onKernelController($event);
    $result = ($event->getController())('full');

    self::assertSame('Stored title', $captured_entity?->label());
    self::assertContains(AutoSaveManager::CACHE_TAG, $result->getCacheTags());
    self::assertContains('oauth2_scopes', $result->getCacheContexts());
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

    $captured_entity = NULL;
    $controller = $this->createPreviewController($captured_entity);
    $event = $this->createControllerEvent($page);
    $this->getSubscriber($controller)->onKernelController($event);

    try {
      ($event->getController())('full');
      self::fail('An inaccessible auto-save must not render stored content.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertContains(AutoSaveManager::CACHE_TAG, $exception->getCacheTags());
      self::assertContains('oauth2_scopes', $exception->getCacheContexts());
      self::assertContains('user.permissions', $exception->getCacheContexts());
      self::assertNull($captured_entity);
    }
  }

  /**
   * Tests reconstruction of default and non-default translation auto-saves.
   */
  public function testPreviewReconstructsAllTranslationAutoSaves(): void {
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
    $this->setCurrentAccount($this->createTokenAccount(with_preview_scope: TRUE));

    $captured_entity = NULL;
    $controller = $this->createPreviewController($captured_entity);
    $event = $this->createControllerEvent($this->reloadPage()->getTranslation('fr'));
    $this->getSubscriber($controller)->onKernelController($event);
    ($event->getController())('full');

    self::assertInstanceOf(ContentEntityInterface::class, $captured_entity);
    self::assertSame('French draft title', $captured_entity->label());
    self::assertSame('English auto-save', $captured_entity->getUntranslated()->label());
  }

  /**
   * Tests malformed token scope fields are not treated as preview tokens.
   */
  public function testInspectorRejectsUnexpectedScopeFieldType(): void {
    $token = $this->createMock(Oauth2TokenInterface::class);
    $token->method('get')->with('scopes')->willReturn($this->createMock('\Drupal\Core\Field\FieldItemListInterface'));
    $account = $this->createMock(TokenAuthUserInterface::class);
    $account->method('getToken')->willReturn($token);

    self::assertFalse(PreviewTokenInspector::hasPreviewScope($account));
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
        'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
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
   * Sets the account used by controller selection and entity access checks.
   */
  private function setCurrentAccount(AccountInterface $account): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($account);
  }

  /**
   * Creates a controller event shaped like Lupus's canonical CE replacement.
   */
  private function createControllerEvent(ContentEntityInterface $entity): ControllerEvent {
    $entity_type_id = $entity->getEntityTypeId();
    $request = Request::create('/entity/' . $entity->id());
    $request->setRequestFormat('custom_elements');
    $request->attributes->set('_route', "entity.$entity_type_id.canonical");
    $request->attributes->set('_route_object', new Route("/entity/{{$entity_type_id}}"));
    $request->attributes->set('_raw_variables', new ParameterBag([
      $entity_type_id => (string) $entity->id(),
    ]));
    $request->attributes->set($entity_type_id, $entity);

    $request_stack = $this->container->get(RequestStack::class);
    $request_stack->push($request);
    $this->pushedRequests++;
    $this->addToAssertionCount(1);
    $this->assertSame($request, $request_stack->getCurrentRequest());

    return new ControllerEvent(
      $this->createMock(HttpKernelInterface::class),
      [new CustomElementsController(), 'entityView'],
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    );
  }

  /**
   * Gets the subscriber, optionally with a recording preview controller.
   */
  private function getSubscriber(?AutoSavePreviewController $controller = NULL): AutoSavePreviewControllerSubscriber {
    return new AutoSavePreviewControllerSubscriber(
      $this->container->get(AccountProxyInterface::class),
      $controller ?? $this->container->get(AutoSavePreviewController::class),
    );
  }

  /**
   * Creates a preview controller whose generator records its input entity.
   */
  private function createPreviewController(?ContentEntityInterface &$captured_entity): AutoSavePreviewController {
    $generator = $this->createMock(CustomElementGenerator::class);
    $generator->method('generate')
      ->willReturnCallback(static function (ContentEntityInterface $entity) use (&$captured_entity): CustomElement {
        $captured_entity = $entity;
        return new CustomElement();
      });
    return new AutoSavePreviewController(
      $this->container->get(RouteMatchInterface::class),
      $this->container->get(EntityTypeManagerInterface::class),
      $generator,
      $this->container->get(AutoSaveManager::class),
      $this->container->get(AccountProxyInterface::class),
    );
  }

  /**
   * Reloads the test page from storage.
   */
  private function reloadPage(): Page {
    $page = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->loadUnchanged(1);
    \assert($page instanceof Page);
    return $page;
  }

}
