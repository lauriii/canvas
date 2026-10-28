<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Theme;

use Drupal\canvas\Theme\CanvasThemeNegotiator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests which routes the Canvas theme negotiator applies to.
 */
#[CoversClass(CanvasThemeNegotiator::class)]
#[Group('canvas')]
final class CanvasThemeNegotiatorTest extends UnitTestCase {

  #[DataProvider('providerApplies')]
  public function testApplies(?string $route_name, array $query, bool $expected): void {
    $request_stack = new RequestStack();
    $request_stack->push(new Request($query));
    $negotiator = new CanvasThemeNegotiator($request_stack, $this->createMock(ConfigFactoryInterface::class));

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn($route_name);

    self::assertSame($expected, $negotiator->applies($route_match));
  }

  /**
   * @return array<string, array{?string, array<string, string>, bool}>
   */
  public static function providerApplies(): array {
    return [
      'editor boot: entity' => ['canvas.boot.entity', [], TRUE],
      'editor boot: empty' => ['canvas.boot.empty', [], TRUE],
      'editor boot: app' => ['canvas.boot.app', [], TRUE],
      'form API route' => ['canvas.api.form.component_instance', [], TRUE],
      'other Canvas route' => ['canvas.api.layout.get', [], FALSE],
      'preview assets route' => ['canvas.api.ui.preview_assets', [], FALSE],
      'non-Canvas route' => ['entity.node.canonical', [], FALSE],
      'no route' => [NULL, [], FALSE],
      'admin theme requested' => ['entity.node.canonical', ['use_admin_theme' => '1'], TRUE],
    ];
  }

}
