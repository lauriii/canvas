<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\Fetch\Policy;

use Drupal\canvas_ssr\Fetch\Policy\MenuLinksetPathPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(MenuLinksetPathPolicy::class)]
#[Group('canvas_ssr')]
final class MenuLinksetPathPolicyTest extends UnitTestCase {

  public function testAllowsLinksetPaths(): void {
    $policy = new MenuLinksetPathPolicy();

    self::assertTrue($policy->isAllowed('/system/menu/main/linkset'));
    self::assertTrue($policy->isAllowed('/system/menu/admin-menu_2/linkset'));
  }

  public function testDeniesNonLinksetPaths(): void {
    $policy = new MenuLinksetPathPolicy();

    self::assertFalse($policy->isAllowed('/system/menu/main'));
    self::assertFalse($policy->isAllowed('/system/menu/main/linkset/extra'));
    self::assertFalse($policy->isAllowed('/jsonapi/node/article'));
  }

}
