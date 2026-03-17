<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\BrandKit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests global brand kit creation.
 *
 * @legacy-covers \canvas_post_update_0014_create_global_brand_kit
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class GlobalBrandKitCreationUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Tests brand kit creation.
   */
  public function testBrandKitCreation(): void {
    $brandKits = BrandKit::loadMultiple();
    $this->assertArrayNotHasKey(BrandKit::GLOBAL_ID, $brandKits);

    $this->runUpdates();

    $brandKits = BrandKit::loadMultiple();
    $this->assertArrayHasKey(BrandKit::GLOBAL_ID, $brandKits);
    $brand_kit = $brandKits[BrandKit::GLOBAL_ID];
    $this->assertEntityIsValid($brand_kit);

    $this->assertSame(BrandKit::GLOBAL_ID, $brand_kit->id());
    $this->assertSame('Global brand kit', $brand_kit->label());
    $this->assertNull($brand_kit->get('fonts'));

    $dependencies = $brand_kit->getDependencies();
    $this->assertArrayHasKey('module', $dependencies);
    $this->assertContains('canvas', $dependencies['module'], 'The global brand kit must have an enforced dependency on the canvas module.');
  }

}
