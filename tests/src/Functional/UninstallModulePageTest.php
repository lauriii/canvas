<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the uninstalling module page is loaded.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class UninstallModulePageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * Tests that the uninstalling module page is loaded.
   */
  public function testUninstallModulePage(): void {
    $account = $this->createUser(['administer modules']);
    \assert($account instanceof UserInterface);
    $this->drupalLogin($account);

    $this->drupalGet('admin/modules/uninstall');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $this->submitForm(['uninstall[canvas]' => 1], 'Uninstall');
    $this->submitForm([], 'Uninstall');
    $assert_session->pageTextContains('The selected modules have been uninstalled.');
    $assert_session->pageTextNotContains('Drupal Canvas');
  }

}
