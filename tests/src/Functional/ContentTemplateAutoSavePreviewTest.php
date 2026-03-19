<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests that auto-saved content templates are used in Canvas preview mode.
 *
 * When editing a Canvas Page that contains a View block, and that View renders
 * entities using a content template, the preview should use auto-saved versions
 * of those content templates.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ContentTemplateAutoSavePreviewTest extends FunctionalTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'dynamic_page_cache',
    'node',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private function getCrawlerForPageInEditor(Page $page): Crawler {
    $this->drupalGet("/canvas/api/v0/layout/canvas_page/{$page->id()}");
    $this->assertSession()->statusCodeEquals(200);
    $parsed_response = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('html', $parsed_response);
    return new Crawler($parsed_response['html']);
  }

  /**
   * Tests that auto-saved content templates are used in Canvas Page preview.
   *
   * This tests the scenario where:
   * 1. A content template exists for the "teaser" view mode
   * 2. A View block renders nodes in "teaser" view mode
   * 3. The View block is placed on a Canvas Page
   * 4. When previewing the Canvas Page in the editor, the View should use
   *    the auto-saved version of the teaser content template.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::loadTemplate
   * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::buildComponents
   */
  public function testAutoSavedTemplateUsedInCanvasPagePreview(): void {
    // Set up content type.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Create a user with permissions to edit Canvas pages.
    $admin_user = $this->drupalCreateUser([
      'access content',
      'administer nodes',
      'administer content templates',
      Page::EDIT_PERMISSION,
    ]);
    $this->assertInstanceOf(AccountInterface::class, $admin_user);
    $this->drupalLogin($admin_user);

    // Create a content template for the teaser view mode.
    // Use the props-no-slots component which has a simple heading prop.
    $template = ContentTemplate::create([
      'id' => 'node.article.teaser',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => 'Published teaser template',
          ],
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    self::assertSame('node.article.teaser', $template->id());

    // Create a View that displays articles in teaser view mode.
    $view = View::create([
      'id' => 'test_articles',
      'label' => 'Test Articles',
      'description' => 'A view for testing article teasers.',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'title' => 'Test Articles',
            'access' => [
              'type' => 'perm',
              'options' => [
                'perm' => 'access content',
              ],
            ],
            'cache' => [
              'type' => 'none',
              'options' => [],
            ],
            'pager' => [
              'type' => 'none',
              'options' => [
                'offset' => 0,
              ],
            ],
            'style' => [
              'type' => 'default',
              'options' => [],
            ],
            'row' => [
              'type' => 'entity:node',
              'options' => [
                'view_mode' => 'teaser',
              ],
            ],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'node_field_data',
                'field' => 'status',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'plugin_id' => 'boolean',
                'value' => '1',
              ],
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'plugin_id' => 'bundle',
                'value' => ['article' => 'article'],
              ],
            ],
          ],
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Block',
          'display_plugin' => 'block',
          'position' => 1,
          'display_options' => [
            'block_description' => 'Article teasers block',
          ],
        ],
      ],
    ]);
    $view->save();

    // Trigger component discovery to create the View block component.
    $this->generateComponentConfig();

    // Enable the View block component.
    $view_block_component = Component::load('block.views_block.test_articles-block_1');
    self::assertNotNull($view_block_component, 'View block component should exist');
    $view_block_component->enable()->save();

    // Get the View block component's default settings.
    $view_block_settings = $view_block_component->getSettings()['default_settings'];

    // Create an article node that will be displayed by the View.
    $node = Node::create([
      'title' => 'Test article for View',
      'type' => 'article',
      'status' => TRUE,
    ]);
    self::assertCount(0, $node->validate());
    $node->save();

    // Create a Canvas Page that contains the View block.
    $page = Page::create([
      'title' => 'Test Canvas Page with View',
      'components' => [
        [
          'uuid' => '550e8400-e29b-41d4-a716-446655440000',
          'component_id' => 'block.views_block.test_articles-block_1',
          'inputs' => $view_block_settings,
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();

    // Case 1: Without auto-save, preview should show published template.
    $crawler = $this->getCrawlerForPageInEditor($page);
    self::assertStringContainsString('Published teaser template', $crawler->text());
    self::assertStringNotContainsString('Auto-saved teaser template', $crawler->text());

    // Create an auto-save entry for the teaser content template.
    // This simulates what happens when a user edits the template in the Canvas
    // editor - saveEntity() writes to the key-value store and invalidates
    // cache tags.
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);
    $template->set('component_tree', [
      [
        'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Auto-saved teaser template',
        ],
      ],
    ]);
    $autoSaveManager->saveEntity($template);

    // Case 2: With auto-save, Canvas Page preview should show auto-saved template.
    // The layout route has _canvas_use_template_draft: TRUE, so the View should
    // render articles using the auto-saved teaser content template.
    $crawler = $this->getCrawlerForPageInEditor($page);
    self::assertStringContainsString('Auto-saved teaser template', $crawler->text());
    self::assertStringNotContainsString('Published teaser template', $crawler->text());

    // Case 3: On the Canvas Page's canonical URL (not preview), published
    // template should be used.
    $this->drupalGet($page->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $page_content = $this->getSession()->getPage()->getContent();
    self::assertStringContainsString('Published teaser template', $page_content);
    self::assertStringNotContainsString('Auto-saved teaser template', $page_content);

    // Case 4: Update the auto-save template and verify the change is picked up.
    $template->set('component_tree', [
      [
        'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Updated auto-saved teaser template',
        ],
      ],
    ]);
    $autoSaveManager->saveEntity($template);

    $crawler = $this->getCrawlerForPageInEditor($page);
    self::assertStringContainsString('Updated auto-saved teaser template', $crawler->text());
    self::assertStringNotContainsString('Auto-saved teaser template', $crawler->text());
    self::assertStringNotContainsString('Published teaser template', $crawler->text());

    // Case 5: Delete the auto-save entry and verify the published template is
    // used again in preview mode.
    $autoSaveManager->delete($template);

    $crawler = $this->getCrawlerForPageInEditor($page);
    self::assertStringContainsString('Published teaser template', $crawler->text());
    self::assertStringNotContainsString('Auto-saved teaser template', $crawler->text());
    self::assertStringNotContainsString('Updated auto-saved teaser template', $crawler->text());
  }

  /**
   * Tests that auto-save changes don't invalidate the live site cache.
   *
   * This tests the key caching behavior:
   * - On canonical (live) routes: AutoSaveManager::CACHE_TAG is NOT added,
   *   so auto-save changes don't invalidate the render cache.
   * - This means the live site performance is not affected by editors making
   *   auto-save changes in the Canvas UI.
   *
   * The preview route behavior (using auto-saved templates) is tested in
   * ::testAutoSavedTemplateUsedInCanvasPagePreview().
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults
   * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::buildComponents
   * @legacy-covers \Drupal\canvas\Cache\CanvasEditorUiCacheContext
   */
  public function testAutoSaveDoesNotInvalidateLiveSiteCache(): void {
    // Set up content type.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Create a user with permissions.
    $admin_user = $this->drupalCreateUser([
      'access content',
      'administer nodes',
      'administer content templates',
      Page::EDIT_PERMISSION,
    ]);
    $this->assertInstanceOf(AccountInterface::class, $admin_user);
    $this->drupalLogin($admin_user);

    // Create a content template for the full view mode.
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => 'Published content template',
          ],
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();

    // Create a node to test with.
    $node = Node::create([
      'title' => 'Test node for cacheability',
      'type' => 'article',
      'status' => TRUE,
    ]);
    self::assertCount(0, $node->validate());
    $node->save();

    // Test canonical route cacheability: first request should be cache MISS.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals(DynamicPageCacheSubscriber::HEADER, 'MISS');

    // Second request to canonical route should be cache HIT.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->responseHeaderEquals(DynamicPageCacheSubscriber::HEADER, 'HIT');

    // Create an auto-save entry for the content template.
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);
    $template->set('component_tree', [
      [
        'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Auto-saved content template',
        ],
      ],
    ]);
    $autoSaveManager->saveEntity($template);

    // KEY ASSERTION: Canonical route cache should NOT be invalidated by
    // auto-save changes. This is critical for live site performance.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->responseHeaderEquals(DynamicPageCacheSubscriber::HEADER, 'HIT');
    // Verify the live site still shows the published template.
    $this->assertSession()->pageTextContains('Published content template');
    $this->assertSession()->pageTextNotContains('Auto-saved content template');

    // Update the auto-save again to verify repeated auto-saves don't invalidate.
    $template->set('component_tree', [
      [
        'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Updated auto-saved content template',
        ],
      ],
    ]);
    $autoSaveManager->saveEntity($template);

    // Cache should STILL be a HIT - auto-saves don't affect the live site.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->responseHeaderEquals(DynamicPageCacheSubscriber::HEADER, 'HIT');
    // And still shows the published template.
    $this->assertSession()->pageTextContains('Published content template');
    $this->assertSession()->pageTextNotContains('Updated auto-saved content template');
  }

}
