<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner;

use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how the selector picks a runner, and passes calls to it.
 *
 * These check the calls that reach each runner rather than asking which one
 * was picked.
 *
 * @phpstan-import-type Base from \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
#[CoversClass(RunnerSelector::class)]
#[Group('canvas_ssr')]
final class RunnerSelectorTest extends UnitTestCase {

  /**
   * Tests the first available candidate takes the whole render.
   */
  public function testHighestPriorityAvailableCandidateIsSelected(): void {
    $unusable = new RecordingRunner(available: FALSE);
    $chosen = new RecordingRunner();
    $spare = new RecordingRunner();
    $selector = new RunnerSelector([$unusable, $chosen, $spare]);

    self::assertTrue($selector->isAvailable());
    $selector->addModule('/m.js', 'export default () => "A";');
    $selector->bindFunction('probe', static fn(): string => '', '() => ""', '(r) => r');
    self::assertSame('invoked /m.js', $selector->invoke('/m.js', '""'));

    self::assertSame(
      [
        'addModule /m.js',
        'bindFunction probe',
        'invoke /m.js',
      ],
      $chosen->calls,
    );
    self::assertSame([], $unusable->calls);
    self::assertSame([], $spare->calls);
  }

  /**
   * Tests the selector acts as UnavailableRunner when no runner is available.
   */
  public function testNoAvailableCandidateBehavesAsUnavailable(): void {
    $selector = new RunnerSelector([new RecordingRunner(available: FALSE)]);

    self::assertFalse($selector->isAvailable());
    // UnavailableRunner doesn't throw from reset(), which failure paths
    // call.
    $selector->reset();

    $this->expectException(\LogicException::class);
    $selector->addModule('/m.js', 'export default () => "A";');
  }

  /**
   * Tests the choice lasts for a render and is made again after reset().
   *
   * Switching mid-render would reach a runner without the render's modules.
   * Never switching would keep a site on a runner that stopped working.
   */
  public function testSelectionLastsUntilReset(): void {
    $first = new RecordingRunner();
    $second = new RecordingRunner();
    $selector = new RunnerSelector([$first, $second]);

    $selector->addModule('/m.js', 'export default () => "A";');
    // The first runner can't run anymore, but the render stays on it.
    $first->available = FALSE;
    $selector->invoke('/m.js', '""');
    self::assertSame(['addModule /m.js', 'invoke /m.js'], $first->calls);
    self::assertSame([], $second->calls);

    $selector->reset();
    // Nothing is selected now, so another reset reaches no runner.
    $selector->reset();
    $selector->addModule('/m.js', 'export default () => "A";');

    self::assertSame(['addModule /m.js', 'invoke /m.js', 'reset'], $first->calls);
    // Registered again, since the second runner starts empty.
    self::assertSame(['addModule /m.js'], $second->calls);
  }

  /**
   * Tests the limitations are the selected runner's, or none.
   */
  public function testLimitationsAreTheSelectedRunners(): void {
    $unusable = new RecordingRunner(available: FALSE);
    $unusable->limitations = ['Not this one.'];
    $chosen = new RecordingRunner();
    $chosen->limitations = ['This one.'];

    self::assertSame(['This one.'], (new RunnerSelector([$unusable, $chosen]))->limitations());
    self::assertSame([], (new RunnerSelector([$unusable]))->limitations());
  }

  /**
   * Tests a base goes to the selected runner only, as it is.
   */
  public function testBaseGoesToTheSelectedRunner(): void {
    $unusable = new RecordingRunner(available: FALSE);
    $chosen = new RecordingRunner();
    $selector = new RunnerSelector([$unusable, $chosen]);
    $base = [
      'modules' => ['/_canvas_ssr/component/main/card.js' => 'export default 1;'],
      'preload' => ['/_canvas_ssr/component/main/card.js'],
      'importMap' => [],
    ];

    self::assertTrue($selector->useBase('abc', self::getDefinition('abc', $base)));

    self::assertSame([], $unusable->calls);
    self::assertSame(['useBase abc', 'getDefinition abc'], $chosen->calls);
    self::assertSame($base, $chosen->bases['abc']);
  }

  /**
   * Tests no base is used, or its definition asked for, with no runner.
   */
  public function testNoRunnerTakesABase(): void {
    $selector = new RunnerSelector([new RecordingRunner(available: FALSE)]);

    self::assertFalse($selector->useBase('abc', static fn() => self::fail('The base was asked for.')));
  }

  /**
   * Tests a pending base is built by the selected runner only.
   *
   * Before a runner is selected, every candidate is asked whether one is
   * pending, since selecting costs more. After, only the selected one is.
   */
  public function testPendingBaseGoesToTheSelectedRunner(): void {
    $unusable = new RecordingRunner(available: FALSE);
    $chosen = new RecordingRunner();
    $failure = ['since' => 1, 'due' => 2, 'error' => 'It ran out of time.'];
    $chosen->pendingBase = $failure;
    $selector = new RunnerSelector([$unusable, $chosen]);

    self::assertTrue($selector->hasPendingBase());
    self::assertSame(['hasPendingBase'], $unusable->calls);
    self::assertSame($failure, $selector->buildPendingBase('abc', self::getDefinition('abc')));
    self::assertSame(['hasPendingBase', 'buildPendingBase abc'], $chosen->calls);

    $unusable->calls = [];
    $unusable->pendingBase = TRUE;
    $chosen->pendingBase = NULL;
    self::assertFalse($selector->hasPendingBase());
    self::assertSame([], $unusable->calls);
  }

  /**
   * Tests with no runner, nothing is built.
   *
   * That holds though a candidate says a base is pending.
   */
  public function testNoRunnerBuildsAPendingBase(): void {
    $unusable = new RecordingRunner(available: FALSE);
    $unusable->pendingBase = TRUE;
    $selector = new RunnerSelector([$unusable]);

    self::assertTrue($selector->hasPendingBase());
    self::assertNull($selector->buildPendingBase('abc', static fn() => self::fail('The base was asked for.')));
    self::assertSame(['hasPendingBase'], $unusable->calls);
  }

  /**
   * Tests the reason given is every candidate's, and only with no runner.
   */
  public function testUnavailableReasonIsEveryCandidatesWithNoRunner(): void {
    $first = new RecordingRunner(available: FALSE);
    $first->unavailableReason = 'The first is missing.';
    $silent = new RecordingRunner(available: FALSE);
    $second = new RecordingRunner(available: FALSE);
    $second->unavailableReason = 'The second is broken.';
    $selector = new RunnerSelector([$first, $silent, $second]);

    self::assertSame('The first is missing. The second is broken.', $selector->unavailableReason());

    $second->available = TRUE;
    self::assertNull($selector->unavailableReason());
    self::assertNull((new RunnerSelector([$silent]))->unavailableReason());
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
