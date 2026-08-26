<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\RenderConverter\JsComponentCanvasRenderConverter;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\custom_elements\CustomElement;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
    'custom_elements',
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

    $dependency = JavaScriptComponent::create([
      'machineName' => 'localDependency',
      'name' => 'Local dependency',
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $dependency->save();

    $local_js = 'export default function CardWithSlot() {}';
    $local_css = '.card-with-slot { display: grid; }';
    JavaScriptComponent::create([
      'machineName' => 'cardWithSlot',
      'name' => 'Local component',
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => $local_js, 'compiled' => $local_js],
      'css' => ['original' => $local_css, 'compiled' => $local_css],
      'dataDependencies' => ['drupalSettings' => ['v0.pageTitle']],
      'dependencies' => [
        'enforced' => [
          'config' => [$dependency->getConfigDependencyName()],
        ],
      ],
    ])->save();

    $synchronizer = new ExternalComponentSync(
      $this->container->get(ConfigFactoryInterface::class),
      $this->container->get(EntityTypeManagerInterface::class),
      $this->container->get('lock'),
      $this->container->get(LoggerChannelFactoryInterface::class),
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
    $this->container->get(LoggerChannelFactoryInterface::class)->addLogger($logs);
    $code_component_saves = new class() {
      public int $count = 0;
    };
    $this->container->get(EventDispatcherInterface::class)->addListener(
      ConfigEvents::SAVE,
      static function (ConfigCrudEvent $event) use ($code_component_saves): void {
        if ($event->getConfig()->getName() === 'canvas.js_component.heroBanner') {
          $code_component_saves->count++;
        }
      },
    );

    $result = $synchronizer->synchronize(self::metadataPayload('Original name', 'integer'));
    self::assertSame(1, $result['created']);
    self::assertSame(1, $result['updated']);
    self::assertSame(0, $result['unchanged']);
    self::assertCount(2, $result['warnings']);
    self::assertCount(1, $result['errors']);

    $component = JavaScriptComponent::load('heroBanner');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame('Original name', $component->label());
    self::assertTrue($component->status());
    self::assertTrue($component->isExternal());
    self::assertSame(['anchorId'], $component->getRequiredProps());
    self::assertSame([
      'anchorId' => [
        'type' => 'string',
        'enum' => ['features', 'contact'],
        'meta:enum' => [
          'features' => 'Features',
          'contact' => 'Contact',
        ],
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
    self::assertSame([
      'content' => [
        'title' => 'Content',
      ],
    ], $component->get('slots'));
    self::assertNull(JavaScriptComponent::load('invalidComponent'));
    $first_version = Component::load('js.heroBanner')?->getActiveVersion();
    self::assertNotNull($first_version);
    self::assertSame(1, $code_component_saves->count);

    $local_component = JavaScriptComponent::load('cardWithSlot');
    self::assertInstanceOf(JavaScriptComponent::class, $local_component);
    self::assertTrue($local_component->isExternal());
    self::assertTrue($local_component->hasFallbackImplementation());
    self::assertSame('Remote component', $local_component->label());
    self::assertSame($local_js, $local_component->getJs());
    self::assertSame($local_css, $local_component->getCss());
    self::assertSame(['drupalSettings' => ['v0.pageTitle']], $local_component->get('dataDependencies'));
    self::assertContains($dependency->getConfigDependencyName(), $local_component->getDependencies()['config']);

    // With no frontend configured, a converted component uses its retained
    // Drupal implementation.
    $local_canvas_component = Component::load('js.cardWithSlot');
    self::assertInstanceOf(Component::class, $local_canvas_component);
    $local_source = $local_canvas_component->getComponentSource();
    self::assertInstanceOf(JsComponent::class, $local_source);
    $local_build = $local_source->renderComponent([
      JsComponent::EXPLICIT_INPUT_NAME => [],
    ], [], 'local-component-uuid');
    self::assertSame('astro_island', $local_build['#type']);
    self::assertContains('canvas/astro_island.cardWithSlot', $local_build['#attached']['library']);

    // Configuring a frontend makes the external implementation authoritative.
    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => 'https://frontend.example']])
      ->save();
    $external_build = $local_source->renderComponent([
      JsComponent::EXPLICIT_INPUT_NAME => [],
    ], [], 'local-component-uuid');
    self::assertSame('', $external_build['#markup']);
    self::assertArrayHasKey(JsComponent::EXTERNAL_RENDER_METADATA, $external_build);

    // Drupal keeps app-owned components renderless, while the headless
    // Custom Elements converter exposes their props, identity, and slots.
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    $source = $canvas_component->getComponentSource();
    self::assertInstanceOf(JsComponent::class, $source);
    $build = $source->renderComponent([
      JsComponent::EXPLICIT_INPUT_NAME => [
        'anchorId' => new EvaluationResult('features'),
        'level' => new EvaluationResult(3),
      ],
    ], $canvas_component->getSlotDefinitions(), 'component-uuid');
    self::assertSame('', $build['#markup']);
    $source->setSlots($build, [
      'content' => [
        JsComponent::EXTERNAL_RENDER_METADATA => [
          'component_id' => 'js.child',
          'component_uuid' => 'child-uuid',
          'props' => ['label' => 'Nested component'],
        ],
        '#markup' => '',
      ],
    ]);
    $converter = $this->container->get('custom_elements.canvas_render_converter');
    self::assertInstanceOf(JsComponentCanvasRenderConverter::class, $converter);
    $element = $converter->convertRenderArray([
      '#type' => 'component_container',
      '#component' => $build,
      '#component_uuid' => 'component-uuid',
    ]);
    self::assertSame('js-herobanner', $element->getTag());
    self::assertSame('features', $element->getAttribute('anchorId'));
    self::assertSame(3, $element->getAttribute('level'));
    self::assertSame('component-uuid', $element->getAttribute('canvasUuid'));
    $slot = $element->getSlot('content');
    self::assertInstanceOf(CustomElement::class, $slot['content'] ?? NULL);
    self::assertSame('js-child', $slot['content']->getTag());
    self::assertSame('child-uuid', $slot['content']->getAttribute('canvasUuid'));

    // Standard JS components keep their Drupal implementation, but the
    // headless converter represents their Astro islands as app-rendered
    // Custom Elements without leaking island-only runtime props.
    $element = $converter->convertRenderArray([
      '#type' => 'component_container',
      '#component' => [
        '#type' => 'astro_island',
        '#machine_name' => 'cardWithSlot',
        '#uuid' => 'card-with-slot-uuid',
        '#props' => [
          'title' => 'Standard component',
          'canvas_uuid' => 'card-with-slot-uuid',
          'canvas_slot_ids' => [],
          'canvas_is_preview' => FALSE,
        ],
      ],
      '#component_uuid' => 'card-with-slot-uuid',
    ]);
    self::assertSame('js-cardwithslot', $element->getTag());
    self::assertSame('Standard component', $element->getAttribute('title'));
    self::assertSame('card-with-slot-uuid', $element->getAttribute('canvasUuid'));
    self::assertNull($element->getAttribute('canvas_uuid'));
    self::assertNull($element->getAttribute('canvas_slot_ids'));
    self::assertNull($element->getAttribute('canvas_is_preview'));

    // The payload's own warnings are surfaced in the Drupal log, and the
    // duplicate HeroBanner definition is skipped: heroBanner keeps the name
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
    self::assertContains('The component metadata payload reported a warning (duplicate-machine-name): Duplicate machine name heroBanner. [hero-banner-copy]', $logged_messages);
    self::assertContains("Skipped a duplicate definition for the external component 'heroBanner': the first definition in the payload wins.", $logged_messages);

    $result = $synchronizer->synchronize(self::metadataPayload('Updated name', 'number'));
    self::assertSame(1, $result['updated']);
    self::assertSame(1, $result['unchanged']);
    $component = JavaScriptComponent::load('heroBanner');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame('Updated name', $component->label());
    self::assertSame('Remote component', JavaScriptComponent::load('cardWithSlot')?->label());
    $second_version = Component::load('js.heroBanner')?->getActiveVersion();
    self::assertNotNull($second_version);
    self::assertNotSame($first_version, $second_version);
    self::assertSame(2, $code_component_saves->count);

    $updated_payload = self::metadataPayload('Updated name', 'number');
    $result = $synchronizer->synchronize($updated_payload);
    self::assertSame(2, $result['unchanged']);
    self::assertSame(2, $code_component_saves->count);

    // Prop display metadata does not affect the component version hash, but it
    // must still be synchronized to the JavaScript component.
    \assert(isset($updated_payload['components'][0]['props']['anchorId']['meta:enum']));
    $updated_payload['components'][0]['props']['anchorId']['meta:enum']['features'] = 'Featured content';
    $result = $synchronizer->synchronize($updated_payload);
    self::assertSame(1, $result['updated']);
    self::assertSame(1, $result['unchanged']);
    $component = JavaScriptComponent::load('heroBanner');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    $props = $component->getProps();
    self::assertIsArray($props);
    self::assertSame('Featured content', $props['anchorId']['meta:enum']['features']);
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    self::assertSame($second_version, $canvas_component->getActiveVersion());
    self::assertSame(3, $code_component_saves->count);

    $result = $synchronizer->synchronize($updated_payload);
    self::assertSame(2, $result['unchanged']);
    self::assertSame(3, $code_component_saves->count);

    // Recreate the Component config entity paired with an unchanged external
    // component when it is missing.
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    $canvas_component->delete();
    $result = $synchronizer->synchronize($updated_payload);
    self::assertSame(1, $result['updated']);
    self::assertSame(1, $result['unchanged']);
    self::assertInstanceOf(Component::class, Component::load('js.heroBanner'));
    self::assertSame(4, $code_component_saves->count);
  }

  /**
   * Builds a component metadata payload fixture in the SDK's shape.
   */
  private static function metadataPayload(string $name, string $level_type): array {
    return [
      'version' => 1,
      'components' => [
        [
          'machineName' => 'heroBanner',
          'name' => $name,
          'status' => TRUE,
          'required' => ['anchorId'],
          'props' => [
            'anchorId' => [
              'type' => 'string',
              'title' => 'Anchor ID',
              'examples' => ['features'],
              'enum' => ['features', 'contact'],
              'meta:enum' => [
                'features' => 'Features',
                'contact' => 'Contact',
              ],
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
          'slots' => [
            'content' => [
              'title' => 'Content',
            ],
          ],
          'relativeDirectory' => 'hero-banner',
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
          'machineName' => 'cardWithSlot',
          'name' => 'Remote component',
          'status' => TRUE,
          'required' => [],
          'props' => [],
          'slots' => [],
          'relativeDirectory' => 'card-with-slot',
        ],
        // Collides with heroBanner after lcfirst() normalization: the first
        // definition in the payload wins, this one is skipped.
        [
          'machineName' => 'HeroBanner',
          'name' => 'Duplicate definition',
          'status' => TRUE,
          'required' => [],
          'props' => [],
          'slots' => [],
          'relativeDirectory' => 'hero-banner-copy',
        ],
      ],
      'warnings' => [
        [
          'code' => 'duplicate-machine-name',
          'message' => 'Duplicate machine name heroBanner.',
          'path' => 'hero-banner-copy',
        ],
      ],
    ];
  }

}
