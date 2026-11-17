<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\DenoProcess;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\Exception\MemoryLimitException;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\Tests\canvas_ssr\Traits\DenoProcessesTrait;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * Tests when the Deno runner starts and ends its Deno process.
 *
 * Starting one is most of what a cold invoke costs, so islands share one.
 * Its realm is the process's, so a script failure, which must not leave
 * state behind, ends it, as reset() does.
 *
 * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase::testRealmLastsFromOneInvokeToTheNext()
 */
#[CoversClass(DenoRunner::class)]
#[CoversClass(DenoProcess::class)]
#[Large]
#[Group('canvas_ssr')]
final class DenoProcessTest extends UnitTestCase {

  use DenoBinaryTrait;
  use WarmsUpDenoTrait;
  use DenoProcessesTrait;

  /**
   * Holds this test's base caches and runner caches.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = self::makeTemporaryDirectory('deno-process');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::removeTree($this->directory);
    parent::tearDown();
  }

  /**
   * Tests islands share a Deno process until a reset ends it.
   *
   * A script failure keeps it, as it keeps the realm.
   */
  public function testIslandsShareOneProcessUntilReset(): void {
    self::skipWithoutDeno();
    $runner = new DenoRunner(self::denoBinary(), [], $this->directory . '/base-caches', $this->directory . '/runner-caches');
    self::assertTrue($runner->isAvailable());
    self::assertSame([], self::denoProcesses(), 'Nothing starts before an invoke.');

    $runner->addModule('/a.js', 'export default () => "A";');
    $runner->addModule('/b.js', 'export default () => "B";');
    $runner->addModule('/fails.js', 'export default () => { throw new Error("No."); };');
    self::assertSame('A', $runner->invoke('/a.js', '""'));
    $first = self::denoProcesses();
    self::assertCount(1, $first);
    self::assertSame('B', $runner->invoke('/b.js', '""'));
    self::assertSame('A', $runner->invoke('/a.js', '""'));
    self::assertSame($first, self::denoProcesses());

    try {
      $runner->invoke('/fails.js', '""');
      self::fail('The invoke was expected to fail.');
    }
    catch (ScriptException) {
    }
    self::assertSame('A', $runner->invoke('/a.js', '""'));
    self::assertSame($first, self::denoProcesses());

    $runner->reset();
    self::assertSame([], self::denoProcesses());
  }

  /**
   * Tests waiting doesn't count against the short limit, only spending.
   *
   * A bound function's time is PHP's, and a process waiting for a core on
   * a busy host isn't running JS. Only where the OS reports CPU time can
   * the runner tell waiting from running. Here the process is stopped for
   * 300 ms while it runs, as the scheduler might.
   */
  public function testWaitingIsNotCountedAgainstTheShortLimit(): void {
    self::skipWithoutDeno();
    self::skipWithoutProcCpuTimes();
    $runner = $this->runner(['short' => 100, 'medium' => 3000]);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $processes = self::denoProcesses();
    self::assertCount(1, $processes);
    $pid = $processes[0];
    $runner->bindFunction('slowHost', static function () use ($pid): string {
      \usleep(300000);
      // Stops the process just after it gets the answer, for 300 ms.
      \exec(\sprintf('(sleep 0.02; kill -STOP %1$d; sleep 0.3; kill -CONT %1$d) > /dev/null 2>&1 &', $pid));
      return 'answered';
    }, '() => ""', '(answer) => answer');
    $runner->addModule('/waits.js', <<<'JS'
      export default () => {
        const answer = slowHost();
        // Runs until 350 ms have passed. Stopped for 300 of them, it
        // spends about 50.
        const end = Date.now() + 350;
        while (Date.now() < end);
        return answer;
      };
      JS);

    self::assertSame('answered', $runner->invoke('/waits.js', '""'));
  }

