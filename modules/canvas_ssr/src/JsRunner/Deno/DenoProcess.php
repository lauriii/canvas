<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Exception\MemoryLimitException;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\canvas_ssr\JsRunner\Exception\TimeLimitException;
use Drupal\canvas_ssr\Timeouts;

/**
 * One Deno process running js/runner.js, and the NDJSON protocol to it.
 *
 * Constructing one starts the process on a runner cache or base cache as
 * its DENO_DIR, with a Deno import map. Because Deno runs out of process,
 * every value crosses as JSON, and a time limit is enforced by killing the
 * process. A binding call blocks the process until PHP answers, so bindings
 * are still synchronous.
 *
 * Each command PHP sends it has two limits: the time the process may spend
 * on it, and a wall-clock one. Where the OS reports the process's CPU time,
 * the first is CPU time, so a process waiting for a core on a busy host
 * isn't failed for it. Elsewhere, it's wall time less the time PHP spent
 * answering binding calls. The process also runs with a CPU time limit, so
 * a process PHP lost track of can't run forever.
 *
 * Its memory is limited twice. V8 caps the JS heap. Buffers aren't on the
 * heap, so where the OS reports the process's resident memory, PHP also
 * checks it while a command runs, and kills the process past the limit.
 * JS only runs during a command. The process is the first the kernel kills
 * when the host runs out of memory, so that's a CSR fallback rather than a
 * database or a web server.
 *
 * A Deno process outlives PHP only while it runs JS or reads stdin. The
 * first meets its CPU time limit, and the second ends when PHP's end of the
 * pipe closes, as it does however PHP ends. A shutdown function kills any
 * still running, so even a fatal error in PHP leaves none spinning.
 *
 * The process holds a realm. Deno reads its import map only at startup and
 * evaluates each URL once, so the realm is replaced by stopping this
 * process and starting another. A process that dies, runs out of time or
 * breaks the protocol is killed and never reused.
 *
 * The process gets no permissions at all; see ADR 9 and the README's
 * security model. Before Deno gets control, which is when the process
 * starts and with each message PHP sends it, the OS user's directories it
 * reads from are checked again, since what Deno reads next could come from
 * any of them.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner
 */
final class DenoProcess {

  /**
   * The shell script that starts Deno with a CPU time limit.
   *
   * It's given the limit in seconds, then the command. A limit above the
   * one the host sets is lowered to it, since raising it would fail. If
   * setting it fails anyway, Deno still starts.
   *
   * It also makes the process the kernel's first choice to kill when the
   * host runs out of memory. Any process may raise its own score, and Deno
   * inherits it. Where that fails, as without /proc, Deno still starts.
   */
  private const LIMIT_SCRIPT = <<<'SH'
    limit=$1
    shift
    hard=$(ulimit -H -t)
    if [ "$hard" != unlimited ] && [ "$hard" -lt "$limit" ]; then limit=$hard; fi
    ulimit -t "$limit" 2>/dev/null
    { echo 1000 > /proc/self/oom_score_adj; } 2>/dev/null
    exec "$@"
    SH;

  /**
   * How many clock ticks a second /proc reports CPU time in.
   *
   * That's USER_HZ, which Linux fixes at 100 for what it reports to user
   * space, whatever the kernel's own tick rate.
   */
  private const CLOCK_TICKS_PER_SECOND = 100;

  /**
   * The shortest wait between two checks of CPU time, in seconds.
   *
   * /proc reports the process's CPU time in hundredths.
   */
  private const CPU_CHECK_INTERVAL = 0.01;

  /**
   * How often the process's resident memory is checked, in seconds.
   *
   * Reading it costs a few microseconds, and most invokes end before the
   * first check. Memory grows only as fast as it's written, which can be
   * tens of MiB in that time, so that's about how far past the limit a
   * process gets.
   */
  private const MEMORY_CHECK_INTERVAL = 0.002;

  /**
   * The most one message from the process may be, in bytes.
   *
   * An invoke's result is the largest: an island's HTML, which holds its
   * slots', so a layout's can be a page's. A message is held about three
   * times over as it's read and decoded.
   */
  public const MESSAGE_LIMIT = 4 * 1024 * 1024;

