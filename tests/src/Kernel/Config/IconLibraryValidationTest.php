<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\IconLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of icon library entities.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class IconLibraryValidationTest extends BetterConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    // Provides the `canvas_test` icon pack, to test pack id collisions.
    'canvas_test_icons',
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
    'description',
    'template',
    'assets',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');

    $this->entity = IconLibrary::create([
      'id' => 'test_library',
      'label' => 'Test',
    ]);
    $this->entity->save();
  }

  #[DataProvider('providerTestEntityShapes')]
  public function testEntityShapes(array $shape, array $expected_errors): void {
    $this->entity = IconLibrary::create($shape);
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestEntityShapes(): array {
    return [
      'Valid: no assets' => [
        [
          'id' => 'test_library',
          'label' => 'Test',
          'assets' => NULL,
        ],
        [],
      ],
      'Valid: assets, description, and template' => [
        [
          'id' => 'test_library',
          'label' => 'Test',
          'description' => 'A library of icons.',
          'template' => '<svg {{ attributes }}>{{ content }}</svg>',
          'assets' => [
            [
              'name' => 'star.svg',
              'uri' => 'public://canvas/icons/test_library/star.svg',
            ],
            [
              'name' => 'heart.svg',
              'uri' => 'public://canvas/icons/test_library/heart.svg',
            ],
          ],
        ],
        [],
      ],
      'Invalid: asset uri outside the library directory' => [
        [
          'id' => 'test_library',
          'label' => 'Test',
          'assets' => [
            [
              'name' => 'star.svg',
              'uri' => 'public://canvas/icons/other_library/star.svg',
            ],
          ],
        ],
        [
          'assets[0][uri]' => "Asset URIs must be located in this icon library's directory, <em class=\"placeholder\">public://canvas/icons/test_library/</em>.",
        ],
      ],
      'Invalid: asset name without .svg extension' => [
        [
          'id' => 'test_library',
          'label' => 'Test',
          'assets' => [
            [
              'name' => 'star.png',
              'uri' => 'public://canvas/icons/test_library/star.png',
            ],
          ],
        ],
        [
          'assets[0][name]' => 'Asset names must consist of letters, numbers, dots, dashes, and underscores, and use the .svg extension.',
        ],
      ],
      'Invalid: asset name with path separator' => [
        [
          'id' => 'test_library',
          'label' => 'Test',
          'assets' => [
            [
              'name' => '../star.svg',
              'uri' => 'public://canvas/icons/test_library/star.svg',
            ],
          ],
        ],
        [
          'assets[0][name]' => 'Asset names must consist of letters, numbers, dots, dashes, and underscores, and use the .svg extension.',
        ],
      ],
      'Invalid: duplicate asset names' => [
        [
          'id' => 'test_library',
          'label' => 'Test',
          'assets' => [
            [
              'name' => 'star.svg',
              'uri' => 'public://canvas/icons/test_library/star.svg',
            ],
            [
              'name' => 'star.svg',
              'uri' => 'public://canvas/icons/test_library/star2.svg',
            ],
          ],
        ],
        [
          'assets[1].name' => 'Asset names must be unique.',
        ],
      ],
      'Invalid: id collides with an extension-provided icon pack' => [
        [
          'id' => 'canvas_test',
          'label' => 'Test',
        ],
        [
          'id' => 'This ID is already used by an icon pack provided by an installed extension.',
        ],
      ],
    ];
  }

}
