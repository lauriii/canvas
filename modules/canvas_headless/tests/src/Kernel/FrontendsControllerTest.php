<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Controller\FrontendsController;
use Drupal\canvas_headless\FrontendManager;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth\Entity\Oauth2ScopeEntityInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the headless frontend configuration controller.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class FrontendsControllerTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setAlias('test.frontend_storage', 'config.storage.active')->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * Tests reading and replacing the ordered frontend list.
   */
  public function testGetAndPatch(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);

    $initial = self::decode($controller->get());
    self::assertSame([], $initial['frontends']);
    self::assertIsString($initial['frontendUrlsHash']);

    $response = $controller->patch(self::request([
      'frontendUrlsHash' => $initial['frontendUrlsHash'],
      'frontends' => [
        ['url' => 'https://first.example/app'],
        ['url' => 'http://127.0.0.1:3000'],
      ],
    ]));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      ['url' => 'https://first.example/app', 'components' => []],
      ['url' => 'http://127.0.0.1:3000', 'components' => []],
    ], self::decode($response)['frontends']);
    self::assertSame(self::decode($response), self::decode($controller->get()));

    $this->config('canvas_headless.settings')
      ->set('frontends', [
        ['url' => 'https://first.example/app', 'components' => ['js.heroBanner']],
        ['url' => 'http://127.0.0.1:3000', 'components' => ['js.card']],
      ])
      ->save();

    $response = $controller->patch(self::request([
      'frontendUrlsHash' => self::decode($controller->get())['frontendUrlsHash'],
      'frontends' => [
        ['url' => 'http://127.0.0.1:3000'],
        ['url' => 'https://first.example/app'],
      ],
    ]));
    self::assertSame([
      ['url' => 'http://127.0.0.1:3000', 'components' => ['js.card']],
      ['url' => 'https://first.example/app', 'components' => ['js.heroBanner']],
    ], self::decode($response)['frontends']);
  }

  /**
   * Tests malformed, invalid, and duplicate entries.
   */
  public function testInvalidPayloads(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);

    $response = $controller->patch(self::request(['frontends' => 'not a list']));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

    $response = $controller->patch(self::request([
      'frontendUrlsHash' => self::decode($controller->get())['frontendUrlsHash'],
      'frontends' => [['url' => 'https://example.com/']],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

    $response = $controller->patch(self::request([
      'frontendUrlsHash' => self::decode($controller->get())['frontendUrlsHash'],
      'frontends' => [
        ['url' => 'https://example.com'],
        ['url' => 'HTTPS://EXAMPLE.COM:443'],
      ],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame([
      'error' => '"HTTPS://EXAMPLE.COM:443" is already in the list.',
    ], self::decode($response));
    foreach (['https://example.com/', 'javascript:alert(1)', 'https://user@example.com', 'https://example.com?query=1', 'https://example.com#fragment', 'https://example.com/app/../other'] as $url) {
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $controller->post(self::request(['url' => $url]))->getStatusCode());
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $controller->delete(self::request(['url' => $url]))->getStatusCode());
    }
    foreach ([[], ['url' => 1], ['url' => 'https://example.com', 'default' => NULL], ['url' => 'https://example.com', 'default' => 'true']] as $payload) {
      self::assertSame(Response::HTTP_BAD_REQUEST, $controller->post(self::request($payload))->getStatusCode());
    }
    foreach ([[], ['url' => 1], ['url' => 'https://example.com', 'confirmed' => FALSE]] as $payload) {
      self::assertSame(Response::HTTP_BAD_REQUEST, $controller->delete(self::request($payload))->getStatusCode());
    }
  }

  /**
   * Tests item mutations, canonical no-ops, defaults, and association retention.
   */
  public function testItemMutations(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);
    $manager = $this->container->get(FrontendManager::class);
    $added = self::decode($controller->post(self::request(['url' => 'HTTPS://FIRST.EXAMPLE:443'])));
    self::assertSame(['url' => 'https://first.example', 'changed' => TRUE], $added);
    $first = FrontendUrl::fromConfig('https://first.example');
    self::assertNotNull($first);
    $manager->replaceComponents($first, ['js.hero']);
    $before = $manager->get();
    $duplicate = self::decode($controller->post(self::request(['url' => 'https://first.example'])));
    self::assertSame(['url' => 'https://first.example', 'changed' => FALSE], $duplicate);
    self::assertSame($before, $manager->get());

    $manager->add('https://second.example');
    $manager->add('https://third.example');
    $response = self::decode($controller->post(self::request(['url' => 'HTTPS://SECOND.EXAMPLE:443', 'default' => TRUE])));
    self::assertSame(['url' => 'https://second.example', 'changed' => TRUE], $response);
    $frontends = $manager->get()['frontends'];
    self::assertSame(['https://second.example', 'https://first.example', 'https://third.example'], array_column($frontends, 'url'));
    self::assertSame(['js.hero'], $frontends[1]['components']);
    self::assertFalse($manager->add('https://second.example', TRUE)['changed']);
    $manager->add('https://fourth.example', TRUE);
    self::assertSame(['https://fourth.example', 'https://second.example', 'https://first.example', 'https://third.example'], array_column($manager->get()['frontends'], 'url'));

    $removed = self::decode($controller->delete(self::request(['url' => 'HTTPS://FOURTH.EXAMPLE:443'])));
    self::assertSame(['url' => 'https://fourth.example', 'removed' => TRUE, 'newDefault' => 'https://second.example'], $removed);
    self::assertSame(['https://second.example', 'https://first.example', 'https://third.example'], array_column($manager->get()['frontends'], 'url'));
    $missing = self::decode($controller->delete(self::request(['url' => 'https://absent.example'])));
    self::assertSame(['url' => 'https://absent.example', 'removed' => FALSE], $missing);
    self::assertSame(['url' => 'https://first.example', 'removed' => TRUE], $manager->remove('https://first.example'));
    $manager->add('https://first.example');
    self::assertSame([], $manager->get()['frontends'][2]['components']);
    // An in-flight synchronization must not recreate a removed frontend.
    $manager->remove('https://first.example');
    $manager->replaceComponents($first, ['js.hero']);
    self::assertCount(2, $manager->get()['frontends']);
    $manager->remove('https://second.example');
    self::assertSame(['url' => 'https://third.example', 'removed' => TRUE, 'newDefault' => NULL], $manager->remove('https://third.example'));
    self::assertSame([], $manager->get()['frontends']);
  }

  /**
   * Tests stale UI writes and fresh associations on an unchanged URL-list hash.
   */
  public function testStaleSaveProtection(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);
    $manager = $this->container->get(FrontendManager::class);
    $manager->add('https://first.example');
    $loaded = [
      'frontends' => [['url' => 'https://first.example']],
      'frontendUrlsHash' => $manager->get()['frontendUrlsHash'],
    ];
    $first = FrontendUrl::fromConfig('https://first.example');
    self::assertNotNull($first);
    $manager->replaceComponents($first, ['js.new']);
    self::assertSame($loaded['frontendUrlsHash'], $manager->get()['frontendUrlsHash']);
    $saved = $controller->patch(self::request($loaded));
    self::assertSame(Response::HTTP_OK, $saved->getStatusCode());
    self::assertSame(['js.new'], self::decode($saved)['frontends'][0]['components']);

    $manager->add('https://second.example');
    $latest = $manager->get();
    $stale = $controller->patch(self::request($loaded));
    self::assertSame(Response::HTTP_CONFLICT, $stale->getStatusCode());
    self::assertSame($latest, $manager->get());
    // Reordering changes the hash even though membership is unchanged.
    $manager->add('https://second.example', TRUE);
    self::assertSame(Response::HTTP_CONFLICT, $controller->patch(self::request([
      'frontends' => [['url' => 'https://first.example'], ['url' => 'https://second.example']],
      'frontendUrlsHash' => $latest['frontendUrlsHash'],
    ]))->getStatusCode());
    self::assertSame(Response::HTTP_BAD_REQUEST, $controller->patch(self::request(['frontends' => []]))->getStatusCode());
  }

  /**
   * Tests permission-backed read access, scope provisioning, and lock failures.
   */
  public function testAccessAndLock(): void {
    $this->installConfig(['canvas_headless']);
    $preview = User::create(['uid' => 2]);
    self::assertFalse(FrontendsController::accessGet($preview)->isAllowed());
    foreach (['access canvas headless preview', 'administer canvas headless frontends'] as $permission) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('hasPermission')->willReturnCallback(static fn(string $candidate): bool => $candidate === $permission);
      self::assertTrue(FrontendsController::accessGet($account)->isAllowed());
    }
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects(self::exactly(2))->method('acquire')->with('canvas_headless_frontends')->willReturn(FALSE);
    $lock->expects(self::once())->method('wait')->with('canvas_headless_frontends', 5);
    $lock->expects(self::never())->method('release');
    $frontend_storage = $this->container->get('test.frontend_storage');
    self::assertInstanceOf(StorageInterface::class, $frontend_storage);
    $controller = new FrontendsController(new FrontendManager($this->container->get(ConfigFactoryInterface::class), $lock, $frontend_storage));
    self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $controller->post(self::request(['url' => 'https://example.com']))->getStatusCode());
    require_once __DIR__ . '/../../../canvas_headless.post_update.php';
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('oauth2_scope');
    $scope = $storage->load('canvas_headless_frontends');
    self::assertNotNull($scope);
    $scope->delete();
    canvas_headless_post_update_0002_frontend_oauth_scopes();
    self::assertNotNull($storage->load('canvas_headless_frontends'));
    self::assertNull($storage->load('canvas_headless_preview'));
    // Upgrades must not overwrite site-owned changes to existing scopes.
    $scope = $storage->load('canvas_headless_frontends');
    self::assertInstanceOf(Oauth2ScopeEntityInterface::class, $scope);
    $scope->set('description', 'Site-owned description')->save();
    canvas_headless_post_update_0002_frontend_oauth_scopes();
    $scope = $storage->load('canvas_headless_frontends');
    self::assertInstanceOf(Oauth2ScopeEntityInterface::class, $scope);
    self::assertSame('Site-owned description', $scope->getDescription());
  }

  /**
   * Tests a warmed configuration cache cannot overwrite another writer.
   */
  public function testFreshStorageUnderLock(): void {
    $this->installConfig(['canvas_headless']);
    $factory = $this->container->get(ConfigFactoryInterface::class);
    $factory->getEditable('canvas_headless.settings')->get('frontends');
    $storage = $this->container->get('test.frontend_storage');
    self::assertInstanceOf(StorageInterface::class, $storage);
    $settings = $storage->read('canvas_headless.settings');
    self::assertIsArray($settings);
    $settings['frontends'] = [['url' => 'https://concurrent.example', 'components' => ['js.hero']]];
    $settings['assertion_expiration'] = 120;
    $storage->write('canvas_headless.settings', $settings);
    $manager = $this->container->get(FrontendManager::class);
    $manager->add('https://new.example');
    self::assertSame([
      ['url' => 'https://concurrent.example', 'components' => ['js.hero']],
      ['url' => 'https://new.example', 'components' => []],
    ], $manager->get()['frontends']);
    $saved_settings = $storage->read('canvas_headless.settings');
    self::assertIsArray($saved_settings);
    self::assertSame(120, $saved_settings['assertion_expiration']);
  }

  /**
   * Builds a JSON request.
   *
   * @param array<string, mixed> $payload
   *   The request payload.
   */
  private static function request(array $payload): Request {
    return Request::create(
      '/canvas/api/v0/headless/frontends',
      'PATCH',
      content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Decodes a controller response.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private static function decode(Response $response): array {
    $content = $response->getContent();
    self::assertIsString($content);
    $decoded = json_decode($content, TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

}
