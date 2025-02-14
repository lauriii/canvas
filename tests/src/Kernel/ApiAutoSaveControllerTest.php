<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Controller\ApiAutoSaveController;
use Drupal\experience_builder\Controller\ErrorCodesEnum;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\file\Entity\File;
use Drupal\image\ImageStyleInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiAutoSaveController
 * @group experience_builder
 */
final class ApiAutoSaveControllerTest extends KernelTestBase {

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
    'test_user_config',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('system');
    (new XBTestSetup())->setup();
  }

  public function testApiAutoSaveControllerGet(): void {
    $this->installConfig(['test_user_config']);
    $permissions = ['access administration pages'];
    $emptyData = [
      'layout' => [
        [
          'id' => 'content',
          'nodeType' => 'region',
          'name' => 'Content',
          'components' => [],
        ],
      ],
      'model' => [],
      'entity_form_fields' => [],
    ];
    $anonAccountContent = Node::create([
      'type' => 'article',
      'title' => 'Anon, empty',
    ]);
    $anonAccountContent->save();
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $autoSave->save($anonAccountContent, $emptyData);

    // Add user picture field.
    $fileUri = 'public://image-2.jpg';
    \Drupal::service(FileSystemInterface::class)->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    $picture = File::create([
      'uri' => $fileUri,
      'status' => TRUE,
    ]);

    $account1 = $this->createUser($permissions, values: ['user_picture' => $picture]);
    self::assertInstanceOf(AccountInterface::class, $account1);

    $account2 = $this->createUser($permissions);
    self::assertInstanceOf(AccountInterface::class, $account2);
    $this->setCurrentUser($account1);
    $sampleData = \file_get_contents(\dirname(__DIR__, 3) . '/ui/tests/fixtures/layout-default.json');
    self::assertNotFalse($sampleData);
    $data = \json_decode($sampleData, TRUE);
    $data += ['entity_form_fields' => []];
    // Full data.
    $account1content = Node::load(1);
    \assert($account1content instanceof NodeInterface);
    $autoSave->save($account1content, $data);
    // Save a draft of the page template.
    $template = PageTemplate::createFromBlockLayout('stark')->enable();
    $template->save();
    $templateData = [
      'layout' => [
        [
          "components" => [
            [
              "nodeType" => "component",
              "slots" => [],
              "type" => "block.page_title_block",
              "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
            ],
          ],
          "name" => "Highlighted",
          "nodeType" => "region",
          "id" => "highlighted",
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
    $autoSave->save($template, $templateData);
    // Empty data.
    $account2content = Node::load(2);
    \assert($account2content instanceof NodeInterface);
    $this->setCurrentUser($account2);
    $autoSave->save($account2content, $emptyData);
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
    $autoSave->save($code_component, ['dummy' => 'js_component: auto-save data is not validated']);
    $library = AssetLibrary::create([
      'id' => 'global',
      'label' => 'Test',
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'js' => [
        'original' => 'console.log( "Test" )',
        'compiled' => 'console.log("Test")',
      ],
    ]);
    $this->assertSame(SAVED_NEW, $library->save());
    $autoSave->save($library, ['dummy' => 'xb_asset_library: auto-save data is not validated']);
    $request = Request::create(Url::fromRoute('experience_builder.api.autosave.get')->toString());
    $response = $this->request($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertContains(AutoSaveManager::CACHE_TAG, $response->getCacheableMetadata()->getCacheTags());
    self::assertCount(0, \array_diff($account1->getCacheTags(), $response->getCacheableMetadata()->getCacheTags()));
    self::assertCount(0, \array_diff($account1->getCacheContexts(), $response->getCacheableMetadata()->getCacheContexts()));
    self::assertContains('config:user.settings', $response->getCacheableMetadata()->getCacheTags());
    $content = \json_decode($response->getContent() ?: '{}', TRUE);
    $anonContentIdentifier = \sprintf('node:%d:en', $anonAccountContent->id());
    $pageTemplateId = PageTemplate::PLUGIN_ID . ':stark';
    self::assertEquals([
      'js_component:test_code',
      'node:1:en',
      'node:2:en',
      $anonContentIdentifier,
      $pageTemplateId,
      'xb_asset_library:global',
    ], \array_keys($content));
    // We don't assert the exact value of these because of clock-drift during
    // the test, asserting their presence is enough.
    \assert(\is_array($content['node:1:en']));
    \assert(\is_array($content['node:2:en']));
    \assert(\is_array($content[$pageTemplateId]));
    \assert(\is_array($content[$anonContentIdentifier]));
    \assert(\is_array($content['js_component:test_code']));
    \assert(\is_array($content['xb_asset_library:global']));
    self::assertArrayHasKey('updated', $content['node:1:en']);
    self::assertArrayHasKey('updated', $content['node:2:en']);
    self::assertArrayHasKey('updated', $content[$anonContentIdentifier]);
    self::assertArrayHasKey('updated', $content[$pageTemplateId]);
    self::assertArrayHasKey('updated', $content['js_component:test_code']);
    self::assertArrayHasKey('updated', $content['xb_asset_library:global']);
    $imageStyle = \Drupal::entityTypeManager()->getStorage('image_style')->load(ApiAutoSaveController::AVATAR_IMAGE_STYLE);
    self::assertInstanceOf(ImageStyleInterface::class, $imageStyle);
    $avatarUrl = $imageStyle->buildUrl($fileUri);
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
      'label' => $account1content->label(),
      'data_hash' => \hash('xxh64', \serialize($data)),
    ], \array_diff_key($content['node:1:en'], \array_flip(['updated'])));
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
      'data_hash' => \hash('xxh64', \serialize($emptyData)),
    ], \array_diff_key($content['node:2:en'], \array_flip(['updated'])));
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
      'data_hash' => \hash('xxh64', \serialize($emptyData)),
    ], \array_diff_key($content[$anonContentIdentifier], \array_flip(['updated'])));
    self::assertEquals([
      'langcode' => NULL,
      'entity_type' => $template->getEntityTypeId(),
      'entity_id' => $template->id(),
      'owner' => [
        'id' => $account1->id(),
        'name' => $account1->getDisplayName(),
        'avatar' => $avatarUrl,
        'uri' => $account1->toUrl()->toString(),
      ],
      'label' => 'Stark global template',
      'data_hash' => \hash('xxh64', \serialize($templateData)),
    ], \array_diff_key($content[$pageTemplateId], \array_flip(['updated'])));
    self::assertEquals([
      'langcode' => NULL,
      'entity_type' => $code_component->getEntityTypeId(),
      'entity_id' => $code_component->id(),
      'owner' => [
        'id' => $account2->id(),
        'name' => $account2->getDisplayName(),
        'avatar' => NULL,
        'uri' => $account2->toUrl()->toString(),
      ],
      'label' => $code_component->label(),
      'data_hash' => \hash('xxh64', \serialize(['dummy' => 'js_component: auto-save data is not validated'])),
    ], \array_diff_key($content['js_component:test_code'], \array_flip(['updated'])));
    self::assertEquals([
      'langcode' => NULL,
      'entity_type' => $library->getEntityTypeId(),
      'entity_id' => $library->id(),
      'owner' => [
        'id' => $account2->id(),
        'name' => $account2->getDisplayName(),
        'avatar' => NULL,
        'uri' => $account2->toUrl()->toString(),
      ],
      'label' => $library->label(),
      'data_hash' => \hash('xxh64', \serialize(['dummy' => 'xb_asset_library: auto-save data is not validated'])),
    ], \array_diff_key($content['xb_asset_library:global'], \array_flip(['updated'])));
    $this->assertDataCompliesWithApiSpecification($content, 'AutoSaveCollection');
  }

  public static function providerCases(): iterable {
    yield 'without_global' => [FALSE];
    yield 'with_global' => [TRUE];
  }

  /**
   * @dataProvider providerCases
   */
  public function testApiAutoSaveControllerPost(bool $withGlobal = FALSE): void {
    $this->setUpImages();
    $entity_type_manager = $this->container->get('entity_type.manager');
    $code_component_storage = $entity_type_manager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $library_storage = $entity_type_manager->getStorage(AssetLibrary::ENTITY_TYPE_ID);
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    $this->setUpCurrentUser(permissions: ['access administration pages']);
    $this->assertNoAutoSaveData();
    $node1 = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'field_hero' => $this->referencedImage,
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'props' => '{}',
      ],
    ]);
    $node1_original_title = (string) $node1->getTitle();
    self::assertSame(SAVED_NEW, $node1->save());
    $this->assertNodeValues($node1, [], [], $node1_original_title);

    $node2 = Node::create([
      'type' => 'article',
      'title' => 'Are leg-warmers due for a comeback? These young designers are betting on it',
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'props' => '{}',
      ],
    ]);
    self::assertSame(SAVED_NEW, $node2->save());
    $node2_original_title = (string) $node2->getTitle();
    $this->assertNodeValues($node2, [], [], $node2_original_title);

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

    $library = AssetLibrary::create([
      'id' => 'global',
      'label' => 'Original AssetLibrary name',
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'js' => [
        'original' => 'console.log( "Test" )',
        'compiled' => 'console.log("Test")',
      ],
    ]);
    $this->assertSame(SAVED_NEW, $library->save());

    $validClientJson = $this->getValidClientJson(FALSE);

    // Add some global elements.
    if ($withGlobal) {
      $template = PageTemplate::createFromBlockLayout('stark');
      $template->enable()->save();
      $regions_without_breadcrumbs = [
        'sidebar_first',
        'sidebar_second',
        'primary_menu',
        'secondary_menu',
        'footer',
        'highlighted',
        'help',
        'page_top',
        'page_bottom',
      ];
      foreach ($regions_without_breadcrumbs as $region) {
        $validClientJson['layout'][] = [
          "components" => [],
          "name" => $region,
          "nodeType" => "region",
          "id" => $region,
        ];
      }
      $validClientJson['layout'][] = [
        "components" => [
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.page_title_block",
            "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
          ],
        ],
        "name" => "Header",
        "nodeType" => "region",
        "id" => "header",
      ];
      $validClientJson['model'] += [
        "c3f3c22c-c22e-4bb6-ad16-635f069148e4" => [
          "label" => "Page title",
          "label_display" => "0",
          "provider" => "core",
        ],
      ];
    }

    // Auto-save node 1.
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.layout.post', [
      'entity_type' => 'node',
      'entity' => $node1->id(),
    ])->toString(), method: 'POST', server: [
      'CONTENT_TYPE' => 'application/json',
    ], content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Auto-save node 2 with only the heading.
    unset($validClientJson['model'][self::TEST_IMAGE_UUID]);
    unset($validClientJson['layout'][0]['components'][1]);
    // And an invalid prop.
    $validClientJson['model'][self::TEST_HEADING_UUID]['resolved']['style'] = 'flared';

    // This is testing ApiAutoSaveController, not auto-saving itself. So use the
    // auto-save manager directly.
    $autoSave->save($node2, $validClientJson);

    $invalid_client_code_component_data = $code_component->normalizeForClientSide()->values;
    $invalid_client_code_component_data['name'] = 'New name';
    $invalid_client_code_component_data['props'] = [
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
    ];
    $autoSave->save($code_component, $invalid_client_code_component_data);

    $invalid_library_data = $library->normalizeForClientSide()->values;
    $invalid_library_data['label'] = 'New label';
    $invalid_library_data['css']['original'] = NULL;
    $autoSave->save($library, $invalid_library_data);

    $response = $this->makePublishAllRequest();
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $suffix = '';
    if (\version_compare(\Drupal::VERSION, '11.1.2', '>=')) {
      // The format of component violation messages changed in Drupal 11.1.2.
      // @see https://drupal.org/i/3462700
      $suffix = '. The provided value is: "flared".';
    }
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
        'autosave_key' => $autoSave->getAutoSaveKey($code_component),
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
        'autosave_key' => $autoSave->getAutoSaveKey($code_component),
      ],
    ];
    $errors[] = [
      'detail' => 'Does not have a value in the enumeration ["primary","secondary"]' . $suffix,
      'source' => [
        'pointer' => 'model.' . self::TEST_HEADING_UUID . '.style',
      ],
      'meta' => [
        'entity_type' => 'node',
        'entity_id' => $node2->id(),
        // The label should not be updated if model validation failed.
        'label' => $node2_original_title,
        'autosave_key' => $autoSave->getAutoSaveKey($node2),
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
        'autosave_key' => $autoSave->getAutoSaveKey($library),
      ],
    ];

    self::assertEquals($errors, $json['errors']);
    // Ensure that neither the valid nor invalid node gets updated if one is
    // invalid.
    $this->assertNodeValues($node1, [], [], $node1_original_title);
    $this->assertNodeValues($node2, [], [], $node2_original_title);
    // Ensure the code component is not updated.
    $this->assertEquals('Original JavaScriptComponent name', $code_component_storage->loadUnchanged($code_component->id())?->label());
    $this->assertEquals('Original AssetLibrary name', $library_storage->loadUnchanged($library->id())?->label());

    if ($withGlobal) {
      // Note: no additional error appears for the invalid auto-saved layout for
      // the PageTemplate, because missing regions are automatically added from
      // the active/stored PageTemplate.
      // @see \Drupal\experience_builder\Entity\PageTemplate::forAutoSaveData()
      $template = PageTemplate::load('stark');
      self::assertInstanceOf(PageTemplate::class, $template);
      $trees = iterator_to_array($template->getComponentTrees());
      self::assertArrayNotHasKey('header', $trees);
      self::assertInstanceOf(ComponentTreeItem::class, $trees['highlighted']);
    }

    // Fix the errors.
    $validClientJson['model'][self::TEST_HEADING_UUID]['resolved']['style'] = 'primary';
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.layout.post', [
      'entity_type' => 'node',
      'entity' => $node2->id(),
    ])->toString(), method: 'POST', server: [
      'CONTENT_TYPE' => 'application/json',
    ], content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $updated_code_component_data = $code_component->normalizeForClientSide()->values;
    $updated_code_component_data['name'] = 'New new JavaScriptComponent name';
    $autoSave->save($code_component, $updated_code_component_data);
    $updated_library_data = $library->normalizeForClientSide()->values;
    $updated_library_data['label'] = 'New new AssetLibrary label';
    $autoSave->save($library, $updated_library_data);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $node1_auto_save_key = 'node:' . $node1->id() . ':en';
    self::assertArrayHasKey($node1_auto_save_key, $auto_save_data);

    // Make publish requests that have missing, extra, and out-dated auto-save
    // information.
    $missing_auto_save_data = $auto_save_data;
    unset($missing_auto_save_data[$node1_auto_save_key]);
    $response = $this->makePublishAllRequest($missing_auto_save_data);
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
      [
        'detail' => ErrorCodesEnum::MissingItemInPublishRequest->getMessage(),
        'source' => [
          'pointer' => $node1_auto_save_key,
        ],
        'code' => ErrorCodesEnum::MissingItemInPublishRequest->value,
        'meta' => [
          'entity_type' => 'node',
          'entity_id' => $node1->id(),
          'label' => $node1->label(),
          'autosave_key' => $autoSave->getAutoSaveKey($node1),
        ],
      ],
      ],
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

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
            'label' => $node1->label(),
            'autosave_key' => $autoSave->getAutoSaveKey($node1),
          ],
        ],
      ],
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

    $response = $this->makePublishAllRequest();
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => \sprintf('Successfully published %d items.', $withGlobal ? 5 : 4)], $json);

    $this->assertValidJsonUpdateNode($node1, FALSE);
    $this->assertNodeValues(
      $node2,
      [
        'sdc.experience_builder.heading',
        'block.system_branding_block',
      ],
      \array_intersect_key($this->getValidConvertedInputs(), \array_flip([self::TEST_HEADING_UUID, self::TEST_BLOCK])),
      'The updated title.'
    );

    $this->assertSame('New new JavaScriptComponent name', $code_component_storage->loadUnchanged($code_component->id())?->label());
    $this->assertSame('New new AssetLibrary label', $library_storage->loadUnchanged($library->id())?->label());

    if ($withGlobal) {
      $template = PageTemplate::load('stark');
      self::assertInstanceOf(PageTemplate::class, $template);
      $trees = iterator_to_array($template->getComponentTrees());
      self::assertInstanceOf(ComponentTreeItem::class, $trees['header']);
      self::assertArrayNotHasKey('highlighted', $trees);
    }

    // Ensure that after the nodes have been published their auto-save data is
    // removed.
    $this->assertNoAutoSaveData();
  }

  protected function assertNoAutoSaveData(): void {
    $response = $this->makePublishAllRequest([]);
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    self::assertEquals(['message' => 'No items to publish.'], $json);
  }

  protected function makePublishAllRequest(?array $data = NULL): JsonResponse {
    if (is_null($data)) {
      $data = $this->getAutoSaveStatesFromServer();
    }
    $controller = \Drupal::service(ApiAutoSaveController::class);
    $request = Request::create(
      Url::fromRoute('experience_builder.api.autosave.post')->toString(),
      content: (string) json_encode($data)
    );
    return $controller->post($request);
  }

  protected function getAutoSaveStatesFromServer(): array {
    $auto_save_controller = \Drupal::service(ApiAutoSaveController::class);
    $response = $auto_save_controller->get();
    assert($response instanceof JsonResponse);
    $content = $response->getContent();
    assert(is_string($content));
    $auto_saves = json_decode($content, TRUE);
    return $auto_saves;
  }

}
