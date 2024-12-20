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
use Drupal\experience_builder\Controller\ApiPendingChangesController;
use Drupal\file\Entity\File;
use Drupal\image\ImageStyleInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiPendingChangesController
 * @group experience_builder
 */
final class ApiPendingChangesControllerTest extends KernelTestBase {

  use UserCreationTrait;
  use OpenApiSpecTrait;

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
    (new XBTestSetup())->setup();
    $this->installConfig(['test_user_config', 'experience_builder']);
  }

  public function testApiPendingChangesController(): void {
    $permissions = ['access administration pages'];
    $emptyData = [
      'layout' => [
        'uuid' => '533b26c8-a0f7-49a9-963e-fc7f20478116',
        'nodeType' => 'root',
        'children' => [],
      ],
      'model' => [],
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
    // Full data.
    $account1content = Node::load(1);
    \assert($account1content instanceof NodeInterface);
    $autoSave->save($account1content, $data);
    // Empty data.
    $account2content = Node::load(2);
    \assert($account2content instanceof NodeInterface);
    $this->setCurrentUser($account2);
    $autoSave->save($account2content, $emptyData);
    $request = Request::create(Url::fromRoute('experience_builder.api.autosave_collection')->toString());
    $response = $this->container->get(HttpKernelInterface::class)->handle($request);
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertContains(AutoSaveManager::CACHE_TAG, $response->getCacheableMetadata()->getCacheTags());
    self::assertCount(0, \array_diff($account1->getCacheTags(), $response->getCacheableMetadata()->getCacheTags()));
    self::assertCount(0, \array_diff($account1->getCacheContexts(), $response->getCacheableMetadata()->getCacheContexts()));
    self::assertContains('config:user.settings', $response->getCacheableMetadata()->getCacheTags());
    $content = \json_decode($response->getContent() ?: '{}', TRUE);
    $anonContentIdentifier = \sprintf('node:%d:en', $anonAccountContent->id());
    self::assertEquals([
      'node:1:en',
      'node:2:en',
      $anonContentIdentifier,
    ], \array_keys($content));
    // We don't assert the exact value of these because of clock-drift during
    // the test, asserting their presence is enough.
    \assert(\is_array($content['node:1:en']));
    \assert(\is_array($content['node:2:en']));
    \assert(\is_array($content[$anonContentIdentifier]));
    self::assertArrayHasKey('updated', $content['node:1:en']);
    self::assertArrayHasKey('updated', $content['node:2:en']);
    self::assertArrayHasKey('updated', $content[$anonContentIdentifier]);
    $imageStyle = \Drupal::entityTypeManager()->getStorage('image_style')->load(ApiPendingChangesController::AVATAR_IMAGE_STYLE);
    self::assertInstanceOf(ImageStyleInterface::class, $imageStyle);
    $avatarUrl = $imageStyle->buildUrl($fileUri);
    // Smoke test this is of the expected format.
    self::assertStringContainsString(\sprintf('/styles/%s/public/image-2.jpg', ApiPendingChangesController::AVATAR_IMAGE_STYLE), $avatarUrl);
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
      // @todo Because the client currently doesn't send 'entity_form_fields'
      //   this key is added in
      //   \Drupal\experience_builder\AutoSave\AutoSaveManager::save(). Remove
      //   in https://www.drupal.org/i/3487484.
      'data_hash' => \hash('xxh64', \serialize(array_merge(['entity_form_fields' => []], $data))),
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
      // @todo Because the client currently doesn't send 'entity_form_fields'
      //   this key is added in
      //   \Drupal\experience_builder\AutoSave\AutoSaveManager::save(). Remove
      //   in https://www.drupal.org/i/3487484.
      'data_hash' => \hash('xxh64', \serialize(array_merge(['entity_form_fields' => []], $emptyData))),
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
      // @todo Because the client currently doesn't send 'entity_form_fields'
      //   this key is added in
      //   \Drupal\experience_builder\AutoSave\AutoSaveManager::save(). Remove
      //   in https://www.drupal.org/i/3487484.
      'data_hash' => \hash('xxh64', \serialize(array_merge(['entity_form_fields' => []], $emptyData))),
    ], \array_diff_key($content[$anonContentIdentifier], \array_flip(['updated'])));
    $this->assertDataCompliesWithApiSpecification($content, 'AutoSaveCollection');
  }

}
