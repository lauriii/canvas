<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner;

use Drupal\canvas_ssr\JsRunner\Exception\RunnerBusyException;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\canvas_ssr\JsRunner\RunnerGuard;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what the guard lets reach the runner, and what it spares it.
 *
 * Calls are checked exactly as the runner sees them.
 *
 * @phpstan-import-type Base from \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
#[CoversClass(RunnerGuard::class)]
#[Group('canvas_ssr')]
final class RunnerGuardTest extends UnitTestCase {

  /**
   * Tests registering under anything but a module URL is refused.
   *
   * Runners resolve imports by URL rules alone, so under a bare specifier
   * the module's relative imports couldn't resolve.
   */
  public function testNamesThatAreNotModuleUrlsAreRefused(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);

    try {
      $guard->addModule('preact', 'export const h = 1;');
      self::fail('A module was registered under a bare specifier.');
    }
    catch (\InvalidArgumentException) {
    }
    self::assertSame([], $runner->calls);
  }

  /**
   * Tests the end of a request resets the runner.
   *
   * Where PHP keeps services between requests, the realm would otherwise
   * outlive the request.
   */
  public function testDestructResetsTheRunner(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);

    $guard->addModule('/m.js', 'export default () => "m";');
    $guard->destruct();
    self::assertSame(['addModule /m.js', 'reset'], $runner->calls);

    // The registration went with the reset, so it's given again.
    $guard->addModule('/m.js', 'export default () => "m";');
    self::assertSame(['addModule /m.js', 'reset', 'addModule /m.js'], $runner->calls);
  }

  /**
   * Tests a module registered with no runner reaches one found later.
   *
   * With none available, the registration throws, so nothing is remembered,
   * and a runner that becomes available still gets the module.
   */
  public function testModuleRegisteredWithNoRunnerReachesOneFoundLater(): void {
    $runner = new RecordingRunner(available: FALSE);
    $guard = new RunnerGuard(new RunnerSelector([$runner]));

    try {
      $guard->addModule('/m.js', 'export default () => "A";');
      self::fail('A module was registered with no runner.');
    }
    catch (\LogicException) {
    }
    $runner->available = TRUE;
    $guard->addModule('/m.js', 'export default () => "A";');

    self::assertSame(['addModule /m.js'], $runner->calls);
  }

  /**
   * Tests a script failure keeps the registrations.
   */
  public function testScriptFailureKeepsRegistrations(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);
    $guard->useBase('abc', self::getDefinition('abc'));
    $guard->addModule('/m.js', 'export default () => "A";');
    $runner->failWith = 'threw in ';
    $runner->failInScript = TRUE;

    try {
      $guard->invoke('/m.js', '""');
      self::fail('The invoke succeeded.');
    }
    catch (ScriptException $e) {
      self::assertSame('threw in /m.js', $e->getMessage());
    }
    $runner->failWith = NULL;
    $guard->addModule('/m.js', 'export default () => "A";');
    $guard->useBase('abc', self::getDefinition('abc'));
    $guard->invoke('/m.js', '""');

    self::assertSame(
      ['useBase abc', 'getDefinition abc', 'addModule /m.js', 'invoke /m.js', 'invoke /m.js'],
      $runner->calls,
    );
  }

  /**
   * Tests a runner failure resets the runner, so the next render starts over.
   */
  public function testRunnerFailureResets(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);
    $guard->useBase('abc', self::getDefinition('abc'));
    $guard->addModule('/m.js', 'export default () => "A";');
    $runner->failWith = 'stopped in ';

    try {
      $guard->invoke('/m.js', '""');
      self::fail('The invoke succeeded.');
    }
    catch (\RuntimeException $e) {
      self::assertNotInstanceOf(ScriptException::class, $e);
    }
    $runner->failWith = NULL;
    $guard->useBase('abc', self::getDefinition('abc'));
    $guard->addModule('/m.js', 'export default () => "A";');

    self::assertSame(
      [
        'useBase abc',
        'getDefinition abc',
        'addModule /m.js',
        'invoke /m.js',
        'reset',
        'useBase abc',
        'addModule /m.js',
      ],
      $runner->calls,
    );
  }

  /**
   * Tests nothing reaches the runner from inside an invoke.
   *
   * A bound callback can render another island, as a fetch whose subrequest
   * builds a page with islands can. That render's calls are refused, and
   * the runner is left as it was for the outer one. Only the base in use is
   * still answered, since that doesn't reach the runner.
   */
  public function testCallsFromInsideAnInvokeAreRefused(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);
    $guard->useBase('abc', self::getDefinition('abc'));
    $guard->addModule('/m.js', 'export default () => "A";');
    $refused = [];
    $runner->duringInvoke = function () use ($guard, &$refused): void {
      self::assertTrue($guard->useBase('abc', self::getDefinition('abc')));
      $calls = [
        'addModule' => static fn() => $guard->addModule('/n.js', 'export default () => "B";'),
        'bindFunction' => static fn() => $guard->bindFunction('probe', static fn(): string => '', '() => ""', '(r) => r'),
        'invoke' => static fn() => $guard->invoke('/m.js', '""'),
        'useBase' => static fn() => $guard->useBase('def', self::getDefinition('def')),
        'buildPendingBase' => static fn() => $guard->buildPendingBase('def', self::getDefinition('def')),
      ];
      foreach ($calls as $name => $call) {
        try {
          $call();
        }
        catch (RunnerBusyException) {
          $refused[] = $name;
        }
      }
    };

    self::assertSame('invoked /m.js', $guard->invoke('/m.js', '""'));
    self::assertSame(['addModule', 'bindFunction', 'invoke', 'useBase', 'buildPendingBase'], $refused);

    // Once it returns, calls reach the runner again, and nothing was reset.
    $runner->duringInvoke = NULL;
    $guard->addModule('/n.js', 'export default () => "B";');
    $guard->invoke('/n.js', '""');
    self::assertSame(
      [
        'useBase abc',
        'getDefinition abc',
        'addModule /m.js',
        'invoke /m.js',
        'addModule /n.js',
        'invoke /n.js',
      ],
      $runner->calls,
    );
  }

  /**
   * Tests a reset asked for inside an invoke waits until it returns.
   *
   * Resetting then would drop the realm the outer island is rendering in.
   */
  public function testResetInsideAnInvokeWaitsForIt(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);
    $guard->addModule('/m.js', 'export default () => "A";');
    $runner->duringInvoke = static function () use ($guard, $runner): void {
      $guard->reset();
      self::assertSame(['addModule /m.js', 'invoke /m.js'], $runner->calls);
    };

    $guard->invoke('/m.js', '""');
    $runner->duringInvoke = NULL;
    $guard->addModule('/m.js', 'export default () => "A";');

    self::assertSame(['addModule /m.js', 'invoke /m.js', 'reset', 'addModule /m.js'], $runner->calls);
  }

  /**
   * Tests a base is kept under the id its definition comes with.
   *
   * The base can change after its id is read, and then comes with its own.
   */
  public function testBaseIsKeptUnderTheDefinitionsId(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);

    self::assertTrue($guard->useBase('abc', self::getDefinition('def')));
    self::assertTrue($guard->useBase('def', static fn() => self::fail('The base was asked for.')));
    self::assertTrue($guard->useBase('abc', self::getDefinition('abc')));

    self::assertSame(['useBase abc', 'getDefinition def', 'useBase abc', 'getDefinition abc'], $runner->calls);
    self::assertSame(['def', 'abc'], \array_keys($runner->bases));
  }

  /**
   * Tests a base a runner didn't keep is asked about again.
   */
  public function testBaseNotKeptIsAskedAboutAgain(): void {
    $runner = new RecordingRunner();
    $runner->keepsBase = FALSE;
    $guard = new RunnerGuard($runner);

    self::assertFalse($guard->useBase('abc', self::getDefinition('abc')));
    self::assertFalse($guard->useBase('abc', self::getDefinition('abc')));

    self::assertSame(['useBase abc', 'getDefinition abc', 'useBase abc', 'getDefinition abc'], $runner->calls);
  }

  /**
   * Tests a runner's empty list of unloadable modules reads as in use.
   */
  public function testNoUnloadableModulesReadsAsInUse(): void {
    $runner = new RecordingRunner();
    $runner->baseResult = [];
    $guard = new RunnerGuard($runner);

    self::assertTrue($guard->useBase('abc', self::getDefinition('abc')));
  }

  /**
   * Tests a failing getDefinition reaches the caller and keeps the runner.
   */
  public function testDefinitionFailureKeepsTheRunner(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);

    try {
      $guard->useBase('abc', static fn() => throw new \RuntimeException('No base.'));
      self::fail('The base was used.');
    }
    catch (\RuntimeException $e) {
      self::assertSame('No base.', $e->getMessage());
    }

    self::assertSame(['useBase abc'], $runner->calls);
  }

  /**
   * Tests a repeat registration is skipped, and a changed one isn't.
   *
   * Every island registers its whole graph, so this is what keeps a repeat
   * island from handing the runner it all again. A source edited between
   * islands must still reach it, and reset() starts over.
   */
  public function testRepeatRegistrationsAreSkipped(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);

    $island = static function (string $source) use ($guard): void {
      $guard->addModule('/lib/dep.js', $source);
      $guard->addModule('/lib/preact.js', 'export const h = 1;');
    };
    $first = [
      'addModule /lib/dep.js',
      'addModule /lib/preact.js',
    ];

    $island('export default 1;');
    $island('export default 1;');
    self::assertSame($first, $runner->calls);

    $island('export default 2;');
    self::assertSame([...$first, 'addModule /lib/dep.js'], $runner->calls);

    $guard->reset();
    $island('export default 2;');
    self::assertSame([...$first, 'addModule /lib/dep.js', 'reset', ...$first], $runner->calls);
  }

  /**
   * Tests the base in use is forwarded once, until reset().
   */
  public function testBaseInUseIsForwardedOnceUntilReset(): void {
    $runner = new RecordingRunner();
    $guard = new RunnerGuard($runner);

    $guard->useBase('abc', self::getDefinition('abc'));
    // Every render does this, but repeats don't reach the runner.
    $guard->useBase('abc', self::getDefinition('abc'));
    // A new id reaches the runner.
    $guard->useBase('def', self::getDefinition('def'));
    // After a reset, no base is in use until a render uses one.
    $guard->reset();
    $guard->useBase('def', self::getDefinition('def'));

    self::assertSame(
      [
        'useBase abc',
        'getDefinition abc',
        'useBase def',
        'getDefinition def',
        'reset',
        'useBase def',
      ],
      $runner->calls,
    );
  }

  /**
   * Tests unloadable preload modules are returned once per base in use.
   *
   * Every render asks, so a repeat must not reach the runner. A new base or
   * reset() can change the answer.
   */
  public function testUnloadableModulesAreReturnedOncePerBase(): void {
    $runner = new RecordingRunner();
    $runner->unloadable = ['/broken.js'];
    $guard = new RunnerGuard($runner);

    self::assertSame(['/broken.js'], $guard->useBase('abc', self::getDefinition('abc')));
    self::assertSame(['/broken.js'], $guard->useBase('abc', self::getDefinition('abc')));
    $runner->unloadable = [];
    self::assertSame(['/broken.js'], $guard->useBase('abc', self::getDefinition('abc')));
    self::assertTrue($guard->useBase('def', self::getDefinition('def')));
    $runner->unloadable = ['/other.js'];
    $guard->reset();
    self::assertSame(['/other.js'], $guard->useBase('def', self::getDefinition('def')));

    self::assertSame(
      [
        'useBase abc',
        'getDefinition abc',
        'useBase def',
        'getDefinition def',
        'reset',
        'useBase def',
      ],
      $runner->calls,
    );
  }

  /**
   * Returns a getDefinition callback for an empty base, or the one given.
   *
   * @param string $id
   *   The id it returns.
   * @param Base $base
   *   The base it returns.
   */
  private static function getDefinition(string $id, array $base = ['modules' => [], 'preload' => [], 'importMap' => []]): \Closure {
    return static fn() => [$id, $base];
  }

}
