<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Test to ensure the Canvas packages doc file has not changed.
 *
 * @group canvas_ai
 */
class CanvasPackagesDocsFileHashTest extends KernelTestBase {

  /**
   * Tests the hash of the packages doc file.
   */
  public function testLibrariesFileHash(): void {
    // Path to the packages file as defined in the docs section.
    $file_path = __DIR__ . '/../../../../../docs/user/src/content/docs/code-components/packages.mdx';
    $expected_hash = '5f0692937656ba9507ad247ee085e10c6d18849e76117e65a2888893816204ca';

    $this->assertFileExists($file_path);

    $actual_hash = hash_file('sha256', $file_path);

    $this->assertSame(
      $expected_hash,
      $actual_hash,
      'Library definitions are out of sync. The changes made to the packages.mdx file must be registered in CanvasBuilder::getSupportedLibraries().'
    );
  }

}
