<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Routing;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Routing\CanvasEntityByUuidConverter;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for CanvasEntityByUuidConverter::convert().
 */
#[CoversClass(CanvasEntityByUuidConverter::class)]
#[Group('canvas')]
final class CanvasEntityByUuidConverterTest extends CanvasKernelTestBase {

  use PageTrait;

  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
  }

  /**
   * @legacy-covers ::convert
   */
  public function testConvertReturnsEntityForValidUuid(): void {
    $page = Page::create(['title' => 'Test Page']);
    $page->save();

    $converter = $this->container->get(CanvasEntityByUuidConverter::class);
    $result = $converter->convert($page->uuid(), ['type' => 'canvas_entity_by_uuid:' . Page::ENTITY_TYPE_ID], 'canvas_page', []);

    $this->assertInstanceOf(Page::class, $result);
    $this->assertSame($page->id(), $result->id());
  }

  /**
   * @legacy-covers ::convert
   */
  public function testConvertThrowsForNonExistentUuid(): void {
    $converter = $this->container->get(CanvasEntityByUuidConverter::class);

    $this->expectException(ParamNotConvertedException::class);
    $converter->convert('00000000-dead-beef-0000-000000000000', ['type' => 'canvas_entity_by_uuid:' . Page::ENTITY_TYPE_ID], 'canvas_page', []);
  }

}
