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
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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

    $account = $this->drupalCreateUser(['edit canvas_page', 'delete canvas_page']);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);
    $this->drupalGet(Url::fromRoute('view.canvas_pages.page_1'));

    $assert = self::assertSession();
    $assert->linkByHrefExists($page->toUrl('canonical')->toString());
    $assert->linkByHrefExists($page->toUrl('edit-form')->toString());
    $assert->linkByHrefExists($page->toUrl('delete-form')->toString());
  }

}
