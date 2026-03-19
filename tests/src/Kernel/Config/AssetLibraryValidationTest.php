<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Component\Utility\Crypt;
use Drupal\canvas\Entity\AssetLibrary;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of Asset Library entities.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class AssetLibraryValidationTest extends BetterConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'file',
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore property.defaultValue
   */
  protected static array $propertiesWithRequiredKeys = [
    'css' => [
      "'original' is a required key.",
      "'compiled' is a required key.",
    ],
    'js' => [
      "'original' is a required key.",
      "'compiled' is a required key.",
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static array $propertiesWithOptionalValues = [
    'css',
    'js',
    'imports',
    'assets',
    'shared',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entity = AssetLibrary::create([
      'id' => 'global',
      'label' => 'Test',
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'js' => [
        'original' => 'console.log( "Test" )',
        'compiled' => 'console.log("Test")',
      ],
    ]);
    $this->entity->save();
  }

  public function testEntityAssets(): void {
    $css = $this->entity->get('css')['compiled'];
    $js = $this->entity->get('js')['compiled'];
    $css_hash = Crypt::hmacBase64($css, $this->entity->uuid());
    $js_hash = Crypt::hmacBase64($js, $this->entity->uuid());

    self::assertStringEqualsFile('assets://canvas/' . $css_hash . '.css', $css);
    self::assertStringEqualsFile('assets://canvas/' . $js_hash . '.js', $js);
  }

  /**
   * Tests different permutations of entity values.
   *
   * @param array $shape
   *   Array of entity values.
   * @param array $expected_errors
   *   Expected validation errors.
   */
  #[DataProvider('providerTestEntityShapes')]
  public function testEntityShapes(array $shape, array $expected_errors): void {
    $this->entity = AssetLibrary::create($shape);
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestEntityShapes(): array {
    return [
      'Valid: no JS or CSS' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
        ],
        [],
      ],
      'Valid: complete library with all properties' => [
        [
          'id' => 'global',
          'label' => 'Complete Test Library',
          'css' => [
            'original' => '.hero { display: flex; align-items: center; }',
            'compiled' => '.hero{display:flex;align-items:center;}',
          ],
          'js' => [
            'original' => 'import { motion } from "motion";\nconsole.log("Canvas ready");',
            'compiled' => 'import{motion}from"motion";console.log("Canvas ready");',
          ],
          'imports' => [
            [
              'name' => 'motion',
              'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/motion.js',
            ],
            [
              'name' => 'react',
              'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/react.js',
            ],
          ],
          'assets' => [
            [
              'name' => '@/components/hero/index.js',
              'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'components/hero/index.js',
            ],
            [
              'name' => '@/utils/helpers.js',
              'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'utils/helpers.js',
            ],
          ],
          'shared' => [
            [
              'name' => '@/shared/constants.js',
              'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'shared/constants.js',
            ],
          ],
        ],
        [],
      ],
      'Invalid: import manifest entry has blank name' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
          'imports' => [
            [
              'name' => '',
              'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/motion.js',
            ],
          ],
        ],
        [
          'imports.0.name' => 'This value should not be blank.',
        ],
      ],
      'Invalid: asset manifest entry has blank uri' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
          'assets' => [
            [
              'name' => '@/components/hero/index.js',
              'uri' => '',
            ],
          ],
        ],
        [
          'assets.0.uri' => [
            'This value should not be blank.',
            'This value should be of the correct primitive type.',
          ],
        ],
      ],
      'Invalid: compiled without source' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => [
            'compiled' => '.disgusting {color: puke-green;}',
          ],
          'js' => [
            'compiled' => 'console.log( "To paraphrase: The only source of compiled is original." )',
          ],
        ],
        [
          'css' => "'original' is a required key.",
          'js' => "'original' is a required key.",
        ],
      ],
      'Invalid: source without compiled' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => [
            'original' => '.gracie-dog { color: black-brown; }',
          ],
          'js' => [
            'original' => 'console.log( "I am the source of nothing!" )',
          ],
        ],
        [
          'css' => "'compiled' is a required key.",
          'js' => "'compiled' is a required key.",
        ],
      ],
      'Invalid: incorrect key under css and js' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => [
            'snazzy_css' => '.test { opacity: 30; }',
          ],
          'js' => [
            'snazzy_js' => 'console.log( "🎤Is this thing on?" )',
          ],
        ],
        [
          'css' => [
            "'original' is a required key.",
            "'compiled' is a required key.",
          ],
          'css.snazzy_css' => "'snazzy_css' is not a supported key.",
          'js' => [
            "'original' is a required key.",
            "'compiled' is a required key.",
          ],
          'js.snazzy_js' => "'snazzy_js' is not a supported key.",
        ],
      ],
      'Invalid: empty imports array' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
          'imports' => [],
        ],
        [
          'imports' => 'This value should not be blank.',
        ],
      ],
      'Invalid: empty assets array' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
          'assets' => [],
        ],
        [
          'assets' => 'This value should not be blank.',
        ],
      ],
      'Invalid: import manifest entry has non-public URI scheme' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
          'imports' => [
            [
              'name' => 'motion',
              'uri' => 'private://vendor/motion.js',
            ],
          ],
        ],
        [
          'imports.0.uri' => '\'private\' is not allowed, must be one of the allowed schemes: public.',
        ],
      ],
      'Invalid: asset manifest entry has non-public URI scheme' => [
        [
          'id' => 'global',
          'label' => 'Test',
          'css' => NULL,
          'js' => NULL,
          'assets' => [
            [
              'name' => '@/components/hero/index.js',
              'uri' => 'private://components/hero/index.js',
            ],
          ],
        ],
        [
          'assets.0.uri' => '\'private\' is not allowed, must be one of the allowed schemes: public.',
        ],
      ],
    ];
  }

  /**
 * Tests invalid machine name characters.
 */
  #[DataProvider('providerInvalidMachineNameCharacters')]
  public function testInvalidMachineNameCharacters(string $machine_name, bool $is_expected_to_be_valid): void {
    // @todo Change the autogenerated stub
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
   *
   * Due to allowing only a single config entity, we need to do something extra
   * special to allow this test to pass.
   */
  protected function assertValidationErrors(array $expected_messages): void {
    if ($expected_messages === ['' => "The 'id' property cannot be changed."]) {
      $expected_messages['id'] = 'The <em class="placeholder">&quot;something&quot;</em> machine name is not valid.';
    }
    parent::assertValidationErrors($expected_messages);
  }

  public function testMachineNameLength(string $prefix = ''): void {
    // \Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase::testMachineNameLength()
    // does not allow overriding the expected message for an invalid machine
    // name. Since we only allow 1 possible value it seems reasonable to skip this
    // test until we support other machine names.
    $this->markTestSkipped("Currently the only allowed value for the machine name is 'global'.");
  }

  public function testImmutableProperties(array $valid_values = []): void {
    $valid_values['id'] = 'something';
    parent::testImmutableProperties($valid_values);
  }

}
