<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

// cspell:ignore Druplicons

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropSource\DefaultRelativeUrlPropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Provides the basic infrastructure for consistently testing component sources.
 *
 * Every ComponentSource plugin should subclass this. Each must implement
 * `::testDiscovery()`. Most other test methods should depend on it, and test
 * critical ComponentSource plugin functionality, such as:
 * - getting the plugin class (if any) for each component, critical for
 *   restricting XB component trees
 * - a component instance that crashes during rendering due to logic or invalid
 *   input does not result in complete failure
 * - rendering of component instances on the live site
 * - generating client-side info that powers the XB UI
 * - the source-specific settings that were generated for the discovered
 *   Component config entity
 * - calculating of source-specific dependencies
 * - et cetera
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
abstract class ComponentSourceTestBase extends KernelTestBase implements LoggerInterface {

  use RfcLoggerTrait;
  protected array $logMessages = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if ($level <= RfcLogLevel::ERROR) {
      $this->logMessages[] = $message;
    }
  }

  use CiModulePathTrait;
  use CrawlerTrait;
  use ComponentTreeItemInstantiatorTrait;
  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;

  protected readonly EntityStorageInterface $componentStorage;
  protected readonly ComponentIncompatibilityReasonRepository $componentReasonRepository;
  protected readonly ConfigFactoryInterface $configFactory;
  protected readonly ComponentTreeLoader $componentTreeLoader;
  protected readonly RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'file',
    'image',
    'link',
    'options',
    'system',
    'media',
    'path',
    'xb_test_sdc',
    'block',
    'datetime',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->componentReasonRepository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    $this->componentStorage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);
    $this->configFactory = $this->container->get(ConfigFactoryInterface::class);
    $this->componentTreeLoader = $this->container->get(ComponentTreeLoader::class);
    $this->renderer = $this->container->get(RendererInterface::class);
    $this->installEntitySchema('user');
    $this->installSchema('user', 'users_data');
  }

  /**
   * @see ::findCreatedComponentConfigEntities()
   * @see ::findIneligibleComponents()
   */
  abstract public function testDiscovery(): array;

  /**
   * @see ::renderComponentsLive()
   */
  abstract public function testRenderComponentLive(array $component_ids): void;

  /**
   * @see ::getReferencedPluginClasses()
   * @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraint
   */
  abstract public function testGetReferencedPluginClass(array $component_ids): void;

  /**
   * @see ::getAllSettings()
   */
  abstract public function testSettings(array $component_ids): void;

  /**
   * @see ::getAllCalculatedDependencies()
   */
  abstract public function testCalculateDependencies(array $component_ids): void;

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   * @return array<ComponentConfigEntityId, array>
   */
  protected function getAllSettings(array $component_ids): array {
    $this->assertNotEmpty($component_ids);
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();
    $components = $this->componentStorage->loadMultiple($component_ids);

    $settings = [];
    foreach ($components as $component_id => $component) {
      assert($component instanceof Component);
      $settings[$component_id] = $component->getSettings();
    }
    return $settings;
  }

  /**
   * @param string $method_name
   * @param array<ComponentConfigEntityId> $component_ids
   * @return array<ComponentConfigEntityId, array>
   */
  protected function callSourceMethodForEach(string $method_name, array $component_ids): array {
    $this->assertNotEmpty($component_ids);
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();
    $components = $this->componentStorage->loadMultiple($component_ids);

    $return_values = [];
    foreach ($components as $component_id => $component) {
      assert($component instanceof Component);
      $return_values[$component_id] = match ($method_name) {
        'getClientSideInfo' => $component->getComponentSource()->getClientSideInfo($component),
        default => $component->getComponentSource()->$method_name(),
      };
    }
    return $return_values;
  }

  public function findCreatedComponentConfigEntities(string $component_source_plugin_id, string $test_module): array {
    // @phpstan-ignore-next-line
    $component_config_entity_type_prefix = $this->componentStorage->getEntityType()->getConfigPrefix();

    // Construct a config prefix to discover all Component config entities
    // created for the tested ComponentSource's test module.
    $prefix = sprintf(
      '%s.%s.%s',
      $component_config_entity_type_prefix,
      $component_source_plugin_id,
      $test_module,
    );

    // Transform from `experience_builder.component.<ID>` to just `<ID>`.
    $discovered_component_config_names = $this->configFactory->listAll($prefix);
    $discovered_component_entity_ids = array_map(
      fn(string $config_name) => str_replace("$component_config_entity_type_prefix.", '', $config_name),
      $discovered_component_config_names
    );

    ksort($discovered_component_entity_ids);
    return $discovered_component_entity_ids;
  }

  public function findIneligibleComponents(string $component_source_plugin_id, string $test_module): array {
    $ineligible_components = $this->componentReasonRepository->getReasons()[$component_source_plugin_id] ?? [];
    ksort($ineligible_components);
    return array_filter(
      $ineligible_components,
      fn (string $id) => str_starts_with($id, $component_source_plugin_id . '.' . $test_module),
      ARRAY_FILTER_USE_KEY,
    );
  }

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   * @return array<ComponentConfigEntityId, class-string|null>
   */
  protected function getReferencedPluginClasses(array $component_ids): array {
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();

    $actual_classes = [];
    foreach ($this->componentStorage->loadMultiple($component_ids) as $component_id => $component) {
      assert($component instanceof Component);
      $actual_classes[$component_id] = $component->getComponentSource()->getReferencedPluginClass();
    }
    return $actual_classes;
  }

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   */
  protected function renderComponentsLive(array $component_ids, callable $get_default_input): array {
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();

    $rendered = [];
    foreach ($this->componentStorage->loadMultiple($component_ids) as $component_id => $component) {
      assert($component instanceof ComponentInterface);
      $build = $component->getComponentSource()->renderComponent(
        $get_default_input($component),
        'some-uuid',
        // Live: `isPreview: FALSE`.
        FALSE,
      );
      $html = (string) $this->renderer->renderInIsolation($build);
      // Strip trailing whitespace to make heredocs easier to write.
      $html = preg_replace('/ +$/m', '', $html);
      assert(is_string($html));
      // Make it easier to write expectations containing root-relative URLs
      // pointing somewhere into the site-specific directory.
      $html = str_replace(base_path() . $this->siteDirectory, '::SITE_DIR_BASE_URL::', $html);
      $html = str_replace(self::getCiModulePath(), '::XB_MODULE_PATH::', $html);
      // Ensure predictable order of cache contexts & tags.
      // @see https://www.drupal.org/node/3230171
      sort($build['#cache']['contexts']);
      sort($build['#cache']['tags']);
      $rendered[$component_id] = [
        'html' => $html,
        'cacheability' => CacheableMetadata::createFromRenderArray($build),
        'attachments' => BubbleableMetadata::createFromRenderArray($build)->getAttachments(),
      ];
    }
    return $rendered;
  }

  /**
   * For use with ::renderComponentsLive() for Sources with generated input UX.
   *
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getDefaultStaticPropSource()
   */
  protected static function getDefaultInputForGeneratedInputUx(Component $component): array {
    assert($component->getComponentSource() instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    $explicit_inputs = [];
    foreach ($component->getSettings()['prop_field_definitions'] as $sdc_prop_name => $prop_field_definition) {
      if ($prop_field_definition['default_value'] === NULL) {
        continue;
      }

      // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
      // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getDefaultStaticPropSource()
      if ($prop_field_definition['default_value'] === []) {
        // @phpstan-ignore-next-line
        $client_side_info_for_prop = $component->getComponentSource()
          ->getClientSideInfo($component)['propSources'][$sdc_prop_name];

        // The prop might be optional without a default value.
        if (!array_key_exists('default_values', $client_side_info_for_prop)) {
          continue;
        }

        $explicit_inputs[$sdc_prop_name] = (new DefaultRelativeUrlPropSource(
          value: $client_side_info_for_prop['default_values']['resolved'],
          jsonSchema: $client_side_info_for_prop['jsonSchema'],
          componentId: $component->id(),
        ))->evaluate(NULL);

        continue;
      }

      $explicit_inputs[$sdc_prop_name] = StaticPropSource::parse([
        'sourceType' => 'static:field_item:' . $prop_field_definition['field_type'],
        'value' => $prop_field_definition['default_value'],
        'expression' => $prop_field_definition['expression'],
        'sourceTypeSettings' => [
          'cardinality' => $prop_field_definition['cardinality'] ?? 1,
          'storage' => $prop_field_definition['field_storage_settings'] ?? [],
          'instance' => $prop_field_definition['field_instance_settings'] ?? [],
        ],
      ])
        // Static prop sources can be evaluated without a host entity.
        ->evaluate(NULL);
    }
    return [GeneratedFieldExplicitInputUxComponentSourceBase::EXPLICIT_INPUT_NAME => $explicit_inputs];
  }

  /**
   * Constructs the component tree to use for testing crash resistance.
   *
   * Renders the potentially crashing component:
   * - nested (not in the root level), to be able to assert that a parent
   *   component instance still renders
   * - with a component instance in an adjacent slot
   * - with a component instance both immediately before and after it
   *
   * The containing component is always the "two-column" SDC. All the other non-
   * crash component instances are the "Druplicon" SDCs.
   * The use of SDCs does not make this dummy component tree SDC-specific,
   * because the crashing component instance will be provided by the tested
   * ComponentSource plugin. Not every ComponentSource plugin supports slots.
   *
   * In other words: if there's 3 Druplicons detected, then all is good!
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
   */
  protected function generateCrashTestDummyComponentTree(string $component_id, array $inputs): ComponentTreeItem {
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();

    $field_item = $this->createDanglingComponentTree();
    $field_item->setValue([
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          [
            'uuid' => 'container',
            'component' => 'sdc.experience_builder.two_column',
          ],
        ],
        'container' => [
          'column_one' => [
            [
              'uuid' => 'component-before-crash',
              'component' => 'sdc.experience_builder.druplicon',
            ],
            [
              // @see https://en.wikipedia.org/wiki/Crash_test_dummy
              'uuid' => 'crash-test-dummy',
              'component' => $component_id,
            ],
            [
              'uuid' => 'component-after-crash',
              'component' => 'sdc.experience_builder.druplicon',
            ],
          ],
          'column_two' => [
            [
              'uuid' => 'slot-adjacent-to-crash',
              'component' => 'sdc.experience_builder.druplicon',
            ],
          ],
        ],
      ],
      'inputs' => [
        // Pass the crash test dummy component instance inputs as-is.
        'crash-test-dummy' => $inputs,
        // The container has a single explicit input that it requires; this can
        // be hardcoded.
        'container' => [
          'width' => StaticPropSource::generate(
            expression: new FieldTypePropExpression('integer', 'value'),
            cardinality: 1,
          )->withValue(33)->toArray(),
        ],
      ],
    ]);
    return $field_item;
  }

  /**
   * @dataProvider providerRenderComponentFailure
   * $expected_exception array{'class': string, 'message': string}|NULL
   */
  public function testRenderComponentFailure(string $component_id, array $inputs, array $expected_validation_errors, ?array $expected_exception, ?string $expected_output_selector): void {
    $this->container->get('logger.factory')->addLogger($this);

    $component_tree = $this->generateCrashTestDummyComponentTree($component_id, $inputs);

    // Unless explicitly expected to be invalid, inputs should be valid.
    $this->assertSame($expected_validation_errors, $this->violationsToArray($component_tree->validate()), 'Unrealistic test case encountered: it must still represent a valid component tree!');
    $page = Page::create([
      'title' => 'A page',
    ]);
    $exception_output = [
      // When preview is TRUE, should refer user to logs.
      'Component failed to render, check logs for more detail.' => TRUE,
      // When preview is FALSE, should show a more user-friendly message.
      'Oops, something went wrong! Site admins have been notified.' => FALSE,
    ];
    foreach ($exception_output as $displayedMessage => $isPreview) {
      $this->logMessages = [];
      // Make sure we don't get incremented IDs when rendering blocks.
      Html::resetSeenIds();
      $build = $component_tree->toRenderable($page, $isPreview);
      if (is_array($expected_exception)) {
        $crawler = $this->crawlerForRenderArray($build);
        self::assertCount(1, $this->logMessages, \implode(',', $this->logMessages));
        $message = \reset($this->logMessages);
        \assert(\is_string($message));
        self::assertStringContainsString($expected_exception['message'], $message);
        self::assertStringContainsString('Page A page (-)', $message);
        self::assertStringContainsString($expected_exception['class'], $message);
        self::assertCount(1, $crawler->filter(\sprintf('[data-component-uuid="crash-test-dummy"]:contains("%s")', $displayedMessage)));
      }
      else {
        $crawler = self::assertRenderArrayMatchesSelectors($build, [$expected_output_selector]);
        \assert(!\is_null($crawler));
        // All 3 surrounding Druplicons must also be present, as proof
        // that any problem remains isolated!
        self::assertCount(3, $crawler->filter('svg title:contains("Druplicon")'));
      }
    }
  }

  protected function assertRenderArrayMatchesSelectors(array $build, array $selectors): ?Crawler {
    if ([] === $selectors) {
      self::assertSame('', (string) $this->renderer->renderInIsolation($build));
      return NULL;
    }
    $crawler = $this->crawlerForRenderArray($build);
    foreach ($selectors as $selector) {
      self::assertGreaterThanOrEqual(
        1,
        $crawler->filter($selector)->count(),
        "Failed finding selector '$selector'"
      );
    }
    return $crawler;
  }

  abstract public static function providerRenderComponentFailure(): \Generator;

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   *   The component IDs to test.
   *
   * @covers ::getClientSideInfo()
   * @depends testDiscovery
   */
  public function testGetClientSideInfo(array $component_ids): void {
    $expected_client_side_info = static::getExpectedClientSideInfo();
    $actual_client_side_info = $this->callSourceMethodForEach('getClientSideInfo', $component_ids);

    // Test `build` using `expected_output_selectors`.
    foreach ($component_ids as $component_id) {
      if (!array_key_exists($component_id, $expected_client_side_info)) {
        throw new \OutOfRangeException(sprintf('Test expectations missing for %s.', $component_id));
      }
      $expected_output_selectors = $expected_client_side_info[$component_id]['expected_output_selectors'];
      unset($expected_client_side_info[$component_id]['expected_output_selectors']);
      $build = $actual_client_side_info[$component_id]['build'];
      unset($actual_client_side_info[$component_id]['build']);
      $this->assertRenderArrayMatchesSelectors($build, $expected_output_selectors);
    }

    // Test all other expected client-side info.
    self::assertSame($expected_client_side_info, $actual_client_side_info);
  }

  /**
   * Return the associative array of the expected build on each component.
   */
  abstract public static function getExpectedClientSideInfo(): array;

  /**
   * Build and save a component that can be used for testing fallback behavior.
   *
   * @return \Drupal\experience_builder\Entity\ComponentInterface
   */
  abstract protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface;

  /**
   * Build and save a component that is not in use for testing fallback behavior.
   *
   * @return \Drupal\experience_builder\Entity\ComponentInterface
   */
  abstract protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface;

  /**
   * Perform an action that will cause a fallback for the given components.
   */
  abstract protected function forceComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void;

  /**
   * Perform an action that will cause a component to recover from the fallback.
   *
   * @param \Drupal\experience_builder\Entity\ComponentInterface $component
   */
  abstract protected function recoverComponentFallback(ComponentInterface $component): void;

  protected static function getPropsForComponentFallbackTesting(): array {
    return [];
  }

  public function testFallback(): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->generateComponentConfig();
    $used_component = $this->createAndSaveInUseComponentForFallbackTesting();
    $unused_component = $this->createAndSaveUnusedComponentForFallbackTesting();
    $component_label = $used_component->label();
    $source = $used_component->getComponentSource();
    $slots = [];
    if ($source instanceof ComponentSourceWithSlotsInterface) {
      $slots = \array_keys($source->getSlotDefinitions());
    }

    $entity = Page::create([
      'title' => $this->randomMachineName(),
      'components' => self::generateFallbackComponentTree($used_component, $slots),
    ]);
    // Save this so the usage can be queried.
    $entity->save();
    $hydrated = $entity->getComponentTree()->get('hydrated');
    $renderable = $hydrated->toRenderable($entity, TRUE);
    $out = $this->crawlerForRenderArray($renderable);
    // Should be no fallback container.
    self::assertCount(0, $out->filter('[data-fallback]'));
    foreach ($slots as $slot) {
      // Children should render in the slots.
      self::assertCount(1, $out->filter(\sprintf('h1:contains("This is %s")', $slot)));
    }

    // Trigger an action that causes the components to perform
    // ::onDependencyRemoval and update its source plugin to use the fallback.
    $this->forceComponentFallback($used_component, $unused_component);
    $component_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Component::ENTITY_TYPE_ID);
    $used_component = $component_storage->loadUnchanged($used_component->id());
    \assert($used_component instanceof ComponentInterface);
    // Assert that the component has the same label, despite being dropped back
    // to a fallback.
    self::assertEquals($component_label, $used_component->label());
    self::assertFalse($used_component->status());
    // Assert that the component without any usage was cascade-deleted.
    self::assertNull($component_storage->loadUnchanged($unused_component->id()));
    // Assert that we can still render the fallback component and any children
    // in its slots.
    $hydrated = $entity->getComponentTree()->get('hydrated');
    $renderable = $hydrated->toRenderable($entity, TRUE);
    $out = $this->crawlerForRenderArray($renderable);
    // Should be a fallback container.
    self::assertGreaterThanOrEqual(1, $out->filter('[data-fallback]')->count());
    foreach ($slots as $slot) {
      // Children should still render in the slots even though it is a fallback.
      self::assertCount(1, $out->filter(\sprintf('h1:contains("This is %s")', $slot)));
    }
    // We should also have the HTML comments that allow overlays to work.
    $html = \trim(\preg_replace('/\s+/', ' ', $out->html()) ?: '');
    foreach ($slots as $slot_name) {
      self::assertMatchesRegularExpression(sprintf('/<!-- xb-slot-start-uuid-in-root\/%s -->/', $slot_name), $html);
      self::assertMatchesRegularExpression(sprintf('/xb-slot-end-(.*)\/%s -->/', $slot_name), $html);
    }

    if (static::class === BlockComponentTest::class) {
      // @todo Update Component entities with BlockComponent source plugin: https://drupal.org/i/3484682
      $this->markTestIncomplete('Block components do not yet update component config entities');
    }
    // Now perform an action that causes the component to recover from the
    // fallback.
    $this->recoverComponentFallback($used_component);
    $hydrated = $entity->getComponentTree()->get('hydrated');
    $renderable = $hydrated->toRenderable($entity, TRUE);
    $out = $this->crawlerForRenderArray($renderable);
    // Should be no fallback container.
    self::assertCount(0, $out->filter('[data-fallback]'));
    foreach ($slots as $slot) {
      // Children should still render in the slots.
      self::assertCount(1, $out->filter(\sprintf('h1:contains("This is %s")', $slot)));
    }
  }

  private static function generateFallbackComponentTree(ComponentInterface $component, array $slots): array {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        // Place the component that will become a fallback in the root of the
        // tree.
        ['uuid' => 'uuid-in-root', 'component' => $component->id()],
      ],
    ];
    $inputs = [
      // Populate any input as appropriate.
      'uuid-in-root' => static::getPropsForComponentFallbackTesting(),
    ];
    // Ensure we have something in each slot. When we trigger the conditions
    // that result in the component switching to use the 'fallback' plugin, we
    // want to ensure that any components placed in slots as children continue
    // to render.
    foreach ($slots as $slot) {
      // Generate a unique ID for each child component.
      $uuid = \sprintf('uuid-in-%s', $slot);
      // And place it inside the parent slot.
      $tree['uuid-in-root'][$slot] = [
        ['uuid' => $uuid, 'component' => 'sdc.experience_builder.heading'],
      ];
      // Give it some inputs we can assert still exist when the fallback
      // conditions are triggered.
      $inputs[$uuid] = [
        'text' => [
          'sourceType' => 'static:field_item:string',
          'value' => \sprintf('This is %s', $slot),
          'expression' => 'ℹ︎string␟value',
        ],
        'element' => [
          'sourceType' => 'static:field_item:list_string',
          'value' => 'h1',
          'expression' => 'ℹ︎list_string␟value',
        ],
      ];
    }
    // Return component values.
    return [
      'tree' => $tree,
      'inputs' => $inputs,
    ];
  }

}
