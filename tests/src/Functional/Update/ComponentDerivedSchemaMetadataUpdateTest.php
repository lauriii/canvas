<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests backfilling `derived_schema_metadata` on pre-existing Components.
 *
 * The fixture seeds two components that together cover every string shape
 * recognized by `PropShape::getTranslatableStringShape()` — plain string, the
 * 4 URI-esque formats, rich text, multi-line, an array of strings — plus
 * non-translatable props (string enum, email format, integer).
 *
 * Deprecations are ignored because the `canvas_test_sdc` SDCs the fixture
 * enables include object props relying on the to-be-removed same-reference
 * FieldTypeObjectPropsExpression (https://www.drupal.org/node/3563451).
 *
 * @legacy-covers \canvas_post_update_0021_store_prop_derived_schema_metadata
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[IgnoreDeprecations]
final class ComponentDerivedSchemaMetadataUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * What the backfill must produce, per component, per version, per prop.
   *
   * The version that is *active when the backfill runs* is described by the
   * live implementation, so its props get the live-derived `string_shape` —
   * or an empty mapping for the shapes that do not hold translatable text
   * (string enum, email, integer). What shape a prop had when a *past* version
   * was created is unknowable: its props get an empty mapping (not
   * translatable). Component instances referencing a past version become
   * translatable when automatic component instance updating moves them to the
   * active version.
   *
   * Because `derived_schema_metadata` is excluded from the version hash, the
   * backfill itself creates no versions and changes no version ids.
   *
   * @see \Drupal\canvas\Entity\Component::preSave()
   * @see \Drupal\canvas\PropShape\PropShape::getTranslatableStringShape()
   */
  private const array EXPECTED = [
    'js.string_shapes' => [
      // The active version: live-derived, one prop per string shape a code
      // component can express.
      '61c2c2d0c08e99fb' => [
        'plain' => ['string_shape' => []],
        'uri' => ['string_shape' => ['format' => 'uri']],
        'uri_reference' => ['string_shape' => ['format' => 'uri-reference']],
        'iri' => ['string_shape' => ['format' => 'iri']],
        'iri_reference' => ['string_shape' => ['format' => 'iri-reference']],
        'rich_text' => ['string_shape' => ['contentMediaType' => 'text/html', 'x-formatting-context' => 'block']],
        'string_enum' => [],
        'email' => [],
        'quantity' => [],
        // An array of translatable items is translatable.
        'strings' => ['string_shape' => []],
      ],
      // The past version: unknowable, so every prop gets an empty mapping —
      // even though the props were identical when it was created.
      '16b01eba802743e6' => [
        'plain' => [],
        'uri' => [],
        'uri_reference' => [],
        'iri' => [],
        'iri_reference' => [],
        'rich_text' => [],
        'string_enum' => [],
        'email' => [],
        'quantity' => [],
        'strings' => [],
      ],
    ],
    // The multi-line `pattern` shape is not expressible in a code component:
    // an SDC covers it.
    'sdc.canvas_test_sdc.code-example' => [
      '9ba0b4240a0e8ffd' => [
        'language' => [],
        'code' => ['string_shape' => ['pattern' => '(.|\r?\n)*']],
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/derived_schema_metadata/derived-schema-metadata-fixture.php';
  }

  /**
   * Tests that every version's props gain `derived_schema_metadata`.
   */
  public function testComponentDerivedSchemaMetadataBackfill(): void {
    // Before the update path: no version of either Component has
    // `derived_schema_metadata` for any prop.
    foreach (self::EXPECTED as $component_id => $expected_versions) {
      $component = Component::load($component_id);
      self::assertInstanceOf(Component::class, $component);
      self::assertSame(\array_keys($expected_versions), $component->getVersions(), $component_id);
      foreach ($component->getVersions() as $version) {
        $component->loadVersion($version);
        foreach ($component->getSettings()['prop_field_definitions'] as $prop_name => $prop_field_definition) {
          self::assertArrayNotHasKey('derived_schema_metadata', $prop_field_definition, "$component_id@$version:$prop_name");
        }
      }
    }

    $this->runUpdates();

    $actual = [];
    foreach (\array_keys(self::EXPECTED) as $component_id) {
      $component = Component::load($component_id);
      self::assertInstanceOf(Component::class, $component);
      foreach ($component->getVersions() as $version) {
        $component->loadVersion($version);
        foreach ($component->getSettings()['prop_field_definitions'] as $prop_name => $prop_field_definition) {
          $actual[$component_id][$version][$prop_name] = $prop_field_definition['derived_schema_metadata'];
        }
      }
      // The backfill created no versions and the required key is now present
      // everywhere: the entity is valid.
      self::assertEntityIsValid($component);
    }
    self::assertSame(self::EXPECTED, $actual);
  }

}
