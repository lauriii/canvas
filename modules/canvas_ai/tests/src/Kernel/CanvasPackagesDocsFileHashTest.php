<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test to ensure the Canvas packages doc file has not changed.
 */
#[Group('canvas_ai')]
class CanvasPackagesDocsFileHashTest extends CanvasKernelTestBase {

  /**
   * Tests the hash of the packages doc file.
   */
  public function testLibrariesFileHash(): void {
    // Path to the packages file as defined in the docs section.
    $file_path = __DIR__ . '/../../../../../docs/user/src/content/docs/code-components/packages.mdx';
    $expected_hash = 'd435eafb969326b176dd08c02e1dd5ad20535d954b94ba1277a2d6706ed4da87';

    $this->assertFileExists($file_path);

    $actual_hash = hash_file('sha256', $file_path);

    $this->assertSame(
      $expected_hash,
      $actual_hash,
      'Library definitions are out of sync. The changes made to the packages.mdx file must be registered in CanvasBuilder::getSupportedLibraries().'
    );
  }

}
