<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests synchronization of external component definitions.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class ExternalComponentSyncTest extends CanvasKernelTestBase {

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
  }

  /**
   * Tests that metadata creates and updates external components.
   */
  public function testSynchronization(): void {
    $this->installConfig(['canvas_headless']);

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

    $synchronizer = new ExternalComponentSync(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('lock'),
      $this->container->get('logger.factory'),
      $this->container->get(ComponentSourceManager::class),
      $this->container->get(TypedConfigManagerInterface::class),
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

    $result = $synchronizer->synchronize(self::metadataPayload('Original name', 'integer'));
    self::assertSame(1, $result['created']);
    self::assertSame(0, $result['updated']);
    self::assertSame(0, $result['unchanged']);
    self::assertCount(2, $result['warnings']);
    self::assertCount(2, $result['errors']);

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

    $result = $synchronizer->synchronize(self::metadataPayload('Updated name', 'number'));
    self::assertSame(1, $result['updated']);
    $component = JavaScriptComponent::load('baseAnchor');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame('Updated name', $component->label());
    self::assertSame('Local component', JavaScriptComponent::load('localComponent')?->label());
    $second_version = Component::load('js.baseAnchor')?->getActiveVersion();
    self::assertNotNull($second_version);
    self::assertNotSame($first_version, $second_version);
    self::assertSame(2, $code_component_saves->count);

    $result = $synchronizer->synchronize(self::metadataPayload('Updated name', 'number'));
    self::assertSame(1, $result['unchanged']);
    self::assertSame(2, $code_component_saves->count);

    // Recreate the Component config entity paired with an unchanged external
    // component when it is missing.
    Component::load('js.baseAnchor')?->delete();
    $result = $synchronizer->synchronize(self::metadataPayload('Updated name', 'number'));
    self::assertSame(1, $result['updated']);
    self::assertInstanceOf(Component::class, Component::load('js.baseAnchor'));
    self::assertSame(3, $code_component_saves->count);
  }

  /**
   * Builds a component metadata payload fixture in the SDK's shape.
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
