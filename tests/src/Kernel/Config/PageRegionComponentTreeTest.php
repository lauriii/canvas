<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\experience_builder\Entity\PageRegion;

/**
 * Tests the component tree aspects of the PageRegion config entity type.
 *
 * @group experience_builder
 * @coversDefaultClass \Drupal\experience_builder\Entity\PageRegion
 */
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
