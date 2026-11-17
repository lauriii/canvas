<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory;
use Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which directories the Deno runner trusts with its caches.
 *
 * Another OS user who could change a directory on the way to the runner's
 * directory could put modules into this OS user's renders, or read them.
 * Cases that need a directory owned by another OS user skip unless the
 * tests run as root, which can chown.
 */
#[CoversClass(OsUserDirectory::class)]
#[CoversClass(OsUserDirectoryChangedException::class)]
#[Group('canvas_ssr')]
final class OsUserDirectoryTest extends UnitTestCase {

  use DenoBinaryTrait;

  /**
   * An OS user other than PHP's, for cases that need one.
   */
  private const OTHER_UID = 65534;

  /**
   * Holds this test's roots.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = self::makeTemporaryDirectory('os-user-directory');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::removeTree($this->directory);
    parent::tearDown();
  }

  /**
   * Tests a missing root is made shared, and the OS user's directory private.
   */
  public function testMissingRootIsMadeSharedAndTheDirectoryPrivate(): void {
    $root = $this->directory . '/missing/root';

    $directory = OsUserDirectory::under($root);

    self::assertSame($root . '/' . self::osUserDirectoryName() . '/canvas/ssr/deno', $directory->path);
    self::assertDirectoryExists($directory->path);
    // Shared by every OS user, like /tmp.
    self::assertSame(01777, \fileperms($root) & 07777);
    self::assertSame(0700, \fileperms($root . '/' . self::osUserDirectoryName()) & 07777);
  }

  /**
   * Tests an OS user's directory others may use is made private first.
   *
   * It's this OS user's, so narrowing it is safe, and nobody else can then
   * read or change what's under it.
   */
  public function testOsUserDirectoryOthersMayUseIsMadePrivate(): void {
    $os_user_directory = $this->directory . '/' . self::osUserDirectoryName();
    \mkdir($os_user_directory);
    \chmod($os_user_directory, 0777);

    OsUserDirectory::under($this->directory);

    \clearstatcache();
    self::assertSame(0700, \fileperms($os_user_directory) & 07777);
  }

  /**
   * Tests a directory on the root's path that others may write isn't used.
   *
   * Whoever can write to it could swap what's below for their own, unless
   * it's sticky like /tmp.
   */
  public function testDirectoryOthersMayWriteOnTheRootsPathIsNotUsed(): void {
    \mkdir($this->directory . '/parent/root', 0755, TRUE);
    foreach (['root', 'parent'] as $writable) {
      $path = $writable === 'root' ? $this->directory . '/parent/root' : $this->directory . '/parent';
      \chmod($path, 0777);
      try {
        OsUserDirectory::under($this->directory . '/parent/root');
        self::fail(\sprintf('The root is used although others may write its %s.', $writable));
      }
      catch (\RuntimeException $e) {
        // It says who may change it, so an admin can tell what to fix.
        self::assertStringContainsString(\sprintf(
          'is not a directory that only root or the OS user PHP runs as, uid %1$d, may change: it is a directory owned by uid %1$d, with mode 0777.',
          \posix_geteuid(),
        ), $e->getMessage());
      }
      // Sticky, like /tmp: others may add entries but not change this one's.
      \chmod($path, 01777);
      OsUserDirectory::under($this->directory . '/parent/root');
      \chmod($path, 0755);
    }
  }

  /**
   * Tests a root is used by its real path, which is what's checked.
   */
  public function testSymlinkedRootIsUsedByItsRealPath(): void {
    \mkdir($this->directory . '/real');
    \symlink($this->directory . '/real', $this->directory . '/link');

    $directory = OsUserDirectory::under($this->directory . '/link');

    self::assertStringStartsWith(\realpath($this->directory) . '/real/', $directory->path);
  }

  /**
   * Tests an OS user's directory that's a symlink isn't used.
   *
   * It could point anywhere, including somewhere another OS user owns.
   */
  public function testOsUserDirectoryThatIsASymlinkIsNotUsed(): void {
    \mkdir($this->directory . '/elsewhere', 0700);
    \symlink($this->directory . '/elsewhere', $this->directory . '/' . self::osUserDirectoryName());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(\sprintf('is not a directory private to the OS user PHP runs as, uid %1$d: it is a symlink owned by uid %1$d', \posix_geteuid()));
    OsUserDirectory::under($this->directory);
  }

