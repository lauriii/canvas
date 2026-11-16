<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rendering a content template for a content entity type besides `node`.
 *
 * @legacy-covers \Drupal\canvas\Hook\ContentTemplateHooks::entityTypeAlter
 * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class TaxonomyTermTemplatesTest extends FunctionalTestBase {

  use GenerateComponentConfigTrait;

  /**
   * Rendered only by the content template, never by core's taxonomy term View.
   */
  private const string TEMPLATE_MARKER = 'Rendered by Drupal Canvas';

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'taxonomy',
    // Provides core's `taxonomy_term` View, which owns `/taxonomy/term/{tid}`.
    'views',
  ];

  /**
   * {@inheritdoc}
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * A taxonomy term template renders, but nested inside core's View.
   *
   * Supporting an entity type is not the whole story: whatever owns the
   * entity's canonical route decides how much of the page Canvas controls. For
   * taxonomy terms, core's `taxonomy_term` View owns `taxonomy/term/%`, so the
   * template does NOT become the page. It still renders, because that View's
   * header is an `entity` area handler pointing at the term in the `full` view
   * mode — so the template appears as a fragment inside the View, above the
   * listing of tagged nodes and the feed link.
   *
   * To have Canvas own the whole term page, that View (or at least its page
   * display) has to go. This test demonstrates both states.
   *
   * @see core/modules/taxonomy/config/optional/views.view.taxonomy_term.yml
   * @see \Drupal\views\Plugin\views\area\Entity
   */
  public function testTaxonomyTermTemplateRendersInsideCoreView(): void {
    $this->generateComponentConfig();

    // `taxonomy_term` has a `field_ui_base_route`, so Canvas renders it.
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $this->assertInstanceOf(
      ContentTemplateAwareViewBuilder::class,
      $entity_type_manager->getViewBuilder('taxonomy_term'),
    );

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'Cats']);
    $term->save();

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'taxonomy_term',
      'content_entity_type_bundle' => 'tags',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => self::TEMPLATE_MARKER,
          ],
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    $this->assertSame('taxonomy_term.tags.full', $template->id());

    $account = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);

    // The premise of this test: core's View is installed and owns the route.
    $view = View::load('taxonomy_term');
    $this->assertInstanceOf(View::class, $view);

    // 1. The template renders, but only as a fragment inside the View: Canvas
    //    does not own the page, so the View's own output is present too.
    $this->drupalGet($term->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(self::TEMPLATE_MARKER);
    $this->assertSession()->elementExists('css', '.views-element-container');
    $this->assertSession()->elementExists('css', 'a.feed-icon');

    // 2. Remove the View, so the term's canonical route is no longer overridden
    //    and core's own controller renders the term via its view builder.
    // @see \Drupal\views\Plugin\views\display\PathPluginBase::alterRoutes()
    $view->delete();
    $route_builder = $this->container->get(RouteBuilderInterface::class);
    \assert($route_builder instanceof RouteBuilderInterface);
    $route_builder->rebuild();

    // 3. Now Canvas owns the term's entire rendered output: same template, no
    //    View wrapper, no node listing, no feed link.
    $this->drupalGet($term->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(self::TEMPLATE_MARKER);
    $this->assertSession()->elementNotExists('css', '.views-element-container');
    $this->assertSession()->elementNotExists('css', 'a.feed-icon');
  }

}
