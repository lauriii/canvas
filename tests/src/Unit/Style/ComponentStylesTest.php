<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Style;

use Drupal\canvas\Style\ComponentStyles;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the parts of component styles that do not need core's Style API.
 */
#[CoversClass(ComponentStyles::class)]
#[Group('canvas')]
final class ComponentStylesTest extends UnitTestCase {

  /**
   * @return array<string, array{mixed, list<string>}>
   */
  public static function providerValidateShape(): array {
    return [
      'empty' => [[], []],
      'null' => [NULL, []],
      'valid' => [['spacing' => 'large', 'background_color' => 'primary'], []],
      'option IDs may contain spaces and dashes' => [['size' => 'Extra large-2'], []],
      'list instead of map' => [['a', 'b'], ['Styles must be a map of style ID to option ID.']],
      'not an array' => ['spacing', ['Styles must be a map of style ID to option ID.']],
      'invalid style ID' => [['Background Color' => 'x'], ['"Background Color" is not a valid style ID. Style IDs contain only lowercase letters, numbers, and underscores, optionally after a part ID and a dot.']],
      'empty option ID' => [['spacing' => ''], ['The option for "spacing" is not a valid option ID.']],
      'option ID 0' => [['spacing' => '0'], ['The option for "spacing" is not a valid option ID.']],
      'markup is never accepted' => [['spacing' => '"><script>'], ['The option for "spacing" is not a valid option ID.']],
      'per-breakpoint values are reserved' => [['spacing' => ['base' => 'large', 'md' => 'small']], ['Per-breakpoint values are not supported yet. Set "spacing" to a single option ID.']],
    ];
  }

  #[DataProvider('providerValidateShape')]
  public function testValidateShape(mixed $styles, array $expected_messages): void {
    self::assertSame($expected_messages, \array_column(ComponentStyles::validateShape($styles), 'message'));
  }

  public function testDefaultSetting(): void {
    self::assertSame([], ComponentStyles::defaultSetting());
  }

  public function testParseInlineStyle(): void {
    self::assertSame(
      ['--gap' => 'var(--space-7)', 'marginTop' => '1rem', 'backgroundColor' => 'red'],
      ComponentStyles::parseInlineStyle('--gap: var(--space-7); margin-top: 1rem;; BACKGROUND-COLOR:red; broken'),
    );
  }

  /**
   * Resolves the fixtures shared with drupal-canvas' resolveStyles().
   *
   * @see packages/drupal-canvas/src/styles.test.tsx
   */
  public function testResolveMatchesSharedFixtures(): void {
    $fixtures = json_decode((string) file_get_contents(\dirname(__DIR__, 4) . '/packages/drupal-canvas/src/styles.fixtures.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $definitions = $fixtures['definitions'];
    // Definitions are listed in join order, as ComponentStyles::definitions()
    // returns them.
    uasort($definitions, static fn (array $a, array $b): int => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
    foreach ($fixtures['cases'] as $case) {
      $resolved = ComponentStyles::resolveWithDefinitions($case['selection'], $definitions);
      self::assertSame($case['expected']['className'], $resolved['className'], $case['name']);
      self::assertSame($case['expected']['style'], $resolved['style'], $case['name']);
    }
    self::assertSame(['my_theme/backgrounds'], ComponentStyles::resolveWithDefinitions(['background_color' => 'primary'], $definitions)['libraries']);
  }

}
