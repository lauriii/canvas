<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Folder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of Color config entities.
 *
 * @see \Drupal\canvas\Entity\Color
 * @see \Drupal\canvas\Plugin\Validation\Constraint\UniqueColorCssVariableConstraint
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ColorComponentCountConstraint
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ColorValidationTest extends BetterConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    // Canvas dependencies needed for service container compilation.
    'block',
    'datetime',
    'file',
    'field',
    'image',
    'link',
    'options',
    'path',
    'text',
    'filter',
    'ckeditor5',
    'editor',
    'user',
  ];

  /**
   * {@inheritdoc}
   *
   * Color entity properties are all required at the top level.
   * Nested optional fields within 'value' (alpha, hex) are handled
   * by config schema validation, not entity property validation.
   */
  protected static array $propertiesWithOptionalValues = [];

  /**
   * {@inheritdoc}
   *
   * 'value' is a mapping whose required nested keys (colorSpace, components)
   * produce errors at the nested path, not the top-level property path. Pass
   * those as additional expected errors so the base test does not fail when it
   * sets value to [].
   */
  protected static array $propertiesWithRequiredKeys = [];

  /**
   * {@inheritdoc}
   *
   * When 'value' is set to [] the two required nested keys are absent, which
   * fires violations at the 'value' level (as an array of messages). Pass those
   * through $additional_expected_validation_errors_when_missing so the base
   * assertion accounts for them.
   */
  public function testRequiredPropertyKeysMissing(?array $additional_expected_validation_errors_when_missing = NULL): void {
    parent::testRequiredPropertyKeysMissing([
      'value' => [
        'value' => [
          "'colorSpace' is a required key.",
          "'components' is a required key.",
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Install Canvas config (including global BrandKit) because Color::postSave()
    // references BrandKit::loadMultiple() and automatically registers new Colors.
    $this->installConfig('canvas');

    $this->entity = Color::create([
      'name' => 'Primary Blue',
      'cssVariable' => '--color-primary',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.4, 0.8],
        'hex' => '#0066cc',
      ],
      'weight' => 0,
    ]);
    $this->entity->save();
  }

  /**
   * Returns a UUID instead of a machine name.
   *
   * Color uses 'uuid' as its entity key, not 'id'.
   *
   * @see \Drupal\canvas\Entity\Color
   */
  protected function randomMachineName($length = 8): string {
    return \Drupal::service('uuid')->generate();
  }

  #[DataProvider('providerTestEntityShapes')]
  public function testEntityShapes(array $shape, array $expected_errors): void {
    $this->entity = Color::create($shape);
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestEntityShapes(): array {
    return [
      'Valid: sRGB, no alpha' => [
        [
          'name' => 'Secondary Blue',
          'cssVariable' => '--color-secondary',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [0.0, 0.27, 0.6],
            'hex' => '#004499',
          ],
          'weight' => 1,
        ],
        [],
      ],
      'Valid: sRGB, alpha at lower boundary (0.0)' => [
        [
          'name' => 'Transparent',
          'cssVariable' => '--color-transparent',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 1.0, 1.0],
            'hex' => '#ffffff',
            'alpha' => 0.0,
          ],
          'weight' => 2,
        ],
        [],
      ],
      'Valid: sRGB, alpha at upper boundary (1.0)' => [
        [
          'name' => 'Opaque',
          'cssVariable' => '--color-opaque',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [0.0, 0.0, 0.0],
            'hex' => '#000000',
            'alpha' => 1.0,
          ],
          'weight' => 3,
        ],
        [],
      ],
      'Valid: sRGB, alpha at mid-range (0.5)' => [
        [
          'name' => 'Semi-transparent',
          'cssVariable' => '--color-semi',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#ff0000',
            'alpha' => 0.5,
          ],
          'weight' => 4,
        ],
        [],
      ],
      'Valid: HSL, no alpha' => [
        [
          'name' => 'HSL Color',
          'cssVariable' => '--color-hsl',
          'value' => [
            'colorSpace' => 'hsl',
            'components' => [120.0, 100.0, 50.0],
          ],
          'weight' => 5,
        ],
        [],
      ],
      'Valid: HSL, with alpha' => [
        [
          'name' => 'HSL with Alpha',
          'cssVariable' => '--color-hsl-alpha',
          'value' => [
            'colorSpace' => 'hsl',
            'components' => [240.0, 100.0, 50.0],
            'alpha' => 0.75,
          ],
          'weight' => 6,
        ],
        [],
      ],
      'Invalid: cssVariable without -- prefix' => [
        [
          'name' => 'Invalid Variable',
          'cssVariable' => 'color-invalid',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#ff0000',
          ],
          'weight' => 0,
        ],
        [
          'cssVariable' => 'The <em class="placeholder">&quot;color-invalid&quot;</em> is not a valid CSS custom property name.',
        ],
      ],
      'Invalid: cssVariable with spaces' => [
        [
          'name' => 'Invalid Variable Spaces',
          'cssVariable' => '--color invalid',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#ff0000',
          ],
          'weight' => 0,
        ],
        [
          'cssVariable' => 'The <em class="placeholder">&quot;--color invalid&quot;</em> is not a valid CSS custom property name.',
        ],
      ],
      'Invalid: hex missing # prefix' => [
        [
          'name' => 'Invalid Hex',
          'cssVariable' => '--color-hex',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => 'ff0000',
          ],
          'weight' => 0,
        ],
        [
          'value.hex' => 'The <em class="placeholder">&quot;ff0000&quot;</em> is not a valid 6-digit hex color.',
        ],
      ],
      'Invalid: hex too short (3 chars)' => [
        [
          'name' => 'Short Hex',
          'cssVariable' => '--color-short',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#f00',
          ],
          'weight' => 0,
        ],
        [
          'value.hex' => 'The <em class="placeholder">&quot;#f00&quot;</em> is not a valid 6-digit hex color.',
        ],
      ],
      'Invalid: hex too long (8 chars)' => [
        [
          'name' => 'Long Hex',
          'cssVariable' => '--color-long',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#ff0000ff',
          ],
          'weight' => 0,
        ],
        [
          'value.hex' => 'The <em class="placeholder">&quot;#ff0000ff&quot;</em> is not a valid 6-digit hex color.',
        ],
      ],
      'Invalid: alpha above range (1.1)' => [
        [
          'name' => 'High Alpha',
          'cssVariable' => '--color-high',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#ff0000',
            'alpha' => 1.1,
          ],
          'weight' => 0,
        ],
        [
          'value.alpha' => 'Alpha must be between 0 and 1.',
        ],
      ],
      'Invalid: alpha below range (-0.1)' => [
        [
          'name' => 'Low Alpha',
          'cssVariable' => '--color-low',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0],
            'hex' => '#ff0000',
            'alpha' => -0.1,
          ],
          'weight' => 0,
        ],
        [
          'value.alpha' => 'Alpha must be between 0 and 1.',
        ],
      ],
      'Invalid: unknown colorSpace' => [
        [
          'name' => 'Unknown Color Space',
          'cssVariable' => '--color-unknown',
          'value' => [
            'colorSpace' => 'xyz',
            'components' => [1.0, 0.0, 0.0],
          ],
          'weight' => 0,
        ],
        [
          'value.colorSpace' => 'The value you selected is not a valid choice.',
        ],
      ],
      // ColorComponentCount constraint cases.
      'Invalid: sRGB with too few components (2)' => [
        [
          'name' => 'Bad sRGB',
          'cssVariable' => '--color-bad-srgb-few',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0],
          ],
          'weight' => 0,
        ],
        [
          'value.components' => 'The <em class="placeholder">srgb</em> color space requires exactly <em class="placeholder">3</em> component(s), but <em class="placeholder">2</em> were provided.',
        ],
      ],
      'Invalid: sRGB with too many components (4)' => [
        [
          'name' => 'Extra sRGB',
          'cssVariable' => '--color-bad-srgb-many',
          'value' => [
            'colorSpace' => 'srgb',
            'components' => [1.0, 0.0, 0.0, 0.5],
          ],
          'weight' => 0,
        ],
        [
          'value.components' => 'The <em class="placeholder">srgb</em> color space requires exactly <em class="placeholder">3</em> component(s), but <em class="placeholder">4</em> were provided.',
        ],
      ],
      'Invalid: HSL with too few components (2)' => [
        [
          'name' => 'Bad HSL',
          'cssVariable' => '--color-bad-hsl-few',
          'value' => [
            'colorSpace' => 'hsl',
            'components' => [120.0, 100.0],
          ],
          'weight' => 0,
        ],
        [
          'value.components' => 'The <em class="placeholder">hsl</em> color space requires exactly <em class="placeholder">3</em> component(s), but <em class="placeholder">2</em> were provided.',
        ],
      ],
      'Invalid: HSL with too many components (4)' => [
        [
          'name' => 'Extra HSL',
          'cssVariable' => '--color-bad-hsl-many',
          'value' => [
            'colorSpace' => 'hsl',
            'components' => [120.0, 100.0, 50.0, 0.5],
          ],
          'weight' => 0,
        ],
        [
          'value.components' => 'The <em class="placeholder">hsl</em> color space requires exactly <em class="placeholder">3</em> component(s), but <em class="placeholder">4</em> were provided.',
        ],
      ],
    ];
  }

  /**
   * Tests the UniqueColorCssVariableConstraint.
   *
   * When a Color with a CSS variable name already exists, creating another
   * Color with the same CSS variable name should fail validation.
   */
  public function testUniqueColorCssVariableConstraint(): void {
    // The entity from setUp() has cssVariable = '--color-primary'
    // Creating a new Color with the same cssVariable should fail.
    $this->entity = Color::create([
      'name' => 'Duplicate CSS Variable',
      'cssVariable' => '--color-primary',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $this->assertValidationErrors([
      'cssVariable' => 'CSS variable <em class="placeholder">--color-primary</em> is already in use by another color.',
    ]);
  }

  /**
   * Tests the UniqueNamePerFolderTypeConstraint for Color entities.
   *
   * Color implements FolderItemInterface. The UniqueNamePerFolderTypeConstraint
   * checks if a Folder with the given name exists for that config entity type.
   * Since Colors don't organize into Folders, the constraint only triggers if
   * a Folder of type 'color' exists with the same name.
   *
   * This test creates a Folder of type 'color' to trigger the constraint.
   */
  public function testUniqueNamePerFolderTypeConstraint(): void {
    // Create a Folder of type 'color' with the name we want to duplicate.
    // This creates the condition where the constraint will trigger.
    $folder = Folder::create([
      'name' => 'Unique Color Name',
      'configEntityTypeId' => Color::ENTITY_TYPE_ID,
    ]);
    $folder->save();

    // Now creating a Color with the same name should fail because a Folder
    // with that name already exists for the 'color' type.
    $this->entity = Color::create([
      'name' => 'Unique Color Name',
      'cssVariable' => '--color-unique',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $this->assertValidationErrors([
      'name' => 'Name <em class="placeholder">Unique Color Name</em> is not unique in Folder type "<em class="placeholder">color</em>"',
    ]);

    // Clean up the folder.
    $folder->delete();
  }

  /**
   * {@inheritdoc}
   *
   * Override to handle the Color entity's UUID immutability.
   * We need to delete the original entity first to avoid triggering
   * the UniqueColorCssVariableConstraint when testing immutability.
   */
  public function testImmutableProperties(array $valid_values = []): void {
    // Get the constraints for this entity type.
    $constraints = $this->entity->getEntityType()->getConstraints();
    $immutable_properties = \array_key_exists('properties', $constraints['ImmutableProperties'])
      ? $constraints['ImmutableProperties']['properties']
      : $constraints['ImmutableProperties'];

    $this->assertNotEmpty($immutable_properties, 'All config entities should have at least one immutable ID property.');

    foreach ($immutable_properties as $property_name) {
      // Store the original value.
      $original_value = $this->entity->get($property_name);

      // For Color entities, we need to handle UUID immutability specially.
      // Generate a new UUID to try to set.
      $new_uuid = \Drupal::service('uuid')->generate();
      $this->entity->set($property_name, $new_uuid);

      // Validate and check for the immutability error.
      // Note: We don't call assertValidationErrors() here because it also checks
      // schema completeness, which may fail due to the entity being in an invalid state.
      $violations = $this->entity->getTypedData()->validate();
      $violation_messages = [];
      foreach ($violations as $violation) {
        $property_path = $violation->getPropertyPath();
        $violation_messages[$property_path] = (string) $violation->getMessage();
      }

      // Check for the immutability violation on the property.
      $this->assertArrayHasKey('', $violation_messages);
      $this->assertSame("The '$property_name' property cannot be changed.", $violation_messages['']);

      // Restore the original value.
      $this->entity->set($property_name, $original_value);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Skip machine name length tests for Color because it uses UUID as ID,
   * which is auto-assigned and not subject to machine name length rules.
   */
  public function testMachineNameLength(string $prefix = ''): void {
    $this->markTestSkipped('Color uses UUID as ID, which is auto-assigned. Machine name length rules do not apply.');
  }

  /**
   * Tests that updating an existing Color does not duplicate it in BrandKit.
   */
  public function testUpdateDoesNotDuplicateInBrandKit(): void {
    // The entity was created in setUp(). Now update it.
    $original_uuid = $this->entity->id();
    $this->entity->set('name', 'Updated Name');
    $this->entity->save();

    // BrandKit::getColors() queries the DB; the Color appears exactly once.
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $occurrences = array_filter($brand_kit->getColors(), static fn (string $id): bool => $id === $original_uuid);
    $this->assertCount(1, $occurrences, 'Color should appear exactly once in BrandKit after update.');
  }

}
