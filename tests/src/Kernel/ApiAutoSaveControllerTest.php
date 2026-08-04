<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\canvas\Kernel\Traits\CanvasWorkspaceConfigTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspace_config\Kernel\WorkspaceConfigTestTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests Drupal\canvas\Controller\ApiAutoSaveController.
 *
 * @todo Refactor this to start using CanvasKernelTestBase and stop using CanvasTestSetup in https://www.drupal.org/project/canvas/issues/3531679
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiAutoSaveController::class)]
#[Group('canvas')]
#[Group('#slow')]
final class ApiAutoSaveControllerTest extends KernelTestBase {

  use AutoSaveManagerTestTrait;
  use AutoSaveRequestTestTrait;
  use ContentModerationTestTrait;
  use ConstraintViolationsTestTrait;
  use UserCreationTrait;
  use OpenApiSpecTrait;
  use BlockCreationTrait;
  use RequestTrait;
  use CanvasFieldCreationTrait;
  use CanvasFieldTrait;
  use VfsPublicStreamUrlTrait;
  use CanvasWorkspaceConfigTestTrait;
  use WorkspaceConfigTestTrait;

  /**
   * {@inheritdoc}
   *
   * KernelTestBase replaces the 'keyvalue' service with a synthetic in-memory
   * factory, which skips workspace_config's decoration; workspace publishes
   * would then trip its publish-time factory assertion. Reproduce the
   * production wiring: pin Canvas staging bookkeeping to the pristine factory
   * first, then re-apply workspace_config's decoration. Registering both here
   * (rather than in setUp) survives the mid-test container rebuilds that
   * module installs trigger, because register() runs on every rebuild and the
   * decorator resolves the workspace resolver lazily from the live container.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerCanvasStagingKeyValue($container);
    $this->registerWorkspaceConfigKeyValue($container);
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path_alias',
    'path',
    'test_user_config',
    'canvas_force_publish_error',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup();
  }

  public function testApiAutoSaveControllerGet(): void {
    $this->installConfig(['test_user_config']);
    $permissions = [
      Page::EDIT_PERMISSION,
      // We need access to page regions even for seeing there are changes.
      PageRegion::ADMIN_PERMISSION,
    ];
    $anonAccountContent = Node::create([
      'type' => 'article',
      'title' => 'Anon, empty',
    ]);
    $anonAccountContent->save();
    \assert($anonAccountContent instanceof NodeInterface);
    // Trigger a new hash with a content (non-label) change: revision
    // bookkeeping fields are excluded from auto-save content hashing.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::normalizeEntity()
    $anonAccountContent->setSticky(TRUE);
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $autoSave->saveEntity($anonAccountContent);

    [$account1, $avatarUrl] = $this->setUserWithPictureField($permissions);
    self::assertInstanceOf(AccountInterface::class, $account1);
    self::assertInstanceOf(UserInterface::class, $account1);

    $account2 = $this->createUser($permissions);
    self::assertInstanceOf(AccountInterface::class, $account2);
    $this->setCurrentUser($account1);

    // Update the page title.
    $new_title = $this->getRandomGenerator()->sentences(10);
    $account1content = Node::load(1);
    \assert($account1content instanceof NodeInterface);
    $account1content->setTitle($new_title);
    $autoSave->saveEntity($account1content);
    // Save a draft of the page region.
    $region = PageRegion::createFromBlockLayout('stark')['stark.highlighted']->enable();
    $region->save();
    $regionData = [
      'layout' => [
        [
          "nodeType" => "component",
          "slots" => [],
          "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
          "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        ],
      ],
      'model' => [
        "c3f3c22c-c22e-4bb6-ad16-635f069148e4" => [
          "label" => "Page title",
          "label_display" => "0",
          "provider" => "core",
        ],
      ],
    ];
    $region = $region->forAutoSaveData($regionData, validate: TRUE);
    $autoSave->saveEntity($region);
    // Empty data.
    $account2content = Node::load(2);
    \assert($account2content instanceof NodeInterface);
    // Trigger a new hash with a content (non-label) change: revision
    // bookkeeping fields are excluded from auto-save content hashing.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::normalizeEntity()
    $account2content->setSticky(TRUE);
    $this->setCurrentUser($account2);
    $autoSave->saveEntity($account2content);
    $code_component = JavaScriptComponent::create(
      [
        'machineName' => 'test_code',
        'name' => 'Test',
        'status' => TRUE,
        'props' => [
          'text' => [
            'type' => 'string',
            'title' => 'Title',
            'examples' => ['Press', 'Submit now'],
          ],
        ],
        'slots' => [
          'test-slot' => [
            'title' => 'test',
            'description' => 'Title',
            'examples' => [
              'Test 1',
              'Test 2',
            ],
          ],
        ],
        'js' => [
          'original' => 'console.log("Test")',
          'compiled' => 'console.log("Test")',
        ],
        'css' => [
          'original' => '.test { display: none; }',
          'compiled' => '.test{display:none;}',
        ],
        'dataDependencies' => [],
      ]
    );
    $this->assertSame(SAVED_NEW, $code_component->save());
    $code_component->set('props', $code_component->get('props') + ['yeah' => 'this is not valid, but not validated either']);
    $autoSave->saveEntity($code_component);
    $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($library instanceof AssetLibrary);
    $library->set('css', $library->get('css') + ['yeah' => 'this is not validated either']);
    $autoSave->saveEntity($library);

    $staged_set_homepage = StagedConfigUpdate::create([
      'id' => 'canvas_set_homepage',
      'label' => 'Update the front page',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['page.front' => '/home'],
        ],
      ],
    ]);
    $staged_set_homepage->save();

    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertContains(AutoSaveManager::CACHE_TAG, $response->getCacheableMetadata()->getCacheTags());
    self::assertCount(0, \array_diff($account1->getCacheTags(), $response->getCacheableMetadata()->getCacheTags()));
    self::assertCount(0, \array_diff($account1->getCacheContexts(), $response->getCacheableMetadata()->getCacheContexts()));
    self::assertContains('config:user.settings', $response->getCacheableMetadata()->getCacheTags());
    $response_body = \json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayHasKey('data', $response_body);
    $content = $response_body['data'];
    // Auto-save keys are workspace-prefixed; with no active workspace they
    // resolve against the Main workspace.
    $prefix = AutoSaveWorkspace::ID . ':';
    $anonContentIdentifier = \sprintf('%snode:%d:en', $prefix, $anonAccountContent->id());
    self::assertEquals([
      $prefix . 'asset_library:global',
      $prefix . 'js_component:test_code',
      $prefix . 'node:1:en',
      $prefix . 'node:2:en',
      $anonContentIdentifier,
      $prefix . 'page_region:stark.highlighted',
      $prefix . 'staged_config_update:canvas_set_homepage',
    ], \array_keys($content));
    // We don't assert the exact value of these because of clock-drift during
    // the test, asserting their presence is enough.
    \assert(\is_array($content[$prefix . 'node:1:en']));
    \assert(\is_array($content[$prefix . 'node:2:en']));
    \assert(\is_array($content[$prefix . 'page_region:stark.highlighted']));
    \assert(\is_array($content[$anonContentIdentifier]));
    \assert(\is_array($content[$prefix . 'js_component:test_code']));
    \assert(\is_array($content[$prefix . 'staged_config_update:canvas_set_homepage']));
    \assert(\is_array($content[$prefix . 'asset_library:global']));
    self::assertArrayHasKey('updated', $content[$prefix . 'node:1:en']);
    self::assertArrayHasKey('updated', $content[$prefix . 'node:2:en']);
    self::assertArrayHasKey('updated', $content[$anonContentIdentifier]);
    self::assertArrayHasKey('updated', $content[$prefix . 'page_region:stark.highlighted']);
    self::assertArrayHasKey('updated', $content[$prefix . 'js_component:test_code']);
    self::assertArrayHasKey('updated', $content[$prefix . 'staged_config_update:canvas_set_homepage']);
    self::assertArrayHasKey('updated', $content[$prefix . 'asset_library:global']);
    $imageStyle = \Drupal::entityTypeManager()->getStorage('image_style')->load(ApiAutoSaveController::AVATAR_IMAGE_STYLE);
    self::assertInstanceOf(ImageStyleInterface::class, $imageStyle);
    // Smoke test this is of the expected format.
    self::assertStringContainsString(\sprintf('/styles/%s/public/image-2.jpg', ApiAutoSaveController::AVATAR_IMAGE_STYLE), $avatarUrl);
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $account1content->getEntityTypeId(),
      'entity_id' => $account1content->id(),
      'owner' => [
        'id' => $account1->id(),
        'name' => $account1->getDisplayName(),
        'avatar' => $avatarUrl,
        'uri' => $account1->toUrl()->toString(),
      ],
      'label' => $new_title,
    ], \array_diff_key($content[$prefix . 'node:1:en'], \array_flip(['updated', 'data_hash'])));
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $account2content->getEntityTypeId(),
      'entity_id' => $account2content->id(),
      'owner' => [
        'id' => $account2->id(),
        'name' => $account2->getDisplayName(),
        'avatar' => NULL,
        'uri' => $account2->toUrl()->toString(),
      ],
      'label' => $account2content->label(),
    ], \array_diff_key($content[$prefix . 'node:2:en'], \array_flip(['updated', 'data_hash'])));
    $anonAccount = User::load(0);
    self::assertInstanceOf(AccountInterface::class, $anonAccount);
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $anonAccountContent->getEntityTypeId(),
      'entity_id' => $anonAccountContent->id(),
      // This should not leak the anonymous user implementation details -
      // AutoSaveTempSTore uses a random hash that is stored in the session as
      // the owner ID for anonymous users.
      // @see \Drupal\canvas\AutoSave\AutoSaveTempStoreFactory::get
      'owner' => [
        'id' => 0,
        'name' => $anonAccount->getDisplayName(),
        'avatar' => NULL,
        'uri' => $anonAccount->toUrl()->toString(),
      ],
      'label' => $anonAccountContent->label(),
    ], \array_diff_key($content[$anonContentIdentifier], \array_flip(['updated', 'data_hash'])));
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $region->getEntityTypeId(),
      'entity_id' => $region->id(),
      'owner' => [
        'id' => $account1->id(),
        'name' => $account1->getDisplayName(),
        'avatar' => $avatarUrl,
        'uri' => $account1->toUrl()->toString(),
      ],
      'label' => 'Highlighted region',
    ], \array_diff_key($content[$prefix . 'page_region:stark.highlighted'], \array_flip(['updated', 'data_hash'])));
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $code_component->getEntityTypeId(),
      'entity_id' => $code_component->id(),
      'owner' => [
        'id' => $account2->id(),
        'name' => $account2->getDisplayName(),
        'avatar' => NULL,
        'uri' => $account2->toUrl()->toString(),
      ],
      'label' => $code_component->label(),
    ], \array_diff_key($content[$prefix . 'js_component:test_code'], \array_flip(['updated', 'data_hash'])));
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $staged_set_homepage->getEntityTypeId(),
      'entity_id' => $staged_set_homepage->id(),
      'owner' => [
        'id' => $account2->id(),
        'name' => $account2->getDisplayName(),
        'avatar' => NULL,
        'uri' => $account2->toUrl()->toString(),
      ],
      'label' => $staged_set_homepage->label(),
    ], \array_diff_key($content[$prefix . 'staged_config_update:canvas_set_homepage'], \array_flip(['updated', 'data_hash'])));
    self::assertEquals([
      'langcode' => 'en',
      'entity_type' => $library->getEntityTypeId(),
      'entity_id' => $library->id(),
      'owner' => [
        'id' => $account2->id(),
        'name' => $account2->getDisplayName(),
        'avatar' => NULL,
        'uri' => $account2->toUrl()->toString(),
      ],
      'label' => $library->label(),
    ], \array_diff_key($content[$prefix . 'asset_library:global'], \array_flip(['updated', 'data_hash'])));
    $this->assertDataCompliesWithApiSpecification($content, 'AutoSaveCollection');
  }

  public function testApiAutoSaveControllerGetConflictDetection(): void {
    // @todo Remove the use of 'canvas_dev_cd' flag in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    $this->enableModules(['canvas_dev_cd']);
    $this->installConfig(['test_user_config']);
    $permissions = [
      Page::EDIT_PERMISSION,
      // We need access to page regions even for seeing there are changes.
      PageRegion::ADMIN_PERMISSION,
    ];

    $account = $this->createUser($permissions);
    self::assertInstanceOf(AccountInterface::class, $account);
    $this->setCurrentUser($account);

    $page = Page::create([
      'title' => self::NEW_PAGE_TITLE,
      'status' => FALSE,
      'owner' => $account->id(),
    ]);
    self::assertSame([], self::violationsToArray($page->validate()));
    $page->save();
    $page2 = Page::create([
      'title' => self::NEW_PAGE_TITLE,
      'status' => FALSE,
      'owner' => $account->id(),
    ]);
    self::assertSame([], self::violationsToArray($page2->validate()));
    $page2->save();

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $auto_save */
    $auto_save = $this->container->get(AutoSaveManager::class);
    $page->set('title', 'Test title, please ignore');
    $auto_save->saveEntity($page);

