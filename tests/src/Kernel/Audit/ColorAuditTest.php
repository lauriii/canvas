<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Audit;

use Drupal\canvas\Audit\ColorAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Drupal\canvas\Audit\ColorAudit.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ColorAudit::class)]
#[Group('canvas')]
class ColorAuditTest extends CanvasKernelTestBase {

  protected static $modules = [
    'language',
    'node',
  ];

  private Color $color;

  private string $colorPattern;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $this->color = Color::create([
      'name' => 'Test Blue',
      'cssVariable' => '--color-test-blue',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.0, 1.0],
        'hex' => '#0000ff',
      ],
    ]);
    $this->color->save();
    $this->colorPattern = Color::REFERENCE_PREFIX . $this->color->id();
  }

  /**
   * Tests content entity usage is found, and confined to matching revisions.
   *
   * @legacy-covers ::hasContentUsages
   * @legacy-covers ::getContentRevisionsUsingAuditTarget
   */
  public function testContentEntityUsage(): void {
    $audit = $this->container->get(ColorAudit::class);

    // No usages yet.
    self::assertFalse($audit->hasContentUsages($this->color));
    self::assertCount(0, $audit->getContentRevisionsUsingAuditTarget($this->color));

    // Save a page whose component tree references the color.
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Color Test Heading',
          'background_color' => $this->colorPattern,
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $revision_id_1 = $page->getRevisionId();

    // Save a second page that does not reference this color.
    $non_matching_page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Color Test Heading',
          'background_color' => '#ff0000ff',
        ],
      ],
    ]);
    self::assertEntityIsValid($non_matching_page);
    $non_matching_page->save();

    self::assertTrue($audit->hasContentUsages($this->color));
    $latest = $audit->getContentRevisionsUsingAuditTarget($this->color, which_revisions: RevisionAuditEnum::Latest);
    self::assertCount(1, $latest);
    self::assertSame($page->uuid(), \reset($latest)->uuid());

    // Save a new revision that no longer references the color.
    $page->setNewRevision();
    $page->set('components', [])->save();

    // Latest revision no longer uses the color.
    $latest = $audit->getContentRevisionsUsingAuditTarget($this->color, which_revisions: RevisionAuditEnum::Latest);
    self::assertCount(0, $latest);
    self::assertFalse($audit->hasContentUsages($this->color, RevisionAuditEnum::Latest));
    self::assertTrue($audit->hasContentUsages($this->color, RevisionAuditEnum::All));

    // But the old revision still shows up in All.
    $all = $audit->getContentRevisionsUsingAuditTarget($this->color, which_revisions: RevisionAuditEnum::All);
    self::assertCount(1, $all);
    self::assertSame((string) $revision_id_1, (string) \reset($all)->getRevisionId());
  }

  /**
   * Tests a usage in only a non-default translation is found.
   *
   * The entity query matches the field table across all translations, but the
   * candidate it yields is loaded as its default translation. Confirming the
   * candidate against that translation alone would drop it, and a color used
   * only by a translation would be reported unused — and become deletable.
   *
   * @legacy-covers ::hasContentUsages
   * @legacy-covers ::getContentRevisionsUsingAuditTarget
   * @legacy-covers ::getContentColorUsagesWithDetailSplit
   */
  public function testUsageInNonDefaultTranslationOnly(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $audit = $this->container->get(ColorAudit::class);
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    $instance_uuid = $this->container->get('uuid')->generate();

    // The default translation holds a literal color, not the reference.
    $page = Page::create([
      'title' => 'English title',
      'components' => [
        'uuid' => $instance_uuid,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'English heading',
          'background_color' => '#ff0000ff',
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    self::assertFalse($audit->hasContentUsages($this->color));

    // Only the French translation references the color.
    $page->addTranslation('fr', [
      'title' => 'Titre français',
      'components' => [
        'uuid' => $instance_uuid,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Titre',
          'background_color' => $this->colorPattern,
        ],
      ],
    ])->save();

    self::assertTrue($audit->hasContentUsages($this->color));
    $revisions = $audit->getContentRevisionsUsingAuditTarget($this->color);
    self::assertCount(1, $revisions);
    self::assertSame($page->id(), \reset($revisions)->id());

    $split = $audit->getContentColorUsagesWithDetailSplit($this->color);
    self::assertCount(1, $split['current']);
    self::assertCount(0, $split['prior']);
    self::assertSame(
      [['component_uuid' => $instance_uuid, 'prop_name' => 'background_color']],
      \array_map(static fn (array $usage): array => \array_intersect_key($usage, \array_flip(['component_uuid', 'prop_name'])), $split['current'][0]['usages']),
    );

    // The same instance prop using the color in both translations is still a
    // single usage.
    $page->set('components', [
      'uuid' => $instance_uuid,
      'component_id' => $component->id(),
      'component_version' => $component->getActiveVersion(),
      'inputs' => [
        'heading' => 'English heading',
        'background_color' => $this->colorPattern,
      ],
    ])->save();
    $split = $audit->getContentColorUsagesWithDetailSplit($this->color);
    self::assertCount(1, $split['current']);
    self::assertCount(1, $split['current'][0]['usages']);
  }

  /**
   * Tests a color-like value in a non-color prop is not reported as a usage.
   *
   * The entity query matches the raw stored JSON, so a plain string prop whose
   * value merely looks like a color reference is a candidate. It must not
   * survive confirmation: it creates no config dependency either, so treating
   * it as a usage would block deleting a color that nothing actually uses.
   *
   * @legacy-covers ::hasContentUsages
   * @legacy-covers ::getContentRevisionsUsingAuditTarget
   * @legacy-covers ::getConfigColorUsagesWithDetail
   * @see \Drupal\Tests\canvas\Kernel\Config\ColorTest::testColorLikeValueInNonColorPropIsNotADependency()
   */
  public function testColorLikeValueInNonColorPropIsNotAUsage(): void {
    $audit = $this->container->get(ColorAudit::class);
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);

    // `heading` is a plain string prop, `background_color` is the color prop.
    $inputs = [
      'heading' => $this->colorPattern,
      'background_color' => '#ff0000ff',
    ];

    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => $inputs,
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    Pattern::create([
      'id' => 'color_lookalike_pattern',
      'label' => 'Color Lookalike Pattern',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => $inputs,
        ],
      ],
    ])->save();

    // The entity query does match the page: the confirmation pass is what
    // rejects it.
    self::assertCount(1, $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('components.inputs', $this->colorPattern, 'CONTAINS')
      ->execute());

    self::assertCount(0, $audit->getContentRevisionsUsingAuditTarget($this->color, which_revisions: RevisionAuditEnum::All));
    self::assertCount(0, $audit->getConfigColorUsagesWithDetail($this->color));
    $split = $audit->getContentColorUsagesWithDetailSplit($this->color);
    self::assertCount(0, $split['current']);
    self::assertCount(0, $split['prior']);
    self::assertFalse($audit->hasContentUsages($this->color, RevisionAuditEnum::All));
  }

  /**
   * Tests separates current from prior revisions correctly distinguished.
   *
   * @legacy-covers ::getContentColorUsagesWithDetailSplit
   */
  public function testSplitByRevisionStatus(): void {
    $audit = $this->container->get(ColorAudit::class);
    $parent_component = Component::load('sdc.canvas_test_sdc.props-slots');
    $child_component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($parent_component instanceof Component);
    \assert($child_component instanceof Component);
    $parent_uuid = $this->container->get('uuid')->generate();
    $child_uuid = $this->container->get('uuid')->generate();

    $page = Page::create([
      'title' => $this->randomMachineName(),
      'components' => [
        [
          'uuid' => $parent_uuid,
          'component_id' => $parent_component->id(),
          'component_version' => $parent_component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Container heading',
          ],
          'label' => 'Container label',
        ],
        [
          'uuid' => $child_uuid,
          'parent_uuid' => $parent_uuid,
          'slot' => 'the_body',
          'component_id' => $child_component->id(),
          'component_version' => $child_component->getActiveVersion(),
          // StaticPropSource shape: color value nested under `value`.
          'inputs' => [
            'heading' => 'Color Test Heading',
            'background_color' => ['value' => $this->colorPattern],
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    // Single revision: appears in 'current', nothing in 'prior'.
    $split = $audit->getContentColorUsagesWithDetailSplit($this->color);
    self::assertCount(1, $split['current']);
    self::assertCount(0, $split['prior']);
    self::assertCount(1, $split['current'][0]['usages']);
    self::assertSame($child_uuid, $split['current'][0]['usages'][0]['component_uuid']);
    self::assertSame('background_color', $split['current'][0]['usages'][0]['prop_name']);
    self::assertSame(['Container label'], $split['current'][0]['usages'][0]['ancestor_labels']);

    // A forward (pending, non-default) revision without the color does not
    // make the default revision's usage 'prior': the default revision is still
    // rendered, and it still blocks deletion.
    $page->setNewRevision();
    $page->isDefaultRevision(FALSE);
    $page->set('components', [])->save();

    $split = $audit->getContentColorUsagesWithDetailSplit($this->color);
    self::assertCount(1, $split['current']);
    self::assertCount(0, $split['prior']);

    // New *default* revision without color: nothing renders it any more, so
    // the earlier revision becomes 'prior'.
    $page->setNewRevision();
    $page->isDefaultRevision(TRUE);
    $page->set('components', [])->save();

    $split = $audit->getContentColorUsagesWithDetailSplit($this->color);
    self::assertCount(0, $split['current']);
    self::assertCount(1, $split['prior']);
  }

  /**
   * Tests getConfigColorUsagesWithDetail() finds a Pattern referencing the color.
   *
   * @legacy-covers ::getConfigColorUsagesWithDetail
   */
  public function testConfigEntityUsage(): void {
    $audit = $this->container->get(ColorAudit::class);

    self::assertCount(0, $audit->getConfigColorUsagesWithDetail($this->color));

    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    $pattern = Pattern::create([
      'id' => 'test_color_pattern',
      'label' => 'Test Color Pattern',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Color Test Heading',
            'background_color' => $this->colorPattern,
          ],
        ],
      ],
    ]);
    $pattern->save();

    $other_color = Color::create([
      'name' => 'Test Red',
      'cssVariable' => '--color-test-red',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
    ]);
    $other_color->save();
    $other_color_pattern = Color::REFERENCE_PREFIX . $other_color->id();

    // Create a second pattern that references a different color UUID.
    Pattern::create([
      'id' => 'test_other_color_pattern',
      'label' => 'Test Other Color Pattern',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Color Test Heading',
            'background_color' => $other_color_pattern,
          ],
        ],
      ],
    ])->save();

    $config_usages = $audit->getConfigColorUsagesWithDetail($this->color);
    self::assertCount(1, $config_usages);
    $first_config_usage = \reset($config_usages);
    self::assertNotFalse($first_config_usage);
    self::assertSame('test_color_pattern', $first_config_usage['entity']->id());

    // hasContentUsages() ignores config usage by design: the caller that needs
    // it is validating a config import that may be changing it.
    self::assertFalse($audit->hasContentUsages($this->color, RevisionAuditEnum::All));
  }

  /**
   * Tests getConfigColorUsagesWithDetail() finds a ContentTemplate referencing the color.
   *
   * @legacy-covers ::getConfigColorUsagesWithDetail
   */
  public function testConfigEntityUsageContentTemplate(): void {
    $this->installEntitySchema('node');
    $audit = $this->container->get(ColorAudit::class);
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);

    EntityViewMode::create([
      'id' => 'node.canvas_test',
      'label' => 'Canvas Test',
      'targetEntityType' => 'node',
    ])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $content_template = ContentTemplate::create([
      'id' => 'node.page.canvas_test',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'page',
      'content_entity_type_view_mode' => 'canvas_test',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Color Test Heading',
            'background_color' => $this->colorPattern,
          ],
        ],
      ],
    ]);
    $content_template->save();

    $config_usages = $audit->getConfigColorUsagesWithDetail($this->color);
    self::assertCount(1, $config_usages);
    $first_config_usage = \reset($config_usages);
    self::assertNotFalse($first_config_usage);
    self::assertSame('node.page.canvas_test', $first_config_usage['entity']->id());
  }

  /**
   * Tests getConfigColorUsagesWithDetail() finds a PageRegion referencing the color.
   *
   * @legacy-covers ::getConfigColorUsagesWithDetail
   */
  public function testConfigEntityUsagePageRegion(): void {
    $audit = $this->container->get(ColorAudit::class);
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);

    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Color Test Heading',
            'background_color' => $this->colorPattern,
          ],
        ],
      ],
    ]);
    $page_region->save();

    $config_usages = $audit->getConfigColorUsagesWithDetail($this->color);
    self::assertCount(1, $config_usages);
    $first_config_usage = \reset($config_usages);
    self::assertNotFalse($first_config_usage);
    self::assertSame('stark.sidebar_first', $first_config_usage['entity']->id());
    self::assertCount(1, $first_config_usage['usages']);
    self::assertSame('background_color', $first_config_usage['usages'][0]['prop_name']);
  }

}
