<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\canvas\Entity\Page;

/**
 * Tests the admin view for the canvas page content listing.
 *
 * @group canvas
 * @covers \Drupal\canvas\Entity\Page
 */
class CanvasPageListTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'system',
    // Ensures local tasks display.
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * Tests the admin page.
   */
  public function testCanvasContentListPage(): void {
    $page = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
    ]);
    $page->save();

    $account = $this->drupalCreateUser([
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      Page::DELETE_PERMISSION,
      // For other collections to ensure local tasks show.
      'access content overview',
    ]);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);
    $this->drupalGet(Url::fromRoute('entity.canvas_page.collection'));

    $assert = $this->assertSession();

    $assert->linkByHrefExists(Url::fromRoute('system.admin_content')->toString());
    $assert->linkByHrefExists(Url::fromRoute('entity.canvas_page.collection')->toString());

    $assert->linkByHrefExists('/admin/content/pages/add?token=');

    $assert->linkByHrefExists($page->toUrl('canonical')->toString());
    $assert->linkByHrefExists($page->toUrl('edit-form')->toString());
    $assert->linkByHrefExists($page->toUrl('delete-form')->toString());
  }

}
