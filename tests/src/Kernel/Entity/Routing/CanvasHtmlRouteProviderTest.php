<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity\Routing;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\CanvasUiAssertionsTrait;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests Canvas Html Route Provider.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasHtmlRouteProviderTest extends CanvasKernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;
  use CanvasUiAssertionsTrait;
  use ContentTranslationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'language',
    'content_translation',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
    $this->installEntitySchema('user');
    $this->installConfig(['language']);
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
  }

  public function testEditFormRoute(): void {
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);
    $page = Page::create([]);
    $page->save();
    $url = $page->toUrl('edit-form')->toString();
    $this->request(Request::create($url));
    $this->assertCanvasMount();
  }

  /**
   * Tests that admin-only routes are marked as such.
   *
   * @see \Drupal\canvas\Entity\Routing\CanvasHtmlRouteProvider
   * @see \Drupal\Core\Entity\Routing\AdminHtmlRouteProvider
   */
  public function testAdminRouteOption(): void {
    $route_provider = $this->container->get(RouteProviderInterface::class);
    foreach ([
      'entity.canvas_page.edit_form',
      'entity.canvas_page.delete_form',
      'entity.canvas_page.content_translation_overview',
    ] as $route_name) {
      $route = $route_provider->getRouteByName($route_name);
      $this->assertTrue(
        $route->getOption('_admin_route'),
        \sprintf('The "%s" route is expected to be an admin route.', $route_name)
      );
    }
  }

}
