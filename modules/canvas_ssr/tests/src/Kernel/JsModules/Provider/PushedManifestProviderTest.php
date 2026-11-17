<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules\Provider;

use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\SsrImportMap;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\GlobalAssetLibraryTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which modules a CLI push provides.
 *
 * Nothing here renders, so no runner is needed. PushedManifestModulesTest
 * renders what a push uploaded. The symlink test changes public:// and
 * rebuilds the container, so it has its own method.
 *
 * The merge memoizes its providers' answers and drops the memo on their
 * cache tags. The unit test covers which tags drop what. The re-push scenario
 * checks the real thing: the provider declares the tag that saving the asset
 * library invalidates.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\AllProvidedModules
 * @legacy-covers \Drupal\canvas_ssr\JsModules\Provider\CanvasPushedManifestModuleProvider
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsModules\Provider\PushedManifestModulesTest
 * @see \Drupal\Tests\canvas_ssr\Unit\JsModules\AllProvidedModulesTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class PushedManifestProviderTest extends CanvasSsrKernelTestBase {

  use GlobalAssetLibraryTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Saving the global AssetLibrary records file usage.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->cleanupGlobalAssetLibraryModules();
    parent::tearDown();
  }

  /**
   * Tests which manifest entries are provided, and what a re-push replaces.
   *
   * The scenarios share one site install. Each save replaces the whole
   * manifest, so none depends on another.
   */
  public function testPushedManifestEntries(): void {
    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    $url = static fn (string $uri): string => $file_url_generator->generateString($uri);
    $provided = \Drupal::service(AllProvidedModules::class);
    \assert($provided instanceof AllProvidedModules);
    $ssr_import_map = \Drupal::service(SsrImportMap::class);
    \assert($ssr_import_map instanceof SsrImportMap);

    $this->assertScenarios([
      'a re-push replaces what is provided, rather than adding' => function () use ($provided): void {
        $first = $this->pushLibraryModule('pushed-B1aQxK9z.js', 'first');
        self::assertArrayHasKey($first, $provided->sources());

        // Only the save happens in between: no cache clear, no container
        // rebuild.
        $second = $this->pushLibraryModule('pushed-Xz7Lm2Qd.js', 'second');

        self::assertArrayHasKey(
          $second,
          $provided->sources(),
          'The module the current manifest names resolves.',
        );
        // The first file is still on disk but no longer in the manifest, so
        // it must not resolve. That shows the memo was rebuilt, not added to.
        self::assertArrayNotHasKey(
          $first,
          $provided->sources(),
          'The module the manifest no longer names does not resolve.',
        );
      },

      // A push uploads .js files flat into one directory. An entry naming
      // anything else is stale or tampered with, and is skipped. The other
      // entries are still provided.
      'a manifest entry naming anything but an uploaded module is skipped' => function () use ($provided, $url): void {
        $file_system = \Drupal::service(FileSystemInterface::class);
        \assert($file_system instanceof FileSystemInterface);
        $write = function (string $uri) use ($file_system): void {
          $directory = \dirname($uri);
          $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
          $file_system->saveData('export const x = 1;', $uri, FileExists::Replace);
          self::assertFileExists((string) $file_system->realpath($uri));
        };
        $write('public://canvas/elsewhere.js');
        $write('public://canvas/assets/sub/nested.js');
        $write('public://canvas/assets/logo.svg');
        $write('public://canvas/assets/real.js');

        $this->setGlobalAssetLibraryEntries([
          ['name' => 'above', 'uri' => 'public://canvas/elsewhere.js'],
          // The same file again, spelled as a path out of the directory.
          ['name' => 'traversal', 'uri' => 'public://canvas/assets/../elsewhere.js'],
          ['name' => 'below', 'uri' => 'public://canvas/assets/sub/nested.js'],
          ['name' => 'not a module', 'uri' => 'public://canvas/assets/logo.svg'],
          ['name' => 'absent', 'uri' => 'public://canvas/assets/never-uploaded.js'],
          ['name' => 'real', 'uri' => 'public://canvas/assets/real.js'],
        ]);

        // Checking the file's own URL covers both "above" and "traversal".
        self::assertArrayNotHasKey($url('public://canvas/elsewhere.js'), $provided->sources());
        self::assertArrayNotHasKey($url('public://canvas/assets/sub/nested.js'), $provided->sources());
        self::assertArrayNotHasKey($url('public://canvas/assets/logo.svg'), $provided->sources());
        self::assertArrayNotHasKey($url('public://canvas/assets/never-uploaded.js'), $provided->sources());
        self::assertArrayHasKey($url('public://canvas/assets/real.js'), $provided->sources());
      },

      // GlobalImports adds asset library imports after Canvas's own, so an
      // entry like "react" replaces Canvas's. Canvas documents this for
      // vendor-bundled builds. SSR must load the pushed file too.
      'a manifest entry named like a site import overrides it' => function () use ($provided, $ssr_import_map, $file_url_generator): void {
        $override_uri = $this->writeGlobalAssetModule(
          'react-override.js',
          'export const marker = "override"; export default marker;',
        );
        $this->setGlobalAssetLibraryEntries([
          ['name' => 'react', 'uri' => $override_uri],
        ]);

        // Saving the library dropped the merged modules, so the SSR import
        // map reads the override.
        $override_url = (string) \parse_url($file_url_generator->generateString($override_uri), PHP_URL_PATH);

        $react = $ssr_import_map->get()['imports']['react'] ?? NULL;
        self::assertIsString($react);
        self::assertSame($override_url, \parse_url($react, PHP_URL_PATH));
        self::assertStringContainsString('/canvas/assets/', $override_url);
        self::assertStringNotContainsString('astro-hydration/dist', $override_url);
        self::assertArrayHasKey($override_url, $provided->sources());
      },
    ]);
  }

  /**
   * Tests a manifest entry naming a symlink is skipped.
   *
   * Uploads are never symlinks, and following one could load any file into
   * the runner. It's skipped even when it points at a valid .js sibling.
   */
  public function testManifestEntryNamingSymlinkIsSkipped(): void {
    // The default vfsStream public:// can't hold symlinks, so use a real
    // temporary directory.
    $public = \sys_get_temp_dir() . '/canvas_ssr_pub_' . $this->randomMachineName();
    $assets = $public . '/canvas/assets';
    self::assertTrue(\mkdir($assets, 0777, TRUE));
    $this->setSetting('file_public_path', $public);
    $this->container->get('kernel')->rebuildContainer();
    try {
      $file_system = \Drupal::service(FileSystemInterface::class);
      self::assertSame($public, $file_system->realpath('public://'));

      // Two real .js modules, plus a ".js" symlink to one of them.
      \file_put_contents($assets . '/good.js', 'export const ok = 1;');
      \file_put_contents($assets . '/target.js', 'export const t = 1;');
      self::assertTrue(\symlink($assets . '/target.js', $assets . '/link.js'));
      $this->setGlobalAssetLibraryEntries([
        ['name' => 'good', 'uri' => 'public://canvas/assets/good.js'],
        ['name' => 'target', 'uri' => 'public://canvas/assets/target.js'],
        ['name' => 'link', 'uri' => 'public://canvas/assets/link.js'],
      ]);

      $provided = \Drupal::service(AllProvidedModules::class);
      \assert($provided instanceof AllProvidedModules);

      // Modules are provided under their module URLs, as the provider
      // computes them.
      $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
      $url = static fn (string $uri): string => $file_url_generator->generateString($uri);

      self::assertArrayHasKey($url('public://canvas/assets/good.js'), $provided->sources());
      self::assertArrayHasKey($url('public://canvas/assets/target.js'), $provided->sources());
      self::assertArrayNotHasKey($url('public://canvas/assets/link.js'), $provided->sources());
    }
    finally {
      @\unlink($assets . '/link.js');
      @\unlink($assets . '/good.js');
      @\unlink($assets . '/target.js');
      @\rmdir($assets);
      @\rmdir($public . '/canvas');
      @\rmdir($public);
    }
  }

  /**
   * Writes a module and makes it the manifest's only entry, like a CLI push.
   *
   * @param string $file_name
   *   The uploaded file name.
   * @param string $label
   *   What the module exports, so each push has different content.
   *
   * @return string
   *   The module URL: where it's served from.
   */
  private function pushLibraryModule(string $file_name, string $label): string {
    $uri = $this->writeGlobalAssetModule(
      $file_name,
      \sprintf('export const label = "%s";', $label),
    );
    $this->setGlobalAssetLibraryEntries(assets: [
      ['name' => '@/lib/pushed', 'uri' => $uri],
    ]);

    $file_url_generator = $this->container->get(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);

    return $file_url_generator->generateString($uri);
  }

}
