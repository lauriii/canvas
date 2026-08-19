<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Controller\ErrorCodesEnum;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\block\Traits\BlockCreationTrait;
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
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
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
    // Trigger a new hash.
    $anonAccountContent->setRevisionUserId(2);
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
    $account2content->setRevisionUser($account2);
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
    $anonContentIdentifier = \sprintf('node:%d:en', $anonAccountContent->id());
    self::assertEquals([
      'asset_library:global',
      'js_component:test_code',
      'node:1:en',
      'node:2:en',
      $anonContentIdentifier,
      'page_region:stark.highlighted',
      'staged_config_update:canvas_set_homepage',
    ], \array_keys($content));
    // We don't assert the exact value of these because of clock-drift during
    // the test, asserting their presence is enough.
    \assert(\is_array($content['node:1:en']));
    \assert(\is_array($content['node:2:en']));
    \assert(\is_array($content['page_region:stark.highlighted']));
    \assert(\is_array($content[$anonContentIdentifier]));
    \assert(\is_array($content['js_component:test_code']));
    \assert(\is_array($content['staged_config_update:canvas_set_homepage']));
    \assert(\is_array($content['asset_library:global']));
    self::assertArrayHasKey('updated', $content['node:1:en']);
    self::assertArrayHasKey('updated', $content['node:2:en']);
    self::assertArrayHasKey('updated', $content[$anonContentIdentifier]);
    self::assertArrayHasKey('updated', $content['page_region:stark.highlighted']);
    self::assertArrayHasKey('updated', $content['js_component:test_code']);
    self::assertArrayHasKey('updated', $content['staged_config_update:canvas_set_homepage']);
    self::assertArrayHasKey('updated', $content['asset_library:global']);
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
    ], \array_diff_key($content['node:1:en'], \array_flip(['updated', 'data_hash'])));
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
    ], \array_diff_key($content['node:2:en'], \array_flip(['updated', 'data_hash'])));
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
    ], \array_diff_key($content['page_region:stark.highlighted'], \array_flip(['updated', 'data_hash'])));
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
    ], \array_diff_key($content['js_component:test_code'], \array_flip(['updated', 'data_hash'])));
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
    ], \array_diff_key($content['staged_config_update:canvas_set_homepage'], \array_flip(['updated', 'data_hash'])));
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
    ], \array_diff_key($content['asset_library:global'], \array_flip(['updated', 'data_hash'])));
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
    $pageContentIdentifier = \sprintf('canvas_page:%d:en', $page->id());

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
    $page2ContentIdentifier = \sprintf('canvas_page:%d:en', $page2->id());

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
    // Trigger a new hash.
    $article->setRevisionUserId(2);
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
    self::assertSame(['user.permissions'], $response->getCacheableMetadata()->getCacheContexts());
    $response_body = \json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayHasKey('data', $response_body);
    $content = $response_body['data'];
    $anonContentIdentifier = \sprintf('node:%d:en', $article->id());
    // Assert we get the keys of auto-save data that we can view (even if maybe
    // we aren't allowed to update).
    // We can view code components, contents and staged config updates
    // but not the page region entity.
    self::assertEquals([
      'canvas_page:2:en',
      'js_component:test_code',
      $anonContentIdentifier,
      'staged_config_update:canvas_set_homepage',
    ], \array_keys($content));
  }

  public static function providerCases(): iterable {
    yield 'unauthorized' => [FALSE, "The 'publish auto-saves' permission is required."];
    yield 'authorized' => [TRUE, NULL];
  }

  /**
   * Tests post.
   *
   * @legacy-covers ::post
   */
  #[DataProvider('providerCases')]
  public function testPost(bool $authorized, ?string $expected_403_message): void {
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
    ];
    if ($authorized) {
      $permissions[] = AutoSaveManager::PUBLISH_PERMISSION;
    }
    $this->setUpCurrentUser(permissions: $permissions);
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

    // Try to publish all the changes. We are not allowed, as we are missing
    // permissions for the code components and library assets and staged config
    // updates.
    try {
      $this->makePublishAllRequest();
      $this->fail('Expected access denied error after field check on publishing auto-saved changes.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      // Get access denied as expected. The label is the new one that we set.
      $this->assertSame("Unable to update entities: 'New label', 'New name', 'Update the front page'.", $exception->getMessage());
      $this->assertSame([
        'config:canvas.asset_library.global',
        AutoSaveManager::CACHE_TAG,
        'config:canvas.js_component.test-component',
        'config:system.site',
      ], $exception->getCacheTags());
      $this->assertSame(['user.permissions'], $exception->getCacheContexts());
    }
    // Grant that permission.
    $this->setUpCurrentUser(permissions: [
      ...$permissions,
      AssetLibrary::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
    ]);

    // Verify that the user must have `administer site configuration` permission
    // to change the homepage.
    try {
      $this->makePublishAllRequest();
      $this->fail('Expected access denied error after field check on publishing auto-saved changes.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      // Get access denied as expected. The label is the new one that we set.
      $this->assertSame("Unable to update entities: 'Update the front page'.", $exception->getMessage());
      $this->assertSame([
        'config:system.site',
        AutoSaveManager::CACHE_TAG,
      ], $exception->getCacheTags());
      $this->assertSame(['user.permissions'], $exception->getCacheContexts());
    }
    // Grant that permission.
    $user = $this->setUpCurrentUser(permissions: [
      ...$permissions,
      AssetLibrary::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
      'administer site configuration',
    ]);

    $response = $this->makePublishAllRequest();
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
    // Before publishing empty string properties are unset to enforce the
    // 'required' validation.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::unsetEmptyProps()
    $errors[] = [
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
    ];
    $errors[] = [
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
    ];
    $errors[] = [
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
    ];

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
    $node1_auto_save_key = 'node:' . $node1->id() . ':en';
    self::assertArrayHasKey($node1_auto_save_key, $auto_save_data);

    // Make publish requests that have extra, and out-dated auto-save
    // information.
    $extra_auto_save_data = $auto_save_data;
    $extra_key = 'node:' . (((int) $node2->id()) + 1) . ':en';
    $extra_auto_save_data[$extra_key] = $auto_save_data[$node1_auto_save_key];
    $response = $this->makePublishAllRequest($extra_auto_save_data);
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
        [
          'detail' => ErrorCodesEnum::UnexpectedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $extra_key,
          ],
          'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
        ],
      ],
    ], \json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));

    $out_dated_auto_save_data = $auto_save_data;
    $out_dated_auto_save_data[$node1_auto_save_key]['data_hash'] = 'old-hash';
    $response = $this->makePublishAllRequest($out_dated_auto_save_data);
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
        [
          'detail' => ErrorCodesEnum::UnmatchedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $node1_auto_save_key,
          ],
          'code' => ErrorCodesEnum::UnmatchedItemInPublishRequest->value,
          'meta' => [
            'entity_type' => 'node',
            'entity_id' => $node1->id(),
            'label' => $validClientJson['entity_form_fields']['title[0][value]'],
            ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node1),
          ],
        ],
      ],
    ], \json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));

    // Publish only node 1.
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $auto_save_count = \count($auto_save_data);
    $node1_auto_save = [$node1_auto_save_key => $auto_save_data[$node1_auto_save_key]];
    $response = $this->makePublishAllRequest($node1_auto_save);
    $json = json_decode((string) $response->getContent(), TRUE);
    self::assertEquals(['message' => 'Successfully published 1 item.'], $json);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $this->assertValidJsonUpdateNode($node1, FALSE);
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    self::assertArrayNotHasKey($node1_auto_save_key, $auto_save_data);
    self::assertCount($auto_save_count - 1, $auto_save_data);
    // Ensure none of other the entities were updated.
    $this->assertNodeValues($node2, [], [], ['title' => $node2_original_title, 'status' => '1']);
    $this->assertNotNull($code_component->id());
    $this->assertEquals('Original JavaScriptComponent name', $code_component_storage->loadUnchanged($code_component->id())?->label());
    $this->assertNotNull($library->id());
    $this->assertEquals($originalGlobalLibraryName, $library_storage->loadUnchanged($library->id())?->label());
    $this->assertNotNull($page->id());
    $this->assertSame(self::NEW_PAGE_TITLE, $page_storage->loadUnchanged($page->id())->label());
    $saved_template = $content_template_storage->loadUnchanged($template->id());
    \assert($saved_template instanceof ContentTemplate);
    $this->assertFalse($saved_template->status());
    $this->assertSiteHomepage('/user/login');

    // Try publishing something with a field change that we don't have access to.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    try {
      $this->makePublishAllRequest();
      $this->fail('Expected access denied error after field check on publishing auto-saved changes.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      // Access denied as expected, the title listed must be the new one.
      $this->assertSame('Unable to update field title for entity "The updated title.".', $exception->getMessage());
    }
    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas_test_field_access']);

    self::assertArrayHasKey(AutoSaveManager::getAutoSaveKey($template), $auto_save_data);
    $response = $this->makePublishAllRequest();
    $json = json_decode((string) $response->getContent(), TRUE);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => \sprintf('Successfully published %d items.', $auto_save_count - 1)], $json);

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

    $this->assertNotNull($page->id());
    $page = $page_storage->loadUnchanged($page->id());
    \assert($page instanceof Page);
    $this->assertTrue($page->isPublished());
    $this->assertSame('The updated title.', $page->label());
    $this->assertSame($page->getRevisionUserId(), $user->id());

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

    // Ensure that after the nodes have been published their auto-save data is
    // removed.
    $this->assertNoAutoSaveData();

    // Now save both nodes with the same titles and expect to fail. To avoid
    // affecting other tests the validator will only be applied to if the title
    // contains the string 'unique!'.
    // @see \Drupal\canvas_test_validation\Plugin\Validation\Constraint\UniqueTitleConstraintValidator
    $node1_auto_save_key = 'node:' . $node1->id() . ':en';
    $node1->set('title', 'I am not unique!');
    $autoSave->saveEntity($node1);
    $node2_auto_save_key = 'node:' . $node2->id() . ':en';
    $node2->set('title', 'I am not unique!');
    // Remove the invalid prop set above.
    $node2->set('field_canvas_demo', []);
    $autoSave->saveEntity($node2);
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $response = $this->makePublishAllRequest([
      $node1_auto_save_key => $auto_save_data[$node1_auto_save_key],
      $node2_auto_save_key => $auto_save_data[$node2_auto_save_key],
    ]);
    $decoded = self::decodeResponse($response);
    $this->assertSame(
      [
        'errors' => [
          [
            'detail' => 'A content item with Title <em class="placeholder">I am not unique!</em> already exists.',
            'source' => [
              'pointer' => 'title',
            ],
          ],
        ],
      ],
      $decoded,
    );

    // All should be good now.
    $autoSave->saveEntity($node1->set('title', 'I am unique!'));
    $autoSave->saveEntity($node2->set('title', 'I am different!'));
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $response = $this->makePublishAllRequest([
      $node1_auto_save_key => $auto_save_data[$node1_auto_save_key],
      $node2_auto_save_key => $auto_save_data[$node2_auto_save_key],
    ]);
    $this->assertSame(['message' => 'Successfully published 2 items.'], self::decodeResponse($response));

    $autoSave->saveEntity($node1->set('title', 'cause exception'));
    $autoSave->saveEntity($node2->set('title', 'this will be fine'));
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $response = $this->makePublishAllRequest([
      $node1_auto_save_key => $auto_save_data[$node1_auto_save_key],
      $node2_auto_save_key => $auto_save_data[$node2_auto_save_key],
    ]);
    $decoded = self::decodeResponse($response);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Forced exception for testing purposes.',
          'source' => [
            'pointer' => 'error',
          ],
          'meta' => [
            'entity_type' => 'node',
            'entity_id' => $node1->id(),
            'label' => 'cause exception',
            ApiAutoSaveController::AUTO_SAVE_KEY => $autoSave->getAutoSaveKey($node1),
          ],
        ],
      ],
    ], $decoded);

    $autoSave->deleteAll();
    // Test conflict detection for a single page entity during early validation
    // of the auto-save items in ApiAutoSaveController.
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::validateExpectedAutoSaves()
    // @todo Remove the use of 'canvas_dev_cd' flag in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    $this->enableModules(['canvas_dev_cd']);
    $autoSave->saveEntity($page->set('title', 'Safe title'));
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $page_content_identifier = $autoSave::getAutoSaveKey($page);

    // Update the page title outside of the auto-save workflow.
    // This will cause a conflict to be detected when attempting to publish this
    // entity via the auto-save workflow.
    $page->set('title', 'This will cause conflict');
    $page->setNewRevision();
    self::assertSame([], self::violationsToArray($page->validate()));
    $page->save();

    $response = $this->makePublishAllRequest([
      $page_content_identifier => $auto_save_data[$page_content_identifier],
    ]);

    $this->assertConflictErrorResponse(
      $response,
      $page->getLoadedRevisionId(),
      $page_content_identifier,
    );

    // Conflict detection during the handling of the publishing request should
    // prevent the changes to the page entity from being saved.
    self::assertNotNull($page->id());
    $saved_page = $page_storage->loadUnchanged($page->id());
    \assert($saved_page instanceof Page);
    self::assertNotEquals($page->label(), $saved_page->label());
    self::assertNotEquals($page->getRevisionId(), $saved_page->getRevisionId());

    // Re-fetch AutoSaveManager so it uses the current container services.
    // This ensures getUnresolvedConflictForEntity() uses the updated $page.
    $autoSave = $this->container->get(AutoSaveManager::class);
    $detected_conflicts[$page_content_identifier] = $autoSave->getUnresolvedConflictForEntity($page);
    self::assertNotNull($detected_conflicts[$page_content_identifier]);

    // Resolve the conflict so the publishing request can proceed.
    $autoSave->resolveConflict($page, $detected_conflicts[$page_content_identifier]);

    // Publishing should succeed now.
    $response = $this->makePublishAllRequest([
      $page_content_identifier => $auto_save_data[$page_content_identifier],
    ]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $json = json_decode((string) $response->getContent(), TRUE);
    self::assertEquals(['message' => 'Successfully published 1 item.'], $json);

    // Test publishing multiple pages when one has an unresolved conflict.
    $page_without_conflict = Page::create([
      'title' => 'Page without conflict',
      'status' => FALSE,
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page_without_conflict->save());

    $page_with_conflict = Page::create([
      'title' => 'Page with conflict',
      'status' => FALSE,
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page_with_conflict->save());

    $autoSave->saveEntity($page_without_conflict->setPublished());
    $autoSave->saveEntity($page_with_conflict->setPublished());
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $page_without_conflict_key = $autoSave::getAutoSaveKey($page_without_conflict);
    $page_with_conflict_key = $autoSave::getAutoSaveKey($page_with_conflict);

    // Create a conflict only for the second page before sending the request.
    $page_with_conflict->set('title', 'Persisted conflicting title');
    $page_with_conflict->setUnpublished();
    $page_with_conflict->setNewRevision();
    $page_with_conflict->save();

    // Make a publish request for both pages.
    $response = $this->makePublishAllRequest([
      $page_without_conflict_key => $auto_save_data[$page_without_conflict_key],
      $page_with_conflict_key => $auto_save_data[$page_with_conflict_key],
    ]);

    // Expect the request to fail due to the conflict on the second page
    // entity, because all entities in a publish request must be
    // published together or not at all.
    $this->assertConflictErrorResponse(
      $response,
      $page_with_conflict->getLoadedRevisionId(),
      $page_with_conflict_key,
    );

    // Assert that auto-save changes for $page_with_conflict are not persisted
    self::assertNotNull($page_with_conflict->id());
    $saved_page_with_conflict = $page_storage->loadUnchanged($page_with_conflict->id());
    \assert($saved_page_with_conflict instanceof Page);
    self::assertFalse($saved_page_with_conflict->isPublished());

    // Assert that auto-save changes for $page_without_conflict are not persisted.
    self::assertNotNull($page_without_conflict->id());
    $saved_page_without_conflict = $page_storage->loadUnchanged($page_without_conflict->id());
    \assert($saved_page_without_conflict instanceof Page);
    self::assertFalse($saved_page_without_conflict->isPublished());

    // The auto-save items should not change if the publishing request fails due
    // to detection of an unresolved conflict.
    self::assertEquals($auto_save_data, $this->getAutoSaveStatesFromServer());

    // Test the secondary conflict detection that is performed during the page
    // entity validation when handling the publishing request.
    // Test that a new conflict can be detected for an entity that previously
    // had a resolved conflict.
    // Test that consecutive conflicts for the same entity have different IDs.
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::getConflictAwareContentEntityViolations()
    // @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber::violationToJsonApiStyleErrorObject()
    $autoSave->saveEntity($page->set('title', 'Another safe title'));
    $auto_save_data = $this->getAutoSaveStatesFromServer();

    // Validate that the auto-save key for the page is correct and that the
    // previously detected conflict is resolved.
    self::assertEquals($page_content_identifier, AutoSaveManager::getAutoSaveKey($page));
    self::assertNull($autoSave->getUnresolvedConflictForEntity($page));
    self::assertNotNull($detected_conflicts[$page_content_identifier]);

    // Cause new conflict.
    $page->set('title', 'this will cause new conflict');
    $page->setNewRevision();
    $page->save();

    // Validate that a new conflict is detected for the page.
    $current_conflict = $autoSave->getUnresolvedConflictForEntity($page);
    self::assertNotNull($current_conflict);

    // Validate that the new conflict ID is different from the previously
    // resolved conflict ID for the same entity.
    self::assertNotEquals($detected_conflicts[$page_content_identifier], $current_conflict);

    // Mock AutoSaveManager to control the conflict information returned in the
    // publish request validation and entity validation separately, to simulate
    // a race condition from this single-threaded test.
    $mockedAutoSave = $this->createMock(AutoSaveManager::class);
    $mockedAutoSave->expects($this->any())
      ->method('getAllAutoSaveList')
      // First conflict detection is performed during the auto-save item
      // validation in ApiAutoSaveController::validateExpectedAutoSaves().
      // As we want to skip the first conflict detection, we intentionally fetch
      // auto-save items without the conflict information.
      ->willReturn($autoSave->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE));
    $mockedAutoSave->expects($this->exactly(1))
      ->method('getUnresolvedConflictForEntity')
      ->with()
      // Second conflict detection is performed during entity validation in
      // ApiAutoSaveController::getConflictAwareContentEntityViolations(). We want
      // to test that conflict can still be detected if it occurs mid-request
      // after the validation in ::validateExpectedAutoSaves() is performed.
      ->willReturn($autoSave->getUnresolvedConflictForEntity($page));

    // Replace the AutoSaveManager in the container with the mocked one.
    $this->container->set(AutoSaveManager::class, $mockedAutoSave);
    // Force ApiAutoSaveController to be rebuilt with the mocked dependency.
    $this->container->set(ApiAutoSaveController::class, new ApiAutoSaveController(
        $this->container->get(Connection::class),
        $this->container->get(EntityTypeManagerInterface::class),
        $this->container->get(ConfigFactoryInterface::class),
        $this->container->get(FileUrlGeneratorInterface::class),
        $mockedAutoSave,
        $this->container->get('logger.channel.canvas'),
        $this->container->get(AccountInterface::class),
        $this->container->get(ComponentSourceManager::class),
        $this->container->get(ComponentTreeLoader::class),
        $this->container->get(ModuleHandlerInterface::class),
    ));

    $response = $this->makePublishAllRequest([
      $page_content_identifier => $auto_save_data[$page_content_identifier],
    ]);

    // Confirm that conflict was detected despite the fact that the conflict
    // information was missing during the early validation in the
    // ApiAutoSaveController::validateExpectedAutoSaves().
    $this->assertConflictErrorResponse(
      $response,
      $current_conflict,
      $page_content_identifier,
    );
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
    self::assertArrayHasKey("node:{$node->id()}:en", $auto_save_data);
    self::assertArrayHasKey(\sprintf('%s:global', AssetLibrary::ENTITY_TYPE_ID), $auto_save_data);

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
   * Tests enforcement of global asset library publishing with code components.
   *
   * @todo Adjust this in https://www.drupal.org/project/canvas/issues/3535038
   * @legacy-covers ::validateExpectedAutoSaves
   */
  #[TestWith([TRUE, ["js_component:test-enforce-component", "asset_library:global"], 200, "Successfully published 2 items."])]
  #[TestWith([TRUE, ["js_component:test-enforce-component"], 424])]
  #[TestWith([FALSE, ["js_component:test-enforce-component"], 200, "Successfully published 1 item."])]
  public function testEnforceGlobalAssetPublish(bool $global_asset_library_auto_save_exists, array $auto_save_keys_to_publish, int $expected_status_code, ?string $expected_message = NULL): void {
    $this->setUpCurrentUser(permissions: [
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
      AssetLibrary::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);

    $code_component = JavaScriptComponent::create([
      'machineName' => 'test-enforce-component',
      'name' => 'Test Enforce Component',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
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
    self::assertCount(0, $code_component->getTypedData()->validate());
    $this->assertSame(SAVED_NEW, $code_component->save());

    // Always create an auto-save for the code component, maybe make one for the
    // global asset library.
    $code_component->set('name', 'Updated Component Name');
    $autoSave->saveEntity($code_component);
    if ($global_asset_library_auto_save_exists) {
      $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
      \assert($library instanceof AssetLibrary);
      $library->set('css', [
        'original' => '.test { display: block; }',
        'compiled' => '.test{display:block;}',
      ]);
      $autoSave->saveEntity($library);
    }

    // Construct the request body to publish specified auto-saves, then send it.
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $publish_data = array_combine(
      $auto_save_keys_to_publish,
      \array_map(
        fn (string $auto_save_key) => $auto_save_data[$auto_save_key],
        $auto_save_keys_to_publish
      ),
    );
    $response = $this->makePublishAllRequest($publish_data);

    self::assertSame($expected_status_code, $response->getStatusCode());
    $json = json_decode((string) $response->getContent(), TRUE);
    if ($expected_status_code === 200) {
      \assert(\is_string($expected_message));
      self::assertSame(['message' => $expected_message], $json);
    }
    else {
      \assert(\is_null($expected_message));
      self::assertSame([
        'errors' => [
          [
            'detail' => ErrorCodesEnum::GlobalAssetNotPublished->getMessage(),
            'source' => [
              'pointer' => AssetLibrary::ENTITY_TYPE_ID . ':' . AssetLibrary::GLOBAL_ID,
            ],
            'code' => ErrorCodesEnum::GlobalAssetNotPublished->value,
            'meta' => [
              'entity_type' => AssetLibrary::ENTITY_TYPE_ID,
              'entity_id' => AssetLibrary::GLOBAL_ID,
              'label' => 'Global CSS',
              ApiAutoSaveController::AUTO_SAVE_KEY => AssetLibrary::ENTITY_TYPE_ID . ':' . AssetLibrary::GLOBAL_ID,
            ],
          ],
        ],
      ], $json);
    }
  }

  /**
   * Tests that publishing carries over the auto-saved moderation state.
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

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $auto_save_key = $autoSave->getAutoSaveKey($node);
    $response = $this->makePublishAllRequest([$auto_save_key => $auto_save_data[$auto_save_key]]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => 'Successfully published 1 item.'], json_decode((string) $response->getContent(), TRUE));

    // The `moderation_state` field is computed (states are persisted as
    // `content_moderation_state` entities), but the auto-saved state must
    // still be published: the latest revision must be a draft, while the
    // default revision remains published.
    $node_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node');
    \assert($node_storage instanceof RevisionableStorageInterface);
    $latest_revision_id = $node_storage->getLatestRevisionId((int) $node->id());
    self::assertNotNull($latest_revision_id);
    $latest_revision = $node_storage->loadRevision($latest_revision_id);
    \assert($latest_revision instanceof Node);
    self::assertSame('draft', $latest_revision->get('moderation_state')->value);
    self::assertFalse($latest_revision->isPublished());
    $default_revision = $node_storage->loadUnchanged((string) $node->id());
    \assert($default_revision instanceof Node);
    self::assertTrue($default_revision->isPublished());
  }

  /**
   * Tests publishing behavior for draft, published, and unpublished pages.
   *
   * This test covers different publishing scenarios:
   * - Draft pages (never published): auto-publish when publishing changes
   * - Published pages: preserve status from autosaved entity
   * - Unpublished pages (previously published, now unpublished): preserve status
   *   from autosaved entity, allowing both unpublishing and republishing.
   *
   * @legacy-covers ::post
   */
  public function testPublishingBehaviorForDraftPublishedAndUnpublishedPages(): void {
    $this->setUpCurrentUser(permissions: [
      'bypass node access',
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
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
    $response = $this->makePublishAllRequest([
      $published_node_key => $auto_save_data[$published_node_key],
    ]);
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
    $response = $this->makePublishAllRequest([
      $to_be_unpublished_node_key => $auto_save_data[$to_be_unpublished_node_key],
    ]);
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
    $response = $this->makePublishAllRequest([
      $to_be_republished_node_key => $auto_save_data[$to_be_republished_node_key],
    ]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Verify: Node should be published again with updated title.
    $to_be_republished_node = $node_storage->loadUnchanged($to_be_republished_node_id);
    \assert($to_be_republished_node instanceof NodeInterface);
    self::assertTrue($to_be_republished_node->isPublished());
    self::assertSame('Republished Article', $to_be_republished_node->getTitle());

    // Test Case 4: Draft page auto-publishes.
    // This verifies that draft pages (never published) are automatically
    // published when publishing changes, even if the autosaved entity has
    // status FALSE.
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
    $response = $this->makePublishAllRequest([
      $draft_node_key => $auto_save_data[$draft_node_key],
    ]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Verify: Draft should be auto-published (existing behavior).
    $draft_node = $node_storage->loadUnchanged($draft_node_id);
    \assert($draft_node instanceof NodeInterface);
    self::assertTrue($draft_node->isPublished());
    self::assertSame('Updated Draft Article', $draft_node->getTitle());
  }

  /**
   * Tests access errors for publishing a set of auto-saves with varied access.
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

    // Construct the request body to publish the auto-saved Page.
    $auto_save_data = $autoSave->getAllAutoSaveList(FALSE, FALSE);
    self::assertCount(3, $auto_save_data, 'Only the 3 auto-saves exist that were just created are present.');
    $publish_request_body = $auto_save_data;
    // Publish in a deterministic order, to get predictable error responses.
    ksort($publish_request_body);

    // This test exercises `view` access to `Page` entities; so revoke it from
    // all authenticated users
    Role::load('authenticated')?->revokePermission('access content')->save();

    // 1. Publish request as user that can publish auto-saves, but cannot view
    // Page/Node entities, cannot update Page entities, but CAN update article
    // Node entities:
    // - TRICKY: note this was an artificially constructed request body, because
    //   GET returns nothing.
    // - `canvas_page:4:en`, 'canvas_page:5:en` and `node:4:en` do exist, so no
    //   409.
    // - No `view label` access for any of them: 403.
    $this->setUpCurrentUser(permissions: [
      'edit any article content',
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    self::assertSame([], $this->getAutoSaveStatesFromServer());
    $response = $this->makePublishAllRequest($publish_request_body);
    self::assertSame(403, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');
    self::assertSame([
      'errors' => [
        [
          'detail' => 'An unexpected item was found in the publish request. Please refresh your page and try again.',
          'source' => [
            'pointer' => 'canvas_page:4:en',
          ],
          'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
        ],
        [
          'detail' => 'An unexpected item was found in the publish request. Please refresh your page and try again.',
          'source' => [
            'pointer' => 'canvas_page:5:en',
          ],
          'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
        ],
        [
          'detail' => 'An unexpected item was found in the publish request. Please refresh your page and try again.',
          'source' => [
            'pointer' => 'node:4:en',
          ],
          'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
        ],
      ],
    ], $response_content);

    // 2. Same request, but now with permission to view Page and Node entities:
    // no `update` access for Page entities, so 403 (but different reason).
    $this->setUpCurrentUser(permissions: [
      'access content',
      'edit any article content',
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    // The `GET` response now matches the artificially constructed `POST`
    // request body precisely.
    $available_to_publish = $this->getAutoSaveStatesFromServer();
    ksort($available_to_publish);
    self::assertSame(\array_keys($publish_request_body), \array_keys($available_to_publish));
    self::assertArrayIsIdenticalToArrayOnlyConsideringListOfKeys($available_to_publish, $publish_request_body, ['entity_type', 'entity_id', 'owner', 'langcode', 'label', 'data_hash', 'updated']);
    try {
      $this->makePublishAllRequest($publish_request_body);
      $this->fail('Expected access denied error when publishing a mix of accessible and inaccessible auto-save items.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      $message = $exception->getMessage();
      $this->assertStringStartsWith('Unable to update entities: ', $message);
      $this->assertStringContainsString("'{$inaccessible_page_one->label()}'", $message);
      $this->assertStringContainsString("'{$inaccessible_page_two->label()}'", $message);
      $this->assertStringNotContainsString("'{$accessible_article->label()}'", $message);
    }
  }

  private function assertSiteHomepage(string $path): void {
    self::assertEquals($path, $this->config('system.site')->get('page.front'));
  }

  /**
   * Asserts an unresolved-conflict error response.
   */
  private function assertConflictErrorResponse(Response $response, int|string $expected_conflict_id, string $expected_auto_save_key): void {
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $response_content = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($response_content);
    $this->assertArrayHasKey('errors', $response_content);
    self::assertCount(1, $response_content['errors']);
    $this->assertDataCompliesWithApiSpecification($response_content['errors'][0], 'Error');
    self::assertArrayHasKey('code', $response_content['errors'][0]);
    self::assertSame(ErrorCodesEnum::ItemEntityUpdatedExternally->value, $response_content['errors'][0]['code']);
    self::assertArrayHasKey('meta', $response_content['errors'][0]);
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $response_content['errors'][0]['meta']);
    self::assertSame($expected_conflict_id, $response_content['errors'][0]['meta'][AutoSaveManager::AUTO_SAVE_CONFLICT_KEY]);
    self::assertSame($expected_auto_save_key, $response_content['errors'][0]['meta']['api_auto_save_key']);
  }

}