  /**
   * The flags the process runs with. The constructor adds the import map.
   *
   * Never add an --allow-* flag. The rest turn off things Deno does by
   * default:
   * - --cached-only: never fetch, so only seeded modules load.
   * - --no-config, --no-lock: ignore any deno.json or lockfile in the
   *   working directory.
   * - --no-npm: refuse npm: specifiers, which components mustn't rely on.
   * - --no-prompt: refuse a permission instead of waiting on a tty.
   * - --quiet: keep Deno's own diagnostics out of stderr.
   */
  private const DENO_FLAGS = [
    'run',
    '--cached-only',
    '--no-config',
    '--no-lock',
    '--no-npm',
    '--no-prompt',
    '--quiet',
  ];

  /**
   * The node: modules built into Deno, which the process refuses to import.
   *
   * No flag turns off Deno's Node compatibility, and an import map can't
   * block a whole scheme, so each module is blocked by a null. runner.js
   * removes the Node globals. SandboxTest fails if Deno adds a module this
   * list lacks.
   *
   * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno\SandboxTest
   */
  public const NODE_BUILTINS = [
    '_http_agent', '_http_common', '_http_outgoing', '_http_server',
    '_stream_duplex', '_stream_passthrough', '_stream_readable',
    '_stream_transform', '_stream_writable', '_tls_common', '_tls_wrap',
    'assert', 'assert/strict', 'async_hooks', 'buffer', 'child_process',
    'cluster', 'console', 'constants', 'crypto', 'dgram',
    'diagnostics_channel', 'dns', 'dns/promises', 'domain', 'events', 'fs',
    'fs/promises', 'http', 'http2', 'https', 'inspector',
    'inspector/promises', 'module', 'net', 'os', 'path', 'path/posix',
    'path/win32', 'perf_hooks', 'process', 'punycode', 'querystring',
    'readline', 'readline/promises', 'repl', 'sqlite', 'stream',
    'stream/consumers', 'stream/promises', 'stream/web', 'string_decoder',
    'sys', 'test', 'test/reporters', 'timers', 'timers/promises', 'tls',
    'trace_events', 'tty', 'url', 'util', 'util/types', 'v8', 'vm', 'wasi',
    'worker_threads', 'zlib',
  ];

  /**
   * The file in the DENO_DIR holding the Deno import map.
   */
  private const IMPORT_MAP_FILE = 'import-map.json';

  /**
   * Where in the DENO_DIR Deno keeps what scripts store.
   *
   * LocalStorage and the Cache API write under it. runner.js deletes both,
   * and an empty file here makes them fail if one were ever left, since
   * Deno can't make a directory where a file is, whoever it runs as.
   */
  private const STORAGE_DIRECTORY = 'location_data';

  /**
   * What V8 writes to stderr as it ends a process whose heap is full.
   */
  private const V8_HEAP_LIMIT = 'Fatal JavaScript out of memory: Reached heap limit';

  /**
   * How much of the process's stderr to keep for diagnostics, in bytes.
   */
  private const STDERR_LIMIT = 65536;

  /**
   * The signal that ends the process.
   *
   * SIGKILL, since a process being ended may not be responding. Spelled out
   * because web SAPIs often lack pcntl, which defines the constant.
   */
  private const SIGKILL = 9;

  /**
   * The process, until stop().
   *
   * @var resource|null
   */
  private $process = NULL;

  /**
   * The process's stdin, stdout and stderr, keyed 0, 1 and 2.
   *
   * @var array<int, resource>
   */
  private array $pipes = [];

  /**
   * Stdout read but not yet parsed as a message.
   */
  private string $stdout = '';

  /**
   * How much of $stdout is known to hold no newline, in bytes.
   *
   * So a long message is searched once, not again with each read.
   */
  private int $stdoutScanned = 0;

  /**
   * When to next check the process's resident memory, as a microtime.
   *
   * NULL where the OS doesn't report it.
   */
  private ?float $nextMemoryCheck = NULL;

  /**
   * Whether the OS reports the process's resident memory, until it's read.
   */
  private bool $memoryReported = TRUE;

  /**
   * Every running process, so a shutdown function can kill them.
   *
   * Weak, so a process nothing else holds is still destructed, and stopped.
   *
   * @var array<int, \WeakReference<self>>
   */
  private static array $running = [];

  /**
   * Whether stopRunning() is registered as a shutdown function.
   */
  private static bool $stopsAtShutdown = FALSE;

  /**
   * The process's most recent stderr output, trimmed to STDERR_LIMIT.
   */
  private string $stderr = '';

  /**
   * The next command id.
   */
  private int $nextCommandId = 1;

  /**
   * The PHP callbacks bound in the realm, keyed by their global's name.
   *
   * @var array<string, callable>
   */
  private array $callbacks = [];

  /**
   * The process ID, or NULL if PHP couldn't tell.
   */
  private ?int $pid = NULL;

