<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Page;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests that auto-saved changes are deleted when reverting a page revision.
 *
 * @group experience_builder
 */
class AutoSaveRevertTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder', 'user'];

  /**
   * Tests access to page revisions.
   */
  public function testRevisionAccess(): void {
    $user = $this->drupalCreateUser(['edit xb_page']);
    assert($user instanceof AccountInterface);
    $this->drupalLogin($user);

    $page = Page::create(['title' => 'Test Page']);
    $page->save();
    $original_vid = $page->getRevisionId();

    $page->setNewRevision(TRUE);
    $page->set('title', 'Test Page - Revision 2');
    $page->save();

    $this->drupalGet('/page/' . $page->id() . '/revisions/' . $original_vid . '/revert');
    $this->assertSession()->pageTextNotContains('This page has unpublished changed in Experience Builder.');

    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $page->set('title', 'Test Page - Auto-saved revision');
    $autoSaveManager->saveEntity($page);

    $this->drupalGet('/page/' . $page->id() . '/revisions/' . $original_vid . '/revert');
    $this->assertSession()->pageTextContains('This page has unpublished changed in Experience Builder.');
    $this->submitForm([], 'Revert');

    $this->drupalGet('/page/' . $page->id() . '/revisions/' . $original_vid . '/revert');
    $this->assertSession()->pageTextNotContains('This page has unpublished changed in Experience Builder.');
    self::assertTrue($autoSaveManager->getAutoSaveEntity($page)->isEmpty());
  }

}
