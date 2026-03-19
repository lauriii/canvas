<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Stark theme is installed if it wasn't.
 *
 * @legacy-covers \canvas_update_11200
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasStarkAlwaysInstalledUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Tests canvas stark is installed.
   */
  public function testCanvasStarkIsInstalled(): void {
    $theme_installer = \Drupal::service(ThemeInstallerInterface::class);
    \assert($theme_installer instanceof ThemeInstallerInterface);
    $theme_handler = \Drupal::service(ThemeHandlerInterface::class);
    \assert($theme_handler instanceof ThemeHandlerInterface);

    $theme_installer->uninstall(['canvas_stark']);
    $this->assertFalse($theme_handler->themeExists('canvas_stark'));

    $this->runUpdates();

    // The service caches the list of installed themes, so we need to
    // instantiate it again.
    $theme_handler = \Drupal::service(ThemeHandlerInterface::class);
    \assert($theme_handler instanceof ThemeHandlerInterface);
    $this->assertTrue($theme_handler->themeExists('canvas_stark'));
  }

}
