<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Component;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversMethod(CanvasConfigUpdater::class, 'needsComponentVersionHashRecomputationForListFloatDefaultValue')]
#[CoversMethod(CanvasConfigUpdater::class, 'updateListFloatComponentVersionHash')]
#[CoversMethod(CanvasConfigUpdater::class, 'recomputeActiveVersionHash')]
#[CoversFunction('canvas_post_update_0019_recompute_list_float_component_version_hashes')]
#[Group('canvas')]
#[Group('canvas_data_model')]
final class ComponentVersionHashTypedDataCastUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  private const string COMPONENT_ID = 'sdc.canvas_test_list_float.heading';

  // The hash computed (incorrectly) from the un-cast native integer default
  // value, and the corrected hash computed from the config-cast string value.
  private const string OLD_VERSION = 'e5103d546cfaa008';
  private const string NEW_VERSION = '871c4e77625ab1d1';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/version_hash_typed_data/component-with-list-float-prop.php';
  }

  /**
   * Tests that a stale component version hash is recomputed and re-keyed.
   *
   * A `number` + `enum` prop maps to the `list_float` field type, whose
   * `field.value.list_float.value` is typed `string` in core. Before the fix,
   * the default value was hashed as the native int `2` at generation time but
   * as the string `"2"` after a config round-trip, leaving an `active_version`
   * that no longer matches the recomputed hash.
   */
  public function test(): void {
    // Before: the stored active version is the incorrect (un-cast) hash, so the
    // component fails validation.
    $component_before = Component::load(self::COMPONENT_ID);
    \assert($component_before instanceof Component);
    self::assertSame(self::OLD_VERSION, $component_before->getActiveVersion());
    // The component is invalid for one specific reason: the stored (un-cast)
    // active version hash no longer matches the hash recomputed from the
    // config-cast settings. Assert that exact violation — recording core's
    // message here means we will notice if core ever stops casting the value.
    $violation_messages = \array_map(
      static fn($violation): string => (string) $violation->getMessage(),
      \iterator_to_array($component_before->getTypedData()->validate()),
    );
    self::assertSame([
      \sprintf('The version %s does not match the hash of the settings for this version, expected %s.', self::OLD_VERSION, self::NEW_VERSION),
    ], $violation_messages);

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
