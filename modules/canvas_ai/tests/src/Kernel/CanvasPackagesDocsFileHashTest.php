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
    $expected_hash = '643a8826c58f8947cd7eac144df21c0674b1c8095eaf2611009c75c1656ae066';

    $this->assertFileExists($file_path);

    $actual_hash = hash_file('sha256', $file_path);

    $this->assertSame(
      $expected_hash,
      $actual_hash,
      'Library definitions are out of sync. The changes made to the packages.mdx file must be registered in CanvasBuilder::getSupportedLibraries().'
    );
  }

}
