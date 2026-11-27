<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Style;

use Drupal\canvas\Style\ComponentStyles;
use Drupal\canvas\Style\StyleValues;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests custom values and token references in style selections.
 */
#[CoversClass(StyleValues::class)]
#[CoversClass(ComponentStyles::class)]
#[Group('canvas')]
final class StyleValuesTest extends UnitTestCase {

  private const array GAP = [
    'type' => 'length',
    'units' => ['px', 'rem'],
    'min' => 0.0,
    'max' => 256.0,
    'step' => 4.0,
    'value' => 'gap-(--gap)',
    'property' => '--gap',
  ];

  /**
   * The shared test cases, also run by the editor and drupal-canvas tests.
   *
   * @return array<string, mixed>
   */
  private static function cases(): array {
    return json_decode((string) file_get_contents(\dirname(__DIR__, 4) . '/packages/drupal-canvas/src/style-values.fixtures.json'), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  public function testNormalizeSharedCases(): void {
    foreach (self::cases()['specs'] as $name => ['authored' => $authored, 'normalized' => $normalized]) {
      // JSON has no floats without a fraction; compare loosely on numbers.
      self::assertEquals($normalized, StyleValues::normalizeCustom($authored), $name);
    }
  }

  public function testParseSharedCases(): void {
    $cases = self::cases();
    foreach ($cases['parse'] as ['spec' => $spec, 'value' => $value, 'css' => $css]) {
      $normalized = StyleValues::normalizeCustom($cases['specs'][$spec]['authored']);
      self::assertNotNull($normalized);
      self::assertSame($css, StyleValues::parseCustom($value, $normalized)['css'] ?? NULL, "$spec $value");
    }
  }

  public function testUnusableDeclarations(): void {
    // Not a custom property.
    self::assertNull(StyleValues::normalizeCustom(['units' => ['px' => []], 'value' => 'gap-(--gap)', 'property' => 'gap']));
    // No class.
    self::assertNull(StyleValues::normalizeCustom(['units' => ['px' => []], 'value' => '', 'property' => '--x']));
    // Units, none valid.
    self::assertNull(StyleValues::normalizeCustom(['units' => ['PX' => []], 'value' => 'x', 'property' => '--x']));
    self::assertNull(StyleValues::normalizeCustom('yes'));
  }

  public function testShapes(): void {
    self::assertTrue(StyleValues::hasValidShape('large'));
    self::assertTrue(StyleValues::hasValidShape('=24px'));
    self::assertTrue(StyleValues::hasValidShape('canvas-color:5b7e2a4c-1d3f-4e8a-9b2c-0f1e2d3c4b5a'));
    self::assertFalse(StyleValues::hasValidShape('=24px;color:red'));
    self::assertFalse(StyleValues::hasValidShape('canvas-color:../../etc'));
    self::assertSame([], ComponentStyles::validateShape(['gap' => '=24px']));
  }

  public function testResolveCustomAndTokens(): void {
    $reference = 'canvas-color:5b7e2a4c-1d3f-4e8a-9b2c-0f1e2d3c4b5a';
    $definitions = [
      'background' => self::definition('background', [
        $reference => [
          'id' => $reference,
          'kind' => 'token',
          'value' => '',
          'attribute' => 'class',
          'swatch' => '#1b6f8a',
          'cssVariable' => '--brand-lagoon',
        ],
      ], tokens: ['type' => 'color', 'folders' => [], 'value' => 'bg-(--style-bg)', 'property' => '--style-bg']),
      'gap' => self::definition('gap', [
        'tight' => ['id' => 'tight', 'kind' => 'option', 'value' => 'gap-2', 'attribute' => 'class', 'swatch' => NULL, 'cssVariable' => NULL],
      ], custom: self::GAP),
    ];
    $resolved = ComponentStyles::resolveWithDefinitions(['background' => $reference, 'gap' => '=24px'], $definitions);
    self::assertSame('bg-(--style-bg) gap-(--gap)', $resolved['className']);
    self::assertSame(['--style-bg' => 'var(--brand-lagoon, #1b6f8a)', '--gap' => '24px'], $resolved['style']);
    self::assertSame(['attribute' => 'style', 'value' => '--style-bg: var(--brand-lagoon, #1b6f8a); --gap: 24px'], \end($resolved['attributes']));

    // An invalid custom value renders nothing.
    self::assertNull(ComponentStyles::resolveWithDefinitions(['gap' => '=999px'], $definitions)['className']);
  }

  /**
   * @return array<string, mixed>
   */
  private static function definition(string $id, array $options, ?array $tokens = NULL, ?array $custom = NULL): array {
    return [
      'id' => $id,
      'library' => '',
      'options' => $options,
      'tokens' => $tokens,
      'custom' => $custom,
    ];
  }

}
