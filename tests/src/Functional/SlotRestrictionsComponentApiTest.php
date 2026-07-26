<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that slot restrictions survive the trip to the Canvas UI.
 *
 * The component list is what the client half of the enforcement resolves
 * `expected` against, and every response on this route is validated against
 * `openapi.yml` on any site that has `league/openapi-psr7-validator` installed
 * — which is every development site and every CI job. A property the source
 * adds but the specification does not declare therefore turns the whole
 * component library into an HTTP 500.
 *
 * @internal
 *
 * @legacy-covers \Drupal\canvas\SlotRestrictions
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase
 */
#[Group('canvas')]
final class SlotRestrictionsComponentApiTest extends FunctionalTestBase {

  use GenerateComponentConfigTrait;
  use OpenApiSpecTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_slot_restrictions',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that a tagged component does not break the component list.
   */
  public function testTaggedComponentIsServed(): void {
    $this->generateComponentConfig();
    $this->drupalLogin($this->createUser(admin: TRUE));

    $this->drupalGet('canvas/api/v0/config/component');
    $this->assertSession()->statusCodeEquals(200);
    $components = \json_decode($this->getSession()->getPage()->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);

    $child = $components['sdc.canvas_test_slot_restrictions.restricted-child'] ?? NULL;
    self::assertIsArray($child);
    self::assertSame(['canvas-test-tag'], $child['tags']);
    $this->assertDataCompliesWithApiSpecification($child, 'Component');

    // A component that declares no tags does not carry the key at all.
    $container = $components['sdc.canvas_test_slot_restrictions.restricted-container'] ?? NULL;
    self::assertIsArray($container);
    self::assertArrayNotHasKey('tags', $container);
    $this->assertDataCompliesWithApiSpecification($container, 'Component');

    // The restrictions themselves reach the client verbatim: they are what the
    // client resolves into the set of components each slot accepts.
    self::assertSame([
      'expected' => [
        'canvas_test_sdc:props-no-slots',
        'canvas-test-tag',
      ],
      'minItems' => 1,
      'maxItems' => 2,
    ], \array_diff_key(
      $container['metadata']['slots']['items'],
      \array_flip(['title', 'description', 'examples']),
    ));
  }

}
