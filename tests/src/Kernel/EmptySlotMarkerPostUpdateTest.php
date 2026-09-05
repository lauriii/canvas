<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installing the empty-slot marker component on existing sites.
 *
 * @legacy-covers canvas_post_update_0031_install_empty_slot_marker()
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class EmptySlotMarkerPostUpdateTest extends CanvasKernelTestBase {

  public function testInstallsMarkerOnExistingSites(): void {
    \Drupal::moduleHandler()->loadInclude('canvas', 'post_update.php');
    $storage = \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID);

    // An existing site predating the marker: the config entity is absent.
    Component::load(Marker::EMPTY_SLOT_COMPONENT_ID)?->delete();
    self::assertEmpty($storage->loadMultiple([Marker::EMPTY_SLOT_COMPONENT_ID]));

    canvas_post_update_0031_install_empty_slot_marker();
    $marker = Component::load(Marker::EMPTY_SLOT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);
    // The created marker matches the shipped config, so a site that ran the
    // update and a fresh install agree.
    // @see config/install/canvas.component.marker.empty_slot.yml
    self::assertSame(Marker::SOURCE_PLUGIN_ID, $marker->get('source'));
    self::assertSame(Marker::EMPTY_SLOT_LOCAL_ID, $marker->get('source_local_id'));
    self::assertSame('3b12c0b99a6caecc', $marker->getActiveVersion());
    // Markers are hidden from the component library by the editor, not by
    // their status, exactly like the page content marker.
    // @see ui/src/services/pageVariants.ts (isMarkerComponentType)
    self::assertTrue($marker->status());

    // Idempotent: a site that already has it is untouched.
    canvas_post_update_0031_install_empty_slot_marker();
    self::assertCount(1, $storage->loadMultiple([Marker::EMPTY_SLOT_COMPONENT_ID]));
  }

}
