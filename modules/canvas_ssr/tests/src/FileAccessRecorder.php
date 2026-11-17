<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr;

/**
 * Records the files PHP touches, in place of the file stream wrapper.
 *
 * Every operation is passed on to the real wrapper. Including code isn't
 * recorded, since it's opcache's to serve. glob() and realpath() don't go
 * through stream wrappers, so they aren't seen, but what they find is only
 * worth something if it's then read.
 *
 * phpcs:disable Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
 * PHP names a stream wrapper's methods.
 *
 * PHP sets a stream wrapper's $context property, which this doesn't use,
 * so it isn't declared, and the class allows dynamic properties instead.
 */
#[\AllowDynamicProperties]
final class FileAccessRecorder {

  /**
   * The option PHP opens a file with to include it.
   *
   * STREAM_OPEN_FOR_INCLUDE in PHP's C source. It isn't a PHP constant.
   */
  private const int OPEN_FOR_INCLUDE = 0x80;

  /**
   * What was touched while recording, as "operation path".
   *
   * @var list<string>
   */
  public static array $accessed = [];

  /**
   * The real stream, or directory handle, this one passes on to.
   *
   * @var resource|false
   */
  private $handle = FALSE;

  /**
   * Starts recording, from nothing.
   */
  public static function start(): void {
    self::$accessed = [];
    \stream_wrapper_unregister('file');
    \stream_wrapper_register('file', self::class);
  }

  /**
   * Stops recording.
   */
  public static function stop(): void {
    \stream_wrapper_restore('file');
  }

  /**
   * Runs an operation on the real wrapper.
   *
   * @param callable(): mixed $operation
   *   The operation.
   *
   * @return mixed
   *   What it returned.
   */
  private static function real(callable $operation): mixed {
    \stream_wrapper_restore('file');
    try {
      return $operation();
    }
    finally {
      \stream_wrapper_unregister('file');
      \stream_wrapper_register('file', self::class);
    }
  }

  /**
   * Opens a file.
   */
  public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
    if (($options & self::OPEN_FOR_INCLUDE) === 0) {
      self::$accessed[] = 'open ' . $path;
    }
    $use_path = ($options & \STREAM_USE_PATH) !== 0;
    $this->handle = self::real(static fn() => ($options & \STREAM_REPORT_ERRORS) !== 0
      ? \fopen($path, $mode, $use_path)
      : @\fopen($path, $mode, $use_path));

    return $this->handle !== FALSE;
  }

  /**
   * Reads from the file.
   */
  public function stream_read(int $count): string|false {
    \assert($this->handle !== FALSE);
    return \fread($this->handle, \max(1, $count));
  }

  /**
   * Writes to the file.
   */
  public function stream_write(string $data): int|false {
    \assert($this->handle !== FALSE);
    return \fwrite($this->handle, $data);
  }

  /**
   * Whether the file is at its end.
   */
  public function stream_eof(): bool {
    \assert($this->handle !== FALSE);
    return \feof($this->handle);
  }

  /**
   * Closes the file.
   */
  public function stream_close(): void {
    if ($this->handle !== FALSE) {
      \fclose($this->handle);
    }
  }

  /**
   * Returns the open file's stat.
   *
   * @return array<int|string, int>|false
   *   The stat.
   */
  public function stream_stat(): array|false {
    \assert($this->handle !== FALSE);
    return \fstat($this->handle);
  }

  /**
   * Seeks in the file.
   */
  public function stream_seek(int $offset, int $whence): bool {
    \assert($this->handle !== FALSE);
    return \fseek($this->handle, $offset, $whence) === 0;
  }

  /**
   * Returns the position in the file.
   */
  public function stream_tell(): int|false {
    \assert($this->handle !== FALSE);
    return \ftell($this->handle);
  }

  /**
   * Flushes the file.
   */
  public function stream_flush(): bool {
    \assert($this->handle !== FALSE);
    return \fflush($this->handle);
  }

  /**
   * Locks the file.
   */
  public function stream_lock(int $operation): bool {
    \assert($this->handle !== FALSE);
    return $operation !== 0 && \flock($this->handle, $operation & (LOCK_SH | LOCK_EX | LOCK_UN | LOCK_NB));
  }

  /**
   * Truncates the file.
   */
  public function stream_truncate(int $size): bool {
    \assert($this->handle !== FALSE);
    return \ftruncate($this->handle, \max(0, $size));
  }

  /**
   * Sets an option on the stream, which this doesn't support.
   */
  public static function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
    return FALSE;
  }

  /**
   * Changes a file's metadata.
   */
  public static function stream_metadata(string $path, int $option, mixed $value): bool {
    self::$accessed[] = 'metadata ' . $path;
    return self::real(static fn() => match ($option) {
      \STREAM_META_TOUCH => \touch($path, ...(array) $value),
      \STREAM_META_OWNER, \STREAM_META_OWNER_NAME => \chown($path, $value),
      \STREAM_META_GROUP, \STREAM_META_GROUP_NAME => \chgrp($path, $value),
      \STREAM_META_ACCESS => \chmod($path, $value),
      default => FALSE,
    });
  }

  /**
   * Returns a path's stat, as is_file() and file_exists() ask for.
   *
   * @return array<int|string, int>|false
   *   The stat.
   */
  public static function url_stat(string $path, int $flags): array|false {
    self::$accessed[] = 'stat ' . $path;
    return self::real(static function () use ($path, $flags): array|false {
      $link = ($flags & \STREAM_URL_STAT_LINK) !== 0;
      if (($flags & \STREAM_URL_STAT_QUIET) !== 0) {
        return $link ? @\lstat($path) : @\stat($path);
      }
      return $link ? \lstat($path) : \stat($path);
    });
  }

  /**
   * Deletes a file.
   */
  public static function unlink(string $path): bool {
    self::$accessed[] = 'unlink ' . $path;
    return self::real(static fn() => \unlink($path));
  }

  /**
   * Renames a file.
   */
  public static function rename(string $from, string $to): bool {
    self::$accessed[] = 'rename ' . $from;
    return self::real(static fn() => \rename($from, $to));
  }

  /**
   * Makes a directory.
   */
  public static function mkdir(string $path, int $mode, int $options): bool {
    self::$accessed[] = 'mkdir ' . $path;
    return self::real(static fn() => \mkdir($path, $mode, ($options & \STREAM_MKDIR_RECURSIVE) !== 0));
  }

  /**
   * Removes a directory.
   */
  public static function rmdir(string $path, int $options): bool {
    self::$accessed[] = 'rmdir ' . $path;
    return self::real(static fn() => \rmdir($path));
  }

  /**
   * Opens a directory.
   */
  public function dir_opendir(string $path, int $options): bool {
    self::$accessed[] = 'opendir ' . $path;
    $this->handle = self::real(static fn() => \opendir($path));

    return $this->handle !== FALSE;
  }

  /**
   * Reads the next entry in the directory.
   */
  public function dir_readdir(): string|false {
    \assert($this->handle !== FALSE);
    return \readdir($this->handle);
  }

  /**
   * Starts the directory over.
   */
  public function dir_rewinddir(): bool {
    \assert($this->handle !== FALSE);
    \rewinddir($this->handle);
    return TRUE;
  }

  /**
   * Closes the directory.
   */
  public function dir_closedir(): bool {
    if ($this->handle !== FALSE) {
      \closedir($this->handle);
    }
    return TRUE;
  }

}