  /**
   * Whether the process has answered a command yet.
   *
   * The first answer also waits for Deno to start.
   */
  private bool $answered = FALSE;

  /**
   * When the current command runs out of wall time, as a microtime.
   */
  private float $wallDeadline = 0.0;

  /**
   * How long the process may spend on the current command, in seconds.
   */
  private float $spendLimit = 0.0;

  /**
   * When to next check what the process spent, as a microtime.
   */
  private float $nextSpendCheck = 0.0;

  /**
   * When the current command was sent, as a microtime.
   */
  private float $commandStart = 0.0;

  /**
   * The process's CPU time when the current command was sent, in seconds.
   *
   * NULL where the OS doesn't report it.
   */
  private ?float $cpuAtCommandStart = NULL;

  /**
   * How long PHP has spent answering binding calls, in seconds.
   *
   * It counts those in the current command.
   */
  private float $answeringTime = 0.0;

  /**
   * Starts a Deno process.
   *
   * @param string $binary
   *   Path to the Deno binary.
   * @param string $denoDir
   *   The process's DENO_DIR: a runner cache or a base cache being built,
   *   where the modules are seeded and where Deno keeps its caches. The
   *   Deno import map is written there, and storage is blocked there.
   * @param array<string, string|null> $denoImportMap
   *   The Deno import map's entries, which locate modules in the DENO_DIR.
   *   Every node: builtin is added, blocked.
   * @param list<\Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory> $directories
   *   The OS user's directories the process reads from: the one holding
   *   the DENO_DIR, and the base caches directory if a base cache is linked
   *   into it.
   * @param \Drupal\canvas_ssr\Timeouts $timeouts
   *   The runner's time limits. The process's first answer gets the medium
   *   one, commands other than invoke get the short one, and the process's
   *   CPU time limit derives from the long one.
   * @param int $memoryLimitMib
   *   The most memory the process may use, in MiB: the JS heap's limit, and
   *   where the OS reports it, the process's resident memory.
   *
   * @throws \RuntimeException
   *   When the import map can't be written, the binary can't be started, or
   *   one of the directories changed.
   */
  public function __construct(
    string $binary,
    string $denoDir,
    private readonly array $denoImportMap,
    private readonly array $directories,
    private readonly Timeouts $timeouts,
    private readonly int $memoryLimitMib,
  ) {
    $this->assertDirectoriesIntact();
    $import_map = $denoDir . '/' . self::IMPORT_MAP_FILE;
    $refused = \array_fill_keys(\array_map(
      static fn(string $name): string => 'node:' . $name,
      self::NODE_BUILTINS,
    ), NULL);
    $json = \json_encode(['imports' => (object) ($denoImportMap + $refused)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (@\file_put_contents($import_map, $json) === FALSE) {
      throw new \RuntimeException(\sprintf('Failed to write the Deno import map "%s".', $import_map));
    }
    $storage = $denoDir . '/' . self::STORAGE_DIRECTORY;
    if (@\file_put_contents($storage, '') === FALSE) {
      throw new \RuntimeException(\sprintf('Failed to block storage at "%s".', $storage));
    }
    // Through sh, which execs Deno, so the process is Deno's.
    $command = [
      '/bin/sh',
      '-c',
      self::LIMIT_SCRIPT,
      'sh',
      (string) $timeouts->cpuLimitSeconds(),
      $binary,
      ...self::DENO_FLAGS,
      '--v8-flags=--max-old-space-size=' . $memoryLimitMib,
      '--import-map=' . $import_map,
      __DIR__ . '/js/runner.js',
    ];
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ] + self::nulledDescriptors();
    $pipes = [];
    $this->assertDirectoriesIntact();
    // In its DENO_DIR, not wherever PHP runs.
    $process = \proc_open($command, $descriptors, $pipes, $denoDir, [
      // No update check and no colored output.
      'DENO_NO_UPDATE_CHECK' => '1',
      'NO_COLOR' => '1',
      // The process can't start subprocesses, so it needs no node shim.
      'DENO_DISABLE_NODE_SHIM' => '1',
      'DENO_DIR' => $denoDir,
    ]);
    if (!\is_resource($process)) {
      throw new \RuntimeException(\sprintf('Failed to start Deno from "%s".', $binary));
    }
    foreach ($pipes as $pipe) {
      \stream_set_blocking($pipe, FALSE);
    }
    $this->process = $process;
    $this->pipes = $pipes;
    $pid = \proc_get_status($process)['pid'];
    $this->pid = $pid > 0 ? $pid : NULL;

    if (!self::$stopsAtShutdown) {
      \register_shutdown_function([self::class, 'stopRunning']);
      self::$stopsAtShutdown = TRUE;
    }
    self::$running[\spl_object_id($this)] = \WeakReference::create($this);
  }

  /**
   * Returns /dev/null in place of each descriptor PHP has open above 2.
   *
   * A child process inherits every descriptor PHP didn't open close-on-exec,
   * such as a database connection or the FastCGI socket. Deno's sandbox
   * gives JS no way to reach them, but the process needn't hold them
   * either. proc_open() can't close them in the child, and the shell that
   * starts Deno can't name a descriptor above 9, so each is replaced with
   * /dev/null. Where the open descriptors can't be listed, as under
   * open_basedir, Deno inherits them.
   *
   * @return array<int, array{0: string}>
   *   Descriptor specs for proc_open(), keyed by descriptor.
   */
  private static function nulledDescriptors(): array {
    foreach (['/proc/self/fd', '/dev/fd'] as $directory) {
      $entries = @\scandir($directory);
      if ($entries === FALSE) {
        continue;
      }
      $descriptors = [];
      foreach ($entries as $entry) {
        if (\ctype_digit($entry) && (int) $entry > 2) {
          $descriptors[(int) $entry] = ['null'];
        }
      }

      return $descriptors;
    }

    return [];
  }

  /**
   * Kills every process still running, as PHP shuts down.
   *
   * Destructors don't run after a fatal error, but shutdown functions do.
   *
   * @internal
   *   Registered as a shutdown function.
   */
  public static function stopRunning(): void {
    foreach (self::$running as $process) {
      $process->get()?->stop();
    }
    self::$running = [];
  }

  /**
   * Kills the process.
   *
   * It would exit with PHP anyway, but a PHP-FPM worker outlives many
   * requests.
   */
  public function __destruct() {
    $this->stop();
  }

  /**
   * Returns the Deno import map the process was started with.
   *
   * @return array<string, string|null>
   *   Its entries.
   */
  public function denoImportMap(): array {
    return $this->denoImportMap;
  }

  /**
   * Sends the SSR import map, which import.meta.resolve() reads.
   *
   * Only `imports` is sent. The resolver ignores scopes, as
   * js/runner.js explains, and handles malformed entries.
   *
   * @param array<string, mixed> $imports
   *   The SSR import map's entries.
   * @param int|null $timeout_ms
   *   How long it may take, waiting for Deno to start included, if it's
   *   the first command. NULL means the short limit, or the medium one for
   *   the first command.
   */
  public function setSsrImports(array $imports, ?int $timeout_ms = NULL): void {
    $command = ['type' => 'setImportMap', 'imports' => (object) $imports];
    if ($timeout_ms === NULL) {
      $this->send($command, $this->timeouts->short, $this->timeouts->short);
      return;
    }
    $this->send($command, $timeout_ms, $timeout_ms, TRUE);
  }

  /**
   * Binds a PHP callback to a global in the realm.
   *
   * The process gets only the name and the two JS expressions, and calls
   * back over the pipe.
   *
   * @param string $name
   *   The global's name.
   * @param callable $callback
   *   The PHP callback, taking and returning a string.
   * @param string $serialize_js
   *   The JS expression that turns `this` and the call's arguments into a
   *   string.
   * @param string $deserialize_js
   *   The JS expression that turns the returned string into a value.
   */
  public function bind(string $name, callable $callback, string $serialize_js, string $deserialize_js): void {
    $this->callbacks[$name] = $callback;
    $this->send([
      'type' => 'bind',
      'name' => $name,
      'serialize' => $serialize_js,
      'deserialize' => $deserialize_js,
    ], $this->timeouts->short, $this->timeouts->short);
  }

  /**
   * Invokes a main module and returns what it produced.
   *
   * The process may spend the short limit on it, and the whole call,
   * binding calls included, may take the medium limit.
   *
   * @param string $main
   *   The main module's cache URL.
   * @param string $payload
   *   The payload, as JSON.
   *
   * @return string
   *   The string the main module's default export resolved to.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Exception\ScriptException
   *   When the JS failed. The process is still running, and the caller
   *   decides whether to keep it, unless the failure stopped it (see
   *   isStopped()).
   * @throws \RuntimeException
   *   When the process died, answered out of turn or ran out of time or
   *   memory, or answered with something other than a string: for time and
   *   memory, a TimeLimitException or a MemoryLimitException. The process
   *   has been killed.
   */
  public function invoke(string $main, string $payload): string {
    $result = $this->send(['type' => 'invoke', 'main' => $main, 'payload' => $payload], $this->timeouts->short, $this->timeouts->medium);
    if (($result['type'] ?? '') === 'invoke-error') {
      throw new ScriptException($this->describeInvokeError($result));
    }
    $value = $result['value'] ?? NULL;
    if (!\is_string($value)) {
      $this->fail('The Deno process returned an invoke result carrying no string.');
    }

    return $value;
  }

  /**
   * Imports modules, so Deno's caches hold them.
   *
   * @param list<string> $urls
   *   The modules' cache URLs.
   * @param int $timeout_ms
   *   How long the imports may take, in wall time.
   *
   * @return list<string>
   *   The cache URLs of those that couldn't load.
   */
  public function preload(array $urls, int $timeout_ms): array {
    $reply = $this->send(['type' => 'preload', 'urls' => $urls], $timeout_ms, $timeout_ms);
    $unloadable = $reply['unloadable'] ?? NULL;

    return \is_array($unloadable) ? \array_values(\array_filter($unloadable, 'is_string')) : [];
  }

  /**
   * Lets the process exit on its own, and waits for it.
   *
   * Closing its stdin lets it exit cleanly and write its caches, which
   * killing it doesn't. It's killed afterwards either way.
   *
   * @param int $timeout_ms
   *   How long to wait for it to exit.
   *
   * @return bool
   *   Whether it exited cleanly in time, so its caches are whole.
   */
  public function end(int $timeout_ms): bool {
    $stdin = $this->pipes[0] ?? NULL;
    if (\is_resource($stdin)) {
      \fclose($stdin);
    }
    $deadline = \microtime(TRUE) + $timeout_ms / 1000;
    $exited = FALSE;
    while (\is_resource($this->process) && \microtime(TRUE) < $deadline) {
      // The exit code is only given the first time it's not running.
      $status = \proc_get_status($this->process);
      if (!$status['running']) {
        $exited = $status['exitcode'] === 0;
        break;
      }
      \usleep(10000);
    }
    $this->stop();

    return $exited;
  }

  /**
   * Returns whether the process was stopped.
   *
   * A script failure may stop it, as for a message too long to read.
   *
   * @return bool
   *   Whether it was.
   */
  public function isStopped(): bool {
    return $this->process === NULL;
  }

  /**
   * Kills the process, and with it the realm.
   */
  public function stop(): void {
    foreach ($this->pipes as $pipe) {
      if (\is_resource($pipe)) {
        \fclose($pipe);
      }
    }
    $this->pipes = [];
    if (\is_resource($this->process)) {
      \proc_terminate($this->process, self::SIGKILL);
      \proc_close($this->process);
    }
    $this->process = NULL;
    unset(self::$running[\spl_object_id($this)]);
  }

  /**
   * Returns the process's CPU time when the last command was sent.
   *
   * It's read for every command, whatever the limits, so the runner can
   * replace a process before its CPU time limit ends it.
   *
   * @return float|null
   *   The time, in seconds, or NULL if the OS doesn't report it.
   */
  public function cpuSecondsAtLastCommand(): ?float {
    return $this->cpuAtCommandStart;
  }

  /**
   * Returns the CPU time the process has used, in seconds.
   *
   * @return float|null
   *   The time, or NULL where the OS doesn't report it, as on macOS.
   */
  public function cpuSeconds(): ?float {
    if ($this->pid === NULL) {
      return NULL;
    }
    $stat = @\file_get_contents('/proc/' . $this->pid . '/stat');
    // The command name, in parentheses, may itself hold spaces.
    $end = \is_string($stat) ? \strrpos($stat, ')') : FALSE;
    if ($end === FALSE) {
      return NULL;
    }
    // Utime and stime, the 14th and 15th fields.
    $fields = \explode(' ', \substr($stat, $end + 2));
    if (!isset($fields[12])) {
      return NULL;
    }

    return ((int) $fields[11] + (int) $fields[12]) / self::CLOCK_TICKS_PER_SECOND;
  }

  /**
   * Sends a command and waits for its reply, answering calls meanwhile.
   *
   * @param array<string, mixed> $command
   *   The command, without its id.
   * @param int $spend_ms
   *   How long the process may spend on it: CPU time where the OS reports
   *   it, and otherwise wall time less the time spent answering binding
   *   calls.
   * @param int $wall_ms
   *   How long the whole exchange may take. The first command to a new
   *   process gets at least the medium limit for both, since it also waits
   *   for Deno to start.
   * @param bool $exact
   *   Whether to take the limits as given, even for the first command, as
   *   for a build with a deadline of its own.
   *
   * @return array<string, mixed>
   *   The reply.
   *
   * @throws \RuntimeException
   *   When the process dies, answers out of turn, or runs out of time.
   */
  private function send(array $command, int $spend_ms, int $wall_ms, bool $exact = FALSE): array {
    if (!$this->answered && !$exact) {
      $spend_ms = \max($spend_ms, $this->timeouts->medium);
      $wall_ms = \max($wall_ms, $this->timeouts->medium);
    }
    $id = $this->nextCommandId++;
    $this->commandStart = \microtime(TRUE);
    $this->wallDeadline = $this->commandStart + $wall_ms / 1000;
    $this->spendLimit = $spend_ms / 1000;
    $this->nextSpendCheck = $this->commandStart + $this->spendLimit;
    $this->nextMemoryCheck = $this->memoryReported && $this->pid !== NULL ? $this->commandStart + self::MEMORY_CHECK_INTERVAL : NULL;
    $this->answeringTime = 0.0;
    $this->cpuAtCommandStart = $this->cpuSeconds();

    $this->writeLine(\array_merge(['id' => $id], $command));

    while (TRUE) {
      $message = $this->readMessage();
      if (($message['type'] ?? '') === 'call') {
        $started = \microtime(TRUE);
        $this->answerCall($message);
        $answering = \microtime(TRUE) - $started;
        $this->answeringTime += $answering;
        $this->nextSpendCheck += $answering;
        continue;
      }
      if (($message['id'] ?? NULL) !== $id) {
        $this->fail(\sprintf(
          'The Deno process answered command %s out of turn.',
          \json_encode($message['id'] ?? NULL),
        ));
      }
      $this->answered = TRUE;

      return $message;
    }
  }

  /**
   * Fails the command if the process has used up what it may spend on it.
   *
   * Called once the wall time it may spend has passed, since it can't have
   * spent more than that. Where the OS reports CPU time, a process that
   * spent less, waiting for a core, gets to carry on, and is checked again
   * once it could have spent the rest.
   */
  private function checkSpending(): void {
    $now = \microtime(TRUE);
    $cpu = $this->cpuAtCommandStart === NULL ? NULL : $this->cpuSeconds();
    $spent = $cpu === NULL
      ? $now - $this->commandStart - $this->answeringTime
      : $cpu - $this->cpuAtCommandStart;
    $left = $this->spendLimit - $spent;
    if ($left <= 0) {
      $this->fail(\sprintf(
        'The Deno process spent its %d ms on a command without answering, so it was killed. A render that never returns is ended this way and no other.',
        (int) \round($this->spendLimit * 1000),
      ), TimeLimitException::class);
    }
    $this->nextSpendCheck = $now + \max($left, self::CPU_CHECK_INTERVAL);
  }

  /**
   * Fails the command if the process uses more memory than it may.
   *
   * Where the OS doesn't report it, the V8 heap limit is all there is, and
   * it isn't checked again.
   */
  private function checkMemory(): void {
    $bytes = $this->residentBytes();
    if ($bytes === NULL) {
      $this->memoryReported = FALSE;
      $this->nextMemoryCheck = NULL;
      return;
    }
    if ($bytes > $this->memoryLimitMib * 1024 * 1024) {
      $this->fail(\sprintf('The Deno process used more than its %d MiB of memory, so it was killed.', $this->memoryLimitMib), MemoryLimitException::class);
    }
    $this->nextMemoryCheck = \microtime(TRUE) + self::MEMORY_CHECK_INTERVAL;
  }

  /**
   * Returns the process's resident memory, in bytes.
   *
   * @return int|null
   *   The memory, or NULL where the OS doesn't report it, as on macOS, or
   *   PHP can't read it, as under open_basedir.
   */
  public function residentBytes(): ?int {
    if ($this->pid === NULL) {
      return NULL;
    }
    $status = @\file_get_contents('/proc/' . $this->pid . '/status');
    if (!\is_string($status) || \preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $match) !== 1) {
      return NULL;
    }

    return (int) $match[1] * 1024;
  }

