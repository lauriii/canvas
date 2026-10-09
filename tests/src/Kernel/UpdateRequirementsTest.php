<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Hook\UpdateHooks;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas' warning about PHP assertions during database updates.
 *
 * @see \Drupal\canvas\Hook\UpdateHooks::updateRequirements()
 */
#[CoversClass(UpdateHooks::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class UpdateRequirementsTest extends CanvasKernelTestBase {

  public function testWarningWithAssertionsEnabled(): void {
    self::assertSame('1', \ini_get('zend.assertions'), 'PHP assertions must be enabled for this test.');

    $requirements = $this->container->get(ModuleHandlerInterface::class)->invoke('canvas', 'update_requirements');

    self::assertIsArray($requirements);
    self::assertSame(['canvas_assertions'], \array_keys($requirements));
    self::assertSame(RequirementSeverity::Warning, $requirements['canvas_assertions']['severity']);
    self::assertStringContainsString("Canvas' assertions are for Canvas development, not for sites.", (string) $requirements['canvas_assertions']['description']);
    self::assertStringContainsString('https://www.drupal.org/docs/develop/drupal-apis/runtime-assertions', (string) $requirements['canvas_assertions']['description']);
  }

  public function testNoWarningWithAssertionsDisabled(): void {
    \ini_set('zend.assertions', '0');
    try {
      $requirements = $this->container->get(ModuleHandlerInterface::class)->invoke('canvas', 'update_requirements');
    }
    finally {
      \ini_set('zend.assertions', '1');
    }

    self::assertSame([], $requirements);
  }

}
