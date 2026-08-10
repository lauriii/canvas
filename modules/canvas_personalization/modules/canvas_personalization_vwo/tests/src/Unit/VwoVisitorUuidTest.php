<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization_vwo\Unit;

use Drupal\canvas_personalization_vwo\VwoAudienceMembership;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests parsing the visitor UUID out of VWO's identity cookie.
 *
 * The shapes below come from VWO's live SmartCode payload and from the UUID
 * validator in VWO's own PHP SDK.
 *
 * @see https://github.com/wingify/wingify-fme-php-sdk/blob/master/src/Utils/UuidUtil.php
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class VwoVisitorUuidTest extends UnitTestCase {

  #[DataProvider('providerCookieValues')]
  public function testParseVisitorUuid(string $cookie_value, ?string $expected): void {
    $this->assertSame($expected, VwoAudienceMembership::parseVisitorUuid($cookie_value));
  }

  public static function providerCookieValues(): \Generator {
    $uuid = 'DD065E5496981120D4233AFBE003323BA';
    $hash = 'fa64c2dbf8455463770fd5d2edc77faf';

    yield 'the shape VWO actually writes' => [$uuid . '|' . $hash, $uuid];
    // VWO writes the pipe raw but reads the value back through
    // decodeURIComponent, so an encoded pipe has to be accepted too.
    yield 'percent-encoded pipe' => [$uuid . '%7C' . $hash, $uuid];
    yield 'uuid only, no hash field' => [$uuid, $uuid];
    yield 'the J prefix VWO also issues' => ['J' . \substr($uuid, 1) . '|' . $hash, 'J' . \substr($uuid, 1)];
    $lowercase = 'D' . \strtolower(\substr($uuid, 1));
    yield 'lowercase hex body' => [$lowercase . '|' . $hash, $lowercase];

    // Everything VWO's own SDK would reject is rejected here rather than
    // forwarded: a UUID VWO will not accept can only produce a wrong answer.
    yield 'empty' => ['', NULL];
    yield 'missing the D/J prefix' => [\substr($uuid, 1) . '|' . $hash, NULL];
    yield 'wrong prefix letter' => ['X' . \substr($uuid, 1), NULL];
    yield 'too short' => [\substr($uuid, 0, 20), NULL];
    yield 'too long' => [$uuid . 'AB', NULL];
    yield 'non-hex body' => ['D' . \str_repeat('Z', 32), NULL];
    yield 'only the hash field' => [$hash, NULL];
    yield 'leading separator' => ['|' . $uuid, NULL];
  }

}
