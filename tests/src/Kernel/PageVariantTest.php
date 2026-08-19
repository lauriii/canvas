<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiSettingsController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Display\VariantManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Route;

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
  use UserCreationTrait;

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
    $this->installEntitySchema('node');
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

    // An enabled variant is not considered a new draft by the editor.
    self::assertFalse(AutoSaveManager::entityIsConsideredNew($reloaded));
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
    $variant = PageVariant::create(['id' => 'cleared', 'label' => 'Clear me', 'component_tree' => [self::markerInstance()]]);
    $variant->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'cleared')->save();

    // Clearing the default lifts the guard.
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, NULL)->save();
    $variant = PageVariant::load('cleared');
    self::assertInstanceOf(PageVariant::class, $variant);
    self::assertFalse($variant->isSiteDefault());

    $variant->delete();
    self::assertNull(PageVariant::load('cleared'));
  }

  /**
   * Tests that module uninstall can delete the site default variant.
   *
   * Core's ConfigManager cascade-deletes config that depends on an uninstalling
   * module, marking each dependent `isUninstalling()` while `isSyncing()` stays
   * FALSE. The site-default guard must exempt those, or uninstalling a module
   * whose component sits in the default variant's tree would abort mid-way.
   *
   * @see \Drupal\canvas\Entity\PageVariant::preDelete()
   */
  public function testSiteDefaultDeletableDuringModuleUninstall(): void {
    $variant = PageVariant::create(['id' => 'uninstalling', 'label' => 'Uninstalling', 'component_tree' => [self::markerInstance()]]);
    $variant->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'uninstalling')->save();
    $variant = PageVariant::load('uninstalling');
    self::assertInstanceOf(PageVariant::class, $variant);
    self::assertTrue($variant->isSiteDefault());

    // Deleting the site default during a module uninstall is exempt from the
    // guard and succeeds. (A plain delete throwing is covered by
    // testSiteDefaultDeletionGuard.)
    $variant->setUninstalling(TRUE);
    $variant->delete();
    self::assertNull(PageVariant::load('uninstalling'));
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
    // The selection is an options list so the form renders a select widget
    // listing the existing variants by label.
    self::assertSame('list_string', $field->getType());
    self::assertSame(PageVariant::class . '::allowedValues', $field->getSetting('allowed_values_function'));
    self::assertSame('options_select', $field->getDisplayOptions('form')['type'] ?? NULL);
    self::assertTrue($field->isRevisionable());

    PageVariant::create(['id' => 'homepage', 'label' => 'Homepage', 'component_tree' => [self::markerInstance()]])->save();
    PageVariant::create(['id' => 'landing', 'label' => 'Landing', 'component_tree' => [self::markerInstance()]])->save();
    self::assertSame(['homepage' => 'Homepage', 'landing' => 'Landing'], PageVariant::allowedValues());

    // A page can store and reload a page variant selection.
    $page = Page::create([
      'title' => 'Variant-bearing page',
      'page_variant' => 'homepage',
    ]);
    self::assertSaveWithoutViolations($page);

    $reloaded = Page::load($page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertSame('homepage', $reloaded->get('page_variant')->value);

    // A selection referencing a nonexistent variant is invalid.
    $invalid = Page::create([
      'title' => 'Dangling selection',
      'page_variant' => 'ghost',
    ]);
    self::assertNotCount(0, $invalid->get('page_variant')->validate());
  }

  /**
   * Tests that a content template exports and depends on its page variant.
   *
   * @see \Drupal\canvas\Entity\ContentTemplate::calculateDependencies()
   * @see \Drupal\canvas\Entity\ContentTemplate::getPageVariant()
   */
  public function testContentTemplateExportsAndDependsOnPageVariant(): void {
    // The `page_variant` property is part of the config export schema.
    $entity_type = $this->container->get(EntityTypeManagerInterface::class)
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

    // The selection round-trips through the client-side representation, so the
    // editor can read and change it.
    self::assertSame('headline', $reloaded->normalizeForClientSide()->values['pageVariant']);
    $reloaded->updateFromClientSide(['pageVariant' => NULL]);
    self::assertNull($reloaded->getPageVariant());
    self::assertNull($reloaded->normalizeForClientSide()->values['pageVariant']);
    $reloaded->updateFromClientSide(['pageVariant' => 'headline']);
    self::assertSame('headline', $reloaded->getPageVariant());

    // A non-string pageVariant from a client means "no selection", in both
    // update and create: the property is typed, so passing it through would be
    // a fatal error rather than the validation error a client deserves.
    $reloaded->updateFromClientSide(['pageVariant' => 123]);
    self::assertNull($reloaded->getPageVariant());
    self::assertNull(ContentTemplate::createFromClientSide([
      'entityType' => 'node',
      'bundle' => 'helpful',
      'viewMode' => 'full',
      'pageVariant' => ['not' => 'a string'],
    ])->getPageVariant());
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

    // A page also selects the variant.
    $page = Page::create(['title' => 'Selects promo', 'page_variant' => 'promo']);
    self::assertSaveWithoutViolations($page);

    // The dependent template must not block deleting the variant: delete
    // access stays allowed for an administrator, so the graceful
    // onDependencyRemoval() flow below is reachable through the HTTP API too
    // (not just programmatically).
    // @see \Drupal\canvas\EntityHandlers\PageVariantAccessControlHandler
    $this->installEntitySchema('user');
    $admin = $this->createUser([PageVariant::ADMIN_PERMISSION]);
    self::assertNotFalse($admin);
    self::assertTrue($variant->access('delete', $admin));

    // The variant is not the site default, so deletion is allowed. Deleting it
    // triggers the config dependency removal flow.
    $variant->delete();
    self::assertNull(PageVariant::load('promo'));

    // The template still exists; only its variant selection was dropped.
    $reloaded = ContentTemplate::load('node.helpful.full');
    self::assertInstanceOf(ContentTemplate::class, $reloaded);
    self::assertNull($reloaded->getPageVariant());
    self::assertNotContains('canvas.page_variant.promo', $reloaded->getDependencies()['config'] ?? []);

    // The page's dangling selection was cleared too (it is an options list,
    // so a dangling value would fail validation on the page's next save).
    $reloaded_page = Page::load($page->id());
    self::assertInstanceOf(Page::class, $reloaded_page);
    self::assertNull($reloaded_page->get('page_variant')->value);
    self::assertSaveWithoutViolations($reloaded_page);
  }

  /**
   * Tests that deleting a variant clears an auto-saved page selection.
   *
   * A selection can live only in a page's auto-saved draft, never persisted, so
   * the persisted-page sweep cannot reach it. Left behind, the dangling value
   * fails options validation and blocks publishing the draft. postDelete() must
   * sweep drafts too, clearing just the selection.
   *
   * @see \Drupal\canvas\Entity\PageVariant::postDelete()
   */
  public function testDeletingVariantClearsAutoSavedPageSelection(): void {
    PageVariant::create(['id' => 'draft_target', 'label' => 'Draft target', 'component_tree' => [self::markerInstance()]])->save();
    $variant = PageVariant::load('draft_target');
    self::assertInstanceOf(PageVariant::class, $variant);

    // A saved page with no persisted selection: the persisted-page sweep in
    // postDelete() cannot reach it.
    $page = Page::create(['title' => 'Original title']);
    self::assertSaveWithoutViolations($page);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $auto_save_manager);

    // The draft selects the variant and carries an unrelated edit, so it does
    // not revert to the stored page once the selection is swept out.
    $draft = Page::load($page->id());
    self::assertInstanceOf(Page::class, $draft);
    $draft->set('title', 'Draft title');
    $draft->set('page_variant', 'draft_target');
    $auto_save_manager->saveEntity($draft);
    $stored_draft = $auto_save_manager->getAutoSaveEntity($page)->entity;
    self::assertInstanceOf(Page::class, $stored_draft);
    self::assertSame('draft_target', $stored_draft->get('page_variant')->value);

    // The variant is not the site default, so deletion is allowed.
    $variant->delete();
    self::assertNull(PageVariant::load('draft_target'));

    // The draft survives with its unrelated edit; only the dangling selection
    // was cleared, so options validation passes and publishing is unblocked.
    $swept_draft = $auto_save_manager->getAutoSaveEntity($page)->entity;
    self::assertInstanceOf(Page::class, $swept_draft);
    self::assertSame('Draft title', $swept_draft->label());
    self::assertNull($swept_draft->get('page_variant')->value);
    self::assertSaveWithoutViolations($swept_draft);
  }

  /**
   * Tests that changing a template's variant keeps its auto-saved draft.
   *
   * The selection is edited on its own through the config API rather than
   * through the layout auto-save flow, so saving it must not look like an
   * out-of-band change that invalidates the editor's unpublished work.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
   */
  public function testChangingTemplateVariantKeepsAutoSavedDraft(): void {
    PageVariant::create(['id' => 'promo', 'label' => 'Promo', 'component_tree' => [self::markerInstance()]])->save();

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ]);
    $template->save();

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $auto_save_manager);

    // A component the editor can place in a content template. The content
    // marker cannot be used here: it is valid only in page variant trees.
    JavaScriptComponent::create([
      'machineName' => 'template_logo',
      'name' => 'Template logo',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();
    $placed = Component::load(JsComponent::componentIdFromJavascriptComponentId('template_logo'));
    self::assertInstanceOf(Component::class, $placed);

    // The editor's pending component change, auto-saved but not published.
    $draft = ContentTemplate::create($template->toArray());
    $draft->enforceIsNew(FALSE);
    $draft->set('component_tree', [[
      'uuid' => $this->container->get('uuid')->generate(),
      'component_id' => $placed->id(),
      'component_version' => $placed->getActiveVersion(),
      'inputs' => [],
    ],
    ]);
    $auto_save_manager->saveEntity($draft);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($template)->isEmpty());

    // Selecting a page variant is a separate, deliberate change to the stored
    // template. It also changes the derived `dependencies`.
    $template->set('page_variant', 'promo');
    $template->save();

    // The pending component change survives, and the draft picked up the new
    // selection so publishing it does not revert the selection.
    $surviving = $auto_save_manager->getAutoSaveEntity($template)->entity;
    self::assertInstanceOf(ContentTemplate::class, $surviving);
    self::assertCount(1, $surviving->getComponentTree());
    self::assertSame('promo', $surviving->getPageVariant());
  }

  /**
   * Tests the resolution chain: entity, then template, then default, then none.
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

    // -- The content-template branch: entities that are not canvas pages. --
    $node = Node::create(['type' => 'helpful', 'title' => 'Templated']);

    // Without a template for the node's bundle, the site default applies.
    $resolved = $resolver->resolve($node);
    self::assertInstanceOf(PageVariant::class, $resolved);
    self::assertSame('site_default', $resolved->id());

    // A matching full-view-mode template's selection resolves for the node,
    // winning over the site default. (Templates default to unpublished, so
    // publish explicitly: only a published template renders the entity.)
    PageVariant::create(['id' => 'templated', 'label' => 'Templated', 'component_tree' => [self::markerInstance()]])->save();
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'page_variant' => 'templated',
      'status' => TRUE,
    ])->save();
    $resolved = $resolver->resolve($node);
    self::assertInstanceOf(PageVariant::class, $resolved);
    self::assertSame('templated', $resolved->id());

    // A stale template selection falls back to the default. Plant the stale
    // id through raw config storage: a validated save would reject it.
    $template = ContentTemplate::load('node.helpful.full');
    self::assertInstanceOf(ContentTemplate::class, $template);
    $raw = $template->toArray();
    $raw['page_variant'] = 'no_longer_exists';
    $this->container->get(StorageCacheInterface::class)->write('canvas.content_template.node.helpful.full', $raw);
    $this->container->get(ConfigFactoryInterface::class)->reset('canvas.content_template.node.helpful.full');
    $this->container->get(EntityTypeManagerInterface::class)->getStorage(ContentTemplate::ENTITY_TYPE_ID)->resetCache();
    $resolved = $resolver->resolve($node);
    self::assertInstanceOf(PageVariant::class, $resolved);
    self::assertSame('site_default', $resolved->id());

    // Only the full view mode's template drives the page chrome: a selection
    // on another view mode's template does not apply.
    $template->delete();
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'teaser',
      'page_variant' => 'templated',
      'status' => TRUE,
    ])->save();
    $resolved = $resolver->resolve($node);
    self::assertInstanceOf(PageVariant::class, $resolved);
    self::assertSame('site_default', $resolved->id());

    // A disabled template never renders the entity, so its selection does not
    // dictate the page chrome either.
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'page_variant' => 'templated',
      'status' => FALSE,
    ])->save();
    $resolved = $resolver->resolve($node);
    self::assertInstanceOf(PageVariant::class, $resolved);
    self::assertSame('site_default', $resolved->id());
  }

  /**
   * Tests that the display variant injects main content at the marker.
   *
   * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
   */
  public function testDisplayVariantInjectsMainContentAtMarker(): void {
    PageVariant::create(['id' => 'rendered', 'label' => 'Render me', 'component_tree' => [self::markerInstance()]])->save();

    $variant_manager = $this->container->get('plugin.manager.display_variant');
    self::assertInstanceOf(VariantManager::class, $variant_manager);
    $plugin = $variant_manager->createInstance(CanvasPageVariant::PLUGIN_ID, [
      CanvasPageVariant::PREVIEW_KEY => FALSE,
      CanvasPageVariant::VARIANT_ID_KEY => 'rendered',
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

  /**
   * Tests the marker's placeholder when the variant tree itself is previewed.
   *
   * When a page variant is edited in Canvas, its tree renders as the preview's
   * main content: no variant fiber injects the route's main content, so the
   * marker must render a visible, selectable placeholder. Outside previews the
   * marker renders nothing.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::renderComponent()
   */
  public function testMarkerPreviewPlaceholder(): void {
    PageVariant::create(['id' => 'edited', 'label' => 'Edited', 'component_tree' => [self::markerInstance()]])->save();
    $variant = PageVariant::load('edited');
    self::assertInstanceOf(PageVariant::class, $variant);

    $renderer = $this->container->get(RendererInterface::class);
    self::assertInstanceOf(RendererInterface::class, $renderer);

    $preview_build = $variant->getComponentTree()->toRenderable($variant, isPreview: TRUE);
    $preview = (string) $renderer->renderInIsolation($preview_build);
    self::assertStringContainsString('canvas--page-content-marker-placeholder', $preview);
    self::assertStringContainsString('Page content', $preview);

    $live_build = $variant->getComponentTree()->toRenderable($variant, isPreview: FALSE);
    $live = (string) $renderer->renderInIsolation($live_build);
    self::assertStringNotContainsString('canvas--page-content-marker-placeholder', $live);
  }

  /**
   * Tests preview rendering of the variant chrome around edited page content.
   *
   * Two preview-only behaviors of the display variant, both regressions from
   * the removed per-region rendering:
   * - An invalid auto-saved variant draft is not rendered; the published
   *   variant is used instead, so a broken draft (drafts are written without
   *   validation) does not break every editor preview of a page that uses it.
   * - The variant tree renders in preview mode, so a code component placed in
   *   the chrome loads its own auto-saved draft.
   *
   * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
   */
  public function testPreviewChromeDraftHandling(): void {
    $sentinel = 'canvas-main-content-preview-7a2b';
    $variant_manager = $this->container->get('plugin.manager.display_variant');
    self::assertInstanceOf(VariantManager::class, $variant_manager);
    $renderer = $this->container->get(RendererInterface::class);
    self::assertInstanceOf(RendererInterface::class, $renderer);
    $auto_save = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $auto_save);

    $render_preview = function (string $variant_id) use ($variant_manager, $renderer, $sentinel): string {
      $plugin = $variant_manager->createInstance(CanvasPageVariant::PLUGIN_ID, [
        CanvasPageVariant::PREVIEW_KEY => TRUE,
        CanvasPageVariant::VARIANT_ID_KEY => $variant_id,
      ]);
      self::assertInstanceOf(CanvasPageVariant::class, $plugin);
      $plugin->setMainContent(['#markup' => $sentinel]);
      $plugin->setTitle('Preview title');
      return (string) $renderer->renderInIsolation($plugin->build()['#content']);
    };

    // An invalid auto-saved variant draft falls back to the published variant.
    // The published variant carries the "Page content" marker, so the route's
    // main content is injected; if the invalid draft (which has no marker) were
    // rendered instead, that injection point would be gone.
    PageVariant::create(['id' => 'chrome', 'label' => 'Chrome', 'component_tree' => [self::markerInstance()]])->save();
    $draft = PageVariant::load('chrome');
    self::assertInstanceOf(PageVariant::class, $draft);
    // A variant tree with no "Page content" marker is invalid.
    $draft->setComponentTree([]);
    self::assertNotCount(0, $draft->getTypedData()->validate());
    $auto_save->saveEntity($draft);
    // The reconstructed draft that build() sees is invalid too.
    $stored_draft = $auto_save->getAutoSaveEntity($draft)->entity;
    self::assertInstanceOf(PageVariant::class, $stored_draft);
    self::assertNotCount(0, $stored_draft->getTypedData()->validate());

    // Rendering does not throw, and the published chrome (its marker) rendered.
    $html = $render_preview('chrome');
    self::assertStringContainsString($sentinel, $html);

    // A code component placed in the chrome renders its auto-saved draft when
    // the surrounding page is previewed.
    $code_component = JavaScriptComponent::create([
      'machineName' => 'chrome_logo',
      'name' => 'Chrome logo',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $code_component->save();
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId('chrome_logo'));
    self::assertInstanceOf(Component::class, $component);

    // Auto-save a draft of the code component with a distinctive name.
    $code_draft = JavaScriptComponent::create(['name' => 'Chrome logo DRAFT'] + $code_component->toArray());
    $auto_save->saveEntity($code_draft);

    PageVariant::create([
      'id' => 'chrome_with_code',
      'label' => 'Chrome with code',
      'component_tree' => [
        self::markerInstance(),
        [
          'uuid' => \Drupal::service('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [],
        ],
      ],
    ])->save();

    $html = $render_preview('chrome_with_code');
    // The draft code component rendered: its draft name appears, and its Astro
    // island points at the auto-save endpoint rather than a published asset URL.
    self::assertStringContainsString('Chrome logo DRAFT', $html);
    self::assertStringContainsString('/canvas/api/v0/auto-saves/js/', $html);
    // The route's main content is still injected at the marker.
    self::assertStringContainsString($sentinel, $html);
  }

  /**
   * Tests that no variant is selected while a page variant itself is edited.
   *
   * Requests that edit a page variant (its layout API and component instance
   * form routes carry it as a parameter) must not have their page wrapped in
   * the route's resolved variant: that would nest the edited variant inside
   * page chrome, or inside itself when it is the site default.
   *
   * @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
   */
  public function testNoVariantSelectedWhileEditingAVariant(): void {
    PageVariant::create(['id' => 'site_default', 'label' => 'Site default', 'component_tree' => [self::markerInstance()]])->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'site_default')->save();
    $variant = PageVariant::load('site_default');
    self::assertInstanceOf(PageVariant::class, $variant);

    $subscriber = $this->container->get(PageVariantSelectorSubscriber::class);
    self::assertInstanceOf(PageVariantSelectorSubscriber::class, $subscriber);
    $route = new Route('/canvas/api/v0/layout/page_variant/{entity}');

    // A request without a page variant parameter resolves the site default.
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('canvas.test', $route, [], []));
    $subscriber->onSelectPageDisplayVariant($event);
    self::assertSame(CanvasPageVariant::PLUGIN_ID, $event->getPluginId());

    // A request editing a page variant leaves core block layout in place.
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('canvas.test', $route, ['entity' => $variant], ['entity' => $variant->id()]));
    $subscriber->onSelectPageDisplayVariant($event);
    self::assertSame('simple_page', $event->getPluginId());
  }

  /**
   * Tests that admin routes are excluded by admin context, not theme name.
   *
   * The selector must key off the route's admin flag, not a comparison of the
   * configured admin theme to the active theme. Sites can point the admin
   * theme at the front-end theme (a name comparison would then bail on every
   * front-end request) or leave it empty (a name comparison would never match,
   * wrapping admin routes in page chrome).
   *
   * @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
   * @see \Drupal\Core\Routing\AdminContext::isAdminRoute()
   */
  public function testAdminRoutesExcludedByAdminContext(): void {
    PageVariant::create(['id' => 'site_default', 'label' => 'Site default', 'component_tree' => [self::markerInstance()]])->save();
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'site_default')->save();

    $subscriber = $this->container->get(PageVariantSelectorSubscriber::class);
    self::assertInstanceOf(PageVariantSelectorSubscriber::class, $subscriber);

    // Point the admin theme at the default (front-end) theme. The removed name
    // comparison would have bailed here; the site default variant must still be
    // selected on a non-admin route.
    $default_theme = $this->config('system.theme')->get('default');
    $this->config('system.theme')->set('admin', $default_theme)->save();
    $front_end = new Route('/a-front-end-page');
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('canvas.test', $front_end, [], []));
    $subscriber->onSelectPageDisplayVariant($event);
    self::assertSame(CanvasPageVariant::PLUGIN_ID, $event->getPluginId());

    // Clear the admin theme ("use default theme"). The removed name comparison
    // would never match, so an admin route would be wrapped in page chrome; the
    // admin-context check must exclude it instead.
    $this->config('system.theme')->set('admin', '')->save();
    $admin_route = new Route('/admin/a-page', [], [], ['_admin_route' => TRUE]);
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('canvas.test', $admin_route, [], []));
    $subscriber->onSelectPageDisplayVariant($event);
    self::assertSame('simple_page', $event->getPluginId());
  }

  /**
   * Tests the settings endpoint that reads and writes the default variant.
   *
   * @see \Drupal\canvas\Controller\ApiSettingsController
   */
  public function testDefaultPageVariantEndpoint(): void {
    $controller = ApiSettingsController::create($this->container);

    // The default starts unset.
    $get = $controller->getDefaultPageVariant();
    self::assertSame(['default_page_variant' => NULL], \json_decode((string) $get->getContent(), TRUE));

    // Setting it to an existing variant persists.
    PageVariant::create(['id' => 'home', 'label' => 'Home', 'component_tree' => [self::markerInstance()]])->save();
    $set = $controller->setDefaultPageVariant(Request::create('/', 'PATCH', content: (string) \json_encode(['default_page_variant' => 'home'])));
    self::assertSame(['default_page_variant' => 'home'], \json_decode((string) $set->getContent(), TRUE));
    self::assertSame('home', $this->config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));

    // Setting it to a variant that does not exist is rejected.
    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->setDefaultPageVariant(Request::create('/', 'PATCH', content: (string) \json_encode(['default_page_variant' => 'ghost'])));
  }

  /**
   * Tests the rules around disabled page variants.
   *
   * Disabled variants keep rendering where they are already selected, and
   * such pages stay saveable, but the variant cannot be selected anew and
   * cannot be (or stay) the site default.
   */
  public function testDisabledVariantRules(): void {
    $field_manager = $this->container->get(EntityFieldManagerInterface::class);
    self::assertInstanceOf(EntityFieldManagerInterface::class, $field_manager);
    $definition = $field_manager->getFieldStorageDefinitions(Page::ENTITY_TYPE_ID)['page_variant'];

    PageVariant::create(['id' => 'main', 'label' => 'Main', 'component_tree' => [self::markerInstance()]])->save();
    $other = PageVariant::create(['id' => 'other', 'label' => 'Other', 'component_tree' => [self::markerInstance()]]);
    $other->save();

    // A page and a content template select 'other' while it is enabled; then
    // 'other' is disabled.
    $page = Page::create(['title' => 'Existing page', 'page_variant' => 'other']);
    self::assertSaveWithoutViolations($page);
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'page_variant' => 'other',
    ])->save();
    $other->setStatus(FALSE)->save();

    // Disabled variants are omitted from new selections…
    self::assertSame(['main' => 'Main'], PageVariant::allowedValues());
    self::assertSame(['main' => 'Main'], PageVariant::allowedValues($definition, Page::create(['title' => 'New page'])));

    // …but the page that already persisted the selection keeps it: it still
    // validates, saves, and renders through the disabled variant.
    $existing = Page::load($page->id());
    self::assertInstanceOf(Page::class, $existing);
    self::assertSame(['main' => 'Main', 'other' => 'Other'], PageVariant::allowedValues($definition, $existing));
    self::assertSaveWithoutViolations($existing);
    $resolver = $this->container->get(PageVariantResolver::class);
    self::assertInstanceOf(PageVariantResolver::class, $resolver);
    self::assertSame('other', $resolver->resolve($existing)?->id());

    // A new page cannot select the disabled variant — not even by setting the
    // value directly, because the persisted (not in-memory) selection is what
    // allowedValues() honors.
    $sneaky = Page::create(['title' => 'Sneaky page', 'page_variant' => 'other']);
    $messages = \array_map(
      static fn ($violation): string => (string) $violation->getMessage(),
      \iterator_to_array($sneaky->validate()->filterByFields(['path'])),
    );
    self::assertContains('The value you selected is not a valid choice.', $messages);

    // The same rules apply to content templates, whose selection has no
    // options list: the schema-level guard rejects selecting the disabled
    // variant anew…
    $disabled_selection_message = 'The page variant <em class="placeholder">other</em> is disabled and cannot be selected.';
    $new_template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'teaser',
      'page_variant' => 'other',
    ]);
    self::assertContains($disabled_selection_message, \array_map(
      static fn ($violation): string => (string) $violation->getMessage(),
      \iterator_to_array($new_template->getTypedData()->validate()),
    ));

    // …while the template whose *persisted* selection is the disabled variant
    // stays valid (and publishable).
    $persisted_template = ContentTemplate::load('node.helpful.full');
    self::assertInstanceOf(ContentTemplate::class, $persisted_template);
    self::assertNotContains($disabled_selection_message, \array_map(
      static fn ($violation): string => (string) $violation->getMessage(),
      \iterator_to_array($persisted_template->getTypedData()->validate()),
    ));

    // The site default cannot be disabled.
    $this->config('canvas.settings')->set(PageVariant::DEFAULT_SETTING, 'main')->save();
    $main = PageVariant::load('main');
    self::assertInstanceOf(PageVariant::class, $main);
    $main->setStatus(FALSE);
    self::assertContains(
      'The site default page variant cannot be disabled. Set another variant as the default first.',
      self::markerViolations($main),
    );

    // A disabled variant cannot become the site default.
    $controller = ApiSettingsController::create($this->container);
    try {
      $controller->setDefaultPageVariant(Request::create('/', 'PATCH', content: (string) \json_encode(['default_page_variant' => 'other'])));
      $this->fail('Setting a disabled variant as the site default must be rejected.');
    }
    catch (UnprocessableEntityHttpException $e) {
      self::assertSame('The page variant "other" is disabled and cannot be the site default.', $e->getMessage());
    }
  }

  /**
   * Tests the config schema constraints on `canvas.settings`.
   *
   * The settings endpoint checks existence at request time; the schema must
   * also reject a dangling default so other write paths (config import,
   * drush config:set) are covered too.
   */
  public function testDefaultPageVariantSettingValidation(): void {
    $typed_config_manager = $this->container->get(TypedConfigManagerInterface::class);
    $validate = static fn (?string $id): array => self::violationsToArray(
      $typed_config_manager->createFromNameAndData('canvas.settings', ['default_page_variant' => $id])->validate()
    );

    // No default is allowed.
    self::assertSame([], $validate(NULL));

    // An existing variant is allowed.
    PageVariant::create(['id' => 'home', 'label' => 'Home', 'component_tree' => [self::markerInstance()]])->save();
    self::assertSame([], $validate('home'));

    // A dangling reference is rejected.
    self::assertSame(
      ['default_page_variant' => "The 'canvas.page_variant.ghost' config does not exist."],
      $validate('ghost'),
    );

    // A malformed machine name is rejected.
    self::assertArrayHasKey('default_page_variant', $validate('Not Valid'));
  }

}
