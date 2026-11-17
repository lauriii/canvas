<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The Deno runner's directory for this OS user under a root.
 *
 * For uid 33 under /var/tmp, it's /var/tmp/drupal-33/canvas/ssr/deno.
 *
 * Base caches are named by their content, so sites run by one OS user can
 * share them. OS users may share a root, so a root this makes is
 * world-writable with the sticky bit, like /tmp.
 *
 * Another OS user who could write anywhere on the way to the directory
 * could put modules into this one's renders or read its sources. So under()
 * checks every directory on the path, and the OS user's directory itself,
 * and assertIntact() checks the OS user's directory again before Deno gets
 * control and before each batch of writes. Only the OS user's directory
 * needs checking again: under() made sure nobody else can change the
 * directories above it, and nobody else can write below it. The README
 * covers the layout.
 */
final class OsUserDirectory {

  /**
   * The directories made under the OS user's directory, in order.
   */
  private const SUBDIRECTORIES = ['/canvas', '/canvas/ssr', self::SUBDIRECTORY];

  /**
   * The runner's directory, under the OS user's directory.
   */
  private const SUBDIRECTORY = '/canvas/ssr/deno';

  /**
   * The file type bits of a stat mode, and three file types.
   *
   * The types are those of a directory, a symlink and a file.
   */
  private const TYPE_MASK = 0170000;
  private const TYPE_DIRECTORY = 0040000;
  private const TYPE_SYMLINK = 0120000;
  private const TYPE_FILE = 0100000;

  /**
   * Constructs an OsUserDirectory, once its path is checked.
   *
   * @param int $uid
   *   The OS user PHP runs as.
   * @param string $osUserDirectory
   *   The OS user's directory under the root, such as /var/tmp/drupal-33.
   * @param string $path
   *   The runner's directory under it.
   */
  private function __construct(
    private readonly int $uid,
    private readonly string $osUserDirectory,
    public readonly string $path,
  ) {}

  /**
   * Returns this OS user's directory under a root, making it if needed.
   *
   * Every directory from / to the root must be a directory, not a symlink,
   * owned by root or this OS user, and writable by no one else unless it's
   * sticky. A root that doesn't exist is made, if that holds for its
   * parent. The OS user's directory must be this OS user's, and is made
   * private to it if it isn't. Below it, everything is made one directory at
   * a time, so nothing is made where it wasn't just checked.
   *
   * @param string $root
   *   The base caches or runner caches root.
   *
   * @return self
   *   The directory.
   *
   * @throws \RuntimeException
   *   When it can't be made, or another OS user could change it.
   */
  public static function under(string $root): self {
    $uid = self::osUserId();
    if ($uid === NULL) {
      throw new \RuntimeException('Failed to tell which OS user PHP runs as.');
    }
    $root = self::checkedRoot($root, $uid);
    $os_user_directory = \sprintf('%s/drupal-%d', $root, $uid);
    // Something else in its place is for makePrivate() to describe.
    if (!@\mkdir($os_user_directory, 0700) && @\lstat($os_user_directory) === FALSE) {
      throw new \RuntimeException(\sprintf(
        'Failed to create the Deno directory "%s". Set the runner\'s roots to directories PHP may write.',
        $os_user_directory,
      ));
    }
    $directory = new self($uid, $os_user_directory, $os_user_directory . self::SUBDIRECTORY);
    $directory->makePrivate();
    foreach (self::SUBDIRECTORIES as $subdirectory) {
      $path = $os_user_directory . $subdirectory;
      if (!@\mkdir($path, 0700) && !\is_dir($path)) {
        throw new \RuntimeException(\sprintf('Failed to create the Deno directory "%s".', $path));
      }
    }

    return $directory;
  }

