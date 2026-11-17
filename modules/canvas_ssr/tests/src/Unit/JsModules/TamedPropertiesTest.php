<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas_ssr\JsModules\TamedProperties;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the tamed properties a site configures.
 */
#[CoversClass(TamedProperties::class)]
#[Group('canvas_ssr')]
final class TamedPropertiesTest extends UnitTestCase {

  /**
   * Tests the additions merge into the defaults.
   *
   * A site sets only the additions, so it keeps the defaults as they change.
   */
  public function testAdditionsMergeIntoTheDefaults(): void {
    $tamed = new TamedProperties(
      [
        'globals' => ['Object.prototype' => ['toString'], 'Date.prototype' => ['get*']],
        'modules' => ['preact' => ['Component.prototype' => ['render']]],
      ],
      [
        'globals' => ['Object.prototype' => ['valueOf', 'toString'], 'Map.prototype' => ['get']],
        'modules' => ['preact' => ['Component.prototype' => ['setState']], 'swr' => ['default' => ['name']]],
      ],
      new CollectingLogger(),
    );

    self::assertSame([
      'Object.prototype' => ['toString', 'valueOf'],
      'Date.prototype' => ['get*'],
      'Map.prototype' => ['get'],
    ], $tamed->globals());
    self::assertSame([
      'preact' => ['Component.prototype' => ['render', 'setState']],
      'swr' => ['default' => ['name']],
    ], $tamed->modules());
  }

  /**
   * Tests every form a path and a property can take.
   *
   * Services.yml writes % as %%, and the container leaves it so in keys.
   */
  public function testNotation(): void {
    $logger = new CollectingLogger();
    $tamed = new TamedProperties([
      'globals' => [
        '%%IteratorPrototype%%' => ['[Symbol.iterator]'],
        '%TypedArray%.prototype' => ['*'],
        'Date.prototype' => ['set*', 'toString'],
      ],
    ], [], $logger);

    self::assertSame([
      '%IteratorPrototype%' => ['[Symbol.iterator]'],
      '%TypedArray%.prototype' => ['*'],
      'Date.prototype' => ['set*', 'toString'],
    ], $tamed->globals());
    self::assertSame([], $logger->at('warning'));
  }

  /**
   * Tests an entry not in the format is skipped, with a warning.
   */
  public function testBadEntriesAreSkipped(): void {
    $logger = new CollectingLogger();
    $tamed = new TamedProperties([], [
      'globals' => [
        'Object.prototype' => ['toString', 'to-string', 3, '@@iterator'],
        'not a path' => ['x'],
        'Array.prototype' => 'push',
      ],
      'modules' => [
        './relative.js' => ['default' => ['x']],
        'lib' => ['default.prototype' => ['render'], 'default..x' => ['y']],
      ],
    ], $logger);

    self::assertSame(['Object.prototype' => ['toString']], $tamed->globals());
    self::assertSame(['lib' => ['default.prototype' => ['render']]], $tamed->modules());
    self::assertSame([
      'The tamed property entry at globals.Object.prototype is skipped, since "to-string" is not a property.',
      'The tamed property entry at globals.Object.prototype is skipped, since a property is not a string.',
      'The tamed property entry at globals.Object.prototype is skipped, since "@@iterator" is not a property.',
      'The tamed property entry at globals.not a path is skipped, since it is not a path.',
      'The tamed property entry at globals.Array.prototype is skipped, since it is not a list of properties.',
      'The tamed property entry at modules../relative.js is skipped, since a relative specifier names no module from the page.',
      'The tamed property entry at modules.lib.default..x is skipped, since it is not a path.',
    ], $logger->at('warning'));
  }

}