  /**
   * Runs the callback for a binding call and sends back the result.
   *
   * A callback's exception is sent to the process, where the JS call
   * throws.
   *
   * @param array<string, mixed> $call
   *   The call message.
   */
  private function answerCall(array $call): void {
    $call_id = $call['callId'] ?? NULL;
    $name = $call['name'] ?? NULL;
    if (!\is_int($call_id) || !\is_string($name)) {
      $this->fail('The Deno process sent a malformed binding call.');
    }

    $callback = $this->callbacks[$name] ?? NULL;
    if ($callback === NULL) {
      $this->writeLine([
        'callId' => $call_id,
        'type' => 'call-error',
        'message' => \sprintf('Nothing is bound to "%s" on the PHP side.', $name),
      ]);
      return;
    }

    try {
      $value = $callback((string) ($call['arg'] ?? ''));
      $reply = [
        'callId' => $call_id,
        'type' => 'call-result',
        'value' => \is_string($value) ? $value : (string) \json_encode($value),
      ];
    }
    catch (\Throwable $error) {
      $reply = [
        'callId' => $call_id,
        'type' => 'call-error',
        'message' => $error->getMessage(),
      ];
    }
    $this->writeLine($reply);
  }

  /**
   * Writes one message to the process, reading its output meanwhile.
   *
   * A long message takes several writes, and the process may fill its own
   * output pipe in between. Reading it prevents a deadlock.
   *
   * @param array<string, mixed> $message
   *   The message to write.
   */
  private function writeLine(array $message): void {
    // Each message hands Deno control, which may read what it hasn't yet.
    try {
      $this->assertDirectoriesIntact();
    }
    catch (OsUserDirectoryChangedException $e) {
      $this->stop();
      throw $e;
    }
    $line = \json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    $offset = 0;
    $length = \strlen($line);

    while ($offset < $length) {
      if (!$this->pump(TRUE)) {
        continue;
      }
      $written = \fwrite($this->pipe(0), \substr($line, $offset));
      if ($written === FALSE) {
        $this->fail('The Deno process stopped reading its input.');
      }
      $offset += $written;
    }
  }

