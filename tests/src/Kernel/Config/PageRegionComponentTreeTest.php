<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
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

  /**
   * Tests that API normalization includes computed resolved inputs.
   */
  public function testNormalizeForClientSideIncludesResolvedInputs(): void {
    $component = Component::load('sdc.canvas_test_sdc.props-no-slots');
    self::assertInstanceOf(ComponentInterface::class, $component);
    $page_region = $this->entity;
    self::assertInstanceOf(PageRegion::class, $page_region);
    $page_region->setComponentTree([
      [
        'uuid' => '429f135f-aed3-43a4-ab04-148cf20b93d9',
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Resolved heading',
        ],
      ],
    ]);

    $component_tree = $page_region
      ->normalizeForClientSide()
      ->values['component_tree'];

    self::assertSame(
      ['heading' => 'Resolved heading'],
      $component_tree[0]['inputs'],
    );
    self::assertSame(
      ['heading' => 'Resolved heading'],
      $component_tree[0]['inputs_resolved'],
    );
  }

}
