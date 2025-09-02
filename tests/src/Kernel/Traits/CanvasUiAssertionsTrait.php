<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\canvas\CodeComponentDataProvider;

trait CanvasUiAssertionsTrait {

  /**
   * Asserts the UI mount element and settings for Drupal Canvas.
   *
   * @param string $entity_type
   *   The entity type.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   (optional) The entity.
   */
  protected function assertCanvasMount(string $entity_type, ?EntityInterface $entity = NULL): void {
    $entity_id = $entity ? $entity->id() : NULL;
    $entity_type_keys = $entity ? $entity->getEntityType()->getKeys() : NULL;
    $this->assertTitle('Drupal Canvas');
    self::assertCount(1, $this->cssSelect('#canvas'));
    self::assertArrayHasKey('canvas', $this->drupalSettings);
    if ($entity_type) {
      self::assertEquals("canvas/$entity_type/$entity_id", $this->drupalSettings['canvas']['base']);
    }
    else {
      self::assertEquals('canvas', $this->drupalSettings['canvas']['base']);
    }
    self::assertEquals($entity_type, $this->drupalSettings['canvas']['entityType']);
    self::assertEquals($entity_id, $this->drupalSettings['canvas']['entity']);
    self::assertEquals($entity_type_keys, $this->drupalSettings['canvas']['entityTypeKeys']);

    // `drupalSettings.canvasData.v0` must be unconditionally present: in case the
    // user starts creating/editing code components.
    self::assertArrayHasKey(CodeComponentDataProvider::CANVAS_DATA_KEY, $this->drupalSettings);
    self::assertArrayHasKey(CodeComponentDataProvider::V0, $this->drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY]);
    self::assertSame([
      'baseUrl',
      'branding',
      'breadcrumbs',
      'jsonapiSettings',
      'pageTitle',
    ], array_keys($this->drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]));
    self::assertSame('This is a page title for testing purposes', $this->drupalSettings[CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['pageTitle']);
  }

}
