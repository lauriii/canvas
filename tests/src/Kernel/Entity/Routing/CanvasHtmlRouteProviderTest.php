<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity\Routing;

use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\CanvasUiAssertionsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
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

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
  }

  public function testEditFormRoute(): void {
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);
    $page = Page::create([]);
    $page->save();
    $url = $page->toUrl('edit-form')->toString();
    $this->request(Request::create($url));
    $this->assertCanvasMount();
  }

}
