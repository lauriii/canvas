<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\canvas\Entity\PageRegion;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the component tree aspects of the PageRegion config entity type.
 *
 * @group canvas
 * @coversDefaultClass \Drupal\canvas\Entity\PageRegion
 */
#[RunTestsInSeparateProcesses]
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
