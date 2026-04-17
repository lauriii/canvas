<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\CanvasNotificationHandler;
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

    // Trigger lazy creation of both notification tables.
    $handler = $this->container->get(CanvasNotificationHandler::class);
    $notification = $handler->create([
      'type' => 'info',
      'title' => 'Test notification',
      'message' => 'Triggers table creation.',
    ]);
    $handler->markRead((int) $account->id(), [$notification['id']]);
    $schema = $this->container->get('database')->schema();
    self::assertTrue($schema->tableExists(CanvasNotificationHandler::NOTIFICATION_TABLE));
    self::assertTrue($schema->tableExists(CanvasNotificationHandler::NOTIFICATION_READ_TABLE));

    $this->drupalGet('admin/modules/uninstall');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $this->submitForm(['uninstall[canvas]' => 1], 'Uninstall');
    $this->submitForm([], 'Uninstall');
    $assert_session->pageTextContains('The selected modules have been uninstalled.');
    $assert_session->pageTextNotContains('Drupal Canvas');

    $schema = \Drupal::database()->schema();
    self::assertFalse($schema->tableExists(CanvasNotificationHandler::NOTIFICATION_TABLE));
    self::assertFalse($schema->tableExists(CanvasNotificationHandler::NOTIFICATION_READ_TABLE));
  }

}
