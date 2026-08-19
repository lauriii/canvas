<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\canvas\Traits\FallbackComponentTreeConfigValidationTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Page Variant Validation.
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class PageVariantValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use GenerateComponentConfigTrait;
  use FallbackComponentTreeConfigValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // Test components.
    'block',
    'canvas_test_sdc',
  ];

  /**
   * The optional properties.
   *
   * The description may be omitted; the tree may be `null` (but then lacks
   * the required "Page content" marker).
   *
   * @var array|string[]
   */
  protected static array $propertiesWithOptionalValues = [
    'description',
    'component_tree',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Installs the "Page content" marker component and `canvas.settings`.
    // @see config/install/canvas.component.marker.page_content.yml
    $this->installConfig(['canvas']);
    $this->generateComponentConfig();
    $this->entity = PageVariant::create([
      'id' => 'test_variant',
      'label' => 'Test variant',
      'description' => 'A named full-page layout.',
      'component_tree' => [
        [
          'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => 'Hello, world!',
          ],
        ],
        [
          'uuid' => '93af433a-8ab0-4dd9-912a-73a99c882347',
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => Component::load(Marker::PAGE_CONTENT_COMPONENT_ID)?->getActiveVersion(),
          'parent_uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'slot' => 'the_body',
          'inputs' => [],
        ],
      ],
    ]);
    $this->entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function testEntityIsValid(): void {
    parent::testEntityIsValid();

    $this->assertSame('test_variant', $this->entity->id());

    // Also validate config dependencies are computed correctly: only the
    // placed components — a page variant never depends on a theme.
    $this->assertSame(
      [
        'config' => [
          'canvas.component.marker.page_content',
          'canvas.component.sdc.canvas_test_sdc.props-slots',
        ],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'config' => [
        'canvas.component.marker.page_content',
        'canvas.component.sdc.canvas_test_sdc.props-slots',
      ],
      'module' => [
        'canvas',
        'canvas_test_sdc',
      ],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * Tests invalid component tree.
   */
  #[DataProvider('providerInvalidComponentTree')]
  public function testInvalidComponentTree(array $component_tree, array $expected_messages): void {
    \assert($this->entity instanceof PageVariant);
    $this->entity->setComponentTree($component_tree);
    $this->assertValidationErrors($expected_messages);
  }

  public static function providerInvalidComponentTree(): \Generator {
    $marker = [
      'uuid' => '93af433a-8ab0-4dd9-912a-73a99c882347',
      'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
      // @see config/install/canvas.component.marker.page_content.yml
      'component_version' => '3b12c0b99a6caecc',
      'inputs' => [],
    ];

    yield 'zero "Page content" markers' => [
      'component_tree' => [
        [
          'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => 'Hello, world!',
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree' => 'A page variant must contain a "Page content" placement.',
      ],
    ];

    yield 'multiple "Page content" markers' => [
      'component_tree' => [
        $marker,
        ['uuid' => 'fa9ff0a8-e23a-492a-ab14-5460611fa2c1'] + $marker,
      ],
      'expected_messages' => [
        'component_tree' => 'A page variant must contain only one "Page content" placement, but found 2.',
      ],
    ];

    yield 'using EntityFieldPropSource' => [
      'component_tree' => [
        $marker,
        [
          'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree' => "The 'entity-field' prop source type must be absent.",
      ],
    ];

    yield 'invalid version' => [
      'component_tree' => [
        $marker,
        [
          'uuid' => '4f785025-9bd9-4752-9dd6-068b957b03ee',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'abc',
          'inputs' => [
            'heading' => 'Hello, world!',
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree.1.component_version' => "'abc' is not a version that exists on component config entity 'sdc.canvas_test_sdc.props-no-slots'. Available versions: 'd34b93534777207a'.",
      ],
    ];
  }

  /**
   * The site default page variant cannot be disabled.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\SiteDefaultPageVariantEnabledConstraint
   */
  public function testCannotDisableSiteDefault(): void {
    // A variant that is not the site default may be disabled.
    $this->entity->setStatus(FALSE);
    $this->assertValidationErrors([]);

    $this->config('canvas.settings')->set('default_page_variant', 'test_variant')->save();
    $this->assertValidationErrors([
      'status' => 'The site default page variant cannot be disabled. Set another variant as the default first.',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function testRequiredPropertyValuesMissing(?array $additional_expected_validation_errors_when_missing = NULL): void {
    parent::testRequiredPropertyValuesMissing([
      // An empty tree is allowed by the schema (`nullable: true`), but it
      // cannot satisfy the "exactly one Page content marker" constraint.
      'component_tree' => [
        'component_tree' => 'A page variant must contain a "Page content" placement.',
      ],
    ]);
  }

}
