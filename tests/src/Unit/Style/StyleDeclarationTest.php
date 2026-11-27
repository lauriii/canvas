<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Style;

use Drupal\canvas\Style\StyleDeclaration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how components declare styles per element, and moving values.
 */
#[CoversClass(StyleDeclaration::class)]
#[Group('canvas')]
final class StyleDeclarationTest extends UnitTestCase {

  public function testAuthoredShapes(): void {
    // A list, a map with limits, and both mixed: one stored shape.
    $stored = StyleDeclaration::fromAuthored([
      'styles' => ['palette', ['fill' => ['folders' => ['Brand']]]],
      'style_parts' => [
        'heading' => [
          'label' => 'Heading',
          'styles' => ['text_size' => ['options' => ['l', 'xl']], 'face' => NULL],
        ],
      ],
    ]);
    self::assertSame([
      'styles' => ['palette', 'fill'],
      'style_limits' => ['fill' => ['folders' => ['Brand']]],
      'style_parts' => [
        'heading' => [
          'label' => 'Heading',
          'styles' => ['text_size', 'face'],
          'limits' => ['text_size' => ['options' => ['l', 'xl']]],
        ],
      ],
    ], $stored);
    // Plain lists store exactly as before, so version hashes don't change.
    self::assertSame(['styles' => ['palette', 'fill']], StyleDeclaration::fromAuthored(['styles' => ['palette', 'fill']]));
    self::assertSame(['styles' => ['*']], StyleDeclaration::fromAuthored(['styles' => '*']));
    self::assertSame([], StyleDeclaration::fromAuthored([]));
  }

  public function testMigrateSelection(): void {
    $v1 = ['styles' => ['palette', 'display_size']];
    $v2 = [
      'styles' => ['palette'],
      'style_parts' => ['heading' => ['label' => 'Heading', 'styles' => ['display_size']]],
    ];
    // Moved to the part that now accepts it, and back.
    self::assertSame(['palette' => 'plum', 'heading.display_size' => 'l'], StyleDeclaration::migrateSelection(['palette' => 'plum', 'display_size' => 'l'], $v1, $v2));
    self::assertSame(['display_size' => 'l'], StyleDeclaration::migrateSelection(['heading.display_size' => 'l'], $v2, $v1));

    // Two parts accept it: ambiguous, so left for the editor.
    $v3 = $v2;
    $v3['style_parts']['intro'] = ['label' => 'Intro', 'styles' => ['display_size']];
    self::assertSame(['display_size' => 'l'], StyleDeclaration::migrateSelection(['display_size' => 'l'], $v1, $v3));

    // The target already has a value: kept as it is.
    self::assertSame(['display_size' => 'l', 'heading.display_size' => 'xl'], StyleDeclaration::migrateSelection(['display_size' => 'l', 'heading.display_size' => 'xl'], $v1, $v2));
  }

}