  /**
   * Tests an invoke ends at the medium limit, its bound calls included.
   *
   * The invoke spends nothing while PHP answers a call, so only the medium
   * limit can end it.
   */
  public function testInvokeEndsAtTheMediumLimit(): void {
    self::skipWithoutDeno();
    $runner = $this->runner(['medium' => 500]);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    // Started, so the wait below isn't the process's first answer.
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $runner->bindFunction('pause', static function (): string {
      \usleep(1_000_000);
      return '';
    }, '() => ""', '(s) => s');
    $runner->addModule('/waits.js', 'export default () => { pause(); return "late"; };');

    $started = \microtime(TRUE);
    try {
      $runner->invoke('/waits.js', '""');
      self::fail('The invoke was expected to run out of time.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('within 500 ms', $e->getMessage());
    }
    self::assertLessThan(2.0, \microtime(TRUE) - $started);
  }

  /**
   * Tests stderr is read only as far as what's kept of it, each time.
   *
   * Otherwise a process that writes faster than PHP reads would keep PHP
   * reading, past its time limits and into its memory. A stream that never
   * runs dry stands in for one.
   */
  public function testStderrIsReadOnlyAsFarAsItsTail(): void {
    $process = (new \ReflectionClass(DenoProcess::class))->newInstanceWithoutConstructor();
    $stream = \fopen('php://memory', 'w+');
    self::assertIsResource($stream);
    \fwrite($stream, \str_repeat('x', 1024 * 1024));
    \rewind($stream);
    (new \ReflectionProperty(DenoProcess::class, 'pipes'))->setValue($process, [2 => $stream]);

    (new \ReflectionMethod(DenoProcess::class, 'drain'))->invoke($process, 2);

    self::assertSame(65536, \ftell($stream));
    self::assertSame(65536, \strlen((new \ReflectionProperty(DenoProcess::class, 'stderr'))->getValue($process)));
  }

  /**
   * Tests each Deno process gets a CPU time limit half again the long one.
   *
   * It ends a process PHP lost track of, like one left spinning when PHP
   * died without killing it.
   */
  public function testProcessHasACpuTimeLimit(): void {
    self::skipWithoutDeno();
    self::skipWithoutProcCpuTimes();
    $runner = $this->runner(['medium' => 1000, 'long' => 2000]);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $processes = self::denoProcesses();
    self::assertCount(1, $processes);

    $limits = (string) \file_get_contents('/proc/' . $processes[0] . '/limits');
    self::assertMatchesRegularExpression('/^Max cpu time\s+3\s+3\s+seconds/m', $limits);
  }

  /**
   * Tests a process past half its CPU time limit is replaced.
   *
   * It's replaced after an invoke, so a later render isn't cut off by the
   * limit.
   *
   * The process spends its CPU time over several invokes, each well within
   * the short limit, as renders do.
   */
  public function testProcessPastHalfItsCpuLimitIsReplaced(): void {
    self::skipWithoutDeno();
    self::skipWithoutProcCpuTimes();
    // A 3 second CPU limit, so half is 1.5 seconds. Each invoke spins until
    // the process has used the CPU time it's given, asking PHP, so it
    // spends about 0.1 s, and the 1 s wall time limit is generous.
    $runner = $this->runner(['short' => 500, 'medium' => 1000, 'long' => 2000]);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    $runner->addModule('/spins.js', 'export default (json) => { const target = JSON.parse(json); while (globalThis.cpuSeconds() < target) { const end = Date.now() + 20; while (Date.now() < end); } return "spun"; };');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $first = self::denoProcesses();
    self::assertCount(1, $first);
    $pid = $first[0];
    $runner->bindFunction('cpuSeconds', static fn(): string => (string) self::cpuSeconds($pid), '() => ""', '(s) => Number(s)');

    // Up to 1.5 s. The last is sent at 1.4 s, so it isn't replaced yet.
    foreach (\range(1, 15) as $tenths) {
      self::assertSame('spun', $runner->invoke('/spins.js', (string) ($tenths / 10)));
    }
    self::assertSame($first, self::denoProcesses());
    // Read when the last invoke above was sent, so the next one replaces it.
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $second = self::denoProcesses();
    self::assertCount(1, $second);
    self::assertNotSame($first, $second);
  }

  /**
   * Tests a JS heap past the memory limit is a runner failure.
   *
   * V8 ends the process, or PHP does, when its resident memory gets there
   * first. Either way, the next invoke gets another.
   */
  public function testHeapPastTheMemoryLimitIsARunnerFailure(): void {
    self::skipWithoutDeno();
    $runner = $this->runner(['short' => 20000, 'medium' => 40000, 'long' => 80000], 128);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    $runner->addModule('/grows.js', 'export default () => { const kept = []; for (;;) kept.push({ n: Math.random(), s: "x".repeat(64) + Math.random() }); };');

    self::assertMemoryLimitHit($runner, '/grows.js');
    // A runner failure resets the runner, so it's registered again.
    $runner->addModule('/ok.js', 'export default () => "ok";');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
  }

  /**
   * Tests buffers past the memory limit are a runner failure.
   *
   * They aren't on the JS heap, so only PHP's check of the process's
   * resident memory ends it.
   */
  public function testBuffersPastTheMemoryLimitAreARunnerFailure(): void {
    self::skipWithoutDeno();
    self::skipWithoutProcCpuTimes();
    $runner = $this->runner(['short' => 20000, 'medium' => 40000, 'long' => 80000], 128);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    $runner->addModule('/grows.js', 'export default () => { const kept = []; for (;;) { const b = new Uint8Array(8 * 1024 * 1024); b.fill(1); kept.push(b); } };');

    $message = self::assertMemoryLimitHit($runner, '/grows.js');
    self::assertStringContainsString('more than its 128 MiB of memory', $message);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
  }

  /**
   * Tests a message past the limit is a script failure.
   *
   * The same inputs would write it again, so the fallback is cached as a
   * render's would be. The process is mid-message, so it goes.
   */
  public function testLongMessageIsAScriptFailure(): void {
    self::skipWithoutDeno();
    $runner = $this->runner([]);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    $runner->addModule('/long.js', \sprintf('export default () => "x".repeat(%d);', DenoProcess::MESSAGE_LIMIT));
    $runner->addModule('/fits.js', \sprintf('export default () => "x".repeat(%d);', DenoProcess::MESSAGE_LIMIT - 1024));
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $first = self::denoProcesses();

    try {
      $runner->invoke('/long.js', '""');
      self::fail('The invoke was expected to fail.');
    }
    catch (ScriptException $e) {
      self::assertStringContainsString('more than 4 MiB', $e->getMessage());
    }
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    self::assertNotSame($first, self::denoProcesses());
    self::assertSame(DenoProcess::MESSAGE_LIMIT - 1024, \strlen($runner->invoke('/fits.js', '""')));
  }

  /**
   * Tests a process starts with its heap limit, out of the site's way.
   *
   * It's the first the kernel kills when the host runs out of memory, and
   * it runs in its runner cache, so nothing it reports names the site's
   * directory.
   */
  public function testProcessStartsWithItsLimits(): void {
    self::skipWithoutDeno();
    self::skipWithoutProcCpuTimes();
    $runner = $this->runner([], 128);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $processes = self::denoProcesses();
    self::assertCount(1, $processes);
    $proc = '/proc/' . $processes[0];

    self::assertContains('--v8-flags=--max-old-space-size=128', \explode("\0", (string) \file_get_contents($proc . '/cmdline')));
    self::assertSame('1000', \trim((string) \file_get_contents($proc . '/oom_score_adj')));
    $runner_caches = (string) \realpath($this->directory . '/runner-caches');
    self::assertNotSame('', $runner_caches);
    self::assertStringStartsWith($runner_caches, (string) \readlink($proc . '/cwd'));
  }

  /**
   * Tests a process holds none of the files and sockets PHP has open.
   *
   * It would inherit them otherwise, such as a database connection.
   */
  public function testProcessInheritsNoDescriptors(): void {
    self::skipWithoutDeno();
    self::skipWithoutProcCpuTimes();
    $file = \fopen(__FILE__, 'r');
    self::assertIsResource($file);
    $server = \stream_socket_server('tcp://127.0.0.1:0');
    self::assertIsResource($server);
    $client = \stream_socket_client('tcp://' . \stream_socket_get_name($server, FALSE));
    self::assertIsResource($client);
    $socket_inodes = [];
    foreach ([$server, $client] as $stream) {
      $stat = \fstat($stream);
      self::assertIsArray($stat);
      $socket_inodes[] = $stat['ino'];
    }

    $runner = $this->runner([]);
    $runner->addModule('/ok.js', 'export default () => "ok";');
    self::assertSame('ok', $runner->invoke('/ok.js', '""'));
    $processes = self::denoProcesses();
    self::assertCount(1, $processes);

    $targets = [];
    foreach (self::paths('/proc/' . $processes[0] . '/fd/*') as $link) {
      $targets[] = (string) @\readlink($link);
    }
    self::assertNotSame([], $targets);
    self::assertNotContains(__FILE__, $targets);
    foreach ($socket_inodes as $inode) {
      self::assertNotContains('socket:[' . $inode . ']', $targets);
    }
    \fclose($file);
  }

  /**
   * Asserts an invoke is a memory limit's runner failure.
   *
   * @return string
   *   The failure's message.
   */
  private static function assertMemoryLimitHit(DenoRunner $runner, string $main_module): string {
    try {
      $runner->invoke($main_module, '""');
    }
    catch (MemoryLimitException $e) {
      return $e->getMessage();
    }
    catch (\Throwable $e) {
      self::fail('A ' . $e::class . ', where a MemoryLimitException was expected: ' . $e->getMessage());
    }
    self::fail('The invoke was expected to fail.');
  }

  /**
   * Returns a runner caching under this test's directory.
   *
   * @param array{short?: int, medium?: int, long?: int} $timeouts
   *   The time limits, in milliseconds.
   * @param int $memory_limit_mib
   *   The memory limit, in MiB.
   */
  private function runner(array $timeouts, int $memory_limit_mib = DenoRunner::DEFAULT_MEMORY_LIMIT_MIB): DenoRunner {
    $runner = new DenoRunner(self::denoBinary(), $timeouts, $this->directory . '/base-caches', $this->directory . '/runner-caches', NULL, $memory_limit_mib);
    self::assertTrue($runner->isAvailable());

    return $runner;
  }

  /**
   * Returns the CPU time a process has used, in seconds, from /proc.
   */
  private static function cpuSeconds(int $pid): float {
    $stat = (string) \file_get_contents('/proc/' . $pid . '/stat');
    // The fields after the command name, which ends at the last ")". User
    // and system time are the 14th and 15th, in clock ticks.
    $fields = \explode(' ', \substr($stat, \strrpos($stat, ')') + 2));

    return ((int) $fields[11] + (int) $fields[12]) / 100;
  }

  /**
   * Skips unless /proc reports process CPU times.
   */
  private static function skipWithoutProcCpuTimes(): void {
    if (!\is_readable('/proc/self/stat')) {
      self::markTestSkipped('This system doesn\'t report process CPU times in /proc.');
    }
  }

}
