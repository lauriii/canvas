<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading a provided module's source.
 */
#[CoversClass(ProvidedModule::class)]
#[Group('canvas_ssr')]
final class ProvidedModuleTest extends UnitTestCase {

  /**
   * Reads a file's source once, when it's first needed.
   */
  public function testFromFileReadsOnce(): void {
    $path = \tempnam(\sys_get_temp_dir(), 'canvas_ssr_');
    \assert(\is_string($path));
    try {
      \file_put_contents($path, 'export default 1;');
      $module = ProvidedModule::fromFile('/x.js', $path, ['x']);
      self::assertSame(['x'], $module->specifiers);
      self::assertSame('export default 1;', $module->source());

      \file_put_contents($path, 'export default 2;');
      self::assertSame('export default 1;', $module->source());
    }
    finally {
      @\unlink($path);
    }
  }

  /**
   * Has no source when the file is missing, or nothing can read it.
   */
  public function testNoSource(): void {
    self::assertNull(ProvidedModule::fromFile('/x.js', '/nonexistent/x.js')->source());
    self::assertNull((new ProvidedModule('/x.js', read: static fn() => NULL))->source());
  }

}
