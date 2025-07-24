<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Controller\ApiAutoSaveController;
use Drupal\experience_builder\Controller\ErrorCodesEnum;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\StagedConfigUpdate;
use Drupal\image\ImageStyleInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\experience_builder\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiAutoSaveController
 * @group experience_builder
 * @group #slow
 */
final class ApiAutoSaveControllerTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use AutoSaveManagerTestTrait;
  use AutoSaveRequestTestTrait;
  use UserCreationTrait;
  use OpenApiSpecTrait;
  use BlockCreationTrait;
  use RequestTrait;
  use XBFieldTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'path_alias',
    'path',
    'test_user_config',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('system');
    $this->installEntitySchema('path_alias');
    (new XBTestSetup())->setup();
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
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
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
          "type" => "block.page_title_block@62af221149ae4887",
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
      'id' => 'xb_set_homepage',
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

    $request = Request::create(Url::fromRoute('experience_builder.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertContains(AutoSaveManager::CACHE_TAG, $response->getCacheableMetadata()->getCacheTags());
    self::assertCount(0, \array_diff($account1->getCacheTags(), $response->getCacheableMetadata()->getCacheTags()));
    self::assertCount(0, \array_diff($account1->getCacheContexts(), $response->getCacheableMetadata()->getCacheContexts()));
    self::assertContains('config:user.settings', $response->getCacheableMetadata()->getCacheTags());
    $content = \json_decode($response->getContent() ?: '{}', TRUE);
    $anonContentIdentifier = \sprintf('node:%d:en', $anonAccountContent->id());
    self::assertEquals([
      'js_component:test_code',
      'node:1:en',
      'node:2:en',
      $anonContentIdentifier,
      'page_region:stark.highlighted',
      'staged_config_update:xb_set_homepage',
      'xb_asset_library:global',
    ], \array_keys($content));
    // We don't assert the exact value of these because of clock-drift during
    // the test, asserting their presence is enough.
    \assert(\is_array($content['node:1:en']));
    \assert(\is_array($content['node:2:en']));
    \assert(\is_array($content['page_region:stark.highlighted']));
    \assert(\is_array($content[$anonContentIdentifier]));
    \assert(\is_array($content['js_component:test_code']));
    \assert(\is_array($content['staged_config_update:xb_set_homepage']));
    \assert(\is_array($content['xb_asset_library:global']));
    self::assertArrayHasKey('updated', $content['node:1:en']);
    self::assertArrayHasKey('updated', $content['node:2:en']);
    self::assertArrayHasKey('updated', $content[$anonContentIdentifier]);
    self::assertArrayHasKey('updated', $content['page_region:stark.highlighted']);
    self::assertArrayHasKey('updated', $content['js_component:test_code']);
    self::assertArrayHasKey('updated', $content['staged_config_update:xb_set_homepage']);
    self::assertArrayHasKey('updated', $content['xb_asset_library:global']);
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
      // @see \Drupal\experience_builder\AutoSave\AutoSaveTempStoreFactory::get
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
    ], \array_diff_key($content['staged_config_update:xb_set_homepage'], \array_flip(['updated', 'data_hash'])));
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
    ], \array_diff_key($content['xb_asset_library:global'], \array_flip(['updated', 'data_hash'])));
    $this->assertDataCompliesWithApiSpecification($content, 'AutoSaveCollection');
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
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $autoSave->saveEntity($article);

    $page = Page::load(2);
    \assert($page instanceof Page);
    // Trigger a new hash.
    $page->set('title', 'New title');
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
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
          "type" => "block.page_title_block@62af221149ae4887",
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
      'id' => 'xb_set_homepage',
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
    assert($user instanceof AccountInterface);
    $this->setCurrentUser($user);

    $request = Request::create(Url::fromRoute('experience_builder.api.auto-save.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'config:experience_builder.js_component.test_code',
      'config:experience_builder.page_region.stark.highlighted',
      'config:system.site',
      'user:0',
      'config:user.settings',
      AutoSaveManager::CACHE_TAG,
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());
    self::assertSame(['user.permissions'], $response->getCacheableMetadata()->getCacheContexts());
    $content = \json_decode($response->getContent() ?: '{}', TRUE);
    $anonContentIdentifier = \sprintf('node:%d:en', $article->id());
    // Assert we get the keys of auto-save data that we can view (even if maybe
    // we aren't allowed to update).
    // We can view code components, contents and staged config updates
    // but not the page region entity.
    self::assertEquals([
      'js_component:test_code',
      $anonContentIdentifier,
      'staged_config_update:xb_set_homepage',
      'xb_page:2:en',
    ], \array_keys($content));
  }

  public static function providerCases(): iterable {
    yield 'unauthorized, without global' => [FALSE, FALSE, "The 'publish auto-saves' permission is required."];
    yield 'authorized, without global' => [TRUE, FALSE, NULL];
    yield 'unauthorized, with global' => [FALSE, FALSE, "The 'publish auto-saves' permission is required."];
    yield 'authorized, with global' => [TRUE, TRUE, NULL];
  }

  /**
   * @covers ::post
   * @dataProvider providerCases
   */
  public function testPost(bool $authorized, bool $withGlobal, ?string $expected_403_message): void {
    $this->setUpImages();
    $this->assertSiteHomepage('/user/login');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $code_component_storage = $entity_type_manager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $library_storage = $entity_type_manager->getStorage(AssetLibrary::ENTITY_TYPE_ID);
    $page_storage = $entity_type_manager->getStorage(Page::ENTITY_TYPE_ID);
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    $permissions = [
      PageRegion::ADMIN_PERMISSION,
      // @todo 'bypass node access' is a very powerful permission and could have
      //   side effects. Determine a way to give the user just the access they
      //   need in https://drupal.org/i/3535354.
      'bypass node access',
      Page::EDIT_PERMISSION,
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
    $node1 = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'status' => FALSE,
      'field_hero' => [
        'target_id' => $this->referencedImage->id(),
        'alt' => 'A man and a women high five each other in a creepy fashion after finding a use for an old toothbrush',
      ],
    ]);
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
    ]);
    $this->assertSame(SAVED_NEW, $code_component->save());

    $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($library instanceof AssetLibrary);
    $originalGlobalLibraryName = $library->label();

    $validClientJson = $this->getValidClientJson($node1, FALSE);
    $page = Page::create([
      'title' => 'Test page',
      'status' => FALSE,
      'components' => [],
    ]);
    $this->assertSame(SAVED_NEW, $page->save());
    $this->assertFalse($page->isPublished());
    // Trigger a new hash for auto-save.
    $page->set('title', 'The updated title.');
    $autoSave->saveEntity($page);

    $staged_set_homepage = StagedConfigUpdate::create([
      'id' => 'xb_set_homepage',
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
            "type" => "block.page_title_block@62af221149ae4887",
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
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.layout.post', [
      'entity_type' => 'node',
      'entity' => $node1->id(),
    ])->toString(), method: 'POST', server: [
      'CONTENT_TYPE' => 'application/json',
    ], content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Auto-save node 2 with only the heading and an invalid prop.
    $node2->set('field_xb_demo', [
      [
        'uuid' => self::TEST_HEADING_UUID,
        'component_id' => 'sdc.xb_test_sdc.heading',
        'component_version' => '9616e3c4ab9b4fce',
        'inputs' => [
          'style' => 'flared',
          'element' => 'h3',
          'text' => '',
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
      $this->assertSame("Unable to update entities: 'New name', 'Update the front page', 'New label'.", $exception->getMessage());
      $this->assertSame([
        'config:experience_builder.js_component.test-component',
        AutoSaveManager::CACHE_TAG,
        'config:system.site',
        'config:experience_builder.xb_asset_library.global',
      ], $exception->getCacheTags());
      $this->assertSame(['user.permissions'], $exception->getCacheContexts());
    }
    // Grant that permission.
    $this->setUpCurrentUser(permissions: [
      ...$permissions,
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
      JavaScriptComponent::ADMIN_PERMISSION,
      'administer site configuration',
    ]);

    $response = $this->makePublishAllRequest();
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $errors[] = [
      'detail' => 'Unable to find class/interface "unknown" specified in the prop "mixed_up_prop" for the component "experience_builder:test-component".',
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
      'detail' => "'enum' is an unknown key because props.mixed_up_prop.type is unknown (see config schema type experience_builder.json_schema.prop.*).",
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
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList::unsetEmptyProps()
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

    self::assertEquals($errors, $json['errors']);
    // Ensure none of the entities are updated if one is invalid.
    $this->assertNodeValues($node1, [], [], ['title' => $node1_original_title, 'status' => '0']);
    $this->assertNodeValues($node2, [], [], ['title' => $node2_original_title, 'status' => '1']);
    $this->assertNotNull($code_component->id());
    $this->assertEquals('Original JavaScriptComponent name', $code_component_storage->loadUnchanged($code_component->id())?->label());
    $this->assertNotNull($library->id());
    $this->assertEquals($originalGlobalLibraryName, $library_storage->loadUnchanged($library->id())?->label());
    $this->assertNotNull($page->id());
    $this->assertSame('Test page', $page_storage->loadUnchanged($page->id())?->label());
    $this->assertSiteHomepage('/user/login');

    if ($withGlobal) {
      // Note: no additional error appears for the invalid auto-saved layout for
      // the PageTemplate, because missing regions are automatically added from
      // the active/stored PageTemplate.
      // @see \Drupal\experience_builder\Entity\PageRegion::forAutoSaveData()
      $page_region = PageRegion::load('stark.header');
      self::assertInstanceOf(PageRegion::class, $page_region);
      self::assertSame([], $page_region->getComponentTree()->getValue());
    }

    // Fix the errors.
    $validClientJson['model'][self::TEST_HEADING_UUID]['resolved']['style'] = 'primary';
    // Auto-save node 2 with only the heading.
    unset($validClientJson['model'][self::TEST_IMAGE_UUID]);
    unset($validClientJson['layout'][0]['components'][1]);
    unset($validClientJson['autoSaves']);
    $validClientJson += $this->getClientAutoSaves([$node2]);
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.layout.post', [
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
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

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
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

    // Publish only node 1.
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $auto_save_count = \count($auto_save_data);
    $node1_auto_save = [$node1_auto_save_key => $auto_save_data[$node1_auto_save_key]];
    $response = $this->makePublishAllRequest($node1_auto_save);
    $json = json_decode($response->getContent() ?: '', TRUE);
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
    $this->assertSame('Test page', $page_storage->loadUnchanged($page->id())->label());
    $this->assertSiteHomepage('/user/login');

    // Try publishing something with a field change that we don't have access to.
    $this->container->get('module_installer')->install(['xb_test_field_access']);
    try {
      $this->makePublishAllRequest();
      $this->fail('Expected access denied error after field check on publishing auto-saved changes.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      // Access denied as expected, the title listed must be the new one.
      $this->assertSame('Unable to update field title for entity "The updated title.".', $exception->getMessage());
    }
    $this->container->get('module_installer')->uninstall(['xb_test_field_access']);

    $response = $this->makePublishAllRequest();
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => \sprintf('Successfully published %d items.', $auto_save_count - 1)], $json);

    $this->assertSiteHomepage('/home');

    $this->assertNodeValues(
      $node2,
      [
        'sdc.xb_test_sdc.heading',
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

    $this->assertNotNull($page->id());
    $page = $page_storage->loadUnchanged($page->id());
    assert($page instanceof Page);
    $this->assertTrue($page->isPublished());
    $this->assertSame('The updated title.', $page->label());
    $this->assertSame($page->getRevisionUserId(), $user->id());

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
  }

  /**
   * @covers ::delete
   */
  public function testDelete(): void {
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    self::assertCount(0, $auto_save_data);

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Article for Delete',
    ]);
    $node->save();

    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    // Update something so the auto-save entry generates a different hash.
    $node->setTitle('Updated Title');
    $autoSave->saveEntity($node);

    $global = AssetLibrary::load('global');
    \assert($global instanceof AssetLibrary);
    $global->set('label', $this->randomMachineName());
    $autoSave->saveEntity($global);

    // Verify auto-save data exists.
    // Set up a user that can access the XB UI, and has 'view label' access to
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
    $url = Url::fromRoute('experience_builder.api.auto-save.delete', [
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
    $account = $this->createUser([AutoSaveManager::PUBLISH_PERMISSION]);
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

    // Nonsense CSRF header
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

    $asset_library_url = Url::fromRoute('experience_builder.api.auto-save.delete', [
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
   * @covers ::validateExpectedAutoSaves
   * @testWith [true, ["js_component:test-enforce-component", "xb_asset_library:global"], 200, "Successfully published 2 items."]
   *           [true, ["js_component:test-enforce-component"], 424]
   *           [false, ["js_component:test-enforce-component"], 200, "Successfully published 1 item."]
   * @todo Adjust this in https://www.drupal.org/project/experience_builder/issues/3535038
   */
  public function testEnforceGlobalAssetPublish(bool $global_asset_library_auto_save_exists, array $auto_save_keys_to_publish, int $expected_status_code, ?string $expected_message = NULL): void {
    $this->setUpCurrentUser(permissions: [
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
      JavaScriptComponent::ADMIN_PERMISSION,
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
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
      array_map(
        fn (string $auto_save_key) => $auto_save_data[$auto_save_key],
        $auto_save_keys_to_publish
      ),
    );
    $response = $this->makePublishAllRequest($publish_data);

    self::assertSame($expected_status_code, $response->getStatusCode());
    $json = json_decode($response->getContent() ?: '', TRUE);
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

  private function assertSiteHomepage(string $path): void {
    self::assertEquals($path, $this->config('system.site')->get('page.front'));
  }

}