  /**
   * Checks the OS user's directories the process reads from are unchanged.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException
   *   When one changed.
   */
  private function assertDirectoriesIntact(): void {
    foreach ($this->directories as $directory) {
      $directory->assertIntact();
    }
  }

  /**
   * Reads the next complete message off the process's stdout.
   *
   * @return array<string, mixed>
   *   The decoded message.
   */
  private function readMessage(): array {
    while (TRUE) {
      $newline = \strpos($this->stdout, "\n", $this->stdoutScanned);
      if ($newline !== FALSE) {
        break;
      }
      $this->stdoutScanned = \strlen($this->stdout);
      if ($this->stdoutScanned > self::MESSAGE_LIMIT) {
        $this->messageTooLong();
      }
      $this->pump(FALSE);
    }
    if ($newline > self::MESSAGE_LIMIT) {
      $this->messageTooLong();
    }

    $line = \substr($this->stdout, 0, $newline);
    $this->stdout = \substr($this->stdout, $newline + 1);
    $this->stdoutScanned = 0;
    try {
      // runner.js writes objects of strings, numbers and arrays of strings,
      // and nothing deeper.
      $decoded = \json_decode($line, TRUE, 3, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $error) {
      $this->fail('The Deno process wrote something that is not a message: ' . $error->getMessage());
    }
    if (!\is_array($decoded)) {
      $this->fail('The Deno process wrote a message that is not an object.');
    }

    return $decoded;
  }

  /**
   * Kills the process for writing a message longer than MESSAGE_LIMIT.
   *
   * It's what the JS wrote, so it's a script failure, which would recur
   * with the same inputs. The process is mid-message, so it can't be used
   * again.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Exception\ScriptException
   *   Always.
   */
  private function messageTooLong(): never {
    $this->stop();
    throw new ScriptException(\sprintf('The Deno process wrote a message of more than %d MiB, such as an island\'s HTML, so it was killed.', self::MESSAGE_LIMIT / 1024 / 1024));
  }

  /**
   * Waits once for the process and reads whatever it wrote.
   *
   * Both output pipes are read every time, so the process never blocks on a
   * full one.
   *
   * It waits until the current command's next limit at most, and fails the
   * command once it's past one.
   *
   * @param bool $for_writing
   *   Whether to also wait for stdin to be writable.
   *
   * @return bool
   *   Whether stdin is ready to be written to.
   */
  private function pump(bool $for_writing): bool {
    $now = \microtime(TRUE);
    if ($now >= $this->wallDeadline) {
      $this->fail(\sprintf(
        'The Deno process did not answer within %d ms, so it was killed. A render that never returns is ended this way and no other.',
        (int) \round(($this->wallDeadline - $this->commandStart) * 1000),
      ), TimeLimitException::class);
    }
    if ($now >= $this->nextSpendCheck) {
      $this->checkSpending();
    }
    if ($this->nextMemoryCheck !== NULL && $now >= $this->nextMemoryCheck) {
      $this->checkMemory();
    }
    $remaining = \min($this->wallDeadline, $this->nextSpendCheck, $this->nextMemoryCheck ?? \INF) - \microtime(TRUE);
    $remaining = \max($remaining, 0.0);

    $read = [$this->pipe(1), $this->pipe(2)];
    $write = $for_writing ? [$this->pipe(0)] : [];
    $except = NULL;
    $ready = @\stream_select(
      $read,
      $write,
      $except,
      (int) $remaining,
      (int) (\fmod($remaining, 1) * 1000000),
    );
    // FALSE means a signal interrupted the wait. Try again.
    if ($ready === FALSE || $ready === 0) {
      return FALSE;
    }

    $this->drain(1);
    $this->drain(2);

    return $write !== [];
  }

  /**
   * Reads what the process has written to stdout or stderr.
   *
   * @param int $descriptor
   *   Either 1 for stdout, which carries the protocol, or 2 for stderr,
   *   whose tail is kept for error messages.
   */
  private function drain(int $descriptor): void {
    $pipe = $this->pipe($descriptor);
    $read = '';
    // Stdout stops once there's more than a message may be, which is
    // enough to tell it's too long. Stderr stops at what's kept of it, so a
    // process that writes faster than this reads can't keep it here: the
    // rest waits in the pipe, for after the time limits are checked.
    $room = $descriptor === 1 ? self::MESSAGE_LIMIT + 1 - \strlen($this->stdout) : self::STDERR_LIMIT;
    while (\strlen($read) < $room) {
      $chunk = \fread($pipe, 65536);
      if (!\is_string($chunk) || $chunk === '') {
        break;
      }
      $read .= $chunk;
    }

    if ($descriptor === 1) {
      $this->stdout .= $read;
      // Checked after reading, since an exiting process's last bytes arrive
      // with its EOF.
      if ($read === '' && \feof($pipe)) {
        // What it wrote to stderr before it exited is in the pipe already.
        $this->drain(2);
        if (\str_contains($this->stderr, self::V8_HEAP_LIMIT)) {
          $this->fail(\sprintf('The Deno process used more than its %d MiB of memory, so V8 ended it.', $this->memoryLimitMib), MemoryLimitException::class);
        }
        $this->fail('The Deno process exited without answering.');
      }
      return;
    }

    $this->stderr .= $read;
    if (\strlen($this->stderr) > self::STDERR_LIMIT) {
      $this->stderr = \substr($this->stderr, -self::STDERR_LIMIT);
    }
  }

  /**
   * Kills the process and throws, adding its stderr.
   *
   * A process in an unknown state is never reused.
   *
   * @param string $reason
   *   What went wrong.
   * @param class-string<\RuntimeException> $class
   *   What to throw.
   *
   * @throws \RuntimeException
   *   Always.
   */
  private function fail(string $reason, string $class = \RuntimeException::class): never {
    $diagnostics = $this->stderr;
    $this->stop();
    throw new $class(
      $diagnostics === '' ? $reason : $reason . "\nDeno wrote:\n" . $diagnostics,
    );
  }

  /**
   * Returns a pipe, or throws if the process is gone.
   *
   * @param int $descriptor
   *   Which pipe: 0 is stdin, 1 is stdout, 2 is stderr.
   *
   * @return resource
   *   The pipe.
   */
  private function pipe(int $descriptor) {
    $pipe = $this->pipes[$descriptor] ?? NULL;
    if (!\is_resource($pipe)) {
      throw new \RuntimeException('The Deno process is not running.');
    }

    return $pipe;
  }

  /**
   * Formats what the JS threw as an exception message.
   *
   * @param array<string, mixed> $error
   *   The invoke-error message.
   *
   * @return string
   *   The message to throw with.
   */
  private function describeInvokeError(array $error): string {
    $name = \is_string($error['name'] ?? NULL) && $error['name'] !== '' ? $error['name'] : 'Error';
    $message = \is_string($error['message'] ?? NULL) ? $error['message'] : '';
    $stack = \is_string($error['stack'] ?? NULL) ? $error['stack'] : '';
    $described = \sprintf('%s: %s', $name, $message);
    if ($stack !== '') {
      $described .= "\n" . $stack;
    }
    if ($this->stderr !== '') {
      $described .= "\nDeno wrote:\n" . $this->stderr;
    }

    return $described;
  }

}
