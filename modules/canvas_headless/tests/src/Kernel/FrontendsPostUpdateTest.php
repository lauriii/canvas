<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Headless post-update hooks.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class FrontendsPostUpdateTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
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
    $this->installConfig(['canvas_headless']);
    $this->includePostUpdateFile();
  }

  /**
   * Tests that existing external components are assigned on upgraded sites.
   */
  public function testPostUpdateAssignsExistingExternalComponents(): void {
    JavaScriptComponent::create([
      'machineName' => 'alpha',
      'name' => 'Alpha',
      'status' => TRUE,
      'type' => 'external',
      'props' => [],
      'required' => [],
      'slots' => [],
      'dataDependencies' => [],
    ])->save();
    JavaScriptComponent::create([
      'machineName' => 'local',
      'name' => 'Local',
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();
    JavaScriptComponent::create([
      'machineName' => 'beta',
      'name' => 'Beta',
      'status' => TRUE,
      'type' => 'external',
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => 'export default function Beta() {}', 'compiled' => 'export default function Beta() {}'],
      'css' => ['original' => '.beta { display: block; }', 'compiled' => '.beta{display:block}'],
      'dataDependencies' => [],
    ])->save();

    $storage = $this->container->get(StorageCacheInterface::class);
    $storage->write('canvas_headless.settings', [
      'frontends' => [
        // Simulate a legacy entry from before per-frontend ownership existed.
        // The update's save is validated against the current schema, which
        // requires the cache invalidation switch.
        ['url' => 'https://first.example/app', 'cache_invalidation' => FALSE],
        // Simulate a frontend that already has explicit ownership and must not
        // be overwritten by the backfill.
        ['url' => 'https://second.example/app', 'components' => ['js.existing'], 'cache_invalidation' => FALSE],
      ],
      'assertion_expiration' => 60,
    ]);
    $this->container->get(ConfigFactoryInterface::class)->reset('canvas_headless.settings');

    canvas_headless_post_update_0001_assign_existing_external_components();

    self::assertSame([
      // Legacy frontends receive every existing external component because the
      // old config shape had no way to record narrower ownership.
      ['url' => 'https://first.example/app', 'components' => ['js.alpha', 'js.beta'], 'cache_invalidation' => FALSE],
      // Already-migrated frontends keep their explicit ownership unchanged.
      ['url' => 'https://second.example/app', 'components' => ['js.existing'], 'cache_invalidation' => FALSE],
    ], $this->config('canvas_headless.settings')->get('frontends'));
  }

  /**
   * Tests that rerunning the update is harmless.
   */
  public function testPostUpdateIsIdempotent(): void {
    $this->config('canvas_headless.settings')
      ->set('frontends', [
        ['url' => 'https://first.example/app', 'components' => ['js.alpha'], 'cache_invalidation' => TRUE],
      ])
      ->save();

    canvas_headless_post_update_0001_assign_existing_external_components();
    canvas_headless_post_update_0002_add_cache_invalidation_switch();

    self::assertSame([
      ['url' => 'https://first.example/app', 'components' => ['js.alpha'], 'cache_invalidation' => TRUE],
    ], $this->config('canvas_headless.settings')->get('frontends'));
  }

  /**
   * Tests that existing frontends get the cache invalidation switch.
   */
  public function testPostUpdateAddsCacheInvalidationSwitch(): void {
    $storage = $this->container->get(StorageCacheInterface::class);
    $storage->write('canvas_headless.settings', [
      'frontends' => [
        // Simulate an entry from before the switch existed.
        ['url' => 'https://first.example/app', 'components' => ['js.alpha']],
        // Simulate a frontend whose switch was turned on since.
        ['url' => 'https://second.example/app', 'components' => [], 'cache_invalidation' => TRUE],
      ],
      'assertion_expiration' => 60,
    ]);
    $this->container->get(ConfigFactoryInterface::class)->reset('canvas_headless.settings');

    canvas_headless_post_update_0002_add_cache_invalidation_switch();

    self::assertSame([
      // Announcing invalidated cache tags is opt-in.
      ['url' => 'https://first.example/app', 'components' => ['js.alpha'], 'cache_invalidation' => FALSE],
      ['url' => 'https://second.example/app', 'components' => [], 'cache_invalidation' => TRUE],
    ], $this->config('canvas_headless.settings')->get('frontends'));
  }

  /**
   * Includes the Canvas Headless post-update file.
   */
  private function includePostUpdateFile(): void {
    $module_path = $this->container->get(ModuleExtensionList::class)->getPath('canvas_headless');
    require_once DRUPAL_ROOT . '/' . $module_path . '/canvas_headless.post_update.php';
  }

}
