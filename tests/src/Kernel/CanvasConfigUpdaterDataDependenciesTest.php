<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\JavaScriptComponent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the import-based data dependency backfill for code components.
 */
#[CoversClass(CanvasConfigUpdater::class)]
#[Group('canvas')]
final class CanvasConfigUpdaterDataDependenciesTest extends CanvasKernelTestBase {

  /**
   * @return \Generator<string, array{string, string[]}>
   */
  public static function provideSources(): \Generator {
    yield 'legacy getters' => [
      "import { getPageData, getSiteData } from 'drupal-canvas';",
      ['v0.baseUrl', 'v0.branding', 'v0.breadcrumbs', 'v0.pageTitle'],
    ];
    yield 'context hooks' => [
      "import { usePageContext, useSiteContext, useJsonApiClient } from 'drupal-canvas/react';",
      ['v0.baseUrl', 'v0.branding', 'v0.themeAssets', 'v0.breadcrumbs', 'v0.pageTitle', 'v0.mainEntity', 'v0.jsonapiSettings'],
    ];
    yield 'no data APIs' => [
      "import { cn } from 'drupal-canvas';",
      [],
    ];
  }

  #[DataProvider('provideSources')]
  public function testBackfill(string $js, array $expected): void {
    $component = JavaScriptComponent::create([
      'machineName' => 'backfill_test',
      'name' => 'Backfill test',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => $js, 'compiled' => $js],
      'css' => ['original' => '', 'compiled' => ''],
      // Pre-dataDependencies config entities trigger the backfill.
      'dataDependencies' => NULL,
    ]);
    $updater = $this->container->get(CanvasConfigUpdater::class);
    $updater->setDeprecationsEnabled(FALSE);
    $updater->updateJavaScriptComponent($component);
    $data_dependencies = $component->get('dataDependencies');
    self::assertSame(
      $expected === [] ? [] : ['drupalSettings' => $expected],
      $data_dependencies,
    );
    self::assertSame(
      \array_map(static fn (string $setting): string => 'canvas/canvasData.' . $setting, $expected),
      $component->getAssetLibraryDependencies(),
    );
  }

}
