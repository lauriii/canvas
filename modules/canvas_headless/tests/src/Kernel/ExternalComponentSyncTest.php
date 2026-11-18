<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\CanvasClientCapabilities;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas_headless\Cache\HeadlessContentFormatCacheContext;
use Drupal\canvas_headless\CanvasContentSerializer;
use Drupal\canvas_headless\CanvasCustomElement;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\canvas_headless\JsonRender\CanvasJsonRenderNormalizer;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\custom_elements\CustomElement;
use Drupal\custom_elements\CustomElementNormalizer;
use Drupal\custom_elements\RenderConverter\RenderConverter;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests synchronization of external component definitions.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class ExternalComponentSyncTest extends CanvasKernelTestBase {

  private const string FRONTEND_URL = 'https://frontend.example';
  private const string SECOND_FRONTEND_URL = 'https://frontend-b.example';

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
    $frontend = FrontendUrl::fromConfig(self::FRONTEND_URL);
    self::assertInstanceOf(FrontendUrl::class, $frontend);

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

    $result = $synchronizer->synchronize($frontend, self::metadataPayload('Original name', 'integer'));
    self::assertSame(1, $result['created']);
    self::assertSame(1, $result['updated']);
    self::assertSame(0, $result['unchanged']);
    self::assertCount(2, $result['warnings']);
    self::assertCount(1, $result['errors']);
    self::assertSame(['js.heroBanner', 'js.cardWithSlot'], $result['components']);

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
      ->set('frontends', [['url' => self::FRONTEND_URL, 'components' => []]])
      ->save();
    self::assertSame([[
      'url' => self::FRONTEND_URL,
      'components' => [],
    ],
    ], $this->config('canvas_headless.settings')->get('frontends'));
    $external_build = $local_source->renderComponent([
      JsComponent::EXPLICIT_INPUT_NAME => [],
    ], [], 'local-component-uuid');
    self::assertSame('', $external_build['#markup']);
    self::assertArrayHasKey(JsComponent::EXTERNAL_RENDER_METADATA, $external_build);

    $result = $synchronizer->synchronize($frontend, self::metadataPayload('Original name', 'integer'));
    self::assertSame(['js.heroBanner', 'js.cardWithSlot'], $result['components']);
    self::assertSame([[
      'url' => self::FRONTEND_URL,
      'components' => ['js.heroBanner', 'js.cardWithSlot'],
    ],
    ], $this->config('canvas_headless.settings')->get('frontends'));

    // Drupal keeps app-owned components renderless, while the headless
    // converter exposes their props, identity, and slots.
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
    $serializer = $this->container->get(CanvasContentSerializer::class);
    self::assertInstanceOf(CanvasContentSerializer::class, $serializer);
    $request_stack = $this->container->get(RequestStack::class);
    self::assertInstanceOf(RequestStack::class, $request_stack);
    // Match the request and normalization format without leaking request state.
    $serialize = static function (array $build, string $format, bool $preview, CacheableMetadata $cacheability) use ($serializer, $request_stack): ?array {
      $request = Request::create(CanvasContentApiRequest::API_PATH);
      $request->attributes->set(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE, '/');
      $request->headers->set(CanvasClientCapabilities::CAPABILITIES_HEADER, $format === CanvasContentSerializer::FORMAT_LEGACY ? '' : 'json-render');
      $request_stack->push($request);
      try {
        [$content] = $serializer->serialize($build, $format, $preview, $cacheability);
        return $content;
      }
      finally {
        $request_stack->pop();
      }
    };
    $component_build = [
      '#type' => 'component_container',
      '#component' => $build,
      '#component_uuid' => 'component-uuid',
    ];
    $document = $serialize($component_build, CanvasContentSerializer::FORMAT_JSON_RENDER, FALSE, new BubbleableMetadata());
    self::assertIsArray($document);
    $element = $document['elements'][$document['root']];
    self::assertSame('js.heroBanner', $element['type']);
    self::assertSame('features', $element['props']['anchorId']);
    self::assertSame(3, $element['props']['level']);
    self::assertSame('component-uuid', $document['root']);
    self::assertArrayNotHasKey('canvasUuid', $element['props']);
    $child = $document['elements'][$element['slots']['content'][0]];
    self::assertSame('js.child', $child['type']);
    self::assertSame(['child-uuid'], $element['slots']['content']);
    self::assertArrayNotHasKey('canvasUuid', $child['props']);

    // The shared tree retains exact Canvas identity without putting it in props.
    // The Canvas format does not replace Custom Elements' generic json-render.
    $shared_converter = $this->container->get('custom_elements.render_converter');
    self::assertInstanceOf(RenderConverter::class, $shared_converter);
    $custom_element = $shared_converter->convert($component_build);
    self::assertInstanceOf(CanvasCustomElement::class, $custom_element);
    self::assertSame([
      'componentId' => 'js.heroBanner',
      'componentUuid' => 'component-uuid',
    ], $custom_element->getCanvasMetadata());
    $normalizer = $this->container->get('custom_elements.normalizer');
    self::assertInstanceOf(CustomElementNormalizer::class, $normalizer);
    $generic = $normalizer->normalize($custom_element, context: ['json_format' => 'json-render']);
    self::assertIsArray($generic);
    self::assertNotSame('component-uuid', $generic['root']);
    self::assertSame('js-herobanner', $generic['elements'][$generic['root']]['type']);
    // Missing json-render capability selects legacy only on headless API requests.
    $legacy_tree = NULL;
    /** @var list<array{string|null, bool, bool}> $request_cases */
    $request_cases = [
      [NULL, FALSE, TRUE],
      ['', FALSE, TRUE],
      [NULL, TRUE, FALSE],
      ['', TRUE, FALSE],
      ['invalid', TRUE, FALSE],
      ['json-render', TRUE, TRUE],
      ['Json-Render', TRUE, TRUE],
      ['future-feature, json-render', TRUE, TRUE],
    ];
    foreach ($request_cases as [$capabilities, $is_api, $modern]) {
      $request = Request::create('/ordinary-page');
      if ($is_api) {
        $request->attributes->set(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE, '/ordinary-page');
      }
      if ($capabilities !== NULL) {
        $request->headers->set(CanvasClientCapabilities::CAPABILITIES_HEADER, $capabilities);
      }
      $request_stack->push($request);
      try {
        $tree = $shared_converter->convert($component_build);
        $passthrough = CustomElement::create('existing-element');
        self::assertSame($passthrough, $shared_converter->convert(['#custom_element' => $passthrough]));
      }
      finally {
        $request_stack->pop();
      }
      self::assertSame($modern, $tree instanceof CanvasCustomElement);
      self::assertSame($is_api, \in_array(HeadlessContentFormatCacheContext::CACHE_CONTEXT_ID, $tree->getCacheContexts(), TRUE));
      if (!$modern) {
        self::assertSame('component-uuid', $tree->getAttribute('canvasUuid'));
        $legacy_tree = $tree;
      }
    }
    self::assertInstanceOf(CustomElement::class, $legacy_tree);
    self::assertArrayNotHasKey('canvasUuid', $custom_element->getAttributes());
    self::assertSame($document, $normalizer->normalize($custom_element, context: ['json_format' => CanvasJsonRenderNormalizer::FORMAT]));

    // Legacy SDKs keep their normalized tag names while json-render preserves
    // the case-sensitive component IDs registered by the application.
    $legacy = $serialize($component_build, CanvasContentSerializer::FORMAT_LEGACY, FALSE, new BubbleableMetadata());
    self::assertIsArray($legacy);
    self::assertSame('js-herobanner', $legacy['element']);
    self::assertSame('js-child', $legacy['slots']['content']['element']);
    self::assertSame('component-uuid', $legacy['props']['canvasUuid']);
    self::assertSame('child-uuid', $legacy['slots']['content']['props']['canvasUuid']);
    self::assertSame($legacy, $normalizer->normalize($legacy_tree, context: ['json_format' => 'explicit']));

    // The built-in legacy serializer normalizes casing and retains
    // single component slots, sibling arrays, and multiple roots.
    $legacy_build = $component_build;
    $legacy_build['#component'][JsComponent::EXTERNAL_RENDER_METADATA]['props']['nested_data'] = [
      ['link_title' => 'A link', 'data-key' => 'value'],
    ];
    $footer_child = $build['#slots']['content'];
    $footer_child[JsComponent::EXTERNAL_RENDER_METADATA]['component_uuid'] = 'footer-uuid';
    $legacy_build['#component']['#slots']['footer_slot'] = [
      $footer_child,
      ['#markup' => '<p>Footer</p>'],
    ];
    $legacy_build['#component']['#slots']['empty_slot'] = [];
    $legacy_content = $serialize($legacy_build, CanvasContentSerializer::FORMAT_LEGACY, FALSE, new BubbleableMetadata());
    self::assertIsArray($legacy_content);
    self::assertSame([['linkTitle' => 'A link', 'dataKey' => 'value']], $legacy_content['props']['nestedData']);
    self::assertSame($legacy['slots']['content'], $legacy_content['slots']['content']);
    self::assertSame([
      ['element' => 'js-child', 'props' => ['label' => 'Nested component', 'canvasUuid' => 'footer-uuid']],
      ['element' => 'drupal-markup', 'slots' => ['default' => '<p>Footer</p>']],
    ], $legacy_content['slots']['footerSlot']);
    self::assertArrayNotHasKey('emptySlot', $legacy_content['slots']);
    self::assertSame([
      'element' => 'renderless-container',
      'slots' => ['default' => [$legacy_content, $legacy]],
    ], $serialize([$legacy_build, [], $component_build], CanvasContentSerializer::FORMAT_LEGACY, FALSE, new BubbleableMetadata()));
    foreach ([[], ['#markup' => '']] as $empty_build) {
      self::assertNull($serialize($empty_build, CanvasContentSerializer::FORMAT_LEGACY, FALSE, new BubbleableMetadata()));
    }
    // Access-denied children stay absent, but still affect response caching.
    $denied_build = $component_build;
    $denied_build['#access'] = AccessResult::forbidden()->addCacheTags(['legacy-access'])->setCacheMaxAge(17);
    $legacy_cacheability = new BubbleableMetadata();
    self::assertNull($serialize($denied_build, CanvasContentSerializer::FORMAT_LEGACY, FALSE, $legacy_cacheability));
    self::assertContains('legacy-access', $legacy_cacheability->getCacheTags());
    self::assertSame(17, $legacy_cacheability->getCacheMaxAge());
    $legacy_cacheability = new BubbleableMetadata();
    self::assertSame($legacy, $serialize([$denied_build, $component_build], CanvasContentSerializer::FORMAT_LEGACY, FALSE, $legacy_cacheability));
    self::assertContains('legacy-access', $legacy_cacheability->getCacheTags());
    self::assertSame(17, $legacy_cacheability->getCacheMaxAge());
    $document = $serialize($legacy_build, CanvasContentSerializer::FORMAT_JSON_RENDER, FALSE, new BubbleableMetadata());
    self::assertIsArray($document);
    self::assertSame([['link_title' => 'A link', 'data-key' => 'value']], $document['elements']['component-uuid']['props']['nested_data']);
    self::assertArrayNotHasKey('nestedData', $document['elements']['component-uuid']['props']);
    self::assertArrayHasKey('footer_slot', $document['elements']['component-uuid']['slots']);

    // Props, including a prop named slot, are application data rather than
    // HTML attributes. Raw slot markup still becomes a drupal-markup element.
    $props = [
      'slot' => 'default',
      'nested_data' => ['link_title' => 'A link'],
      'nestedData' => 'A separate prop',
    ];
    $markup_element = CanvasCustomElement::createWithCanvasMetadata([
      'componentId' => 'js.markupComponent',
      'componentUuid' => 'markup-component-uuid',
    ], $props)
      ->setSlot('default', Markup::create('<p>Slot markup</p>'));
    self::assertSame([
      'root' => 'markup-component-uuid',
      'elements' => [
        'markup-component-uuid' => [
          'type' => 'js.markupComponent',
          'props' => $props,
          'children' => ['el-0'],
        ],
        'el-0' => [
          'type' => 'drupal-markup',
          'props' => ['markup' => '<p>Slot markup</p>'],
          'children' => [],
        ],
      ],
    ], $normalizer->normalize($markup_element, context: ['json_format' => CanvasJsonRenderNormalizer::FORMAT]));

    // Reordering siblings changes references, not their component keys.
    $sibling_build = [
      JsComponent::EXTERNAL_RENDER_METADATA => [
        'component_id' => 'js.child',
        'component_uuid' => 'sibling-uuid',
        'props' => ['label' => 'Sibling component'],
      ],
      '#markup' => '',
    ];
    foreach ([[$component_build, $sibling_build], [$sibling_build, $component_build]] as $index => $builds) {
      $siblings = $serialize($builds, CanvasContentSerializer::FORMAT_JSON_RENDER, FALSE, new BubbleableMetadata());
      self::assertIsArray($siblings);
      self::assertSame($index === 0 ? ['component-uuid', 'sibling-uuid'] : ['sibling-uuid', 'component-uuid'], $siblings['elements'][$siblings['root']]['children']);
      self::assertSame($element, $siblings['elements']['component-uuid']);
    }

    // Transparent slot groups keep children under their component parent, so
    // inserting nested siblings cannot change an unchanged subtree's identity.
    $slot_child = static fn(string $uuid, array $slots = []): array => [
      JsComponent::EXTERNAL_RENDER_METADATA => [
        'component_id' => 'js.child',
        'component_uuid' => $uuid,
        'props' => ['label' => $uuid],
      ],
      '#slots' => $slots,
    ];
    $nested_build = $component_build;
    $nested_build['#component']['#slots']['content'] = [
      $slot_child('first', [
        'default' => [
          $slot_child('first-one'),
          $slot_child('first-two'),
        ],
      ]),
      $slot_child('second', [
        'footer_slot' => [
          [$slot_child('second-one'), $slot_child('second-two')],
          [],
        ],
      ]),
    ];
    foreach ([FALSE, TRUE] as $preview) {
      $nested_document = $serialize($nested_build, CanvasContentSerializer::FORMAT_JSON_RENDER, $preview, new BubbleableMetadata());
      self::assertIsArray($nested_document);
      self::assertSame(['first', 'second'], $nested_document['elements']['component-uuid']['slots']['content']);
      self::assertSame(['first-one', 'first-two'], $nested_document['elements']['first']['children']);
      self::assertSame(['second-one', 'second-two'], $nested_document['elements']['second']['slots']['footer_slot']);
      self::assertNotContains('renderless-container', \array_column($nested_document['elements'], 'type'));

      $updated_build = $nested_build;
      $updated_build['#component']['#slots']['content'][0]['#slots']['default'][] = $slot_child('inserted', [
        'default' => [
          $slot_child('new-one'),
          $slot_child('new-two'),
        ],
      ]);
      $updated_document = $serialize($updated_build, CanvasContentSerializer::FORMAT_JSON_RENDER, $preview, new BubbleableMetadata());
      self::assertIsArray($updated_document);
      self::assertSame(['first-one', 'first-two', 'inserted'], $updated_document['elements']['first']['children']);
      self::assertSame(['new-one', 'new-two'], $updated_document['elements']['inserted']['children']);
      self::assertSame($nested_document['elements']['second'], $updated_document['elements']['second']);
    }

    // Duplicate identities cannot silently replace an existing element.
    try {
      $serialize([$component_build, $component_build], CanvasContentSerializer::FORMAT_JSON_RENDER, FALSE, new BubbleableMetadata());
      self::fail('Duplicate component UUIDs must be rejected.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertSame('Duplicate Canvas component UUID in content document: component-uuid', $exception->getMessage());
    }

    // Any Marker source instance uses the same structural element. Its local
    // ID lets headless renderers distinguish current and future marker types.
    $marker_build = [
      '#type' => 'container',
      Marker::RENDER_METADATA => [
        'marker_id' => 'future_marker',
        'component_uuid' => 'future-marker-uuid',
      ],
      '#prefix' => Markup::create('<!-- canvas-start-future-marker-uuid -->'),
      '#suffix' => Markup::create('<!-- canvas-end-future-marker-uuid -->'),
      'content' => ['#markup' => 'Future marker'],
    ];
    $published_document = $serialize($marker_build, CanvasContentSerializer::FORMAT_JSON_RENDER, FALSE, new BubbleableMetadata());
    self::assertIsArray($published_document);
    self::assertSame('drupal-markup', $published_document['elements'][$published_document['root']]['type']);

    $document = $serialize($marker_build, CanvasContentSerializer::FORMAT_JSON_RENDER, TRUE, new BubbleableMetadata());
    self::assertIsArray($document);
    $marker_element = $document['elements'][$document['root']];
    self::assertSame(CanvasContentSerializer::MARKER_ELEMENT, $marker_element['type']);
    self::assertSame('future_marker', $marker_element['props']['markerId']);
    self::assertSame('future-marker-uuid', $document['root']);
    self::assertArrayNotHasKey('canvasUuid', $marker_element['props']);
    $legacy_marker = $serialize($marker_build, CanvasContentSerializer::FORMAT_LEGACY, TRUE, new BubbleableMetadata());
    self::assertIsArray($legacy_marker);
    self::assertStringContainsString('canvas-start-future-marker-uuid', $legacy_marker['slots']['default']);
    self::assertStringContainsString('canvas-end-future-marker-uuid', $legacy_marker['slots']['default']);
    self::assertSame(1, \substr_count($legacy_marker['slots']['default'], 'canvas-start-future-marker-uuid'));
    unset($marker_build['#prefix'], $marker_build['#suffix']);
    $legacy_marker = $serialize($marker_build, CanvasContentSerializer::FORMAT_LEGACY, FALSE, new BubbleableMetadata());
    self::assertIsArray($legacy_marker);
    self::assertStringNotContainsString('canvas-start-', $legacy_marker['slots']['default']);

    // Standard JS components keep their Drupal implementation, but the
    // headless converter represents their Astro islands as app-rendered
    // components without leaking island-only runtime props.
    $document = $serialize([
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
    ], CanvasContentSerializer::FORMAT_JSON_RENDER, FALSE, new BubbleableMetadata());
    self::assertIsArray($document);
    $element = $document['elements'][$document['root']];
    self::assertSame('js.cardWithSlot', $element['type']);
    self::assertSame('Standard component', $element['props']['title']);
    self::assertSame('card-with-slot-uuid', $document['root']);
    self::assertArrayNotHasKey('canvasUuid', $element['props']);
    self::assertArrayNotHasKey('canvas_uuid', $element['props']);
    self::assertArrayNotHasKey('canvas_slot_ids', $element['props']);
    self::assertArrayNotHasKey('canvas_is_preview', $element['props']);

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

    $result = $synchronizer->synchronize($frontend, self::metadataPayload('Updated name', 'number'));
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
    $result = $synchronizer->synchronize($frontend, $updated_payload);
    self::assertSame(2, $result['unchanged']);
    self::assertSame(2, $code_component_saves->count);

    // Prop display metadata does not affect the component version hash, but it
    // must still be synchronized to the JavaScript component.
    \assert(isset($updated_payload['components'][0]['props']['anchorId']['meta:enum']));
    $updated_payload['components'][0]['props']['anchorId']['meta:enum']['features'] = 'Featured content';
    $result = $synchronizer->synchronize($frontend, $updated_payload);
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

    $result = $synchronizer->synchronize($frontend, $updated_payload);
    self::assertSame(2, $result['unchanged']);
    self::assertSame(3, $code_component_saves->count);

    // Recreate the Component config entity paired with an unchanged external
    // component when it is missing.
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    $canvas_component->delete();
    $result = $synchronizer->synchronize($frontend, $updated_payload);
    self::assertSame(1, $result['updated']);
    self::assertSame(1, $result['unchanged']);
    self::assertInstanceOf(Component::class, Component::load('js.heroBanner'));
    self::assertSame(4, $code_component_saves->count);

    $removed_payload = $updated_payload;
    $removed_payload['components'] = [$removed_payload['components'][0]];
    $result = $synchronizer->synchronize($frontend, $removed_payload);
    self::assertSame(['js.heroBanner'], $result['components']);
    self::assertSame([[
      'url' => self::FRONTEND_URL,
      'components' => ['js.heroBanner'],
    ],
    ], $this->config('canvas_headless.settings')->get('frontends'));
  }

  /**
   * Tests that the most recent synchronization wins across frontends.
   *
   * Frontends share external components by machine name. Synchronizing a
   * frontend overrides the shared metadata, both frontends keep the component
   * in their own lists, and synchronizing the first frontend again
   * reactivates its earlier Component version.
   */
  public function testCrossFrontendLastSyncWins(): void {
    $this->installConfig(['canvas_headless']);
    $frontend_a = FrontendUrl::fromConfig(self::FRONTEND_URL);
    $frontend_b = FrontendUrl::fromConfig(self::SECOND_FRONTEND_URL);
    self::assertInstanceOf(FrontendUrl::class, $frontend_a);
    self::assertInstanceOf(FrontendUrl::class, $frontend_b);

    $this->config('canvas_headless.settings')
      ->set('frontends', [
        ['url' => self::FRONTEND_URL, 'components' => []],
        ['url' => self::SECOND_FRONTEND_URL, 'components' => []],
      ])
      ->save();

    $synchronizer = new ExternalComponentSync(
      $this->container->get(ConfigFactoryInterface::class),
      $this->container->get(EntityTypeManagerInterface::class),
      $this->container->get('lock'),
      $this->container->get(LoggerChannelFactoryInterface::class),
      $this->container->get(ComponentSourceManager::class),
      $this->container->get(TypedConfigManagerInterface::class),
      $this->container->get(PropShapeRepositoryInterface::class),
    );

    $payload_a = self::sharedMetadataPayload('Original name', 'integer');
    $result = $synchronizer->synchronize($frontend_a, $payload_a);
    self::assertSame([], $result['errors']);
    self::assertSame(2, $result['created']);
    $version_a = Component::load('js.heroBanner')?->getActiveVersion();
    self::assertNotNull($version_a);

    // The second frontend overrides the name, prop titles and prop shapes of
    // the shared component instead of being rejected.
    $payload_b = self::sharedMetadataPayload('Overriding name', 'number');
    \assert(isset($payload_b['components'][0]['props']['anchorId']));
    $payload_b['components'][0]['props']['anchorId']['title'] = 'Section';
    $result = $synchronizer->synchronize($frontend_b, $payload_b);
    self::assertSame([], $result['errors']);
    self::assertSame(1, $result['updated']);
    self::assertSame(1, $result['unchanged']);
    self::assertSame(['js.heroBanner', 'js.cardWithSlot'], $result['components']);
    $component = JavaScriptComponent::load('heroBanner');
    self::assertInstanceOf(JavaScriptComponent::class, $component);
    self::assertSame('Overriding name', $component->label());
    $props = $component->getProps();
    self::assertIsArray($props);
    self::assertSame('Section', $props['anchorId']['title']);
    self::assertSame('number', $props['level']['type']);
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    self::assertSame('Overriding name', $canvas_component->label());
    $version_b = $canvas_component->getActiveVersion();
    self::assertNotSame($version_a, $version_b);
    self::assertSame([[
      'url' => self::FRONTEND_URL,
      'components' => ['js.heroBanner', 'js.cardWithSlot'],
    ], [
      'url' => self::SECOND_FRONTEND_URL,
      'components' => ['js.heroBanner', 'js.cardWithSlot'],
    ],
    ], $this->config('canvas_headless.settings')->get('frontends'));

    // Synchronizing the first frontend again restores its metadata and
    // reactivates its earlier Component version, which remains valid and
    // loadable alongside the second frontend's version.
    $result = $synchronizer->synchronize($frontend_a, $payload_a);
    self::assertSame([], $result['errors']);
    self::assertSame(1, $result['updated']);
    self::assertSame('Original name', JavaScriptComponent::load('heroBanner')?->label());
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    self::assertSame('Original name', $canvas_component->label());
    self::assertSame($version_a, $canvas_component->getActiveVersion());
    self::assertContains($version_b, $canvas_component->getVersions());
    self::assertEntityIsValid($canvas_component);
    $canvas_component->save();
    $canvas_component = Component::load('js.heroBanner');
    self::assertInstanceOf(Component::class, $canvas_component);
    self::assertSame($version_a, $canvas_component->getActiveVersion());
    self::assertSame($version_b, $canvas_component->loadVersion($version_b)->getLoadedVersion());

    // An unchanged resynchronization is a no-op.
    $result = $synchronizer->synchronize($frontend_a, $payload_a);
    self::assertSame(2, $result['unchanged']);
    self::assertSame(0, $result['updated']);
  }

  /**
   * Tests that metadata replaces, keeps and validates translatable strings.
   */
  public function testTranslatableStrings(): void {
    $this->installConfig(['canvas_headless']);
    $frontend = FrontendUrl::fromConfig(self::FRONTEND_URL);
    self::assertInstanceOf(FrontendUrl::class, $frontend);
    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => self::FRONTEND_URL, 'components' => []]])
      ->save();
    $synchronizer = new ExternalComponentSync(
      $this->container->get(ConfigFactoryInterface::class),
      $this->container->get(EntityTypeManagerInterface::class),
      $this->container->get('lock'),
      $this->container->get(LoggerChannelFactoryInterface::class),
      $this->container->get(ComponentSourceManager::class),
      $this->container->get(TypedConfigManagerInterface::class),
      $this->container->get(PropShapeRepositoryInterface::class),
    );
    $payload = static fn (array $extra): array => [
      'version' => 1,
      'components' => [
        [
          'machineName' => 'greeting',
          'name' => 'Greeting',
          'status' => TRUE,
          'required' => [],
          'props' => [],
          'slots' => [],
          'relativeDirectory' => 'greeting',
        ] + $extra,
      ],
      'warnings' => [],
    ];
    $strings = static fn (): mixed => JavaScriptComponent::load('greeting')?->get('translatableStrings');

    // Normalized like the strings the code editor and the Canvas CLI send.
    $result = $synchronizer->synchronize($frontend, $payload([
      'translatableStrings' => [
        ['source' => 'Hello', 'context' => 'Greeting'],
        ['source' => 'Hello'],
        ['source' => 'Hello'],
      ],
    ]));
    self::assertSame([], $result['errors']);
    self::assertSame(1, $result['created']);
    self::assertSame([['source' => 'Hello'], ['source' => 'Hello', 'context' => 'Greeting']], $strings());

    // The same strings leave the component unchanged; other strings replace
    // them.
    $result = $synchronizer->synchronize($frontend, $payload(['translatableStrings' => [['source' => 'Hello'], ['source' => 'Hello', 'context' => 'Greeting']]]));
    self::assertSame(1, $result['unchanged']);
    $result = $synchronizer->synchronize($frontend, $payload(['translatableStrings' => [['source' => 'Bye']]]));
    self::assertSame(1, $result['updated']);
    self::assertSame([['source' => 'Bye']], $strings());

    // Payloads of older SDKs, without strings, keep the stored strings.
    $result = $synchronizer->synchronize($frontend, $payload([]));
    self::assertSame(1, $result['unchanged']);
    self::assertSame([['source' => 'Bye']], $strings());

    // Strings Drupal cannot store are rejected, and the stored strings kept.
    foreach ([
      'must contain only ASCII characters' => [['source' => 'May', 'context' => 'Month – long']],
      'invalid translatableStrings metadata' => 'May',
    ] as $message => $invalid) {
      $result = $synchronizer->synchronize($frontend, $payload(['translatableStrings' => $invalid]));
      self::assertCount(1, $result['errors']);
      self::assertStringContainsString($message, $result['errors'][0]);
      self::assertSame([['source' => 'Bye']], $strings());
    }

    // NULL makes them unknown.
    $result = $synchronizer->synchronize($frontend, $payload(['translatableStrings' => NULL]));
    self::assertSame(1, $result['updated']);
    self::assertNull($strings());
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

  /**
   * Builds a valid payload fixture for cross-frontend synchronization tests.
   */
  private static function sharedMetadataPayload(string $name, string $level_type): array {
    $payload = self::metadataPayload($name, $level_type);
    $payload['components'] = [
      $payload['components'][0],
      $payload['components'][2],
    ];
    $payload['warnings'] = [];
    return $payload;
  }

}
