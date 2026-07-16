<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Layout" local task definition's dependency on the node module.
 *
 * @see \Drupal\canvas\Hook\ContentTemplateHooks::localTasksAlter()
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ContentTemplateLayoutTaskTest extends CanvasKernelTestBase {

  /**
   * Without the node module, the task's base route does not exist.
   *
   * The presence of the task on node-enabled sites is covered by the
   * functional tests that exercise the "Layout" tab.
   */
  public function testLayoutTaskRequiresNode(): void {
    $module_handler = $this->container->get(ModuleHandlerInterface::class);
    self::assertFalse($module_handler->moduleExists('node'));
    // The views local task deriver reads route names that get recorded while
    // routes are built, so build them before local task discovery runs.
    $this->container->get('router.builder')->rebuild();
    $manager = $this->container->get('plugin.manager.menu.local_task');
    \assert($manager instanceof LocalTaskManagerInterface);
    self::assertArrayNotHasKey('canvas.content.layout', $manager->getDefinitions());
  }

}