  /**
   * Returns where under() put this OS user's directory, if it's there.
   *
   * For a cheap look at what may be there, without making anything. It
   * costs one lstat: the OS user's directory must be this OS user's alone,
   * as under() left it, so no other OS user chose what's in it.
   *
   * @param string $root
   *   The base caches or runner caches root.
   *
   * @return string|null
   *   The path, or NULL when the OS user's directory isn't there, isn't
   *   this OS user's alone, or PHP can't tell which OS user it runs as.
   */
  public static function pathUnder(string $root): ?string {
    $uid = self::osUserId();
    if ($uid === NULL) {
      return NULL;
    }
    $os_user_directory = \sprintf('%s/drupal-%d', \rtrim($root, '/'), $uid);
    $stat = @\lstat($os_user_directory);
    if ($stat === FALSE || !self::isPrivateDirectory($stat, $uid)) {
      return NULL;
    }

    return $os_user_directory . self::SUBDIRECTORY;
  }

  /**
   * Checks the OS user's directory is still this OS user's alone.
   *
   * It costs one lstat.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException
   *   When it was deleted, or replaced by something another OS user could
   *   change.
   */
  public function assertIntact(): void {
    \clearstatcache(TRUE, $this->osUserDirectory);
    $stat = @\lstat($this->osUserDirectory);
    if ($stat === FALSE || !self::isPrivateDirectory($stat, $this->uid)) {
      throw new OsUserDirectoryChangedException(\sprintf(
        'The Deno directory "%s" was deleted or replaced since it was checked, so it is not used.',
        $this->osUserDirectory,
      ));
    }
  }

  /**
   * Deletes a file or directory tree under an OS user's directory.
   *
   * Symlinks are removed, not followed. Failures are ignored: what's left
   * is pruned later, or by the OS.
   *
   * @param string $path
   *   The path to delete.
   */
  public static function removeTree(string $path): void {
    try {
      (new Filesystem())->remove($path);
    }
    catch (IOException) {
    }
  }

  /**
   * Makes the OS user's directory private, if it's this OS user's.
   *
   * Narrowing a directory this OS user owns is safe, and keeps others from
   * reading what's under it.
   *
   * @throws \RuntimeException
   *   When it's a symlink, or isn't this OS user's.
   */
  private function makePrivate(): void {
    \clearstatcache(TRUE, $this->osUserDirectory);
    $stat = @\lstat($this->osUserDirectory);
    if ($stat !== FALSE && self::isDirectory($stat) && $stat['uid'] === $this->uid && ($stat['mode'] & 0077) !== 0) {
      @\chmod($this->osUserDirectory, 0700);
      \clearstatcache(TRUE, $this->osUserDirectory);
      $stat = @\lstat($this->osUserDirectory);
    }
    if ($stat === FALSE || !self::isPrivateDirectory($stat, $this->uid)) {
      throw new \RuntimeException(\sprintf(
        'The Deno directory "%s" is not used, since it is not a directory private to the OS user PHP runs as, uid %d: %s. Another OS user may have made it first. Have root delete it, or set the runner\'s roots to directories only uid %d may write.',
        $this->osUserDirectory,
        $this->uid,
        self::describe($stat),
        $this->uid,
      ));
    }
  }

  /**
   * Returns a root's real path, once every directory on it is checked.
   *
   * Missing directories at the end of the path are made, each after its
   * parent is checked. The last is world-writable with the sticky bit, so
   * other OS users can have directories under it too.
   *
   * @param string $root
   *   The root.
   * @param int $uid
   *   The OS user PHP runs as.
   *
   * @return string
   *   The root's real path.
   *
   * @throws \RuntimeException
   *   When the root can't be made, or another OS user could change a
   *   directory on its path.
   */
  private static function checkedRoot(string $root, int $uid): string {
    $root = \rtrim($root, '/');
    $missing = [];
    $existing = $root;
    while ($existing !== '' && @\lstat($existing) === FALSE) {
      \array_unshift($missing, \basename($existing));
      $existing = \dirname($existing);
      if ($existing === '.' || $existing === '/') {
        break;
      }
    }
    $real = \realpath($existing === '' ? '/' : $existing);
    if ($real === FALSE) {
      throw new \RuntimeException(\sprintf('Failed to find the Deno root "%s".', $root));
    }
    self::checkPath($real, $uid);
    foreach ($missing as $index => $name) {
      $real = \rtrim($real, '/') . '/' . $name;
      if (!@\mkdir($real, 0755) && !\is_dir($real)) {
        throw new \RuntimeException(\sprintf(
          'Failed to create the Deno root "%s". Set the runner\'s roots to directories PHP may write.',
          $real,
        ));
      }
      if ($index === \array_key_last($missing)) {
        @\chmod($real, 01777);
      }
      self::checkDirectory($real, $uid);
    }

    return $real;
  }