    // Validate that the endpoint response works as expected without conflict detected.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertContains(AutoSaveManager::CACHE_TAG, $response->getCacheableMetadata()->getCacheTags());
    self::assertCount(0, \array_diff($account->getCacheTags(), $response->getCacheableMetadata()->getCacheTags()));
    self::assertCount(0, \array_diff($account->getCacheContexts(), $response->getCacheableMetadata()->getCacheContexts()));
    self::assertContains('config:user.settings', $response->getCacheableMetadata()->getCacheTags());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    $this->assertArrayHasKey('data', $response_content);
    self::assertCount(1, $response_content['data']);
    $this->assertDataCompliesWithApiSpecification($response_content['data'], 'AutoSaveCollection');
    $pageContentIdentifier = \sprintf('%s:canvas_page:%d:en', AutoSaveWorkspace::ID, $page->id());

    // Validate that conflict changes are not leaking into the endpoint response.
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    self::assertArrayNotHasKey('errors', $response_content);
    self::assertArrayNotHasKey('conflict', $response_content['data'][$pageContentIdentifier], 'The "conflict" property should only be added for Page entities with detected resolvable conflicts.');
    self::assertArrayNotHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $response_content['data'][$pageContentIdentifier], \sprintf('The "%s" property should be stripped before returning the response.', AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY));

    // Create a conflict - entity that has auto-save entry is updated outside of the Canvas UI.
    $page->set('title', 'Conflicting title change, first time');
    $page->setNewRevision();
    $page->save();

    // Make auto-save/pending call, conflict should be detected.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertContains(AutoSaveManager::CACHE_TAG, $response->getCacheableMetadata()->getCacheTags());
    self::assertCount(0, \array_diff($account->getCacheTags(), $response->getCacheableMetadata()->getCacheTags()));
    self::assertCount(0, \array_diff($account->getCacheContexts(), $response->getCacheableMetadata()->getCacheContexts()));
    self::assertContains('config:user.settings', $response->getCacheableMetadata()->getCacheTags());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    $this->assertArrayHasKey('data', $response_content);
    self::assertCount(1, $response_content['data']);
    $this->assertDataCompliesWithApiSpecification($response_content['data'], 'AutoSaveCollection');
    $this->assertArrayHasKey('errors', $response_content);
    self::assertCount(1, $response_content['errors']);
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');

    // Validate conflict property is added to the entity with conflict.
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    self::assertArrayNotHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $response_content['data'][$pageContentIdentifier], \sprintf('The "%s" property should be stripped before returning the response.', AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY));
    self::assertArrayNotHasKey('conflict', $response_content['data'][$pageContentIdentifier], 'The property "conflict" should be unset from the auto-save entry in the top-level "data" property of the response body.');
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta'], \sprintf('The "%s" property should be added to all `errors` items that are a result of conflict detection.', AutoSaveManager::AUTO_SAVE_CONFLICT_KEY));
    self::assertEquals($page->getLoadedRevisionId(), $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY]);
    $page_first_conflict = $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY];

    // Repeated requests to auto-save/pending should result in HTTP 409 until
    // all the conflicts in the response are resolved.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayHasKey('data', $response_content);
    $this->assertArrayHasKey('errors', $response_content);
    self::assertCount(1, $response_content['errors']);
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta']);
    self::assertEquals($response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY], $page_first_conflict);

    // Resolve the conflict.
    $auto_save->resolveConflict($page, $page_first_conflict);

    // Create a second conflict for the same entity.
    // This will result in a new conflict ID.
    $page->set('title', 'Conflicting title change, second time');
    $page->setNewRevision();
    $page->save();

    // The response will still detect conflict and return HTTP 409 response.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    // Only the latest active conflict is detected, there cannot be 2 conflict
    // errors per same auto-save entity entry.
    $this->assertArrayHasKey('data', $response_content);
    self::assertCount(1, $response_content['data']);
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    $this->assertArrayHasKey('errors', $response_content);

    // Validate conflict property is added to the relevant error.
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta']);
    self::assertCount(1, $response_content['errors']);
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');

    // Validate it's a conflict based on latest $page revision.
    self::assertEquals($page->getLoadedRevisionId(), $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY]);

    // Validate it's a new conflict with a new conflict id.
    self::assertNotEquals($response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY], $page_first_conflict);
    $page_second_conflict = $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY];

    // Save the second page entity in the auto-save.
    $page2->set('title', 'Page without a conflict in sight.');
    $auto_save->saveEntity($page2);

    // One auto-save entry with active conflict is enough to receive HTTP 409
    // response.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    // Validate there are two auto-save entries and one error.
    $this->assertArrayHasKey('data', $response_content);
    self::assertCount(2, $response_content['data']);
    $this->assertArrayHasKey('errors', $response_content);
    self::assertCount(1, $response_content['errors']);
    $page2ContentIdentifier = \sprintf('%s:canvas_page:%d:en', AutoSaveWorkspace::ID, $page2->id());

    // New page 2 entry without conflict.
    self::assertArrayHasKey($page2ContentIdentifier, $response_content['data']);
    self::assertNotEquals($response_content['errors'][0]['meta']['api_auto_save_key'], $page2ContentIdentifier);

    // Pre-existing page 1 entry with conflict.
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    self::assertEquals($response_content['errors'][0]['meta']['api_auto_save_key'], $pageContentIdentifier);
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta']);
    self::assertEquals($response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY], $page_second_conflict);

    // Create a conflict for the second entry.
    $page2->set('title', 'Secondary entry conflict detected, please ignore.');
    $page2->setNewRevision();
    $page2->save();

    // Two entries with active conflicts - HTTP 409 response.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    // Validate there are two auto-save entries and two errors.
    $this->assertArrayHasKey('data', $response_content);
    self::assertCount(2, $response_content['data']);
    $this->assertArrayHasKey('errors', $response_content);
    self::assertCount(2, $response_content['errors']);

    // Validate both errors match openapi spec.
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][1], 'Error');

    // Validate page 1 has error due to conflict.
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    self::assertEquals($response_content['errors'][0]['meta']['api_auto_save_key'], $pageContentIdentifier);
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta']);
    self::assertEquals($page_second_conflict, $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY]);

    // Validate page 2 has error due to conflict.
    self::assertArrayHasKey($page2ContentIdentifier, $response_content['data']);
    self::assertEquals($response_content['errors'][1]['meta']['api_auto_save_key'], $page2ContentIdentifier);
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][1]['meta']);
    self::assertEquals($page2->getLoadedRevisionId(), $response_content['errors'][1]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY]);

    // Resolve conflict for Page 1.
    $auto_save->resolveConflict($page, $page_second_conflict);

    // One entry out of two with has active conflict - HTTP 409 response.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    // Validate there are two entries and one conflict.
    $this->assertArrayHasKey('data', $response_content);
    $this->assertArrayHasKey('errors', $response_content);
    self::assertCount(2, $response_content['data']);
    self::assertCount(1, $response_content['errors']);

    // Validate that the page 1 entry has resolved conflict.
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta']);
    self::assertNotEquals($response_content['errors'][0]['meta']['api_auto_save_key'], $pageContentIdentifier);
    self::assertEquals($page2->getLoadedRevisionId(), $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY]);

    // Validate that the page 2 entry still has active conflict.
    self::assertEquals($response_content['errors'][0]['meta']['api_auto_save_key'], $page2ContentIdentifier);

    // Resolve conflict for Page 2.
    $page2_conflict_id = $auto_save->getUnresolvedConflictForEntity($page2);
    \assert(!\is_null($page2_conflict_id));
    $auto_save->resolveConflict($page2, $page2_conflict_id);

    // Validate endpoint response returns 200 when all conflicts are resolved.
    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);

    // Validate there are two auto-save entries.
    $this->assertArrayHasKey('data', $response_content);
    self::assertCount(2, $response_content['data']);
    $this->assertDataCompliesWithApiSpecification($response_content['data'], 'AutoSaveCollection');

    // Validate there are no errors.
    $this->assertArrayNotHasKey('errors', $response_content);

    // Validate that the both entries still have auto-save entries.
    self::assertArrayHasKey($pageContentIdentifier, $response_content['data']);
    self::assertArrayHasKey($page2ContentIdentifier, $response_content['data']);
  }

  public function testGetOmitsNotAccessibleEntities(): void {
    $permissions = [
      'create article content',
      Page::EDIT_PERMISSION,
    ];
    $article = Node::create([
      'type' => 'article',
      'title' => 'Anon, empty',
    ]);
    $article->save();
    \assert($article instanceof NodeInterface);
    // Trigger a new hash with a content (non-label) change: revision
    // bookkeeping fields are excluded from auto-save content hashing.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::normalizeEntity()
    $article->setSticky(TRUE);
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $autoSave->saveEntity($article);

    $page = Page::load(2);
    \assert($page instanceof Page);
    // Trigger a new hash.
    $page->set('title', 'New title');
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $autoSave->saveEntity($page);

    $code_component = JavaScriptComponent::create(
      [
        'machineName' => 'test_code',
        'name' => 'Test',
        'status' => TRUE,
        'props' => [
          'text' => [
            'type' => 'string',
            'title' => 'Title',
            'examples' => ['Press', 'Submit now'],
          ],
        ],
        'slots' => [
          'test-slot' => [
            'title' => 'test',
            'description' => 'Title',
            'examples' => [
              'Test 1',
              'Test 2',
            ],
          ],
        ],
        'js' => [
          'original' => 'console.log("Test")',
          'compiled' => 'console.log("Test")',
        ],
        'css' => [
          'original' => '.test { display: none; }',
          'compiled' => '.test{display:none;}',
        ],
        'dataDependencies' => [],
      ]
    );
    $this->assertSame(SAVED_NEW, $code_component->save());
    $code_component->set('props', $code_component->get('props') + ['yeah' => 'this is not valid, but not validated either']);
    $autoSave->saveEntity($code_component);

    // Save a draft of the page region.
    $region = PageRegion::createFromBlockLayout('stark')['stark.highlighted']->enable();
    $region->save();
    $regionData = [
      'layout' => [
        [
          "nodeType" => "component",
          "slots" => [],
          "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
          "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        ],
      ],
      'model' => [
        "c3f3c22c-c22e-4bb6-ad16-635f069148e4" => [
          "label" => "Page title",
          "label_display" => "0",
          "provider" => "core",
        ],
      ],
    ];
    $region = $region->forAutoSaveData($regionData, validate: TRUE);
    $autoSave->saveEntity($region);

    $staged_set_homepage = StagedConfigUpdate::create([
      'id' => 'canvas_set_homepage',
      'label' => 'Update the front page',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['page.front' => '/home'],
        ],
      ],
    ]);
    $staged_set_homepage->save();

    $user = $this->createUser($permissions);
    \assert($user instanceof AccountInterface);
    $this->setCurrentUser($user);

    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'canvas_page:2',
      'config:canvas.js_component.test_code',
      'node:4',
      'config:canvas.page_region.stark.highlighted',
      'config:system.site',
      'user:0',
      'config:user.settings',
      AutoSaveManager::CACHE_TAG,
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());
    // The pending list varies by the active workspace, expressed as the
    // 'workspace' cache context.
    self::assertSame(['workspace', 'user.permissions'], $response->getCacheableMetadata()->getCacheContexts());
    $response_body = \json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayHasKey('data', $response_body);
    $content = $response_body['data'];
    $prefix = AutoSaveWorkspace::ID . ':';
    $anonContentIdentifier = \sprintf('%snode:%d:en', $prefix, $article->id());
    // Assert we get the keys of auto-save data that we can view (even if maybe
    // we aren't allowed to update).
    // We can view code components, contents and staged config updates
    // but not the page region entity.
    self::assertEquals([
      $prefix . 'canvas_page:2:en',
      $prefix . 'js_component:test_code',
      $anonContentIdentifier,
      $prefix . 'staged_config_update:canvas_set_homepage',
    ], \array_keys($content));
  }

  public static function providerCases(): iterable {
    yield 'unauthorized, without global' => [FALSE, FALSE, "The 'publish auto-saves' permission is required."];
    yield 'authorized, without global' => [TRUE, FALSE, NULL];
    yield 'unauthorized, with global' => [FALSE, FALSE, "The 'publish auto-saves' permission is required."];
    yield 'authorized, with global' => [TRUE, TRUE, NULL];
  }

  /**
   * Tests post.
   *
   * @legacy-covers ::post
   */
  #[DataProvider('providerCases')]
  public function testPost(bool $authorized, bool $withGlobal, ?string $expected_403_message): void {
    $this->setUpImages();
    $this->assertSiteHomepage('/user/login');
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_validation']);
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $code_component_storage = $entity_type_manager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $library_storage = $entity_type_manager->getStorage(AssetLibrary::ENTITY_TYPE_ID);
    $page_storage = $entity_type_manager->getStorage(Page::ENTITY_TYPE_ID);
    $content_template_storage = $entity_type_manager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    $permissions = [
      PageRegion::ADMIN_PERMISSION,
      // @todo 'bypass node access' is a very powerful permission and could have
      //   side effects. Determine a way to give the user just the access they
      //   need in https://drupal.org/i/3535354.
      'bypass node access',
      Page::EDIT_PERMISSION,
      ContentTemplate::ADMIN_PERMISSION,
      // Publish access follows core workspace access: the publish operation
      // maps to the edit permissions.
      'edit any workspace',
    ];
    if ($authorized) {
      $permissions[] = AutoSaveManager::PUBLISH_PERMISSION;
    }
    // Core workspace publish promotes the staged revisions as-is, so revision
    // attribution stays with the user who staged the draft.
    $stager = $this->setUpCurrentUser(permissions: $permissions);
    if ($expected_403_message) {
      $this->expectException(AccessDeniedHttpException::class);
      $this->expectExceptionMessage($expected_403_message);
    }
    $this->assertNoAutoSaveData();

    $template_tree = [
      // A static marker so we can easily tell if we're rendering with Canvas.
      [
        'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => 'Canvas is large and in charge!',
        ],
      ],
      // The node body, which needs to be using a entity field prop source
      // because all content templates require at least one entity field prop
      // source.
      [
        'uuid' => '6cf8297a-fc60-4019-be81-c336fd828c39',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
      ],
    ];
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => $template_tree,
    ]);
    self::assertCount(0, $template->getTypedData()->validate());
    $template->save();
    $this->assertFalse($template->status());

    // Make an update so the auto-save manager will save the entity.
    $template_tree['0']['inputs']['heading'] = 'This is an updated text value';
    $template->setComponentTree($template_tree);
    self::assertCount(0, $template->getTypedData()->validate());
    $autoSave->saveEntity($template);
    self:self::assertInstanceOf(ContentTemplate::class, $autoSave->getAutoSaveEntity($template)->entity);

    $node1 = Node::create([
      'type' => 'article',
      'title' => self::NEW_NODE_TITLE,
      'status' => FALSE,
      'field_hero' => [
        'target_id' => $this->referencedImage->id(),
        'alt' => 'A man and a women high five each other in a creepy fashion after finding a use for an old toothbrush',
      ],
    ]);
    self::assertSame([], self::violationsToArray($node1->validate()));
    $node1_original_title = (string) $node1->getTitle();
    self::assertSame(SAVED_NEW, $node1->save());
    // The 'status' field is expected as `0` and not FALSE because the boolean
    // base field will return an integer value.
    $this->assertNodeValues($node1, [], [], ['title' => $node1_original_title, 'status' => '0']);

    $node2 = Node::create([
      'type' => 'article',
      'title' => 'Are leg-warmers due for a comeback? These young designers are betting on it',
    ]);
    self::assertSame(SAVED_NEW, $node2->save());
    $node2_original_title = (string) $node2->getTitle();
    // The 'status' field is expected as `1` and not TRUE because the boolean
    // base field will return an integer value.
    $this->assertNodeValues($node2, [], [], ['title' => $node2_original_title, 'status' => '1']);

    $code_component = JavaScriptComponent::create([
      'machineName' => 'test-component',
      'name' => 'Original JavaScriptComponent name',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
      ],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'dataDependencies' => [],
    ]);
    $this->assertSame(SAVED_NEW, $code_component->save());

    $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($library instanceof AssetLibrary);
    $originalGlobalLibraryName = $library->label();

    $validClientJson = $this->getValidClientJson($node1, FALSE);
    $page = Page::create([
      'title' => self::NEW_PAGE_TITLE,
      'status' => FALSE,
      'components' => [],
    ]);
    self::assertSame([], self::violationsToArray($page->validate()));
    $this->assertSame(SAVED_NEW, $page->save());
    $this->assertFalse($page->isPublished());
    // Trigger a new hash for auto-save.
    $page->set('title', 'The updated title.');
    $autoSave->saveEntity($page);

    $staged_set_homepage = StagedConfigUpdate::create([
      'id' => 'canvas_set_homepage',
      'label' => 'Update the front page',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['page.front' => '/home'],
        ],
      ],
    ]);
    $staged_set_homepage->save();

    // Add some global elements.
    if ($withGlobal) {
      $page_region = PageRegion::createFromBlockLayout('stark')['stark.header'];
      $page_region->enable()->save();
      $validClientJson['layout'][] = [
        "components" => [
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
            "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
          ],
        ],
        "name" => "Header",
        "nodeType" => "region",
        "id" => $page_region->get('region'),
      ];
      $validClientJson['model'] += [
        "c3f3c22c-c22e-4bb6-ad16-635f069148e4" => [
          "label" => "Page title",
          "label_display" => "0",
          "provider" => "core",
        ],
      ];
    }
    unset($validClientJson['autoSaves']);
    $validClientJson += $this->getClientAutoSaves([$node1]);
    // Auto-save node 1.
    $response = $this->request(Request::create(Url::fromRoute('canvas.api.layout.post', [
      'entity_type' => 'node',
      'entity' => $node1->id(),
    ])->toString(), method: 'POST', server: [
      'CONTENT_TYPE' => 'application/json',
    ], content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Auto-save node 2 with only the heading and an invalid prop.
    $node2->set('field_canvas_demo', [
      [
        'uuid' => self::TEST_HEADING_UUID,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '8c01a2bdb897a810',
        'inputs' => [
          'style' => 'flared',
          'element' => 'h3',
          'text' => '',
        ],
      ],
      [
        'uuid' => 'af42c3b3-6d62-4ea8-ad07-670c7b9ccf75',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '8c01a2bdb897a810',
        'inputs' => [
          // Missing input for required `element` prop.
          'text' => 'Crumbling castle',
        ],
      ],
    ]);
    $node2->set('path', '/llama');
    $autoSave->saveEntity($node2);

    $code_component->set('name', 'New name');
    $code_component->set('props', [
      'mixed_up_prop' => [
        'type' => 'unknown',
        'title' => 'Title',
        'enum' => [
          'Press',
          'Click',
          'Submit',
        ],
        'examples' => ['Press', 'Submit now'],
      ],
    ]);
    $autoSave->saveEntity($code_component);

    $library->set('label', 'New label');
    $css = $library->get('css');
    $css['original'] = NULL;
    $library->set('css', $css);
    $autoSave->saveEntity($library);

    // A grouped per-item violation for a missing update access, as produced
    // by the whole-workspace publish pipeline.
    $access_error = static fn (EntityInterface $entity): array => [
      'detail' => \sprintf('You do not have permission to update %s.', (string) $entity->label()),
      'source' => [
        'pointer' => AutoSaveManager::getAutoSaveKey($entity),
      ],
      'meta' => [
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
        'label' => $entity->label(),
        ApiAutoSaveController::AUTO_SAVE_KEY => AutoSaveManager::getAutoSaveKey($entity),
      ],
    ];
    // Node 2's draft carries invalid component inputs; its violations recur
    // in every publish attempt until the draft is fixed. Before publishing
    // empty string properties are unset to enforce the 'required' validation.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::unsetEmptyProps()
    $node2_errors = [
      [
        'detail' => 'The property text is required.',
        'source' => [
          'pointer' => 'model.' . self::TEST_HEADING_UUID . '.text',
        ],
        'meta' => [
          'entity_type' => 'node',
          'entity_id' => $node2->id(),
          // The label should not be updated if model validation failed.
          'label' => $node2_original_title,
          ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node2),
        ],
      ],
      [
        'detail' => 'Does not have a value in the enumeration ["primary","secondary"]. The provided value is: "flared".',
        'source' => [
          'pointer' => 'model.' . self::TEST_HEADING_UUID . '.style',
        ],
        'meta' => [
          'entity_type' => 'node',
          'entity_id' => $node2->id(),
          // The label should not be updated if model validation failed.
          'label' => $node2_original_title,
          ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node2),
        ],
      ],
      [
        'detail' => 'The property element is required.',
        'source' => [
          'pointer' => 'model.af42c3b3-6d62-4ea8-ad07-670c7b9ccf75.element',
        ],
        'meta' => [
          'entity_type' => 'node',
          'entity_id' => $node2->id(),
          // The label should not be updated if model validation failed.
          'label' => $node2_original_title,
          ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node2),
        ],
      ],
    ];

    // Try to publish. The publish request carries no item selection: every
    // item pending in the workspace is validated and access checked, and any
    // failure blocks the whole publish. The user is missing update access for
    // the code component, the library assets, and the staged config update,
    // and node 2's draft is invalid: all of it is reported together, ordered
    // by auto-save key.
    $response = $this->makePublishAllRequest([]);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
        $access_error($library),
        $access_error($code_component),
        ...$node2_errors,
        $access_error($staged_set_homepage),
      ],
    ], self::decodeResponse($response));
    $this->assertSiteHomepage('/user/login');

    // Grant the missing update access.
    $this->setUpCurrentUser(permissions: [
      ...$permissions,
      AssetLibrary::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
      'administer site configuration',
    ]);

    $response = $this->makePublishAllRequest([]);
    $json = json_decode((string) $response->getContent(), TRUE);
    self::assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $errors[] = [
      'detail' => 'This value should not be null.',
      'source' => [
        'pointer' => 'css.original',
      ],
      'meta' => [
        'entity_type' => AssetLibrary::ENTITY_TYPE_ID,
        'entity_id' => $library->id(),
        // The label should not be updated if model validation failed.
        'label' => $library->label(),
        ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($library),
      ],
    ];
    $errors[] = [
      'detail' => "In component canvas:test-component:\nUnable to find class/interface \"unknown\" specified in the prop \"mixed_up_prop\" for the component \"canvas:test-component\".",
      'source' => [
        'pointer' => '',
      ],
      'meta' => [
        'entity_type' => JavaScriptComponent::ENTITY_TYPE_ID,
        'entity_id' => $code_component->id(),
        // The label should not be updated if model validation failed.
        'label' => $code_component->label(),
        ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($code_component),
      ],
    ];
    $errors[] = [
      'detail' => "'enum' is an unknown key because props.mixed_up_prop.type is unknown (see config schema type canvas.json_schema.prop.*||canvas.json_schema.prop_shape.*).",
      'source' => [
        'pointer' => 'props.mixed_up_prop',
      ],
      'meta' => [
        'entity_type' => JavaScriptComponent::ENTITY_TYPE_ID,
        'entity_id' => $code_component->id(),
        // The label should not be updated if model validation failed.
        'label' => $code_component->label(),
        ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($code_component),
      ],
    ];
    $errors[] = [
      'detail' => 'The value you selected is not a valid choice.',
      'source' => [
        'pointer' => 'props.mixed_up_prop.type',
      ],
      'meta' => [
        'entity_type' => JavaScriptComponent::ENTITY_TYPE_ID,
        'entity_id' => $code_component->id(),
        // The label should not be updated if model validation failed.
        'label' => $code_component->label(),
        ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($code_component),
      ],
    ];
    $errors = [...$errors, ...$node2_errors];

    self::assertEquals($errors, $json['errors']);
    // Ensure none of the entities are updated if one is invalid.
    $this->assertNodeValues($node1, [], [], ['title' => $node1_original_title, 'status' => '0']);
    $this->assertNodeValues($node2, [], [], ['title' => $node2_original_title, 'status' => '1']);
    $this->assertNotNull($code_component->id());
    $this->assertEquals('Original JavaScriptComponent name', $code_component_storage->loadUnchanged($code_component->id())?->label());
    $this->assertNotNull($library->id());
    $this->assertEquals($originalGlobalLibraryName, $library_storage->loadUnchanged($library->id())?->label());
    $this->assertNotNull($page->id());
    $this->assertSame(self::NEW_PAGE_TITLE, $page_storage->loadUnchanged($page->id())?->label());
    $saved_template = $content_template_storage->loadUnchanged($template->id());
    \assert($saved_template instanceof ContentTemplate);
    $this->assertFalse($saved_template->status());
    $this->assertSiteHomepage('/user/login');

    if ($withGlobal) {
      // Note: no additional error appears for the invalid auto-saved layout for
      // the PageTemplate, because missing regions are automatically added from
      // the active/stored PageTemplate.
      // @see \Drupal\canvas\Entity\PageRegion::forAutoSaveData()
      $page_region = PageRegion::load('stark.header');
      self::assertInstanceOf(PageRegion::class, $page_region);
      self::assertSame([], $page_region->getComponentTree()->getValue());
    }

    // Fix the errors.
    $validClientJson['model'][self::TEST_HEADING_UUID]['resolved']['style'] = 'primary';
    $validClientJson['model']['af42c3b3-6d62-4ea8-ad07-670c7b9ccf75']['resolved']['element'] = 'h3';
    // Auto-save node 2 with only the heading.
    unset($validClientJson['model'][self::TEST_IMAGE_UUID]);
    unset($validClientJson['layout'][0]['components'][1]);
    unset($validClientJson['autoSaves']);
    $validClientJson += $this->getClientAutoSaves([$node2]);
    $response = $this->request(Request::create(Url::fromRoute('canvas.api.layout.post', [
      'entity_type' => 'node',
      'entity' => $node2->id(),
    ])->toString(), method: 'POST', server: [
      'CONTENT_TYPE' => 'application/json',
    ], content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $code_component->set('name', 'New new JavaScriptComponent name');
    $code_component->set('props', [
      'text' => [
        'type' => 'string',
        'title' => 'Title',
        'examples' => ['Press', 'Submit now'],
      ],
    ]);
    $autoSave->saveEntity($code_component);
    $library->set('label', 'New new AssetLibrary label');
    $css['original'] = '';
    $library->set('css', $css);
    $autoSave->saveEntity($library);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $node1_auto_save_key = AutoSaveManager::getAutoSaveKey($node1);
    self::assertArrayHasKey($node1_auto_save_key, $auto_save_data);
    self::assertArrayHasKey(AutoSaveManager::getAutoSaveKey($template), $auto_save_data);
    $auto_save_count = \count($auto_save_data);

    // The workspace is the unit of publish: with every draft valid, one
    // publish request promotes everything pending at once.
    $response = $this->makePublishAllRequest([]);
    $json = json_decode((string) $response->getContent(), TRUE);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => \sprintf('Successfully published %d items.', $auto_save_count)], $json);

    // Core workspace publish promotes the staged revision verbatim: node 1
    // was never published and its staged draft (built from a form submission
    // that leaves the published checkbox unchecked) is unpublished, so it
    // stays unpublished after the publish — drafts no longer auto-publish.
    $this->assertNodeValues(
      $node1,
      [
        'sdc.canvas_test_sdc.heading',
        'sdc.canvas_test_sdc.image',
        'block.system_branding_block',
      ],
      $this->getValidConvertedInputs(FALSE),
      [
        'title' => 'The updated title.',
        'status' => '0',
      ]
    );
    $this->assertSiteHomepage('/home');

    $this->assertNodeValues(
      $node2,
      [
        'sdc.canvas_test_sdc.heading',
        'block.system_branding_block',
      ],
      \array_intersect_key($this->getValidConvertedInputs(), \array_flip([self::TEST_HEADING_UUID, self::TEST_BLOCK])),
      [
        'title' => 'The updated title.',
        'status' => '1',
      ]
    );

    // Cache tag invalidations require event subscribers to reach instantiated
    // services. But this kernel test instantiated storages disconnected from
    // the container. So: re-retrieve the storages completely anew.
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $code_component_storage = $entity_type_manager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $library_storage = $entity_type_manager->getStorage(AssetLibrary::ENTITY_TYPE_ID);
    $page_storage = $entity_type_manager->getStorage(Page::ENTITY_TYPE_ID);
    $content_template_storage = $entity_type_manager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    // Same staleness hazard for the auto-save manager: its captured workspace
    // manager instance would otherwise disagree with the current container's
    // one about the active workspace, silently turning staged saves into Live
    // saves.
    $autoSave = $this->container->get(AutoSaveManager::class);

    $this->assertNotNull($page->id());
    $page = $page_storage->loadUnchanged($page->id());
    \assert($page instanceof Page);
    // The page was created unpublished and its staged draft never changed
    // that: the staged status goes live verbatim — never-published drafts no
    // longer auto-publish at publish time.
    $this->assertFalse($page->isPublished());
    $this->assertSame('The updated title.', $page->label());
    // The published revision is the staged draft revision itself; it remains
    // attributed to the user who staged it, not the publishing user.
    $this->assertSame($page->getRevisionUserId(), $stager->id());

    // The `path` field is computed (aliases are persisted as `path_alias`
    // entities), but the auto-saved alias must still be published.
    $node2_reloaded = $entity_type_manager->getStorage('node')->loadUnchanged((string) $node2->id());
    \assert($node2_reloaded instanceof Node);
    $this->assertSame('/llama', $node2_reloaded->get('path')->first()?->getValue()['alias']);

    $this->assertNotNull($template->id());
    $template = $content_template_storage->loadUnchanged($template->id());
    \assert($template instanceof ContentTemplate);
    $this->assertTrue($template->status());

    $this->assertNotNull($code_component->id());
    $this->assertSame('New new JavaScriptComponent name', $code_component_storage->loadUnchanged($code_component->id())?->label());
    $this->assertNotNull($library->id());
    $this->assertSame('New new AssetLibrary label', $library_storage->loadUnchanged($library->id())?->label());

    if ($withGlobal) {
      $page_region = PageRegion::load('stark.header');
      self::assertInstanceOf(PageRegion::class, $page_region);
      $tree = $page_region->getComponentTree()->getValue();
      self::assertSame(['block.page_title_block'], \array_column($tree, 'component_id'));
      self::assertSame(['c3f3c22c-c22e-4bb6-ad16-635f069148e4'], \array_column($tree, 'uuid'));
    }

    // Ensure that after the nodes have been published their auto-save data is
    // removed.
    $this->assertNoAutoSaveData();

    // Now save both nodes with the same titles and expect to fail. To avoid
    // affecting other tests the validator will only be applied to if the title
    // contains the string 'unique!'.
    // @see \Drupal\canvas_test_validation\Plugin\Validation\Constraint\UniqueTitleConstraintValidator
    $node1->set('title', 'I am not unique!');
    $autoSave->saveEntity($node1);
    $node2->set('title', 'I am not unique!');
    // Remove the invalid prop set above.
    $node2->set('field_canvas_demo', []);
    $autoSave->saveEntity($node2);
    $response = $this->makePublishAllRequest([]);
    $decoded = self::decodeResponse($response);
    // Validation runs inside the auto-save workspace, so each draft sees the
    // other's staged title: both items report the collision up front, instead
    // of only the second one failing mid-save against the first's Live copy.
    $this->assertSame(
      [
        'errors' => [
          [
            'detail' => 'A content item with Title <em class="placeholder">I am not unique!</em> already exists.',
            'source' => [
              'pointer' => 'title',
            ],
            'meta' => [
              'entity_type' => 'node',
              'entity_id' => $node1->id(),
              'label' => 'I am not unique!',
              ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node1),
            ],
          ],
          [
            'detail' => 'A content item with Title <em class="placeholder">I am not unique!</em> already exists.',
            'source' => [
              'pointer' => 'title',
            ],
            'meta' => [
              'entity_type' => 'node',
              'entity_id' => $node2->id(),
              'label' => 'I am not unique!',
              ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node2),
            ],
          ],
        ],
      ],
      $decoded,
    );

    // All should be good now.
    $autoSave->saveEntity($node1->set('title', 'I am unique!'));
    $autoSave->saveEntity($node2->set('title', 'I am different!'));
    $response = $this->makePublishAllRequest([]);
    $this->assertSame(['message' => 'Successfully published 2 items.'], self::decodeResponse($response));

    // A failure while writing inside the publish transaction rolls the whole
    // publish back: neither draft goes live and both stay pending.
    $autoSave->saveEntity($node1->set('title', 'cause exception'));
    $autoSave->saveEntity($node2->set('title', 'this will be fine'));
    $response = $this->makePublishAllRequest([]);
    $decoded = self::decodeResponse($response);
    self::assertSame(500, $response->getStatusCode());
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Forced exception for testing purposes.',
          'source' => [
            'pointer' => 'error',
          ],
        ],
      ],
    ], $decoded);
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    self::assertSame('I am unique!', $node_storage->loadUnchanged((string) $node1->id())?->label());
    self::assertSame('I am different!', $node_storage->loadUnchanged((string) $node2->id())?->label());
  }

  /**
   * Tests delete.
   *
   * @legacy-covers ::delete
   */
  public function testDelete(): void {
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    self::assertCount(0, $auto_save_data);

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Article for Delete',
    ]);
    $node->save();

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    // Update something so the auto-save entry generates a different hash.
    $node->setTitle('Updated Title');
    $autoSave->saveEntity($node);

    $global = AssetLibrary::load('global');
    \assert($global instanceof AssetLibrary);
    $global->set('label', $this->randomMachineName());
    $autoSave->saveEntity($global);

    // Verify auto-save data exists.
    // Set up a user that can access the Canvas UI, and has 'view label' access to
    // both entities.
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
    ]);
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    self::assertCount(2, $auto_save_data);
    self::assertArrayHasKey(\sprintf('%s:node:%d:en', AutoSaveWorkspace::ID, $node->id()), $auto_save_data);
    self::assertArrayHasKey(\sprintf('%s:%s:global', AutoSaveWorkspace::ID, AssetLibrary::ENTITY_TYPE_ID), $auto_save_data);

    $account = $this->createUser([]);
    \assert($account instanceof AccountInterface);
    $this->setCurrentUser($account);
    $url = Url::fromRoute('canvas.api.auto-save.delete', [
      'entity_type' => 'node',
      'entity' => $node->id(),
    ]);
    $request = Request::create($url->toString(), 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);

    // Authenticated but unauthorized: 403 due to missing permission.
    try {
      $this->request($request);
      $this->fail('Expected access denied exception');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertSame(
        "The 'publish auto-saves' permission is required.",
        $e->getMessage()
      );
    }

    // With permission but no CSRF header.
    // 'access content' grants 'view label' on the node; AssetLibrary::ADMIN_PERMISSION
    // grants it on the global asset library. Both are required by
    // getPublishableAutoSaves(), which mirrors the filter ::get() applies.
    $account = $this->createUser([AutoSaveManager::PUBLISH_PERMISSION, 'access content', AssetLibrary::ADMIN_PERMISSION]);
    \assert($account instanceof AccountInterface);
    $this->setCurrentUser($account);
    $request = Request::create($url->toString(), 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);
    $session_configuration = $this->container->get(SessionConfigurationInterface::class)->getOptions($request);
    $request->cookies->set($session_configuration['name'], 'ABCD');
    try {
      $this->request($request);
      $this->fail('Expected access denied exception');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertSame(
        "X-CSRF-Token request header is missing",
        $e->getMessage()
      );
    }

    // Nonsense CSRF header.
    $request = Request::create($url->toString(), 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);
    $session_configuration = $this->container->get(SessionConfigurationInterface::class)->getOptions($request);
    $request->cookies->set($session_configuration['name'], 'ABCD');
    $request->headers->set('X-CSRF-Token', 'let me in');
    try {
      $this->request($request);
      $this->fail('Expected access denied exception');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertSame(
        "X-CSRF-Token request header is invalid",
        $e->getMessage()
      );
    }

    // Valid DELETE request.
    $token_generator = $this->container->get(CsrfTokenGenerator::class);
    $request = Request::create($url->toString(), 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);
    $request->cookies->set($session_configuration['name'], 'ABCD');
    $request->headers->set('X-CSRF-Token', $token_generator->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $response = $this->request($request);
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    self::assertSame(
      ['message' => 'Auto-save data deleted successfully.'],
      json_decode((string) $response->getContent(), TRUE)
    );

    $asset_library_url = Url::fromRoute('canvas.api.auto-save.delete', [
      'entity_type' => AssetLibrary::ENTITY_TYPE_ID,
      'entity' => $global->id(),
    ]);
    $request = Request::create($asset_library_url->toString(), 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);
    $request->cookies->set($session_configuration['name'], 'ABCD');
    $request->headers->set('X-CSRF-Token', $token_generator->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $response = $this->request($request);
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    self::assertSame(
      ['message' => 'Auto-save data deleted successfully.'],
      json_decode((string) $response->getContent(), TRUE)
    );

    // Verify auto-save data was deleted.
    self::assertCount(0, $this->getAutoSaveStatesFromServer());
    $autoSaveData = $autoSave->getAutoSaveEntity($node);
    self::assertTrue($autoSaveData->isEmpty());

    // Try to delete again, should get 404.
    $request = Request::create($url->toString(), 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);
    $request->headers->set('X-CSRF-Token', $token_generator->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $response = $this->request($request);
    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    self::assertSame(
      ['error' => 'No auto-save data found for this entity.'],
      json_decode((string) $response->getContent(), TRUE)
    );
  }

  /**
   * Tests that a draft moderation state blocks the workspace publish.
   *
   * With the workspace as the unit of publish, core content_moderation
   * refuses to publish a workspace that contains items in an unpublished
   * moderation state: an auto-saved draft state therefore blocks the whole
   * publish until a published state is staged.
   *
   * @see \Drupal\content_moderation\EventSubscriber\WorkspaceSubscriber
   */
  public function testPublishModeratedEntity(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['content_moderation']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');

    $this->setUpCurrentUser(permissions: [
      'access content',
      'view own unpublished content',
      'edit any article content',
      AutoSaveManager::PUBLISH_PERMISSION,
      // Publish access follows core workspace access: the publish operation
      // maps to the edit permissions.
      'edit any workspace',
      'use editorial transition create_new_draft',
      'use editorial transition publish',
    ]);

    $node = Node::create([
      'type' => 'article',
      'title' => 'Moderated node',
      'moderation_state' => 'published',
    ]);
    self::assertSame(SAVED_NEW, $node->save());
    self::assertTrue($node->isPublished());

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    $node->set('moderation_state', 'draft');
    $autoSave->saveEntity($node);

    self::assertArrayHasKey($autoSave->getAutoSaveKey($node), $this->getAutoSaveStatesFromServer());

    // Core's moderation gate stops the whole workspace publish while the
    // staged item is in an unpublished moderation state. The gate reason is
    // surfaced from the publish exception as a client-resolvable conflict.
    $response = $this->makePublishAllRequest([]);
    self::assertSame(409, $response->getStatusCode());
    $decoded = self::decodeResponse($response);
    self::assertStringContainsString('unpublished moderation state', $decoded['errors'][0]['detail']);

    // Nothing was published and the draft survived the refusal.
    $node_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node');
    \assert($node_storage instanceof RevisionableStorageInterface);
    $live = $node_storage->loadUnchanged((string) $node->id());
    \assert($live instanceof Node);
    self::assertTrue($live->isPublished());
    self::assertSame('Moderated node', $live->getTitle());
    self::assertFalse($autoSave->getAutoSaveEntity($node)->isEmpty());

    // Staging a published moderation state unblocks the same publish. The
    // `moderation_state` field is computed (states are persisted as
    // `content_moderation_state` entities), but the auto-saved state must
    // still be published with the workspace.
    $node->set('title', 'Moderated node updated');
    $node->set('moderation_state', 'published');
    $autoSave->saveEntity($node);
    $response = $this->makePublishAllRequest([]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    self::assertEquals(['message' => 'Successfully published 1 item.'], json_decode((string) $response->getContent(), TRUE));

    $live = $node_storage->loadUnchanged((string) $node->id());
    \assert($live instanceof Node);
    self::assertTrue($live->isPublished());
    self::assertSame('published', $live->get('moderation_state')->value);
    self::assertSame('Moderated node updated', $live->getTitle());
    self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());
  }

  /**
   * Staging never validates; publishing refuses invalid items, per item.
   *
   * The auto-save validation contract for workspace-staged content entities:
   * an invalid draft is retained verbatim (an auto-save is never refused or
   * dropped), the publish endpoint refuses it with per-item violations
   * before any live write, the draft survives the refusal, and fixing the
   * draft lets the same publish succeed.
   *
   * @see docs/adr/0014-stage-autosaves-in-a-dedicated-workspace.md
   */
  public function testInvalidDraftIsStagedButRefusedAtPublish(): void {
    $this->setUpCurrentUser(permissions: [
      'bypass node access',
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      // Publish access follows core workspace access: the publish operation
      // maps to the edit permissions.
      'edit any workspace',
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Live title',
      'status' => TRUE,
    ]);
    self::assertSame(SAVED_NEW, $node->save());

    // An intermediate editing state that fails entity validation: a title
    // longer than the field's 255 character limit. Depending on the database
    // driver the draft is retained as a workspace revision or falls back to
    // a payload snapshot; both are part of the retention contract and both
    // are read through the same auto-save API.
    $invalid_title = str_repeat('x', 300);
    $draft = clone $node;
    $draft->set('title', $invalid_title);
    self::assertNotCount(0, $draft->validate());
    $autoSave->saveEntity($draft);

    // Staging is never refused: the invalid draft is retained verbatim.
    $staged = $autoSave->getAutoSaveEntity($node)->entity;
    \assert($staged instanceof NodeInterface);
    self::assertSame($invalid_title, $staged->getTitle());

    // Publishing the invalid draft is refused with a per-item violation.
    $auto_save_key = AutoSaveManager::getAutoSaveKey($node);
    self::assertArrayHasKey($auto_save_key, $this->getAutoSaveStatesFromServer());
    $response = $this->makePublishAllRequest([]);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $json = json_decode((string) $response->getContent(), TRUE);
    self::assertCount(1, $json['errors']);
    self::assertStringContainsString('may not be longer than 255 characters', $json['errors'][0]['detail']);
    self::assertStringStartsWith('title', $json['errors'][0]['source']['pointer']);
    self::assertSame('node', $json['errors'][0]['meta']['entity_type']);
    self::assertSame($auto_save_key, $json['errors'][0]['meta'][ApiAutoSaveController::AUTO_SAVE_KEY]);

    // No live write happened, and the draft survived the refusal.
    $node_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node');
    $live = $node_storage->loadUnchanged((string) $node->id());
    \assert($live instanceof NodeInterface);
    self::assertSame('Live title', $live->getTitle());
    $staged = $autoSave->getAutoSaveEntity($node)->entity;
    \assert($staged instanceof NodeInterface);
    self::assertSame($invalid_title, $staged->getTitle());

    // Fixing the draft makes the same publish succeed.
    $fixed = $autoSave->getEntityForLayoutEditing($node);
    \assert($fixed instanceof NodeInterface);
    $fixed->set('title', 'Draft title');
    $autoSave->saveEntity($fixed);
    $response = $this->makePublishAllRequest([]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => 'Successfully published 1 item.'], json_decode((string) $response->getContent(), TRUE));
    $live = $node_storage->loadUnchanged((string) $node->id());
    \assert($live instanceof NodeInterface);
    self::assertSame('Draft title', $live->getTitle());
    self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());
  }

  /**
   * Tests publishing behavior for draft, published, and unpublished pages.
   *
   * This test covers different publishing scenarios:
   * - Draft pages (never published): auto-publish when publishing changes
   * - Published pages: preserve status from auto-saved entity
   * - Unpublished pages (previously published, now unpublished): preserve status
   *   from auto-saved entity, allowing both unpublishing and republishing.
   *
   * @legacy-covers ::post
   */
  public function testPublishingBehaviorForDraftPublishedAndUnpublishedPages(): void {
    $this->setUpCurrentUser(permissions: [
      'bypass node access',
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      // Publish access follows core workspace access: the publish operation
      // maps to the edit permissions.
      'edit any workspace',
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $node_storage = $entity_type_manager->getStorage('node');

    // Test Case 1: Published page with changes that will remain published.
    // This verifies that published pages can have changes published while
    // maintaining their published status.
    $published_node = Node::create([
      'type' => 'article',
      'title' => 'Published Article',
      'status' => TRUE,
    ]);
    self::assertSame(SAVED_NEW, $published_node->save());
    self::assertTrue($published_node->isPublished());
    $published_node_id = $published_node->id();
    self::assertNotNull($published_node_id);
    // Verify it's not a draft since it was published.
    self::assertFalse(AutoSaveManager::entityIsConsideredNew($published_node));

    // Make changes via auto-save, keeping it published.
    $published_node->set('title', 'Updated Published Article');
    $published_node->set('status', TRUE);
    $autoSave->saveEntity($published_node);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $published_node_key = AutoSaveManager::getAutoSaveKey($published_node);
    self::assertArrayHasKey($published_node_key, $auto_save_data);

    // Publish the changes.
    $response = $this->makePublishAllRequest([]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Verify: Node should remain published with updated title.
    $published_node = $node_storage->loadUnchanged($published_node_id);
    \assert($published_node instanceof NodeInterface);
    self::assertTrue($published_node->isPublished());
    self::assertSame('Updated Published Article', $published_node->getTitle());

    // Test Case 2: Published page that will be unpublished.
    // This tests the ability to unpublish a published page by
    // setting status to FALSE in auto-save and then publishing.
    $to_be_unpublished_node = Node::create([
      'type' => 'article',
      'title' => 'Article To Unpublish',
      'status' => TRUE,
    ]);
    self::assertSame(SAVED_NEW, $to_be_unpublished_node->save());
    self::assertTrue($to_be_unpublished_node->isPublished());
    $to_be_unpublished_node_id = $to_be_unpublished_node->id();
    self::assertNotNull($to_be_unpublished_node_id);
    // Verify it's not a draft since it was published.
    self::assertFalse(AutoSaveManager::entityIsConsideredNew($to_be_unpublished_node));

    // Make changes via auto-save, setting status to FALSE (unpublishing).
    $to_be_unpublished_node->set('title', 'Unpublished Article');
    $to_be_unpublished_node->set('status', FALSE);
    $autoSave->saveEntity($to_be_unpublished_node);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $to_be_unpublished_node_key = AutoSaveManager::getAutoSaveKey($to_be_unpublished_node);
    self::assertArrayHasKey($to_be_unpublished_node_key, $auto_save_data);

    // Publish the changes.
    $response = $this->makePublishAllRequest([]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Verify: Node should be unpublished with updated title.
    $to_be_unpublished_node = $node_storage->loadUnchanged($to_be_unpublished_node_id);
    \assert($to_be_unpublished_node instanceof NodeInterface);
    self::assertFalse($to_be_unpublished_node->isPublished());
    self::assertSame('Unpublished Article', $to_be_unpublished_node->getTitle());
    // Verify it's not a draft since it was published previously.
    self::assertFalse(AutoSaveManager::entityIsConsideredNew($to_be_unpublished_node));

    // Test Case 3: Unpublished page that will be published.
    // This tests the ability to republish an unpublished page (one that was
    // previously published but is now unpublished) by setting status to TRUE
    // in auto-save and then publishing.
    // Create a node, publish it, then unpublish it (so it's not a draft).
    $to_be_republished_node = Node::create([
      'type' => 'article',
      'title' => 'Article To Republish',
      'status' => TRUE,
    ]);
    self::assertSame(SAVED_NEW, $to_be_republished_node->save());
    self::assertTrue($to_be_republished_node->isPublished());
    $to_be_republished_node_id = $to_be_republished_node->id();
    self::assertNotNull($to_be_republished_node_id);

    // Now unpublish it by creating a new revision.
    $to_be_republished_node = $node_storage->loadUnchanged($to_be_republished_node_id);
    \assert($to_be_republished_node instanceof NodeInterface);
    $to_be_republished_node->set('status', FALSE);
    $to_be_republished_node->setNewRevision();
    $to_be_republished_node->save();

    // Reload to verify it's unpublished.
    $to_be_republished_node = $node_storage->loadUnchanged($to_be_republished_node_id);
    \assert($to_be_republished_node instanceof NodeInterface);
    self::assertFalse($to_be_republished_node->isPublished());
    // Verify it's not a draft (has been published before).
    self::assertFalse(AutoSaveManager::entityIsConsideredNew($to_be_republished_node));

    // Make changes via auto-save, setting status back to TRUE (republishing).
    $to_be_republished_node->set('title', 'Republished Article');
    $to_be_republished_node->set('status', TRUE);
    $autoSave->saveEntity($to_be_republished_node);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $to_be_republished_node_key = AutoSaveManager::getAutoSaveKey($to_be_republished_node);
    self::assertArrayHasKey($to_be_republished_node_key, $auto_save_data);

    // Publish the changes.
    $response = $this->makePublishAllRequest([]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Verify: Node should be published again with updated title.
    $to_be_republished_node = $node_storage->loadUnchanged($to_be_republished_node_id);
    \assert($to_be_republished_node instanceof NodeInterface);
    self::assertTrue($to_be_republished_node->isPublished());
    self::assertSame('Republished Article', $to_be_republished_node->getTitle());

    // Test Case 4: Draft pages no longer auto-publish. Core workspace
    // publish promotes the staged revision verbatim, so a never-published
    // draft whose staged status is FALSE stays unpublished; making it live
    // requires staging the published status first (the editor's publish
    // toggle stages exactly that).
    // @see \Drupal\canvas\Controller\ApiContentAutoSaveControllers
    $draft_node = Node::create([
      'type' => 'article',
      'title' => self::NEW_NODE_TITLE,
      'status' => FALSE,
    ]);
    self::assertSame(SAVED_NEW, $draft_node->save());
    self::assertFalse($draft_node->isPublished());
    $draft_node_id = $draft_node->id();
    self::assertNotNull($draft_node_id);
    // Verify it's a draft.
    self::assertTrue(AutoSaveManager::entityIsConsideredNew($draft_node));

    // Make changes via auto-save, keeping status FALSE.
    $draft_node->set('title', 'Updated Draft Article');
    $draft_node->set('status', FALSE);
    $autoSave->saveEntity($draft_node);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $draft_node_key = AutoSaveManager::getAutoSaveKey($draft_node);
    self::assertArrayHasKey($draft_node_key, $auto_save_data);

    // Publish the changes.
    $response = $this->makePublishAllRequest([]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Verify: the staged (unpublished) state went live as-is.
    $draft_node = $node_storage->loadUnchanged($draft_node_id);
    \assert($draft_node instanceof NodeInterface);
    self::assertFalse($draft_node->isPublished());
    self::assertSame('Updated Draft Article', $draft_node->getTitle());

    // Staging the published status and publishing again makes it live.
    $draft_node->set('status', TRUE);
    $autoSave->saveEntity($draft_node);
    $response = $this->makePublishAllRequest([]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $draft_node = $node_storage->loadUnchanged($draft_node_id);
    \assert($draft_node instanceof NodeInterface);
    self::assertTrue($draft_node->isPublished());
  }

  /**
   * Tests that one inaccessible item aborts the whole workspace publish.
   */
  public function testPublishAutoSaveItemsAccessCheckWithMixedAccess(): void {
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);

    $inaccessible_page_one = Page::create([
      'title' => 'Publish Access Denied Page One',
      'path' => [
        'alias' => '/publish-access-denied-page-one',
      ],
    ]);
    self::assertSame([], self::violationsToArray($inaccessible_page_one->validate()));
    self::assertSame(SAVED_NEW, $inaccessible_page_one->save());
    $inaccessible_page_one->set('title', 'Publish Access Denied Page One Modified');
    $autoSave->saveEntity($inaccessible_page_one);

    $accessible_article = Node::create([
      'type' => 'article',
      'title' => 'Publish Access Allowed Article',
    ]);
    self::assertSame([], self::violationsToArray($accessible_article->validate()));
    self::assertSame(SAVED_NEW, $accessible_article->save());
    $accessible_article->set('title', 'Publish Access Allowed Article Modified');
    $autoSave->saveEntity($accessible_article);

    $inaccessible_page_two = Page::create([
      'title' => 'Publish Access Denied Page Two',
      'path' => [
        'alias' => '/publish-access-denied-page-two',
      ],
    ]);
    self::assertSame([], self::violationsToArray($inaccessible_page_two->validate()));
    self::assertSame(SAVED_NEW, $inaccessible_page_two->save());
    $inaccessible_page_two->set('title', 'Publish Access Denied Page Two Modified');
    $autoSave->saveEntity($inaccessible_page_two);

    $auto_save_data = $autoSave->getAllAutoSaveList(FALSE, FALSE);
    self::assertCount(3, $auto_save_data, 'Only the 3 auto-saves exist that were just created are present.');

    // 1. Publishing requires publish access to the active workspace, which
    // follows core workspace permissions (publish maps to edit).
    $this->setUpCurrentUser(permissions: [
      'access content',
      'edit any article content',
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    try {
      $this->makePublishAllRequest([]);
      $this->fail('Expected access denied error when publishing without workspace publish access.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertSame(\sprintf('You do not have permission to publish the "%s" workspace.', AutoSaveWorkspace::LABEL), $exception->getMessage());
    }

    // 2. With workspace publish access but no update access to the Page
    // items, the whole publish aborts with grouped per-item violations: the
    // accessible article is not published either.
    $this->setUpCurrentUser(permissions: [
      'access content',
      'edit any article content',
      AutoSaveManager::PUBLISH_PERMISSION,
      'edit any workspace',
    ]);
    // The GET response lists everything that is pending in the workspace.
    $available_to_publish = $this->getAutoSaveStatesFromServer();
    self::assertSame(\array_keys($auto_save_data), \array_keys($available_to_publish));
    $response = $this->makePublishAllRequest([]);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $response_content = self::decodeResponse($response);
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');
    $access_error = static fn (Page $page): array => [
      'detail' => \sprintf('You do not have permission to update %s.', (string) $page->label()),
      'source' => [
        'pointer' => AutoSaveManager::getAutoSaveKey($page),
      ],
      'meta' => [
        'entity_type' => Page::ENTITY_TYPE_ID,
        'entity_id' => $page->id(),
        'label' => $page->label(),
        ApiAutoSaveController::AUTO_SAVE_KEY => AutoSaveManager::getAutoSaveKey($page),
      ],
    ];
    self::assertEquals([
      'errors' => [
        $access_error($inaccessible_page_one),
        $access_error($inaccessible_page_two),
      ],
    ], $response_content);

    // Nothing went live, and every draft survived the refusal.
    $page_one_id = $inaccessible_page_one->id();
    $page_two_id = $inaccessible_page_two->id();
    $article_id = $accessible_article->id();
    self::assertNotNull($page_one_id);
    self::assertNotNull($page_two_id);
    self::assertNotNull($article_id);
    $page_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID);
    self::assertSame('Publish Access Denied Page One', $page_storage->loadUnchanged($page_one_id)?->label());
    self::assertSame('Publish Access Denied Page Two', $page_storage->loadUnchanged($page_two_id)?->label());
    $node_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node');
    self::assertSame('Publish Access Allowed Article', $node_storage->loadUnchanged($article_id)?->label());
    self::assertSame(\array_keys($auto_save_data), \array_keys($autoSave->getAllAutoSaveList(FALSE, FALSE)));
  }

  private function assertSiteHomepage(string $path): void {
    self::assertEquals($path, $this->config('system.site')->get('page.front'));
  }

}
