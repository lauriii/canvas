<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

// cspell:ignore Szia világ

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
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

  /**
   * A translation override plus a delta-keyed tree must not break validation.
   *
   * The languages involved are entirely unremarkable: the site default is
   * English and so is the page variant -- nothing ever changes langcodes.
   * Merely translating the variant while a delta-keyed tree exists is enough,
   * because the two trees being merged are keyed differently:
   * - a saved component_tree is re-keyed by UUID in preSave(), but an
   *   auto-save draft never runs preSave(), so it keeps the delta keys the
   *   client sends;
   * - a config translation override always targets component instances by
   *   their UUID sequence key, and carries only the translated inputs.
   *
   * CanvasConfigEntityTranslationsAreValidConstraintValidator simulates
   * Config::setOverriddenData() by merging override onto base. Unless the
   * base is re-keyed by UUID first, NestedArray::mergeDeepArray() sees the two
   * as disjoint and appends the override's sparse entries as component
   * instances of their own -- no component_id, no component_version -- which
   * trips the assertions in ComponentInputsMapping when the merged result is
   * validated against config schema.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::preSave()
   * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
   * @see https://www.drupal.org/i/3591850
   */
  public function testValidationWithTranslationOverrideAndDeltaKeyedTree(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['language', 'config_translation']);
    ConfigurableLanguage::createFromLangcode('hu')->save();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->reset();

    // Plain setup: the site default language and the entity langcode agree.
    self::assertSame('en', $language_manager->getDefaultLanguage()->getId());
    self::assertSame('en', $this->entity->language()->getId());

    $config_name = $this->entity->getConfigDependencyName();
    $stored_tree = $this->config($config_name)->get('component_tree');
    \assert(\is_array($stored_tree));
    $uuid = '4f785025-9bd9-4752-9dd6-068b957b03ee';
    // Saving re-keyed the stored tree by UUID.
    self::assertArrayHasKey($uuid, $stored_tree);

    // Reconstruct the delta-keyed shape an auto-save draft has: same
    // component instances, but sequence keys as sent by the client, because
    // drafts are stored and rehydrated without ever running preSave(). This
    // must happen before the translation exists; with a translation present,
    // ::setComponentTree() converts to sequence keys right away -- which is
    // exactly why only pre-existing drafts can carry this shape.
    $draft = PageVariant::create($this->entity->toArray());
    $draft->enforceIsNew(FALSE);
    $draft->set('component_tree', \array_values($stored_tree));
    self::assertSame(
      \range(0, \count($stored_tree) - 1),
      \array_keys($draft->get('component_tree')),
      'The draft component tree is delta-keyed.',
    );

    // Now translate the variant: config_translation writes a UUID-keyed
    // override holding only the translated inputs.
    $base_heading = $stored_tree[$uuid]['inputs']['heading'];
    $language_manager->getLanguageConfigOverride('hu', $config_name)
      ->set('component_tree', [
        $uuid => [
          'inputs' => [
            'heading' => \is_array($base_heading)
              ? ['value' => 'Szia, világ!']
              : 'Szia, világ!',
          ],
        ],
      ])
      ->save();

    // Validating the draft merges the delta-keyed base with the UUID-keyed
    // override. Without the re-keying, this crashes with an AssertionError
    // from ComponentInputsMapping instead of reporting anything.
    $this->entity = $draft;
    $this->assertValidationErrors([]);
  }

}
