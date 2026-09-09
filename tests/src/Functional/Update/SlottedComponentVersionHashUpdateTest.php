<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Component;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversMethod(CanvasConfigUpdater::class, 'needsComponentVersionHashRecomputationForSlotMetadata')]
#[CoversMethod(CanvasConfigUpdater::class, 'updateSlottedComponentVersionHash')]
#[CoversMethod(CanvasConfigUpdater::class, 'recomputeActiveVersionHash')]
#[CoversFunction('canvas_post_update_0031_recompute_slotted_component_version_hashes')]
#[Group('canvas')]
#[Group('canvas_data_model')]
final class SlottedComponentVersionHashUpdateTest extends CanvasUpdatePathTestBase {

  use ConstraintViolationsTestTrait;

  protected $defaultTheme = 'stark';

  private const string COMPONENT_ID = 'js.slotted_component';

  // The hash computed while a slot's title and first example were part of the
  // hashed data, and the corrected hash computed from the slot name alone.
  private const string OLD_VERSION = '800f65a3ec61c647';
  private const string NEW_VERSION = '9226f2d36be768b4';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/slot_metadata_version_hash/component-with-slot.php';
  }

  /**
   * Tests that a version hash including slot metadata is recomputed.
   *
   * Slot metadata (title, examples) used to be part of the version hash, so
   * every Component with at least one slot stores an `active_version` that no
   * longer matches the hash recomputed from the slot names alone.
   */
  public function test(): void {
    // Before: the stored active version still includes the slot metadata, so
    // the component fails validation.
    $component_before = Component::load(self::COMPONENT_ID);
    \assert($component_before instanceof Component);
    self::assertSame(self::OLD_VERSION, $component_before->getActiveVersion());
    self::assertSame([
      'active_version' => \sprintf('The version %s does not match the hash of the settings for this version, expected %s.', self::OLD_VERSION, self::NEW_VERSION),
    ], self::violationsToArray($component_before->getTypedData()->validate()));

    $this->runUpdates();

    // After: the active version is the corrected hash and the component is
    // valid. The old hash is preserved as a past version so existing component
    // instances that reference it keep resolving.
    $component_after = Component::load(self::COMPONENT_ID);
    \assert($component_after instanceof Component);
    self::assertSame(self::NEW_VERSION, $component_after->getActiveVersion());
    self::assertContains(self::OLD_VERSION, $component_after->getVersions());
    self::assertEntityIsValid($component_after);
  }

}
