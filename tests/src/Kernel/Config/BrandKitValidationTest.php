<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\File\FileSystemInterface;
use Drupal\canvas\Entity\BrandKit;
use Drupal\file\Entity\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of Brand Kit entities.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class BrandKitValidationTest extends BetterConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'file',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected static array $propertiesWithRequiredKeys = [];

  /**
   * {@inheritdoc}
   */
  protected static array $propertiesWithOptionalValues = [
    'fonts',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');

    $file_system = \Drupal::service('file_system');
    \assert($file_system instanceof FileSystemInterface);
    $directory = BrandKit::ARTIFACTS_DIRECTORY;
    self::assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS));
    foreach (['inter-regular.woff2', 'inter-variable.woff2'] as $filename) {
      $uri = $directory . $filename;
      $realpath = $file_system->realpath($uri);
      self::assertIsString($realpath);
      self::assertNotFalse(file_put_contents($realpath, 'font-data'));
      $file = File::create(['uri' => $uri]);
      $file->save();
    }

    $this->entity = BrandKit::create([
      'id' => 'global',
      'label' => 'Test',
    ]);
    $this->entity->save();
  }

  #[DataProvider('providerTestEntityShapes')]
  public function testEntityShapes(array $shape, array $expected_errors): void {
    $this->entity = BrandKit::create($shape);
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestEntityShapes(): array {
    $font_directory = BrandKit::ARTIFACTS_DIRECTORY;

    return [
      'Valid: no fonts' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => NULL,
        ],
        [],
      ],
      'Valid: static font entry without axes' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => [
            [
              'id' => '00000000-0000-4000-8000-000000000001',
              'family' => 'Inter',
              'uri' => $font_directory . 'inter-regular.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
            ],
          ],
        ],
        [],
      ],
      'Valid: variable font entry' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => [
            [
              'id' => '00000000-0000-4000-8000-000000000002',
              'family' => 'Inter',
              'uri' => $font_directory . 'inter-variable.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
              'axes' => [
                [
                  'tag' => 'wght',
                  'name' => 'Weight',
                  'min' => 100,
                  'max' => 900,
                  'default' => 400,
                ],
              ],
            ],
          ],
        ],
        [],
      ],
      'Invalid: duplicate variable font axis tags' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => [
            [
              'id' => '00000000-0000-4000-8000-000000000002',
              'family' => 'Inter',
              'uri' => $font_directory . 'inter-variable.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
              'axes' => [
                [
                  'tag' => 'wght',
                  'name' => 'Weight',
                  'min' => 100,
                  'max' => 900,
                  'default' => 400,
                ],
                [
                  'tag' => 'wght',
                  'name' => 'Weight duplicate',
                  'min' => 200,
                  'max' => 800,
                  'default' => 400,
                ],
              ],
            ],
          ],
        ],
        [
          'fonts.0.axes[1].tag' => 'Axis tags must be unique within a font entry.',
        ],
      ],
      'Invalid: variable font axis default out of range' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => [
            [
              'id' => '00000000-0000-4000-8000-000000000002',
              'family' => 'Inter',
              'uri' => $font_directory . 'inter-variable.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
              'axes' => [
                [
                  'tag' => 'wght',
                  'name' => 'Weight',
                  'min' => 100,
                  'max' => 900,
                  'default' => 950,
                ],
              ],
            ],
          ],
        ],
        [
          'fonts[0][axes][0][default]' => 'Axis defaults must stay within the declared min/max range.',
        ],
      ],
      'Invalid: duplicate font IDs' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => [
            [
              'id' => '00000000-0000-4000-8000-000000000003',
              'family' => 'Inter',
              'uri' => $font_directory . 'inter-regular.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
            ],
            [
              'id' => '00000000-0000-4000-8000-000000000003',
              'family' => 'Inter',
              'uri' => $font_directory . 'inter-variable.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
            ],
          ],
        ],
        [
          'fonts[1].id' => 'Font IDs must be unique.',
        ],
      ],
      'Invalid: font URI does not reference a managed file' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'fonts' => [
            [
              'id' => '00000000-0000-4000-8000-000000000004',
              'family' => 'Inter',
              'uri' => $font_directory . 'nonexistent.woff2',
              'format' => 'woff2',
              'weight' => '400',
              'style' => 'normal',
            ],
          ],
        ],
        [
          'fonts[0][uri]' => 'The URI must reference an existing managed file.',
        ],
      ],
    ];
  }

  #[DataProvider('providerInvalidMachineNameCharacters')]
  public function testInvalidMachineNameCharacters(string $machine_name, bool $is_expected_to_be_valid): void {
    parent::testInvalidMachineNameCharacters($machine_name, $is_expected_to_be_valid);
  }

  public static function providerInvalidMachineNameCharacters(): array {
    $cases = parent::providerInvalidMachineNameCharacters();
    unset($cases['VALID: underscore separated']);
    return $cases + [
      'INVALID: not global' => ['not_global', FALSE],
    ];
  }

  /**
   * @see ::testImmutableProperties
   */
  protected function assertValidationErrors(array $expected_messages): void {
    if ($expected_messages === ['' => "The 'id' property cannot be changed."]) {
      $expected_messages['id'] = 'The <em class="placeholder">&quot;something&quot;</em> machine name is not valid.';
    }
    parent::assertValidationErrors($expected_messages);
  }

  public function testMachineNameLength(string $prefix = ''): void {
    $this->markTestSkipped("Currently the only allowed value for the machine name is 'global'.");
  }

  public function testImmutableProperties(array $valid_values = []): void {
    $valid_values['id'] = 'something';
    parent::testImmutableProperties($valid_values);
  }

}
