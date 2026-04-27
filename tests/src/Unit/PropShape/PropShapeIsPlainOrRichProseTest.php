<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\PropShape;

use Drupal\canvas\PropShape\PropShape;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(PropShape::class)]
#[Group('canvas')]
final class PropShapeIsPlainOrRichProseTest extends UnitTestCase {

  /**
   * Ensures prose classification matches normalized string shapes.
   *
   * Strings with a URI format or `enum` must not match; they are not
   * unrestricted prose in the PropShape sense.
   */
  #[DataProvider('providerIsPlainOrRichProseClassification')]
  public function testIsPlainOrRichProseClassification(bool $expected, array $schema): void {
    $this->assertSame($expected, PropShape::isPlainOrRichProse($schema));
  }

  /**
   * @return \Generator<string, array{0: bool, 1: array<string, mixed>}>
   */
  public static function providerIsPlainOrRichProseClassification(): \Generator {
    yield 'plain type string' => [
      TRUE,
      ['type' => 'string', 'title' => 'Label'],
    ];
    yield 'rich HTML string' => [
      TRUE,
      [
        'type' => 'string',
        'contentMediaType' => 'text/html',
        'x-formatting-context' => 'block',
      ],
    ];
    yield 'string with uri format' => [
      FALSE,
      ['type' => 'string', 'format' => 'uri'],
    ];
    yield 'string with uri-reference format' => [
      FALSE,
      ['type' => 'string', 'format' => 'uri-reference'],
    ];
    yield 'string with enum' => [
      FALSE,
      ['type' => 'string', 'enum' => ['a', 'b']],
    ];
  }

}
