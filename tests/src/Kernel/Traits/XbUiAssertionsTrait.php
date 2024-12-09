<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Traits;

trait XbUiAssertionsTrait {

  /**
   * Asserts the UI mount element and settings for Experience Builder.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string|int|null $entity_id
   *   The entity ID.
   */
  protected function assertExperienceBuilderMount(string $entity_type, string|int|null $entity_id): void {
    $this->assertTitle('Drupal Experience Builder');
    self::assertCount(1, $this->cssSelect('#experience-builder'));
    self::assertArrayHasKey('xb', $this->drupalSettings);
    self::assertEquals("xb/$entity_type/$entity_id", $this->drupalSettings['xb']['base']);
    self::assertEquals($entity_type, $this->drupalSettings['xb']['entityType']);
    self::assertEquals($entity_id, $this->drupalSettings['xb']['entity']);
  }

}
