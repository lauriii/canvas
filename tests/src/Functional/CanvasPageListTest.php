<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the admin view for the canvas page content listing.
 *
 * @legacy-covers \Drupal\canvas\Entity\Page
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class CanvasPageListTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'system',
    // Ensures local tasks display.
    'node',
    // To create page revisions.
    'canvas_test_sdc',
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

  /**
   * Tests viewing different revisions of a page.
   *
   * @todo Convert to a kernel test when Drupal 11.4+ is required.
   */
  public function testRevisionRoute(): void {
    $account = $this->createUser([
      'access content',
      Page::EDIT_PERMISSION,
    ]);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);

    $this->container->get(ComponentSourceManager::class)
      ->generateComponents();
    $component = Component::load('sdc.canvas_test_sdc.my-hero');
    $this->assertInstanceOf(Component::class, $component);
    $component_tree = [
      [
        'uuid' => '72bf564a-1cac-4ac7-b055-55edab1a0618',
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Just a draft, really.',
          'cta1href' => '/',
        ],
      ],
    ];

    $page = Page::create(['title' => 'Hello There'])
      ->setComponentTree($component_tree);
    $this->assertCount(0, $page->validate());
    $page->save();
    $v1 = (int) $page->getRevisionId();
    $this->assertGreaterThan(0, $v1);

    $component_tree[0]['inputs']['heading'] = 'An incredible improvement.';
    $page->setComponentTree($component_tree)->setNewRevision();
    $this->assertCount(0, $page->validate());
    $page->save();
    $v2 = (int) $page->getRevisionId();
    $this->assertGreaterThan($v1, $v2);

    // Visiting the current revision works, and shows the latest content.
    $this->drupalGet($page->toUrl('revision'));
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('An incredible improvement.');
    $assert_session->pageTextNotContains('Just a draft, really.');

    // Ensure we can also visit an older revision.
    $storage = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Page::ENTITY_TYPE_ID);
    $this->assertInstanceOf(RevisionableStorageInterface::class, $storage);
    $url = $storage->loadRevision($v1)?->toUrl('revision');
    $this->assertNotEmpty($url);
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextNotContains('An incredible improvement.');
    $assert_session->pageTextContains('Just a draft, really.');

    // If we revoke edit permission, the old revision is no longer available
    // even though the user has the ability to access content.
    [$role_id] = $account->getRoles(TRUE);
    Role::load($role_id)?->revokePermission(Page::EDIT_PERMISSION)
      ->save();
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(403);

    // Ditto for anonymous users.
    $this->drupalLogout();
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(403);
  }

}
