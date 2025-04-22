<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

/**
 * Provides the basic infrastructure for consistently testing component sources.
 *
 * Every ComponentSource plugin should subclass this. Each must implement
 * `::testDiscovery()`. Most other test methods should depend on it, and test
 * critical ComponentSource plugin functionality, such as:
 * - getting the plugin class (if any) for each component, critical for
 *   restricting XB component trees
 * - rendering of component instances on the live site
 * - generating client-side info that powers the XB UI
 * - the source-specific settings that were generated for the discovered
 *   Component config entity
 * - calculating of source-specific dependencies
 * - et cetera
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 *
 * @todo Move BlockComponentTest::testGetClientSideInfo() into this base class in https://www.drupal.org/i/3518832
 */
abstract class ComponentSourceTestBase extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use TestDataUtilitiesTrait;

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
   * @param array<ComponentConfigEntityId> $component_ids
   * @return array<ComponentConfigEntityId, array>
   */
  protected function getAllCalculatedDependencies(array $component_ids): array {
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();
    $components = $this->componentStorage->loadMultiple($component_ids);

    $settings = [];
    foreach ($components as $component_id => $component) {
      assert($component instanceof Component);
      $settings[$component_id] = $component->getComponentSource()->calculateDependencies();
    }
    return $settings;
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
  protected function renderComponentsLive($component_ids, callable $get_default_input): array {
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
      $rendered[$component_id] = [
        // Strip trailing whitespace to make heredocs easier to write.
        'html' => preg_replace('/ +$/m', '', $html),
        'cacheability' => CacheableMetadata::createFromRenderArray($build),
      ];
    }
    return $rendered;
  }

}
