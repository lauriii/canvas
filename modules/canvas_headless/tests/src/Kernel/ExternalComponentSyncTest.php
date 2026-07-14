<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;

/**
 * Tests synchronization of external component definitions.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class ExternalComponentSyncTest extends CanvasKernelTestBase {

  use UserCreationTrait;

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
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');

    // Generate a signing keypair for Simple OAuth: the sync authenticates
    // against the component metadata endpoint with a preview assertion signed by it.
    $dir = $this->siteDirectory . '/keys';
    mkdir($dir, 0777, TRUE);
    $resource = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $this->assertNotFalse($resource);
    openssl_pkey_export($resource, $private_key);
    $details = openssl_pkey_get_details($resource);
    $this->assertNotFalse($details);
    file_put_contents($dir . '/private.key', $private_key);
    file_put_contents($dir . '/public.key', $details['key']);
    $this->config('simple_oauth.settings')
      ->set('private_key', $dir . '/private.key')
      ->set('public_key', $dir . '/public.key')
      ->save();
    $this->config('system.site')
      ->set('uuid', 'c7f2e9a4-3b1d-4e8f-9a6c-5d0b2f8e1a37')
      ->save();
  }

  /**
   * Tests that remote definitions create and update external components.
   */
  public function testSynchronization(): void {
    $this->installConfig(['canvas_headless']);
    $this->config('canvas_headless.settings')
      ->set('frontend_url', 'https://example.com/app')
      ->save();

    JavaScriptComponent::create([
      'machineName' => 'localComponent',
      'name' => 'Local component',
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();

    $history = [];
    $handler = new MockHandler([
      new Response(body: json_encode(self::metadataPayload('Original name', 'integer'), JSON_THROW_ON_ERROR)),
      new Response(body: json_encode(self::metadataPayload('Updated name', 'number'), JSON_THROW_ON_ERROR)),
      new Response(body: json_encode(self::metadataPayload('Updated name', 'number'), JSON_THROW_ON_ERROR)),
      new Response(body: json_encode(self::metadataPayload('Updated name', 'number'), JSON_THROW_ON_ERROR)),
      new Response(body: json_encode(self::metadataPayload('Updated name', 'number'), JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($history));
    // Middleware::history() accepts array|ArrayAccess by reference; pin the
    // narrower type for the assertions below.
    self::assertIsArray($history);
    $synchronizer = new ExternalComponentSync(
      new Client(['handler' => $stack]),
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('lock'),
      $this->container->get('logger.factory'),
      $this->container->get(ComponentSourceManager::class),
      $this->container->get(TypedConfigManagerInterface::class),
      $this->container->get(PreviewUrlGeneratorInterface::class),
      $this->container->get(PropShapeRepositoryInterface::class),
    );
    $logs = new class() extends AbstractLogger {
      /**
       * @var list<array{mixed, string, array<string, mixed>}>
       */
      public array $records = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [$level, (string) $message, $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($logs);
    $code_component_saves = new class() {
      public int $count = 0;
    };
    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::SAVE,
      static function (ConfigCrudEvent $event) use ($code_component_saves): void {
        if ($event->getConfig()->getName() === 'canvas.js_component.baseAnchor') {
          $code_component_saves->count++;
        }
      },
    );

    // Burn uid 1, which bypasses all permission checks.
    $this->createUser();
    $editor = $this->createUser(['access canvas headless preview']);
    $bystander = $this->createUser();
    \assert($editor instanceof UserInterface);
    \assert($bystander instanceof UserInterface);

    // Synchronization is disabled until a component metadata URL is configured.
    $this->setCurrentUser($editor);
    $synchronizer->synchronize();
    self::assertCount(0, $history);
    $this->config('canvas_headless.settings')
      ->set('component_metadata_url', '/component-metadata.json')
      ->save();

    // The metadata endpoint authenticates with a preview assertion; without
    // the permission to mint one, nothing is fetched.
    $this->setCurrentUser($bystander);
    $synchronizer->synchronize();
    self::assertCount(0, $history);
    $this->setCurrentUser($editor);

    $synchronizer->synchronize();
    $component = JavaScriptComponent::load('baseAnchor');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame('Original name', $component->label());
    self::assertTrue($component->status());
    self::assertTrue($component->isExternal());
    self::assertSame(['anchorId'], $component->getRequiredProps());
    self::assertSame([
      'anchorId' => [
        'type' => 'string',
        'title' => 'Anchor ID',
        'examples' => ['features'],
      ],
      'level' => [
        'type' => 'integer',
        'title' => 'Level',
        'description' => 'First line second line.',
        'examples' => [2],
      ],
    ], $component->getProps());
    self::assertNull(JavaScriptComponent::load('invalidComponent'));
    $first_version = Component::load('js.baseAnchor')?->getActiveVersion();
    self::assertNotNull($first_version);
    self::assertSame(1, $code_component_saves->count);

    // The payload's own warnings are surfaced in the Drupal log, and the
    // duplicate BaseAnchor definition is skipped: baseAnchor keeps the name
    // of the first definition in the payload (asserted above).
    // Substitute only @-prefixed placeholders: the logger channel injects
    // extra string context (channel, ip, referer, ...) whose bare keys would
    // otherwise also be replaced wherever they appear in the message text.
    $logged_messages = \array_map(
      static fn(array $record): string => strtr($record[1], \array_filter(
        $record[2],
        static fn(mixed $value, string $key): bool => \is_string($value) && \str_starts_with($key, '@'),
        ARRAY_FILTER_USE_BOTH,
      )),
      $logs->records,
    );
    self::assertContains('The component metadata payload reported a warning (duplicate-machine-name): Duplicate machine name baseAnchor. [base-anchor-copy]', $logged_messages);
    self::assertContains("Skipped a duplicate definition for the external component 'baseAnchor': the first definition in the payload wins.", $logged_messages);

    $synchronizer->synchronize();
    $component = JavaScriptComponent::load('baseAnchor');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame('Updated name', $component->label());
    self::assertSame('Local component', JavaScriptComponent::load('localComponent')?->label());
    $second_version = Component::load('js.baseAnchor')?->getActiveVersion();
    self::assertNotNull($second_version);
    self::assertNotSame($first_version, $second_version);
    self::assertSame(2, $code_component_saves->count);

    // An unchanged candidate version does not cause another config write.
    $synchronizer->synchronize();
    self::assertSame(2, $code_component_saves->count);

    // The Component config entity (js.baseAnchor) that pairs with the code
    // component is recreated when missing, even when the remote definition
    // has not changed.
    Component::load('js.baseAnchor')?->delete();
    $synchronizer->synchronize();
    self::assertInstanceOf(Component::class, Component::load('js.baseAnchor'));
    self::assertSame(3, $code_component_saves->count);

    $this->config('canvas_headless.settings')
      ->set('component_metadata_url', 'https://cdn.example.com/component-metadata.json')
      ->save();
    $synchronizer->synchronize();
    self::assertSame(3, $code_component_saves->count);

    self::assertCount(5, $history);
    $uris = [];
    $authorizations = [];
    foreach ($history as $exchange) {
      self::assertIsArray($exchange);
      self::assertArrayHasKey('request', $exchange);
      $request = $exchange['request'];
      self::assertInstanceOf(RequestInterface::class, $request);
      $uris[] = (string) $request->getUri();
      // Every fetch authenticates with a fresh single-use preview
      // assertion.
      $authorization = $request->getHeaderLine('Authorization');
      self::assertStringStartsWith('Bearer ey', $authorization);
      $authorizations[] = $authorization;
    }
    self::assertSame('https://example.com/app/component-metadata.json', $uris[0]);
    self::assertSame('https://cdn.example.com/component-metadata.json', $uris[4]);
    self::assertSame($authorizations, \array_unique($authorizations));
  }

  /**
   * Builds a component metadata payload fixture in the SDK's shape.
   *
   * Mirrors the component metadata payload of the Drupal Canvas Headless
   * SDK: a version envelope, machineName and name per component, required
   * as a top-level list, and props as a flat prop-name-to-definition map.
   */
  private static function metadataPayload(string $name, string $level_type): array {
    return [
      'version' => 1,
      'components' => [
        [
          'machineName' => 'baseAnchor',
          'name' => $name,
          'status' => TRUE,
          'required' => ['anchorId'],
          'props' => [
            'anchorId' => [
              'type' => 'string',
              'title' => 'Anchor ID',
              'examples' => ['features'],
            ],
            'level' => [
              'type' => $level_type,
              'title' => 'Level',
              'description' => 'First line second line.',
              'examples' => [$level_type === 'integer' ? 2 : 2.0],
              'default' => 2,
              'unsupported' => 'drop me',
            ],
          ],
          'slots' => [],
          'relativeDirectory' => 'base-anchor',
        ],
        [
          'machineName' => 'invalidComponent',
          'name' => 'Invalid component',
          'status' => TRUE,
          'required' => ['count'],
          'props' => [
            'count' => [
              'type' => 'integer',
              'title' => 'Count',
              'examples' => ['2'],
            ],
          ],
          'slots' => [],
          'relativeDirectory' => 'invalid-component',
        ],
        [
          'machineName' => 'localComponent',
          'name' => 'Remote component',
          'status' => TRUE,
          'required' => [],
          'props' => [],
          'slots' => [],
          'relativeDirectory' => 'local-component',
        ],
        // Collides with baseAnchor after lcfirst() normalization: the first
        // definition in the payload wins, this one is skipped.
        [
          'machineName' => 'BaseAnchor',
          'name' => 'Duplicate definition',
          'status' => TRUE,
          'required' => [],
          'props' => [],
          'slots' => [],
          'relativeDirectory' => 'base-anchor-copy',
        ],
      ],
      'warnings' => [
        [
          'code' => 'duplicate-machine-name',
          'message' => 'Duplicate machine name baseAnchor.',
          'path' => 'base-anchor-copy',
        ],
      ],
    ];
  }

}
