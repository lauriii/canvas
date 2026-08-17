<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_ai\Controller\CanvasBuilder;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ensures the documented packages stay in sync with the AI's library context.
 *
 * The code component AI agent is told which packages are available through
 * \Drupal\canvas_ai\Controller\CanvasBuilder::getSupportedLibraries(). The same
 * packages are documented for humans in packages.mdx. This test guards that
 * every package advertised to the AI is also documented, and that the list of
 * advertised packages cannot change without a deliberate update here.
 *
 * It intentionally checks the package list rather than a hash of the whole
 * file: unrelated documentation edits (prose, links, code examples) must not
 * cause spurious failures, only adding or removing a package must. The AI
 * reads getSupportedLibraries(), not the docs, so the enforced direction is
 * advertised-to-AI implies documented; a package documented in packages.mdx
 * but not advertised to the AI is intentionally not flagged.
 */
#[Group('canvas_ai')]
class CanvasPackagesDocsSyncTest extends CanvasKernelTestBase {

  /**
   * Tests every library advertised to the AI is documented in packages.mdx.
   */
  public function testSupportedLibrariesAreDocumented(): void {
    // Path to the packages file as defined in the docs section.
    $file_path = __DIR__ . '/../../../../../docs/user/src/content/docs/code-components/packages.mdx';
    $this->assertFileExists($file_path);
    $docs = file_get_contents($file_path);
    $this->assertIsString($docs);

    // A distinctive token that must appear in packages.mdx for each library
    // name in getSupportedLibraries(). Each token is unique to its package's
    // own section (a source-link path, a package identifier, an example value,
    // or a heading), so it disappears only when that package's section is
    // removed, not when unrelated prose, links, or code examples are edited.
    // When a library is added to the AI context, add its documentation token
    // here and document the package in packages.mdx; when one is removed,
    // remove it from both places.
    $documented_tokens = [
      'formatted_text' => 'src/FormattedText.tsx',
      'cn' => 'src/utils.ts',
      'tailwind' => '--color-drupal-blue',
      'clsx' => '### clsx',
      'class_variance_authority' => 'class-variance-authority',
      'json_api_client' => '@drupal-api-client/json-api-client',
      'drupal_jsonapi_params' => 'drupal-jsonapi-params',
      'swr' => '### swr',
      'tailwind_merge' => 'tailwind-merge',
      'tailwindcss_typography' => '@tailwindcss/typography',
    ];

    // Read the canonical library list straight from the controller without
    // booting the AI stack: getSupportedLibraries() returns a literal array and
    // does not use $this, so an instance without a constructor is enough.
    $method = new \ReflectionMethod(CanvasBuilder::class, 'getSupportedLibraries');
    $libraries = $method->invoke((new \ReflectionClass(CanvasBuilder::class))->newInstanceWithoutConstructor());
    $this->assertIsArray($libraries);
    $names = array_column($libraries, 'name');

    // Every library advertised to the AI must have a documentation token here,
    // and every token must correspond to an advertised library. This forces a
    // deliberate update whenever the supported library list changes.
    $this->assertEqualsCanonicalizing(
      $names,
      \array_keys($documented_tokens),
      'The libraries in CanvasBuilder::getSupportedLibraries() and the documented tokens in this test are out of sync. Update both this test and packages.mdx to match the supported library list.'
    );

    // Every supported library must be documented in packages.mdx.
    foreach ($names as $name) {
      $this->assertStringContainsString(
        $documented_tokens[$name],
        $docs,
        \sprintf('The "%s" package is advertised to the AI in CanvasBuilder::getSupportedLibraries() but is not documented in packages.mdx.', $name)
      );
    }
  }

}
