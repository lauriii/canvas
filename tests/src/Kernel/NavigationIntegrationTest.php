<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
final class NavigationIntegrationTest extends KernelTestBase {

  use PageTrait;

  protected static $modules = [
    'system',
    'canvas',
    ...self::PAGE_TEST_MODULES,
    'block',
    'file',
    'layout_builder',
    'navigation',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['navigation']);
  }

  public function testNavigation(): void {
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $links = $menu_link_manager->getDefinitions();

    $this->assertArrayHasKey('navigation.content', $links);
    $this->assertEquals('system.admin_content', $links['navigation.content']['route_name']);
    $this->assertEquals('CMS', $links['navigation.content']['title']);

    $this->assertArrayHasKey('navigation.content', $links);
    $this->assertEquals('system.admin_content', $links['navigation.content']['route_name']);
    $this->assertEquals('CMS', $links['navigation.content']['title']);
    $this->assertEquals('database', $links['navigation.content']['options']['icon']['icon_id']);

    $this->assertArrayHasKey('navigation.pages', $links);
    $this->assertEquals('entity.canvas_page.collection', $links['navigation.pages']['route_name']);
    $this->assertEquals('Pages', $links['navigation.pages']['title']);
    $this->assertEquals('navigation', $links['navigation.pages']['options']['icon']['pack_id']);
    $this->assertEquals('file', $links['navigation.pages']['options']['icon']['icon_id']);
  }

}