  /**
   * Tests a file where the OS user's directory goes is described.
   *
   * Another OS user may have made it first, so the message says whose it is
   * and how to fix it, rather than blaming the root's permissions.
   */
  public function testFileWhereTheOsUserDirectoryGoesIsDescribed(): void {
    \file_put_contents($this->directory . '/' . self::osUserDirectoryName(), '');
    \chmod($this->directory . '/' . self::osUserDirectoryName(), 0644);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(\sprintf('is not a directory private to the OS user PHP runs as, uid %1$d: it is a file owned by uid %1$d, with mode 0644. Another OS user may have made it first.', \posix_geteuid()));
    OsUserDirectory::under($this->directory);
  }

  /**
   * Tests a look at the directory finds only one that's this OS user's alone.
   *
   * Another OS user may have made what's there, and what's in it is read
   * on every request.
   */
  public function testPathIsGivenOnlyForAPrivateDirectory(): void {
    $os_user_directory = $this->directory . '/' . self::osUserDirectoryName();
    self::assertNull(OsUserDirectory::pathUnder($this->directory));
    self::assertFileDoesNotExist($os_user_directory);

    \mkdir($this->directory . '/elsewhere', 0700);
    \symlink($this->directory . '/elsewhere', $os_user_directory);
    self::assertNull(OsUserDirectory::pathUnder($this->directory));
    \unlink($os_user_directory);

    \mkdir($os_user_directory, 0700);
    \chmod($os_user_directory, 0755);
    self::assertNull(OsUserDirectory::pathUnder($this->directory));

    $directory = OsUserDirectory::under($this->directory);
    self::assertSame($directory->path, OsUserDirectory::pathUnder($this->directory));
  }

  /**
   * Tests another OS user's directories aren't used.
   */
  public function testAnotherOsUsersDirectoriesAreNotUsed(): void {
    self::skipUnlessRoot();
    $os_user_directory = $this->directory . '/' . self::osUserDirectoryName();
    \mkdir($os_user_directory, 0700);
    \chown($os_user_directory, self::OTHER_UID);
    try {
      OsUserDirectory::under($this->directory);
      self::fail('Another OS user\'s directory is used.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString(\sprintf('is not a directory private to the OS user PHP runs as, uid 0: it is a directory owned by uid %d, with mode 0700. Another OS user may have made it first.', self::OTHER_UID), $e->getMessage());
    }

    // A root another OS user owns could have what's under it swapped.
    $root = $this->directory . '/theirs';
    \mkdir($root, 0755);
    \chown($root, self::OTHER_UID);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(\sprintf('may change: it is a directory owned by uid %d, with mode 0755.', self::OTHER_UID));
    OsUserDirectory::under($root);
  }

  /**
   * Tests a deleted or replaced OS user's directory is noticed.
   *
   * Another OS user can claim its name only once it's deleted, and then owns
   * the new directory.
   */
  public function testDeletedOrReplacedDirectoryIsNoticed(): void {
    $directory = OsUserDirectory::under($this->directory);
    $directory->assertIntact();
    $os_user_directory = $this->directory . '/' . self::osUserDirectoryName();

    self::removeTree($os_user_directory);
    try {
      $directory->assertIntact();
      self::fail('A deleted directory is not noticed.');
    }
    catch (OsUserDirectoryChangedException) {
      $this->addToAssertionCount(1);
    }

    \mkdir($this->directory . '/elsewhere', 0700);
    \symlink($this->directory . '/elsewhere', $os_user_directory);
    try {
      $directory->assertIntact();
      self::fail('A directory replaced by a symlink is not noticed.');
    }
    catch (OsUserDirectoryChangedException) {
      $this->addToAssertionCount(1);
    }
    \unlink($os_user_directory);

    if (\posix_geteuid() === 0) {
      \mkdir($os_user_directory, 0777);
      \chown($os_user_directory, self::OTHER_UID);
      $this->expectException(OsUserDirectoryChangedException::class);
      $directory->assertIntact();
    }
  }

  /**
   * Skips the test unless it runs as root, which can chown.
   */
  private static function skipUnlessRoot(): void {
    if (\posix_geteuid() !== 0) {
      self::markTestSkipped('Making a directory another OS user owns needs root.');
    }
  }

  /**
   * Returns the name of the OS user's directory under each root.
   */
  private static function osUserDirectoryName(): string {
    return 'drupal-' . \posix_geteuid();
  }

}
