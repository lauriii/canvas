<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Extension\ThemeInstallerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the component tree aspects of the PageRegion config entity type.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(PageRegion::class)]
#[Group('canvas')]
#[Group('canvas_config_management')]
final class PageRegionComponentTreeTest extends ConfigWithComponentTreeTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service(ThemeInstallerInterface::class)->install(['stark']);
    $this->entity = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
    ]);
  }

}
