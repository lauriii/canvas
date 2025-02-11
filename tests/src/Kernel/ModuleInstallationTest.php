<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests module installation.
 *
 * @group experience_builder
 */
final class ModuleInstallationTest extends KernelTestBase {

  public function testModuleInstallation(): void {
    self::assertFalse($this->container->get('module_handler')->moduleExists('experience_builder'));
    self::assertFalse($this->container->get('theme_handler')->themeExists('xb_stark'));

    $this->container->get('module_installer')->install(['experience_builder']);
    self::assertTrue($this->container->get('module_handler')->moduleExists('experience_builder'));
    $this->assertTXbStarkThemeExists();

    $this->container->get('module_installer')->uninstall(['experience_builder']);
    self::assertFalse($this->container->get('module_handler')->moduleExists('experience_builder'));
    $this->assertTXbStarkThemeExists();

    // Installing the module after uninstallation does not lead to errors.
    $this->container->get('module_installer')->install(['experience_builder']);
    self::assertTrue($this->container->get('module_handler')->moduleExists('experience_builder'));
    $this->assertTXbStarkThemeExists();
  }

  private function assertTXbStarkThemeExists(): void {
    $this->container->get('theme_handler')->reset();
    self::assertTrue($this->container->get('theme_handler')->themeExists('xb_stark'));
  }

}
