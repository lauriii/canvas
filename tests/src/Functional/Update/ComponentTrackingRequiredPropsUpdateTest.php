<?php

declare(strict_types=1);

// cspell:ignore hasnot Requiredness

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;

/**
 * @covers canvas_post_update_0001_track_props_have_required_flag_in_components()
 * @group canvas
 */
final class ComponentTrackingRequiredPropsUpdateTest extends CanvasUpdatePathTestBase {

  use ComponentTreeItemListInstantiatorTrait;

  protected $defaultTheme = 'stark';

  /**
   * The 5 test cases to test the update path, each needs a generated Component.
   *
   * @see tests/fixtures/update/tracking-required/generate-components-with-multiple-versions.php
   */
  public const TEST_CASES = [
    'case_a__active_hasnot_required__past_hasnot_required' => '>1 version, active NOT required, past NOT required',
    'case_b__active_hasnot_required__past_empty' => '1 version, active NOT required',
    'case_c__active_has_required__past_hasnot_required' => '>1 version, active required, past NOT required',
    // These cases do NOT need updating!
    'case_d__active_has_required__past_empty' => '1 version, active required',
    'case_e__active_has_required__past_has_required' => '>1 version, active required, past required',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/tracking-required/tracking-required-fixture.php';
  }

  private function assertExpectedVersionsAndRequiredness(string $component_id, array $expected_info): void {
    $before = Component::load($component_id);
    self::assertInstanceOf(Component::class, $before);
    self::assertSame(array_keys($expected_info), $before->getVersions(), $component_id);
    foreach ($before->getVersions() as $version) {
      $before->loadVersion($version);
      $has_required_key = array_key_exists('required', $before->getSettings()['prop_field_definitions']['title']);
      self::assertSame($expected_info[$version], $has_required_key, $component_id);
    }
  }

  /**
   * Tests updated intermediate dependencies.
   */
  public function testComponentTrackingRequiredProps(): void {
    // For each Component test case, we check
    // - before the update path: which versions exist, and whether in that
    //   version the `required` flag is present for the `title` prop
    // - after: the same — but note that there could be an additional version!
    $entities_under_test = [
      'js.case_a__active_hasnot_required__past_hasnot_required' => [
        'before' => [
          '3832b735acd1c5ad' => FALSE,
          '7929b726e293a593' => FALSE,
        ],
        'after' => [
          // New active version.
          '5bd4cdd8dea16da1' => TRUE,
          // Same as before.
          '3832b735acd1c5ad' => TRUE,
          '7929b726e293a593' => TRUE,
        ],
      ],
      'js.case_b__active_hasnot_required__past_empty' => [
        'before' => [
          '7929b726e293a593' => FALSE,
        ],
        'after' => [
          // New active version.
          'fd6e432ac8bda84e' => TRUE,
          // Same as before.
          '7929b726e293a593' => TRUE,
        ],
      ],
      'js.case_c__active_has_required__past_hasnot_required' => [
        'before' => [
          '3832b735acd1c5ad' => TRUE,
          '7929b726e293a593' => FALSE,
        ],
        'after' => [
          // New active version.
          '5bd4cdd8dea16da1' => TRUE,
          // Same as before.
          '3832b735acd1c5ad' => TRUE,
          '7929b726e293a593' => TRUE,
        ],
      ],
      'js.case_d__active_has_required__past_empty' => [
        'before' => [
          '7929b726e293a593' => TRUE,
        ],
        'after' => [
          // New active version.
          'fd6e432ac8bda84e' => TRUE,
          // Same as before.
          '7929b726e293a593' => TRUE,
        ],
      ],
      'js.case_e__active_has_required__past_has_required' => [
        'before' => [
          '3832b735acd1c5ad' => TRUE,
          '7929b726e293a593' => TRUE,
        ],
        'after' => [
          // New active version.
          '5bd4cdd8dea16da1' => TRUE,
          // Same as before.
          '3832b735acd1c5ad' => TRUE,
          '7929b726e293a593' => TRUE,
        ],
      ],
    ];

    foreach ($entities_under_test as $component_id => ['before' => $expected_info]) {
      self::assertExpectedVersionsAndRequiredness($component_id, $expected_info);
    }

    $this->runUpdates();

    foreach ($entities_under_test as $component_id => ['after' => $expected_info]) {
      self::assertExpectedVersionsAndRequiredness($component_id, $expected_info);
    }
  }

}
