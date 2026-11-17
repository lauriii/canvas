<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas_ssr\JsModules\ModuleEvaluationHooks;
use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Drupal\canvas_ssr\JsModules\TamedProperties;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the lines appended to every module to freeze its exports.
 */
#[CoversClass(ModuleEvaluationHooks::class)]
#[Group('canvas_ssr')]
final class ModuleEvaluationHooksTest extends UnitTestCase {

  /**
   * What a provided module's first line starts with.
   */
  private const string FIRST_LINE = 'const __canvasSsrEvaluation = __canvasSsrBeginEvaluation();';

  /**
   * What any other module's first line starts with.
   */
  private const string COMPONENT_FIRST_LINE = 'const __canvasSsrEvaluation = __canvasSsrBeginEvaluation(true);';

  /**
   * The SSR import map the tests resolve through.
   */
  private const array IMPORT_MAP = [
    'imports' => [
      'preact' => '/dist/preact.module.js?v=1',
      'lib' => '/dist/lib.js',
      'alias' => '/dist/lib.js?v=2',
      '@/assets/' => '/files/assets/',
    ],
  ];

  /**
   * Tests what each kind of module gets.
   */
  public function testEachModuleGetsItsLines(): void {
    $logger = new CollectingLogger();
    $tamed = new TamedProperties([], [
      'modules' => [
        'lib' => ['default.prototype' => ['render']],
        'alias' => ['default.prototype' => ['setState']],
        'missing' => ['default' => ['x']],
      ],
    ], $logger);
    $sources = [
      SsrOnlyModuleProvider::REALM_SETUP_PATH => 'setup',
      '/dist/preact.module.js' => 'preact',
      '/dist/lib.js' => 'lib',
      '/dist/chunk.js' => 'chunk',
      '/files/assets/logo.js' => 'asset',
    ];

    $applied = (new ModuleEvaluationHooks($tamed, $logger))->apply($sources, self::IMPORT_MAP, provided: TRUE);

    self::assertSame('setup', $applied[SsrOnlyModuleProvider::REALM_SETUP_PATH], 'The realm setup module is unchanged.');
    self::assertSame(self::entry('preact', './preact.module.js', '{}', '["options"]'), $applied['/dist/preact.module.js']);
    // Both specifiers name it, so their entries merge.
    self::assertSame(self::entry('lib', './lib.js', '{"default.prototype":["render","setState"]}', 'null'), $applied['/dist/lib.js']);
    self::assertSame(self::entry('asset', './logo.js', '{}', 'null'), $applied['/files/assets/logo.js']);
    self::assertSame(
      self::FIRST_LINE . "chunk\nimport { beginEvaluation as __canvasSsrBeginEvaluation, endEvaluation as __canvasSsrEndEvaluation, holdExports as __canvasSsrHoldExports } from \"@/canvas_ssr/realm-setup\";\nimport * as __canvasSsrExports from \"./chunk.js\";\n__canvasSsrEndEvaluation(__canvasSsrEvaluation);\n__canvasSsrHoldExports(__canvasSsrExports);\n",
      $applied['/dist/chunk.js'],
    );
    self::assertSame(['The tamed property entries for module missing are skipped, since the SSR import map has no such module.'], $logger->at('notice'));
  }

  /**
   * Tests a module that isn't provided is never a chunk.
   *
   * Components and main modules aren't in the import map, but other
   * modules import them. Unlike a library, one may not change the globals
   * as it evaluates.
   */
  public function testComponentsAreNeverChunks(): void {
    $applied = (new ModuleEvaluationHooks(new TamedProperties([], [], new CollectingLogger()), new CollectingLogger()))
      ->apply(['/components/card.js' => 'card'], self::IMPORT_MAP, component: TRUE);

    self::assertSame(self::entry('card', './card.js', '{}', 'null', self::COMPONENT_FIRST_LINE), $applied['/components/card.js']);
  }

  /**
   * Tests the first line's statement goes after a hashbang.
   */
  public function testHashbangStaysFirst(): void {
    $applied = (new ModuleEvaluationHooks(new TamedProperties([], [], new CollectingLogger()), new CollectingLogger()))
      ->apply(['/components/cli.js' => "#!/usr/bin/env node\ncli"], self::IMPORT_MAP, component: TRUE);

    self::assertStringStartsWith("#!/usr/bin/env node\n" . self::COMPONENT_FIRST_LINE . 'cli', $applied['/components/cli.js']);
  }

  /**
   * Returns a module that freezes its exports.
   */
  private static function entry(string $source, string $self, string $tamed, string $defer, string $first_line = self::FIRST_LINE): string {
    // A component's module also gets what tracks its import() calls.
    $track = $first_line === self::COMPONENT_FIRST_LINE ? ', trackImport as __canvasSsrTrackImport' : '';

    return $first_line . "$source\nimport { beginEvaluation as __canvasSsrBeginEvaluation, endEvaluation as __canvasSsrEndEvaluation, freezeExports as __canvasSsrFreezeExports$track } from \"@/canvas_ssr/realm-setup\";\nimport * as __canvasSsrExports from \"$self\";\n__canvasSsrEndEvaluation(__canvasSsrEvaluation);\n__canvasSsrFreezeExports(__canvasSsrExports, $tamed, $defer);\n";
  }

}
