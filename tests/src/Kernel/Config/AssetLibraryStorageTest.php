<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GeneratedAssetCleanup;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Asset Library Storage.
 *
 * @internal
 * @legacy-covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage
 * @legacy-covers \Drupal\canvas\Entity\AssetLibrary
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class AssetLibraryStorageTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The brand kit generates its CSS from managed font files.
    // @see ::createManagedFontFile()
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
  }

  /**
   * Tests generated files.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage::generateFiles
   */
  public function testGeneratedFiles(): void {
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $asset_library->delete();

    $asset_library = AssetLibrary::create([
      'id' => 'global',
      'label' => 'Test',
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
    ]);
    $this->assertGeneratedFiles($asset_library);
  }

  protected function assertGeneratedFiles(CanvasAssetInterface $entity): void {
    $this->assertTrue($entity->isNew());

    // Before saving, the corresponding files do not yet exist.
    self::assertFileDoesNotExist($entity->getCssPath());
    self::assertFileDoesNotExist($entity->getJsPath());

    // After saving, they do.
    $entity->save();
    self::assertFileExists($entity->getCssPath());
    self::assertFileExists($entity->getJsPath());

    // After changing without saving, they don't.
    $original_js_path = $entity->getJsPath();
    $entity->set('js', [
      'original' => 'console.log("hallo");',
      'compiled' => 'console.log("hallo");',
    ]);
    self::assertFileDoesNotExist($entity->getJsPath());

    // After saving, it does, and the original also still exists: the path is a
    // content hash, so the previous file is orphaned rather than overwritten.
    // Cron cleans it up once no response can still be pointing at it.
    // @see ::testStaleGeneratedFilesAreGarbageCollected()
    $entity->save();
    self::assertFileExists($entity->getJsPath());
    self::assertFileExists($original_js_path);
  }

  /**
   * Tests that orphaned generated files are eventually deleted.
   *
   * Drupal core's asset garbage collection only sweeps `assets://css` and
   * `assets://js`, so nothing removed the files Canvas orphans in
   * `assets://canvas` and `assets://astro-island` on every save.
   *
   * @legacy-covers \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles
   */
  public function testStaleGeneratedFilesAreGarbageCollected(): void {
    $cleanup = $this->container->get(GeneratedAssetCleanup::class);
    \assert($cleanup instanceof GeneratedAssetCleanup);

    // All three entity types that generate files, so that both directories and
    // both extensions are covered, and so that removing any of them from the
    // sweep's list of entity types deletes a file that is still in use.
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $brand_kit = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($brand_kit);
    $code_component = JavaScriptComponent::create([
      'machineName' => 'gc_test_component',
      'name' => 'GC test component',
      'status' => FALSE,
      'props' => [
        'title' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Title'],
        ],
      ],
      'required' => ['title'],
      'slots' => [],
      'dataDependencies' => [],
    ]);

    $orphaned = [];
    $current = [];
    foreach ([1 => 'first', 2 => 'second'] as $round => $marker) {
      $asset_library->set('css', [
        'original' => ".$marker { display: none; }",
        'compiled' => ".$marker{display:none}",
      ])->set('js', [
        'original' => "console.log('$marker');",
        'compiled' => "console.log('$marker');",
      ])->save();
      // The brand kit generates its CSS from its fonts; a different font file
      // means different CSS, hence a different content hash.
      $brand_kit->setFonts([
        [
          'id' => '00000000-0000-4000-8000-00000000000' . $round,
          'family' => 'GC Test',
          'uri' => $this->createManagedFontFile("gc-test-$marker.woff2")->getFileUri(),
          'format' => 'woff2',
          'weight' => '400',
          'style' => 'normal',
        ],
      ]);
      $brand_kit->save();
      $code_component->set('css', [
        'original' => ".$marker { color: red; }",
        'compiled' => ".$marker{color:red}",
      ])->set('js', [
        'original' => "export default () => '$marker';",
        'compiled' => "export default () => '$marker';",
      ])->save();

      $paths = [
        $asset_library->getCssPath(),
        $asset_library->getJsPath(),
        $brand_kit->getCssPath(),
        $code_component->getCssPath(),
        $code_component->getJsPath(),
      ];
      foreach ($paths as $path) {
        self::assertFileExists($path);
      }
      // The second round orphans everything the first round wrote.
      if ($round === 1) {
        $orphaned = $paths;
      }
      else {
        $current = $paths;
      }
    }

    self::assertSame([], \array_intersect($orphaned, $current), 'Every path is content-addressed, so a second save orphans the first file');
    // Both directories, both extensions.
    self::assertNotEmpty(\array_filter($orphaned, static fn (string $p): bool => \str_starts_with($p, AssetLibrary::ASSETS_DIRECTORY)));
    self::assertNotEmpty(\array_filter($orphaned, static fn (string $p): bool => \str_starts_with($p, JavaScriptComponent::ASSETS_DIRECTORY)));
    self::assertNotEmpty(\array_filter($orphaned, static fn (string $p): bool => \str_ends_with($p, '.js')));

    // The first sweep only records that the orphans are unreferenced: their
    // retention period starts now, however old the files themselves are, since
    // a response served a moment ago can still name them.
    self::assertSame([], $cleanup->deleteStaleFiles());

    // Backdate that record by a week. The files still in use are never recorded
    // at all, which is what keeps them: being unreferenced is what starts the
    // clock, not age.
    $this->backdateOrphanRecord(7 * 24 * 60 * 60);

    // A site that advertises a page cache lifetime longer than the six-hour
    // floor keeps its files for that lifetime instead: a response cached for
    // two weeks still names them. Seven days is inside that window, so the
    // orphans survive.
    // @see \Drupal\canvas\GeneratedAssetCleanup::getMaxAge()
    $this->config('system.performance')->set('cache.page.max_age', 14 * 24 * 60 * 60)->save();
    self::assertSame([], $cleanup->deleteStaleFiles());
    $this->config('system.performance')->set('cache.page.max_age', 0)->save();
    $this->backdateOrphanRecord(7 * 24 * 60 * 60);

    $deleted = $cleanup->deleteStaleFiles();
    // Installing Canvas writes files of its own, and the first round orphaned
    // those too, so the sweep legitimately collects more than this test made.
    // What matters is that it took every orphan and no file still in use.
    self::assertSame($orphaned, \array_values(\array_intersect($orphaned, $deleted)));
    self::assertSame([], \array_intersect($current, $deleted));
    foreach ($orphaned as $path) {
      self::assertFileDoesNotExist($path);
    }
    foreach ($current as $path) {
      self::assertFileExists($path, 'A file a config entity still points at is never deleted, however long it has been unreferenced');
    }

    // And it is idempotent.
    self::assertSame([], $cleanup->deleteStaleFiles());
  }

  /**
   * Tests that a resurrected file is kept, and collectable again after that.
   *
   * The paths are content hashes, so reverting to earlier content resurrects an
   * orphaned file under its old name rather than writing a new one. What keeps
   * such a file is the reference set, not its age, and the reference set has to
   * be read from storage rather than from a stale static cache.
   *
   * Orphaning it a second time then has to restart the retention period from
   * that write, or the file reads as written-after-recorded on every later run
   * and is never collected.
   *
   * Note this covers the *precondition* of the sweep's race, not the race
   * itself: reproducing an entity save interleaved between the sweep's scan and
   * its unlink is not practical in a single-threaded kernel test.
   *
   * @see \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles()
   * @legacy-covers \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles
   */
  public function testRevertedEntityKeepsItsResurrectedFile(): void {
    $cleanup = $this->container->get(GeneratedAssetCleanup::class);
    \assert($cleanup instanceof GeneratedAssetCleanup);

    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $first = ['original' => '.first { display: none; }', 'compiled' => '.first{display:none}'];
    $second = ['original' => '.second { display: none; }', 'compiled' => '.second{display:none}'];

    $asset_library->set('css', $first)->save();
    $orphaned_css_path = $asset_library->getCssPath();
    $asset_library->set('css', $second)->save();
    self::assertNotSame($orphaned_css_path, $asset_library->getCssPath());

    // Record the orphan and backdate that record, so that on the reference set
    // as it stands now the sweep would collect it.
    self::assertSame([], $cleanup->deleteStaleFiles());
    $this->backdateOrphanRecord(7 * 24 * 60 * 60);

    // Now revert, which makes that same path current again: the path is a
    // content hash, so identical content means the identical file name.
    $asset_library->set('css', $first)->save();
    self::assertSame($orphaned_css_path, $asset_library->getCssPath());

    self::assertSame([], $cleanup->deleteStaleFiles());
    self::assertFileExists($orphaned_css_path, 'A file that is current again is never deleted');

    // Orphan it a second time. Its file has been written since the sweep last
    // recorded it, so the period restarts from that write: keeping the earlier
    // moment would leave the file reading as written-after-recorded on every
    // run from here on, and it would never be collected at all.
    $asset_library->set('css', $second)->save();
    self::assertSame([], $cleanup->deleteStaleFiles());
    $this->backdateOrphanRecord(7 * 24 * 60 * 60, age_files: FALSE);
    $stale_record = $this->orphanRecord()[$orphaned_css_path];

    self::assertSame([], $cleanup->deleteStaleFiles());
    self::assertFileExists($orphaned_css_path);
    self::assertGreaterThan(
      $stale_record,
      $this->orphanRecord()[$orphaned_css_path],
      'A file written since it was recorded starts its retention period again',
    );

    // And once that restarted period has elapsed it is collected, rather than
    // being passed over for ever.
    $this->backdateOrphanRecord(7 * 24 * 60 * 60);
    self::assertContains($orphaned_css_path, $cleanup->deleteStaleFiles());
    self::assertFileDoesNotExist($orphaned_css_path);
  }

  /**
   * Tests that a single sweep deletes a bounded number of files.
   *
   * A site that has never been swept can hold a backlog bigger than cron has
   * memory for. Collecting it all at once would abort the run before anything
   * was deleted, and every later run the same way, so the backlog could never
   * be worked off. A bounded run works it off over several crons instead.
   *
   * @legacy-covers \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles
   */
  public function testSweepIsBoundedPerRun(): void {
    $cleanup = $this->container->get(GeneratedAssetCleanup::class);
    \assert($cleanup instanceof GeneratedAssetCleanup);
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);

    $directory = AssetLibrary::ASSETS_DIRECTORY;
    self::assertTrue($file_system->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    ));
    $directory_path = $file_system->realpath($directory);
    self::assertIsString($directory_path);

    // Matches GeneratedAssetCleanup::MAX_FILES_PER_RUN, which is private.
    $limit = 1000;
    $overflow = 5;
    $long_ago = \time() - (7 * 24 * 60 * 60);
    for ($i = 0; $i < $limit + $overflow; $i++) {
      $real_path = $directory_path . '/gc-backlog-' . $i . '.css';
      self::assertNotFalse(\file_put_contents($real_path, '.gc-backlog{display:none}'));
      self::assertTrue(\touch($real_path, $long_ago));
    }

    // A file the global asset library points at, living in the same directory
    // as the backlog, so that only the reference set can save it.
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $asset_library->set('css', [
      'original' => '.in-use { display: none; }',
      'compiled' => '.in-use{display:none}',
    ])->save();
    $in_use_path = $asset_library->getCssPath();
    self::assertStringStartsWith($directory, $in_use_path);

    // Installing Canvas leaves orphans of its own, so count what this test made
    // rather than everything the sweep is entitled to collect. Iterated rather
    // than globbed because glob() cannot see through a stream wrapper, and the
    // test site's files live on one.
    $remaining = static function () use ($directory): int {
      $count = 0;
      foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file_info) {
        \assert($file_info instanceof \SplFileInfo);
        if (\str_starts_with($file_info->getFilename(), 'gc-backlog-')) {
          $count++;
        }
      }
      return $count;
    };
    self::assertSame($limit + $overflow, $remaining());

    // One run records at most the limit, so the backlog is recorded, and then
    // deleted, over consecutive runs. The file still in use is passed over by
    // every one of them.
    self::assertSame([], $cleanup->deleteStaleFiles());
    $this->backdateOrphanRecord(7 * 24 * 60 * 60);
    self::assertCount($limit, $cleanup->deleteStaleFiles(), 'One run never deletes more than the limit');
    self::assertGreaterThan(0, $remaining(), 'The backlog outlasts the run that hit the limit');

    self::assertSame([], $cleanup->deleteStaleFiles());
    $this->backdateOrphanRecord(7 * 24 * 60 * 60);
    self::assertNotEmpty($cleanup->deleteStaleFiles());
    self::assertSame(0, $remaining(), 'What the limit left behind is worked off by a later run');

    self::assertFileExists($in_use_path);
  }

  /**
   * Backdates the record of when each orphan was first seen unreferenced.
   *
   * The retention period runs from that moment rather than from the file's own
   * modification time, and the request time is fixed for the whole test, so
   * this is how the period is made to elapse.
   *
   * @see \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles()
   */
  private function backdateOrphanRecord(int $seconds, bool $age_files = TRUE): void {
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $store = $this->container->get('keyvalue')->get('canvas.generated_asset_cleanup');
    $orphaned_since = $this->orphanRecord();
    self::assertNotEmpty($orphaned_since, 'The sweep records every file it sees unreferenced');

    $backdated = [];
    foreach ($orphaned_since as $uri => $since) {
      $backdated[$uri] = $since - $seconds;
      // Time passing ages the file along with its record. Leaving the file
      // alone instead models a file written after it was recorded, which is
      // how a resurrected one is spotted.
      $real_path = $file_system->realpath($uri);
      if ($age_files && \is_string($real_path) && \file_exists($real_path)) {
        self::assertTrue(\touch($real_path, $backdated[$uri] - 1));
      }
    }
    $store->set('orphaned_since', $backdated);
    \clearstatcache();
  }

  /**
   * When the sweep last saw each unreferenced file, keyed by URI.
   *
   * @return array<string, int>
   */
  private function orphanRecord(): array {
    $record = $this->container->get('keyvalue')
      ->get('canvas.generated_asset_cleanup')
      ->get('orphaned_since', []);
    \assert(\is_array($record));

    $timestamps = [];
    foreach ($record as $uri => $since) {
      \assert(\is_string($uri));
      \assert(\is_int($since));
      $timestamps[$uri] = $since;
    }

    return $timestamps;
  }

  /**
   * Creates a managed font file for the brand kit to generate CSS from.
   */
  private function createManagedFontFile(string $filename): FileInterface {
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $directory = BrandKit::ARTIFACTS_DIRECTORY;
    self::assertTrue($file_system->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    ));
    $uri = $directory . $filename;
    $real_path = $file_system->realpath($uri);
    self::assertIsString($real_path);
    self::assertNotFalse(\file_put_contents($real_path, 'font-data'));

    $file = File::create(['uri' => $uri]);
    $file->save();

    return $file;
  }

}
