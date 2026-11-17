<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\Fetch\Policy;

// cspell:ignore jsonapineighbor

use Drupal\canvas_ssr\Fetch\Policy\JsonApiPathPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(JsonApiPathPolicy::class)]
#[Group('canvas_ssr')]
final class JsonApiPathPolicyTest extends UnitTestCase {

  public function testDefaultBasePathAllowsJsonApiPaths(): void {
    $policy = new JsonApiPathPolicy('/jsonapi');

    self::assertTrue($policy->isAllowed('/jsonapi'));
    self::assertTrue($policy->isAllowed('/jsonapi/node/article'));
    self::assertFalse($policy->isAllowed('/system/menu/main/linkset'));
    self::assertFalse($policy->isAllowed('/jsonapineighbor'));
  }

  public function testCustomBasePathOnlyAllowsConfiguredPrefix(): void {
    $policy = new JsonApiPathPolicy('/api');

    self::assertTrue($policy->isAllowed('/api'));
    self::assertTrue($policy->isAllowed('/api/node/article'));
    // /jsonapi isn't an alias for the configured prefix.
    self::assertFalse($policy->isAllowed('/jsonapi'));
    self::assertFalse($policy->isAllowed('/jsonapi/node/article'));
  }

}
