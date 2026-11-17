<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsParser;

// cspell:ignore lient ünïcødé

use Drupal\canvas_ssr\JsParser\JsModuleParser;
use Drupal\canvas_ssr\JsParser\ParsedModule;
use Drupal\canvas_ssr\JsParser\ParsedModuleCache;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests parsing JS module source into the facts SSR reads from it.
 */
#[CoversClass(JsModuleParser::class)]
#[CoversClass(ParsedModule::class)]
#[Group('canvas_ssr')]
final class JsModuleParserTest extends UnitTestCase {

  /**
   * The logger passed to the parser.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject&\Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The parser under test.
   */
  private JsModuleParser $parser;

  /**
   * Time limits for a test that isn't about time.
   *
   * How long a parse takes depends on how busy the host is, and a CI runner
   * can take several times longer than the default medium limit for a parse
   * that runs into the memory limit.
   */
  private const array UNHURRIED = ['medium' => 60_000, 'long' => 120_000];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    // A cache that keeps nothing, so each test parses afresh.
    $cache = new ParsedModuleCache(new NullBackend('test'), new NullLockBackend());
    $this->parser = new JsModuleParser($this->logger, $cache, self::UNHURRIED);
  }

  /**
   * Reports supported import/export specifiers in source order.
   *
   * @param string $source
   *   Module source.
   * @param array<int, string> $expected
   *   Expected specifiers.
   */
  #[DataProvider('importProvider')]
  public function testGetImports(string $source, array $expected): void {
    $module = $this->parser->parse($source, 'test');
    $this->assertNotNull($module);
    $this->assertSame($expected, \array_column($module->getImports(), 0));
  }

  /**
   * Data provider for testGetImports().
   *
   * @return array<string, array{string, array<int, string>}>
   *   Cases keyed by description: [source, expected specifiers].
   */
  public static function importProvider(): array {
    return [
      'default import' => [
        "import Foo from './foo';",
        ['./foo'],
      ],
      'named imports collapse to one specifier' => [
        "import { a, b } from 'pkg';",
        ['pkg'],
      ],
      'side-effect import (no bindings)' => [
        "import './setup.js';",
        ['./setup.js'],
      ],
      'dynamic import expression' => [
        "const mod = import('./lazy.js');",
        ['./lazy.js'],
      ],
      'dynamic import of a template with nothing to fill in' => [
        'const mod = import(`./lazy.js`);',
        ['./lazy.js'],
      ],
      'dynamic import of a template with something to fill in' => [
        'const mod = import(`./${name}.js`);',
        [],
      ],
      'export named from' => [
        "export { a, b } from './reexport.js';",
        ['./reexport.js'],
      ],
      'export all from' => [
        "export * from './all.js';",
        ['./all.js'],
      ],
      'export without a source is not a specifier' => [
        "const a = 1;\nexport { a };",
        [],
      ],
      'local export declaration is not a specifier' => [
        "export const x = 1;\nexport default function () {}",
        [],
      ],
      'no imports' => [
        "export default function () { return null; }",
        [],
      ],
      'all forms together, in source order' => [
        <<<'JS'
        import Foo from 'a';
        export { z } from 'b';
        const l = import('c');
        export * from 'd';
        JS,
        ['a', 'b', 'c', 'd'],
      ],
      'jsx syntax with imports' => [
        <<<'JS'
        import { jsx as _jsx } from "react/jsx-runtime";
        import Button from "@/components/button";
        export default function Component() {
          return <Button><span>Hi</span></Button>;
        }
        JS,
        ['react/jsx-runtime', '@/components/button'],
      ],
      'minified, with no spaces' => [
        'import{a}from"x"',
        ['x'],
      ],
      // From tailwind-merge's bundle. A "from" key isn't an import.
      'a look-alike in an object literal' => [
        'const c={"gradient-from":[{from:d()}]};',
        [],
      ],
      'what only looks like an import in strings, comments and JSX' => [
        "const s = 'import \"a\"'; // from \"b\"\n/* import('c') */ const t = <p>import \"d\"</p>;",
        [],
      ],
      'import.meta and a method named import' => [
        'const u = import.meta.resolve("a"); obj.import("b");',
        [],
      ],
      'escapes in the literal are decoded' => [
        "import 'it\\'s'; import \"\\u0040/a.js\";",
        ["it's", '@/a.js'],
      ],
      'quotes and backslashes, escaped or not' => [
        "import \"a\\\"b\"; import 'c\\\"d'; import 'e\"f'; import \"g\\\\h\";",
        ['a"b', 'c"d', 'e"f', 'g\\h'],
      ],
    ];
  }

  /**
   * Tests imports come in source order wherever they're nested.
   *
   * Rewriting copies the source between imports in this order, so it
   * mustn't change with how Peast walks the tree.
   *
   * @param string $source
   *   Module source, importing "a" before "b".
   */
  #[DataProvider('nestingProvider')]
  public function testImportsAreInSourceOrder(string $source): void {
    $module = $this->parser->parse($source, 'test');
    $this->assertNotNull($module);
    $this->assertSame(['a', 'b'], \array_column($module->getImports(), 0));
  }

  /**
   * Data provider for testImportsAreInSourceOrder().
   *
   * @return array<string, array{string}>
   *   Cases keyed by description: [source].
   */
  public static function nestingProvider(): array {
    return [
      'template literal' => ['const t = `${import("a")}x${import("b")}`;'],
      'tagged template' => ['import("a").then(f)`${import("b")}`;'],
      'call arguments' => ['f(import("a"), import("b"));'],
      'conditional' => ['x ? import("a") : import("b");'],
      'computed key and value' => ['const o = {[import("a")]: import("b")};'],
      'default parameter and body' => ['function f(a = import("a")) { return import("b"); }'],
      'class heritage and field' => ['class C extends (import("a"), B) { m = import("b"); }'],
      'do-while body and test' => ['do { import("a"); } while (import("b"));'],
      'destructuring default and value' => ['({ a = import("a") } = import("b"));'],
      'export from, then a declaration' => ['export { x } from "a"; export const y = import("b");'],
      'jsx attribute and child' => ['const j = <p a={import("a")}>{import("b")}</p>;'],
      'import options' => ['import("a", { with: { type: import("b") } });'],
    ];
  }

  /**
   * Tests each import's range covers its literal, quotes included, in bytes.
   *
   * ComponentModules replaces exactly that range.
   *
   * @param string $source
   *   Module source.
   * @param list<array{string, string}> $expected
   *   Each import's specifier and the text its range covers.
   */
  #[DataProvider('rangeProvider')]
  public function testImportRangesCoverTheQuotedLiteral(string $source, array $expected): void {
    $module = $this->parser->parse($source, 'test');
    $this->assertNotNull($module);
    $this->assertSame($expected, \array_map(
      static fn(array $range): array => [$range[0], \substr($source, $range[1], $range[2] - $range[1])],
      $module->getImports(),
    ));
  }

  /**
   * Data provider for testImportRangesCoverTheQuotedLiteral().
   *
   * @return array<string, array{string, list<array{string, string}>}>
   *   Cases keyed by description: [source, expected specifiers and text].
   */
  public static function rangeProvider(): array {
    return [
      'every form, a repeat included' => [
        "import a from \"x\";\nexport * from './y.js';\nimport('./y.js');",
        [['x', '"x"'], ['./y.js', "'./y.js'"], ['./y.js', "'./y.js'"]],
      ],
      'after multibyte text' => [
        "// ünïcødé 😀\nimport a from \"x\"; import 'ü.js';",
        [['x', '"x"'], ['ü.js', "'ü.js'"]],
      ],
      // Peast drops a byte order mark before counting.
      'after a byte order mark' => [
        "\u{FEFF}import a from \"x\";",
        [['x', '"x"']],
      ],
      'escapes stay as written' => [
        "import \"\\u0040/a.js\";",
        [['@/a.js', '"\\u0040/a.js"']],
      ],
    ];
  }

  /**
   * Tests each import() call's range covers the call, in bytes.
   *
   * ComponentModules wraps exactly that range.
   *
   * @param string $source
   *   Module source.
   * @param list<string> $expected
   *   The text each call's range covers, in source order.
   */
  #[DataProvider('dynamicImportProvider')]
  public function testDynamicImportRangesCoverTheCall(string $source, array $expected): void {
    $module = $this->parser->parse($source, 'test');
    $this->assertNotNull($module);
    $this->assertSame($expected, \array_map(
      static fn(array $range): string => \substr($source, $range[0], $range[1] - $range[0]),
      $module->getDynamicImports(),
    ));
  }

  /**
   * Data provider for testDynamicImportRangesCoverTheCall().
   *
   * @return array<string, array{string, list<string>}>
   *   Cases keyed by description: [source, expected text].
   */
  public static function dynamicImportProvider(): array {
    return [
      'none' => ['import a from "x"; import.meta.url;', []],
      'a string, an expression and options' => [
        'import("a").then(f); await import(`./${name}.js`); import(b, { with: { type: "json" } });',
        ['import("a")', 'import(`./${name}.js`)', 'import(b, { with: { type: "json" } })'],
      ],
      'one inside another' => [
        'import(await import("a"));',
        ['import(await import("a"))', 'import("a")'],
      ],
      'after multibyte text' => [
        "// ünïcødé 😀\nimport('ü.js'); import(x);",
        ["import('ü.js')", 'import(x)'],
      ],
      'spaced out' => ["import (\n  'a'\n)", ["import (\n  'a'\n)"]],
    ];
  }

  /**
   * Reports a directive only where a directive can appear.
   *
   * @param string $source
   *   Module source to inspect.
   * @param bool $expected
   *   Whether "use client" is in the module's directive prologue.
   */
  #[DataProvider('hasDirectiveProvider')]
  public function testHasDirective(string $source, bool $expected): void {
    $module = $this->parser->parse($source, 'test');
    $this->assertNotNull($module);
    $this->assertSame($expected, $module->hasDirective('use client'));
  }

  /**
   * Data provider for testHasDirective().
   *
   * @return array<string, array{string, bool}>
   *   Cases keyed by description: [source, expected].
   */
  public static function hasDirectiveProvider(): array {
    return [
      'first statement, double quotes' => [
        "\"use client\";\nexport default () => null;",
        TRUE,
      ],
      'single quotes and no semicolon' => [
        "'use client'\nexport default () => null;",
        TRUE,
      ],
      'behind a BOM and comments' => [
        "\u{FEFF}/* banner */\n// note\n'use client'\nexport const a = 1;",
        TRUE,
      ],
      'later in the prologue, after use strict' => [
        "'use strict';\n'use client';\nexport default () => null;",
        TRUE,
      ],
      'alone in the module' => [
        "'use client'",
        TRUE,
      ],
      'after a real statement is not a directive' => [
        "const x = 1;\n'use client';\nexport default () => x;",
        FALSE,
      ],
      'after an import is not a directive' => [
        "import { h } from 'preact';\n'use client';\nexport default () => null;",
        FALSE,
      ],
      'a string that merely equals it' => [
        "const x = 'use client';\nexport default () => x;",
        FALSE,
      ],
      'mentioned in a comment' => [
        "// import Heading from '@/components/x'; 'use client'\nexport default () => null;",
        FALSE,
      ],
      'escaped literal is an expression, not a directive' => [
        "'use \\u0063lient';\nexport default () => null;",
        FALSE,
      ],
      'no directive at all' => [
        "export default () => null;",
        FALSE,
      ],
    ];
  }

  /**
   * Returns NULL and logs when the source cannot be parsed.
   *
   * NULL, not an empty module, so the caller knows to fall back.
   */
  public function testReturnsNullAndLogsOnParseError(): void {
    $this->logger->expects($this->once())->method('debug');
    $this->assertNull($this->parser->parse('import { from "broken', 'test'));
    // It fails the same way every time.
    $this->assertFalse($this->parser->gaveUpForNow('import { from "broken'));
  }

  /**
   * Parses a given source once, however many times it is asked for.
   */
  public function testParsesEachSourceOnce(): void {
    // Each failed parse logs, so a second log means a second parse. The
    // cached failure is NULL, so the cache check must handle that.
    $this->logger->expects($this->once())->method('debug');
    $source = 'import { from "broken';
    $this->assertNull($this->parser->parse($source, 'test'));
    $this->assertNull($this->parser->parse($source, 'test'));
    $this->assertNull($this->parser->parse($source, 'test'));
  }

  /**
   * Returns the same module object for the same source.
   */
  public function testReturnsSameModuleForSameSource(): void {
    $source = "import Foo from './foo';";
    $this->assertSame($this->parser->parse($source, 'test'), $this->parser->parse($source, 'test'));
  }

  /**
   * Finds results, failures included, in later requests.
   */
  public function testFindsResultsInTheCache(): void {
    $time = $this->createMock(TimeInterface::class);
    $backend = new MemoryBackend($time);
    $source = "import Foo from './foo';\nfoo(1, 2);\n";
    $broken = 'import { from "broken';
    // Only the first request parses the broken source.
    $this->logger->expects($this->once())->method('debug');

    $cache = new ParsedModuleCache($backend, new NullLockBackend());
    $module = (new JsModuleParser($this->logger, $cache))->parse($source, 'test');
    $this->assertNotNull($module);
    $this->assertNull((new JsModuleParser($this->logger, $cache))->parse($broken, 'test'));
    $cache->destruct();

    $next = new JsModuleParser($this->logger, new ParsedModuleCache($backend, new NullLockBackend()));
    $this->assertEquals($module, $next->parse($source, 'test'));
    $this->assertNull($next->parse($broken, 'test'));
  }

  /**
   * Gives up on deep nesting before it can run PHP out of memory.
   *
   * Each shape recurses in Peast in its own way, and each would take
   * hundreds of MiB to parse.
   *
   * @param string $expression
   *   A deeply nested expression.
   */
  #[DataProvider('deepNestingProvider')]
  public function testGivesUpOnDeepNesting(string $expression): void {
    $this->logger->expects($this->once())->method('warning')->with(
      $this->stringContains('MiB'),
      ['component' => 'deep', 'limit' => 32],
    );
    $source = "export default function Deep() { return $expression; }";
    $before = \memory_get_usage();
    \memory_reset_peak_usage();

    $this->assertNull($this->parser->parse($source, 'deep'));
    $this->assertLessThan(2 * JsModuleParser::MEMORY_LIMIT, \memory_get_peak_usage() - $before);
  }

  /**
   * Data provider for testGivesUpOnDeepNesting().
   *
   * @return array<string, array{string}>
   *   Expressions, keyed by what nests.
   */
  public static function deepNestingProvider(): array {
    return [
      'brackets' => [\str_repeat('[', 20000) . \str_repeat(']', 20000)],
      'parentheses' => [\str_repeat('(', 20000) . '1' . \str_repeat(')', 20000)],
      'unary operators' => [\str_repeat('!', 100000) . '1'],
      'arrow functions' => [\str_repeat('a=>', 50000) . '1'],
      'assignments' => [\str_repeat('a=', 200000) . '1'],
      'JSX elements' => [\str_repeat('<a>', 50000) . \str_repeat('</a>', 50000)],
    ];
  }

  /**
   * Remembers a source that went over the memory limit, in later requests.
   *
   * How much memory a parse takes depends only on the source.
   */
  public function testRemembersASourceOverTheMemoryLimit(): void {
    $backend = new MemoryBackend($this->createMock(TimeInterface::class));
    $source = 'export default ' . \str_repeat('[', 20000) . \str_repeat(']', 20000) . ';';
    $this->logger->expects($this->once())->method('warning');

    $cache = new ParsedModuleCache($backend, new NullLockBackend());
    $parser = new JsModuleParser($this->logger, $cache, self::UNHURRIED);
    $this->assertNull($parser->parse($source, 'deep'));
    $this->assertFalse($parser->gaveUpForNow($source));
    $cache->destruct();

    $next = new JsModuleParser($this->logger, new ParsedModuleCache($backend, new NullLockBackend()), self::UNHURRIED);
    $this->assertNull($next->parse($source, 'deep'));
  }

  /**
   * Gives up on a parse that would leave PHP too little memory.
   *
   * It gives up for this request only.
   *
   * That depends on what else the request did, so the next one tries again.
   */
  public function testGivesUpOnAParseWithoutHeadroomForTheRequest(): void {
    $backend = new MemoryBackend($this->createMock(TimeInterface::class));
    // About 11 MiB to parse, well under the memory limit.
    $source = 'export default ' . \str_repeat('[', 1000) . \str_repeat(']', 1000) . ';';
    $this->logger->expects($this->once())->method('warning')->with(
      $this->stringContains('memory_limit'),
      ['component' => 'nested'],
    );

    $cache = new ParsedModuleCache($backend, new NullLockBackend());
    $previous = (string) \ini_get('memory_limit');
    self::assertNotFalse(\ini_set('memory_limit', (string) (\memory_get_usage(TRUE) + JsModuleParser::MEMORY_MARGIN + 4 * 1024 * 1024)));
    $parser = new JsModuleParser($this->logger, $cache, self::UNHURRIED);
    try {
      $this->assertNull($parser->parse($source, 'nested'));
    }
    finally {
      \ini_set('memory_limit', $previous);
    }
    $this->assertTrue($parser->gaveUpForNow($source));
    $cache->destruct();

    $next = new JsModuleParser($this->logger, new ParsedModuleCache($backend, new NullLockBackend()), self::UNHURRIED);
    $this->assertNotNull($next->parse($source, 'nested'));
  }

  /**
   * Gives up on a parse past the medium time limit, for this request only.
   *
   * How long a parse takes depends on how busy the host is, so the next
   * request tries again.
   */
  public function testGivesUpOnASlowParseForTheRequest(): void {
    $backend = new MemoryBackend($this->createMock(TimeInterface::class));
    // Flat, so it's well under the memory limit, but long enough to take
    // more than two milliseconds.
    $source = 'export default [' . \str_repeat('1,', 30000) . '];';
    $this->logger->expects($this->exactly(2))->method('warning')->with(
      $this->stringContains('ms'),
      ['component' => 'slow', 'limit' => 2],
    );

    $cache = new ParsedModuleCache($backend, new NullLockBackend());
    $parser = new JsModuleParser($this->logger, $cache, ['short' => 1, 'medium' => 2]);
    $this->assertNull($parser->parse($source, 'slow'));
    $this->assertNull($parser->parse($source, 'slow'));
    $this->assertTrue($parser->gaveUpForNow($source));
    $cache->destruct();

    $next = new JsModuleParser($this->logger, new ParsedModuleCache($backend, new NullLockBackend()), ['short' => 1, 'medium' => 2]);
    $this->assertNull($next->parse($source, 'slow'));
    // With time enough, it parses.
    $this->assertNotNull((new JsModuleParser($this->logger, new ParsedModuleCache($backend, new NullLockBackend()), self::UNHURRIED))->parse($source, 'slow'));
  }

}
