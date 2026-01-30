<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\canvas\ComponentTreeInputExtractor;
use Drupal\canvas\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
#[CoversClass(ComponentTreeInputExtractor::class)]
final class ComponentTreeInputExtractorTest extends KernelTestBase {

  use PageTrait;

  protected static $modules = [
    'file',
    'image',
    'canvas',
    'canvas_test_sdc',
    'canvas_test_search',
    'datetime',
    'link',
    'media',
    'filter',
    'text',
    'media_library',
    'options',
    'path',
    'system',
    'user',
    'views',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_test_search']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();
  }

  #[DataProvider('componentsAndInputs')]
  public function testExtractedInputs(array $components, array $expected_inputs): void {
    \assert(array_is_list($components));

    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Welcome to our site with a cool meta description',
      'path' => ['alias' => '/homepage'],
      'components' => $components,
    ]);
    $violations = $page->validate()->filterByFields(['path']);
    self::assertCount(
      0,
      $violations,
      var_export(self::violationsToArray($violations), TRUE)
    );

    $inputs = $this->container->get(ComponentTreeInputExtractor::class)->extract($page, ['id', 'class', 'cssClasses', 'extraClasses']);
    self::assertEquals($expected_inputs, $inputs);
  }

  public static function componentsAndInputs(): iterable {
    $uuid_generator = new UuidGenerator();

    yield 'empty' => [
      'components' => [],
      'expected_inputs' => [],
    ];

    $uuid = $uuid_generator->generate();
    yield 'canvas_test_sdc.props-slots' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
      ],
      'expected_inputs' => [
        $uuid => [
          'Welcome to the site!',
        ],
      ],
    ];

    yield 'sdc.canvas_test_search.has-ignored-props test ignored props' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_search.has-ignored-props',
          'inputs' => [
            'text' => 'Hello World!',
            'id' => 'abc123',
            'class' => 'my-class',
            'cssClasses' => 'my-css-class another-css-class',
            'ariaLabel' => 'An ARIA label',
            'size' => 'large',
          ],
        ],
      ],
      'expected_inputs' => [
        $uuid => [
          'Hello World!',
          'An ARIA label',
        ],
      ],
    ];

    yield 'sdc.canvas_test_search.has-all-ignored-props test ignored props' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_search.has-all-ignored-props',
          'inputs' => [
            'id' => 'abc123',
            'class' => 'my-class',
            'cssClasses' => 'my-css-class another-css-class',
          ],
        ],
      ],
      'expected_inputs' => [],
    ];

    yield 'js.canvas_test_search_paragraph' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'js.canvas_test_search_paragraph',
          'inputs' => [
            'text' => 'This is some text!',
          ],
        ],
      ],
      'expected_inputs' => [
        $uuid => [
          'This is some text!',
        ],
      ],
    ];

    $child_uuid = $uuid_generator->generate();
    yield 'inputs in slotted components' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
        [
          'uuid' => $child_uuid,
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'parent' => $uuid,
          'slot' => 'the_body',
          'inputs' => [
            'heading' => 'This is a slotted component!',
          ],
        ],
      ],
      'expected_inputs' => [
        $uuid => [
          'Welcome to the site!',
        ],
        $child_uuid => [
          'This is a slotted component!',
        ],
      ],
    ];

    // For blocks we don't get any input, but for those we probably want the
    // "Rendered item" processor instead.
    yield 'block' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'block.system_branding_block',
          'inputs' => [
            'label' => 'Site branding',
            'label_display' => '',
            'use_site_logo' => TRUE,
            'use_site_name' => FALSE,
            'use_site_slogan' => FALSE,
          ],
        ],
      ],
      'expected_inputs' => [],
    ];
  }

}