  /**
   * Checks every directory on a real path, from / down.
   *
   * @param string $path
   *   The real path.
   * @param int $uid
   *   The OS user PHP runs as.
   *
   * @throws \RuntimeException
   *   When another OS user could change one.
   */
  private static function checkPath(string $path, int $uid): void {
    self::checkDirectory('/', $uid);
    $current = '';
    foreach (\explode('/', \trim($path, '/')) as $name) {
      if ($name !== '') {
        $current .= '/' . $name;
        self::checkDirectory($current, $uid);
      }
    }
  }

  /**
   * Checks one directory on a root's path.
   *
   * @param string $path
   *   The directory.
   * @param int $uid
   *   The OS user PHP runs as.
   *
   * @throws \RuntimeException
   *   When it's not a directory, belongs to another OS user, or another OS
   *   user may write to it and it isn't sticky.
   */
  private static function checkDirectory(string $path, int $uid): void {
    \clearstatcache(TRUE, $path);
    $stat = @\lstat($path);
    $safe = $stat !== FALSE
      && self::isDirectory($stat)
      && ($stat['uid'] === 0 || $stat['uid'] === $uid)
      && (($stat['mode'] & 0022) === 0 || ($stat['mode'] & 01000) !== 0);
    if (!$safe) {
      throw new \RuntimeException(\sprintf(
        'The Deno root is not used, since "%s" is not a directory that only root or the OS user PHP runs as, uid %d, may change: %s.',
        $path,
        $uid,
        self::describe($stat),
      ));
    }
  }

  /**
   * Describes what's at a path, for an error message.
   *
   * @param array<int|string, int>|false $stat
   *   What lstat() returned.
   */
  private static function describe(array|false $stat): string {
    if ($stat === FALSE) {
      return 'it does not exist';
    }
    $type = match ($stat['mode'] & self::TYPE_MASK) {
      self::TYPE_DIRECTORY => 'a directory',
      self::TYPE_SYMLINK => 'a symlink',
      self::TYPE_FILE => 'a file',
      default => 'neither a directory nor a file',
    };

    return \sprintf('it is %s owned by uid %d, with mode %04o', $type, $stat['uid'], $stat['mode'] & 07777);
  }

  /**
   * Returns whether a stat is of a directory, not a symlink.
   *
   * @param array<int|string, int> $stat
   *   What lstat() returned.
   */
  private static function isDirectory(array $stat): bool {
    return ($stat['mode'] & self::TYPE_MASK) === self::TYPE_DIRECTORY;
  }

  /**
   * Returns whether a stat is of a directory only one OS user may use.
   *
   * @param array<int|string, int> $stat
   *   What lstat() returned.
   * @param int $uid
   *   The OS user.
   */
  private static function isPrivateDirectory(array $stat, int $uid): bool {
    return self::isDirectory($stat) && $stat['uid'] === $uid && ($stat['mode'] & 0077) === 0;
  }

  /**
   * Returns the id of the OS user this PHP process runs as.
   *
   * @return int|null
   *   The uid, or NULL when it can't be told.
   */
  private static function osUserId(): ?int {
    if (\function_exists('posix_geteuid')) {
      return \posix_geteuid();
    }
    // Without posix, a file this PHP process makes says who it runs as.
    // Kept, since that costs a file.
    static $owner = NULL;
    if ($owner === NULL) {
      $probe = @\tempnam(\sys_get_temp_dir(), 'canvas-ssr-owner');
      if ($probe === FALSE) {
        return NULL;
      }
      $owner = @\fileowner($probe);
      @\unlink($probe);
    }

    return $owner === FALSE ? NULL : $owner;
  }

}
