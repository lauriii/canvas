<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Icon;

use Drupal\canvas\Icon\IconPropShape;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @legacy-covers \Drupal\canvas\Icon\IconPropShape
 */
#[Group('canvas')]
final class IconPropShapeTest extends UnitTestCase {

  public function testIsIconSchema(): void {
    // The well-known `$ref` form.
    $this->assertTrue(IconPropShape::isIconSchema(['type' => 'string', '$ref' => IconPropShape::SCHEMA_REF]));
    // The dereferenced base pattern form.
    $this->assertTrue(IconPropShape::isIconSchema(['type' => 'string', 'pattern' => IconPropShape::BASE_PATTERN]));
    // Generated scope patterns, single and multiple packs.
    $this->assertTrue(IconPropShape::isIconSchema(['type' => 'string', 'pattern' => '^(phosphor):.+$']));
    $this->assertTrue(IconPropShape::isIconSchema(['type' => 'string', 'pattern' => '^(phosphor|heroicons_2):.+$']));
    // SDC appends `object` to the declared type; the first element counts.
    $this->assertTrue(IconPropShape::isIconSchema(['type' => ['string', 'object'], 'pattern' => '^(phosphor):.+$']));

    // Not icons: other types, other refs, other patterns.
    $this->assertFalse(IconPropShape::isIconSchema(['type' => 'object', '$ref' => IconPropShape::SCHEMA_REF]));
    $this->assertFalse(IconPropShape::isIconSchema(['type' => 'string', '$ref' => 'json-schema-definitions://canvas.module/image']));
    $this->assertFalse(IconPropShape::isIconSchema(['type' => 'string', 'pattern' => '(.|\r?\n)*']));
    $this->assertFalse(IconPropShape::isIconSchema(['type' => 'string', 'pattern' => '^(Not-A-Pack):.+$']));
    $this->assertFalse(IconPropShape::isIconSchema(['type' => 'string']));
  }

  public function testBuildScopePattern(): void {
    $this->assertSame('^(phosphor):.+$', IconPropShape::buildScopePattern(['phosphor']));
    $this->assertSame('^(a|b_2):.+$', IconPropShape::buildScopePattern(['a', 'b_2']));
  }

  public function testGetAllowedPackIds(): void {
    $this->assertSame(['phosphor'], IconPropShape::getAllowedPackIds(['pattern' => '^(phosphor):.+$']));
    $this->assertSame(['a', 'b'], IconPropShape::getAllowedPackIds(['pattern' => '^(a|b):.+$']));
    // The base pattern and absent patterns mean: all installed packs.
    $this->assertNull(IconPropShape::getAllowedPackIds(['pattern' => IconPropShape::BASE_PATTERN]));
    $this->assertNull(IconPropShape::getAllowedPackIds([]));
  }

  public function testDereference(): void {
    // The unscoped `$ref` form gains the base pattern.
    $this->assertSame(
      ['type' => 'string', 'pattern' => IconPropShape::BASE_PATTERN],
      IconPropShape::dereference(['type' => 'string', '$ref' => IconPropShape::SCHEMA_REF]),
    );
    // A sibling scope pattern wins over the base pattern.
    $this->assertSame(
      ['type' => 'string', 'pattern' => '^(phosphor):.+$'],
      IconPropShape::dereference(['type' => 'string', '$ref' => IconPropShape::SCHEMA_REF, 'pattern' => '^(phosphor):.+$']),
    );
    // Non-icon schemas pass through unchanged.
    $image = ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image'];
    $this->assertSame($image, IconPropShape::dereference($image));
  }

}
