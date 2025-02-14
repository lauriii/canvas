<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Traits;

use Drupal\Core\Entity\EntityInterface;

trait XbUiAssertionsTrait {

  /**
   * Asserts the UI mount element and settings for Experience Builder.
   *
   * @param string $entity_type
   *   The entity type.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   (optional) The entity.
   */
  protected function assertExperienceBuilderMount(string $entity_type, ?EntityInterface $entity = NULL): void {
    $entity_id = $entity ? $entity->id() : NULL;
    $entity_type_keys = $entity ? $entity->getEntityType()->getKeys() : NULL;
    $this->assertTitle('Drupal Experience Builder');
    self::assertCount(1, $this->cssSelect('#experience-builder'));
    self::assertArrayHasKey('xb', $this->drupalSettings);
    self::assertEquals("xb/$entity_type/$entity_id", $this->drupalSettings['xb']['base']);
    self::assertEquals($entity_type, $this->drupalSettings['xb']['entityType']);
    self::assertEquals($entity_id, $this->drupalSettings['xb']['entity']);
    self::assertEquals($entity_type_keys, $this->drupalSettings['xb']['entityTypeKeys']);
  }

}
