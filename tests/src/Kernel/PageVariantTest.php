<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Display\VariantManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the page variant foundation.
 *
 * Covers the `page_variant` config entity type, the "Page content" marker and
 * its "exactly one" constraint, and the two selectors that reference a variant:
 * the `page_variant` base field on `canvas_page`, and the `page_variant` config
 * property on `content_template`.
 *
 * @see \Drupal\canvas\Entity\PageVariant
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker
 * @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraint
 * @see \Drupal\canvas\Entity\Page
 * @see \Drupal\canvas\Entity\ContentTemplate
 */
#[CoversClass(PageVariant::class)]
#[Group('canvas')]
final class PageVariantTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PageTrait;

  /**
   * The stored active version of the marker component.
   *
   * Mirrors config/install/canvas.component.marker.page_content.yml. It is the
   * hash of the marker's versioned settings; it changes only if those change.
   */
  private const string MARKER_VERSION = '3b12c0b99a6caecc';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
    // Provides a content entity type + view mode for ContentTemplate coverage.
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    // Install entity schemas (path_alias, canvas_page) before config: saving
    // user config rebuilds permissions, which generates URLs that query the
    // path_alias table.
    $this->installPageEntitySchema();
    // Provides the `node.full` view mode and `user` config that content
    // templates depend on, plus a bundle to template.
    $this->installConfig(['node', 'user']);
    NodeType::create(['type' => 'helpful', 'name' => 'Helpful'])->save();

    // The marker component ships in config/install (not auto-discovered); make
    // it available so variants can be seeded with the content marker.
    if (Component::load(Marker::PAGE_CONTENT_COMPONENT_ID) === NULL) {
      Component::create([
        'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'label' => 'Page content',
        'provider' => 'canvas',
        'source' => Marker::SOURCE_PLUGIN_ID,
        'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
        'active_version' => self::MARKER_VERSION,
        'versioned_properties' => [
          'active' => [
            'settings' => [],
            'fallback_metadata' => ['slot_definitions' => []],
          ],
        ],
        'dependencies' => ['enforced' => ['module' => ['canvas']]],
      ])->save();
    }
  }

  /**
   * Builds a "Page content" marker component instance for a variant tree.
   */
  private static function markerInstance(): array {
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
      'component_version' => self::MARKER_VERSION,
      'inputs' => [],
    ];
  }

  /**
   * The violation messages for a page variant's component tree, as strings.
   *
   * @return string[]
   */
  private static function markerViolations(PageVariant $variant): array {
    $messages = [];
    foreach ($variant->getTypedData()->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    return $messages;
  }

  /**
   * Tests that a valid page variant validates, saves, and reloads intact.
   */
  public function testCreateValidateReload(): void {
    $marker = self::markerInstance();
    $variant = PageVariant::create([
      'id' => 'homepage',
      'label' => 'Homepage',
      'description' => 'The default full-page layout.',
      'component_tree' => [$marker],
    ]);

    // A variant with exactly one marker is valid.
    self::assertEntityIsValid($variant);

    $variant->save();

    // Reloading returns the persisted values.
    $reloaded = PageVariant::load('homepage');
    self::assertInstanceOf(PageVariant::class, $reloaded);
    self::assertSame('homepage', $reloaded->id());
    self::assertSame('Homepage', $reloaded->label());
    self::assertSame('The default full-page layout.', $reloaded->get('description'));
    self::assertCount(1, $reloaded->getComponentTree()->getValue());
  }

  /**
   * Tests the "exactly one Page content marker" constraint.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraint
   */
  public function testContentMarkerConstraint(): void {
    // Zero markers: rejected as missing.
    $none = PageVariant::create(['id' => 'none', 'label' => 'None', 'component_tree' => []]);
    self::assertContains(
      'A page variant must contain a "Page content" placement.',
      self::markerViolations($none),
    );

    // Exactly one marker: accepted.
    $one = PageVariant::create(['id' => 'one', 'label' => 'One', 'component_tree' => [self::markerInstance()]]);
    self::assertEntityIsValid($one);

    // Two markers: rejected as too many.
    $two = PageVariant::create(['id' => 'two', 'label' => 'Two', 'component_tree' => [self::markerInstance(), self::markerInstance()]]);
    self::assertContains(
      'A page variant must contain only one "Page content" placement, but found 2.',
      self::markerViolations($two),
    );
  }

  /**
   * Tests that the marker is rejected outside page variant trees.
   */
  public function testMarkerRejectedInPageTree(): void {
    $page = Page::create([
      'title' => 'Has a stray marker',
      'components' => [self::markerInstance()],
    ]);
    $violations = $page->getComponentTree()->validate();
    $messages = \array_map(static fn ($v): string => (string) $v->getMessage(), \iterator_to_array($violations));
    self::assertNotEmpty(\array_filter(
      $messages,
      static fn (string $m): bool => \str_contains($m, Marker::PAGE_CONTENT_COMPONENT_ID),
    ), 'The page content marker must not be allowed in a canvas_page tree. Got: ' . \implode(' | ', $messages));
  }

  /**
   * Tests that the site default variant cannot be deleted.
   *
   * @see \Drupal\canvas\Entity\PageVariant::preDelete()
   * @see \Drupal\canvas\Entity\PageVariant::isSiteDefault()
   */
  public function testSiteDefaultDeletionGuard(): void {
    $variant = PageVariant::create(['id' => 'guarded', 'label' => 'Guarded', 'component_tree' => [self::markerInstance()]]);
    $variant->save();

    // With no default set, the variant is not protected.
    self::assertNull($this->config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));
    self::assertFalse($variant->isSiteDefault());

    // Mark the variant as the site default.
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'guarded')->save();
    $variant = PageVariant::load('guarded');
    self::assertInstanceOf(PageVariant::class, $variant);
    self::assertTrue($variant->isSiteDefault());

    // Deleting the site default is blocked. The guard throws before deletion,
    // so the variant is left intact.
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('The page variant "guarded" cannot be deleted because it is the site default. Set another variant as the default first.');
    $variant->delete();
  }

  /**
   * Tests that clearing the default lifts the guard and the variant deletes.
   */
  public function testDefaultVariantDeletesOnceCleared(): void {
    $variant = PageVariant::create(['id' => 'clearme', 'label' => 'Clear me', 'component_tree' => [self::markerInstance()]]);
    $variant->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'clearme')->save();

    // Clearing the default lifts the guard.
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, NULL)->save();
    $variant = PageVariant::load('clearme');
    self::assertInstanceOf(PageVariant::class, $variant);
    self::assertFalse($variant->isSiteDefault());

    $variant->delete();
    self::assertNull(PageVariant::load('clearme'));
  }

  /**
   * Tests the `page_variant` base field on the `canvas_page` entity type.
   */
  public function testPageEntityHasPageVariantBaseField(): void {
    $field_manager = $this->container->get(EntityFieldManagerInterface::class);
    self::assertInstanceOf(EntityFieldManagerInterface::class, $field_manager);
    $base_fields = $field_manager->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID);

    self::assertArrayHasKey('page_variant', $base_fields);
    $field = $base_fields['page_variant'];
    self::assertInstanceOf(BaseFieldDefinition::class, $field);
    self::assertSame('string', $field->getType());
    self::assertTrue($field->isRevisionable());

    // A page can store and reload a page variant selection.
    $page = Page::create([
      'title' => 'Variant-bearing page',
      'page_variant' => 'homepage',
    ]);
    self::assertSaveWithoutViolations($page);

    $reloaded = Page::load($page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertSame('homepage', $reloaded->get('page_variant')->value);
  }

  /**
   * Tests that a content template exports and depends on its page variant.
   *
   * @see \Drupal\canvas\Entity\ContentTemplate::calculateDependencies()
   * @see \Drupal\canvas\Entity\ContentTemplate::getPageVariant()
   */
  public function testContentTemplateExportsAndDependsOnPageVariant(): void {
    // The `page_variant` property is part of the config export schema.
    $entity_type = $this->container->get('entity_type.manager')
      ->getDefinition(ContentTemplate::ENTITY_TYPE_ID);
    self::assertInstanceOf(ConfigEntityTypeInterface::class, $entity_type);
    $exported = $entity_type->getPropertiesToExport();
    self::assertIsArray($exported);
    self::assertContains('page_variant', $exported);

    PageVariant::create(['id' => 'headline', 'label' => 'Headline', 'component_tree' => [self::markerInstance()]])->save();

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'page_variant' => 'headline',
    ]);
    self::assertSame('headline', $template->getPageVariant());

    // Selecting a variant adds a config dependency on that variant.
    $template->calculateDependencies();
    self::assertContains('canvas.page_variant.headline', $template->getDependencies()['config']);

    // The dependency survives a save/reload round trip.
    $template->save();
    $reloaded = ContentTemplate::load('node.helpful.full');
    self::assertInstanceOf(ContentTemplate::class, $reloaded);
    self::assertSame('headline', $reloaded->getPageVariant());
    self::assertContains('canvas.page_variant.headline', $reloaded->getDependencies()['config']);
  }

  /**
   * Tests that deleting a selected variant drops the template's selection.
   *
   * The template must survive: its `page_variant` selection is unset instead of
   * the template being cascade-deleted along with the variant.
   *
   * @see \Drupal\canvas\Entity\ContentTemplate::onDependencyRemoval()
   */
  public function testDeletingSelectedPageVariantDropsTemplateSelection(): void {
    PageVariant::create(['id' => 'promo', 'label' => 'Promo', 'component_tree' => [self::markerInstance()]])->save();
    $variant = PageVariant::load('promo');
    self::assertInstanceOf(PageVariant::class, $variant);

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'page_variant' => 'promo',
    ])->save();

    // The saved template selects the variant and depends on it.
    $template = ContentTemplate::load('node.helpful.full');
    self::assertInstanceOf(ContentTemplate::class, $template);
    self::assertSame('promo', $template->getPageVariant());
    self::assertContains('canvas.page_variant.promo', $template->getDependencies()['config']);

    // The variant is not the site default, so deletion is allowed. Deleting it
    // triggers the config dependency removal flow.
    $variant->delete();
    self::assertNull(PageVariant::load('promo'));

    // The template still exists; only its variant selection was dropped.
    $reloaded = ContentTemplate::load('node.helpful.full');
    self::assertInstanceOf(ContentTemplate::class, $reloaded);
    self::assertNull($reloaded->getPageVariant());
    self::assertNotContains('canvas.page_variant.promo', $reloaded->getDependencies()['config'] ?? []);
  }

  /**
   * Tests the resolution chain: entity selection, then default, then none.
   *
   * @see \Drupal\canvas\PageVariantResolver
   */
  public function testResolverChain(): void {
    $resolver = $this->container->get(PageVariantResolver::class);
    self::assertInstanceOf(PageVariantResolver::class, $resolver);

    // No default and no entity: nothing resolves (core block layout renders).
    self::assertNull($resolver->resolve(NULL));

    // With a default set, a request whose entity has no selection uses it.
    PageVariant::create(['id' => 'site_default', 'label' => 'Site default', 'component_tree' => [self::markerInstance()]])->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'site_default')->save();
    $default = $resolver->resolve(Page::create(['title' => 'No selection']));
    self::assertInstanceOf(PageVariant::class, $default);
    self::assertSame('site_default', $default->id());

    // A canvas_page's own selection wins over the default.
    PageVariant::create(['id' => 'chosen', 'label' => 'Chosen', 'component_tree' => [self::markerInstance()]])->save();
    $chosen = $resolver->resolve(Page::create(['title' => 'Picks a variant', 'page_variant' => 'chosen']));
    self::assertInstanceOf(PageVariant::class, $chosen);
    self::assertSame('chosen', $chosen->id());

    // A selection pointing at a missing variant falls back to the default.
    $fallback = $resolver->resolve(Page::create(['title' => 'Stale selection', 'page_variant' => 'deleted']));
    self::assertInstanceOf(PageVariant::class, $fallback);
    self::assertSame('site_default', $fallback->id());
  }

  /**
   * Tests that the display variant injects main content at the marker.
   *
   * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
   */
  public function testDisplayVariantInjectsMainContentAtMarker(): void {
    PageVariant::create(['id' => 'renderme', 'label' => 'Render me', 'component_tree' => [self::markerInstance()]])->save();

    $variant_manager = $this->container->get('plugin.manager.display_variant');
    self::assertInstanceOf(VariantManager::class, $variant_manager);
    $plugin = $variant_manager->createInstance(CanvasPageVariant::PLUGIN_ID, [
      CanvasPageVariant::PREVIEW_KEY => FALSE,
      CanvasPageVariant::VARIANT_ID_KEY => 'renderme',
    ]);
    self::assertInstanceOf(CanvasPageVariant::class, $plugin);

    $sentinel = 'canvas-main-content-3f9c1e';
    $plugin->setMainContent(['#markup' => $sentinel]);
    $plugin->setTitle('Rendered title');
    $build = $plugin->build();

    // The page body renders through the bare variant template.
    self::assertSame('canvas_page_variant', $build['#theme']);

    // The route's main content is injected where the marker sits.
    $renderer = $this->container->get(RendererInterface::class);
    self::assertInstanceOf(RendererInterface::class, $renderer);
    $html = (string) $renderer->renderInIsolation($build['#content']);
    self::assertStringContainsString($sentinel, $html);
  }

}
