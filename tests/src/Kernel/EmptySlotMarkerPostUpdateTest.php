<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installing the empty-slot marker component on existing sites.
 *
 * @legacy-covers canvas_post_update_0026_install_empty_slot_marker()
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class EmptySlotMarkerPostUpdateTest extends CanvasKernelTestBase {

  public function testInstallsMarkerOnExistingSites(): void {
    \Drupal::moduleHandler()->loadInclude('canvas', 'post_update.php');

    // An existing site predating the marker: the config entity is absent.
    Component::load(Component::EMPTY_SLOT_MARKER_ID)?->delete();
    self::assertNull(Component::load(Component::EMPTY_SLOT_MARKER_ID));

    canvas_post_update_0026_install_empty_slot_marker();
    $marker = Component::load(Component::EMPTY_SLOT_MARKER_ID);
    self::assertInstanceOf(Component::class, $marker);
    // The marker must never be placeable from the library.
    self::assertFalse($marker->status());

    // Idempotent: a site that already has it is untouched.
    canvas_post_update_0026_install_empty_slot_marker();
    self::assertInstanceOf(Component::class, Component::load(Component::EMPTY_SLOT_MARKER_ID));
  }

}
