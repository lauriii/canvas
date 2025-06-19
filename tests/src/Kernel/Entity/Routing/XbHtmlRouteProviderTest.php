<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity\Routing;

use Drupal\Core\Url;
use Drupal\experience_builder\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\PageTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\XbUiAssertionsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group experience_builder
 */
final class XbHtmlRouteProviderTest extends KernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;
  use XbUiAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'entity_test',
    ...self::PAGE_TEST_MODULES,
    'block',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'media',
    'options',
    'path',
    'link',
    'system',
    'user',
  ];

  protected function setUp(): void {
    parent::setUp();
    // Needed for date formats.
    $this->installConfig(['system']);
    $this->installPageEntitySchema();
  }

  public function testAddFormRoute(): void {
    $this->setUpCurrentUser([], [Page::CREATE_PERMISSION]);
    $url = Url::fromRoute('entity.xb_page.add_form')->toString();
    $this->request(Request::create($url));
    $this->assertExperienceBuilderMount(Page::ENTITY_TYPE_ID);
  }

  public function testEditFormRoute(): void {
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);
    $page = Page::create([]);
    $page->save();
    $url = $page->toUrl('edit-form')->toString();
    $this->request(Request::create($url));
    $this->assertExperienceBuilderMount(Page::ENTITY_TYPE_ID, $page);
  }

}
