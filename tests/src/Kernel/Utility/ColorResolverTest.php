<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Utility;

use Drupal\canvas\Entity\Color;
use Drupal\canvas\Utility\ColorResolver;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\canvas\Utility\ColorResolver.
 */
#[CoversClass(ColorResolver::class)]
#[Group('canvas')]
final class ColorResolverTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [];

  /**
   * Tests that an empty stored value resolves to NULL.
   */
  public function testResolveEmptyString(): void {
    $resolver = $this->container->get(ColorResolver::class);
    \assert($resolver instanceof ColorResolver);

    [$color, $cacheability] = $resolver->resolve('');

    self::assertNull($color);
    self::assertSame([], $cacheability->getCacheTags());
    self::assertSame([], $cacheability->getCacheContexts());
  }

  /**
   * Tests resolving a stored Brand Kit color reference.
   */
  public function testResolveBrandKitColor(): void {
    $uuid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $color = Color::create([
      'uuid' => $uuid,
      'name' => 'Brand Red',
      'cssVariable' => '--color-brand-red',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'alpha' => 0.5,
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();
    self::assertEntityIsValid($color);

    $resolver = $this->container->get(ColorResolver::class);
    \assert($resolver instanceof ColorResolver);

    [$resolved, $cacheability] = $resolver->resolve('canvas-color:' . $uuid);

    self::assertSame([
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'alpha' => 0.5,
        'hex' => '#ff0000',
      ],
      'cssColorValue' => 'rgba(255, 0, 0, 0.50)',
      'cssVariable' => '--color-brand-red',
      'colorName' => 'Brand Red',
    ], $resolved);
    self::assertSame($color->getCacheTags(), $cacheability->getCacheTags());
  }

  /**
   * Tests resolving a missing Brand Kit color reference.
   */
  public function testResolveMissingBrandKitColor(): void {
    $resolver = $this->container->get(ColorResolver::class);
    \assert($resolver instanceof ColorResolver);

    [$color, $cacheability] = $resolver->resolve('canvas-color:00000000-0000-0000-0000-000000000000');

    self::assertNull($color);
    // The list cache tag should be added so that if the color is later
    // created, the cached render result will be invalidated.
    self::assertSame(['config:color_list'], $cacheability->getCacheTags());
  }

  /**
   * Tests resolving free-pick hex values.
   *
   * @param string $stored_value
   *   The stored color value.
   * @param array<string, mixed>|null $expected
   *   The expected resolved color array.
   */
  #[DataProvider('resolveHexProvider')]
  public function testResolveHex(string $stored_value, ?array $expected): void {
    $resolver = $this->container->get(ColorResolver::class);
    \assert($resolver instanceof ColorResolver);

    [$resolved, $cacheability] = $resolver->resolve($stored_value);

    self::assertSame($expected, $resolved);
    self::assertSame([], $cacheability->getCacheTags());
    self::assertSame([], $cacheability->getCacheContexts());
  }

  /**
   * Provides hex color resolution scenarios.
   *
   * @return array<string, array{string, array<string, mixed>|null}>
   */
  public static function resolveHexProvider(): array {
    return [
      '6-digit opaque hex' => [
        '#ff0000',
        [
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1, 0, 0],
            'alpha' => NULL,
            'hex' => '#ff0000',
          ],
          'cssColorValue' => 'rgb(255, 0, 0)',
          'cssVariable' => NULL,
          'colorName' => NULL,
        ],
      ],
      '8-digit hex with alpha' => [
        '#00ff0080',
        [
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [0, 1, 0],
            'alpha' => 128 / 255,
            'hex' => '#00ff00',
          ],
          'cssColorValue' => 'rgba(0, 255, 0, 0.50)',
          'cssVariable' => NULL,
          'colorName' => NULL,
        ],
      ],
      'HSL opaque' => [
        'hsl(120, 100%, 50%)',
        [
          'value' => [
            'colorSpace' => 'hsl',
            'components' => [120.0, 100.0, 50.0],
            'alpha' => NULL,
            'hex' => NULL,
          ],
          'cssColorValue' => 'hsl(120, 100%, 50%)',
          'cssVariable' => NULL,
          'colorName' => NULL,
        ],
      ],
      'HSLA with alpha' => [
        'hsla(240, 50%, 75%, 0.50)',
        [
          'value' => [
            'colorSpace' => 'hsl',
            'components' => [240.0, 50.0, 75.0],
            'alpha' => 0.5,
            'hex' => NULL,
          ],
          'cssColorValue' => 'hsla(240, 50%, 75%, 0.50)',
          'cssVariable' => NULL,
          'colorName' => NULL,
        ],
      ],
    ];
  }

  /**
   * Tests resolving an HSL Brand Kit color.
   */
  public function testResolveHslBrandKitColor(): void {
    $uuid = 'b1ffcdaa-ad1c-4ef8-bc7e-7ccace491b22';
    $color = Color::create([
      'uuid' => $uuid,
      'name' => 'Brand Green',
      'cssVariable' => '--color-brand-green',
      'value' => [
        'colorSpace' => 'hsl',
        'components' => [142.0, 100.0, 33.3],
        'alpha' => NULL,
        'hex' => NULL,
      ],
      'weight' => 0,
    ]);
    $color->save();
    self::assertEntityIsValid($color);

    $resolver = $this->container->get(ColorResolver::class);
    \assert($resolver instanceof ColorResolver);

    [$resolved, $cacheability] = $resolver->resolve('canvas-color:' . $uuid);

    self::assertSame([
      'value' => [
        'colorSpace' => 'hsl',
        'components' => [142.0, 100.0, 33.3],
        'alpha' => NULL,
        'hex' => NULL,
      ],
      'cssColorValue' => 'hsl(142, 100%, 33%)',
      'cssVariable' => '--color-brand-green',
      'colorName' => 'Brand Green',
    ], $resolved);
    self::assertSame($color->getCacheTags(), $cacheability->getCacheTags());
  }

  /**
   * Tests that an unrecognized stored value resolves to NULL.
   */
  public function testResolveUnrecognized(): void {
    $resolver = $this->container->get(ColorResolver::class);
    \assert($resolver instanceof ColorResolver);

    [$color, $cacheability] = $resolver->resolve('not-a-color');

    self::assertNull($color);
    self::assertSame([], $cacheability->getCacheTags());
  }

}
