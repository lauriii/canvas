<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\GlobalImports;
use Drupal\Tests\canvas\Kernel\Traits\CacheBustingTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests GlobalImports.
 */
#[CoversClass(GlobalImports::class)]
#[Group('canvas')]
final class GlobalImportsTest extends CanvasKernelTestBase {

  use CacheBustingTrait;

  public function testGetImportMapReturnsBaseImports(): void {
    $this->setCacheBustingQueryString($this->container, '1.2.3');
    $globalImports = $this->container->get(GlobalImports::class);
    $map = $globalImports->getImportMap();

    self::assertArrayHasKey('imports', $map);

    // Spot-check some known imports.
    self::assertArrayHasKey('preact', $map['imports']);
    self::assertArrayHasKey('clsx', $map['imports']);
    self::assertArrayHasKey('drupal-canvas', $map['imports']);

    // Cache-busting query strings are appended.
    self::assertStringEndsWith('?1.2.3', $map['imports']['preact']);
  }

  public function testHookCanAlterGlobalImports(): void {
    $this->enableModules(['canvas_test_importmap_alter']);
    $this->setCacheBustingQueryString($this->container, '1.2.3');
    $globalImports = $this->container->get(GlobalImports::class);
    $map = $globalImports->getImportMap();

    // The hook added a new global import.
    self::assertArrayHasKey('test-added-package', $map['imports']);
    self::assertSame('/modules/test/js/test-added-package.js?1.2.3', $map['imports']['test-added-package']);

    // The hook overrode an existing global import.
    self::assertSame('/modules/test/js/custom-clsx.js?1.2.3', $map['imports']['clsx']);
  }

  public function testHookCanAddScopedImports(): void {
    $this->enableModules(['canvas_test_importmap_alter']);
    $globalImports = $this->container->get(GlobalImports::class);
    $map = $globalImports->getImportMap();

    // The hook added scoped imports.
    self::assertArrayHasKey('scopes', $map);
    self::assertArrayHasKey('/modules/test/js/', $map['scopes']);
    self::assertSame('/modules/test/js/custom-preact.js', $map['scopes']['/modules/test/js/']['preact']);
  }

}
