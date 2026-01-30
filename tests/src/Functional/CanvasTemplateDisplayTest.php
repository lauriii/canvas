<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Hook\ContentTemplateHooks;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests view mode tab visibility and template link behavior.
 *
 * Verifies:
 * - Default tabs shown when no template exists.
 * - Template link hidden when template disabled.
 * - View mode tab hidden when template enabled without permission.
 * - Template link replaces view mode tab with permission.
 * - Default tabs restored after template deletion.
 */
#[Group('canvas')]
#[CoversMethod(ContentTemplateHooks::class, 'menuLocalTasksAlter')]
#[CoversMethod(ContentTemplateHooks::class, 'preprocessMenuLocalTask')]
final class CanvasTemplateDisplayTest extends BrowserTestBase {

  /**
   * XPath selector for secondary navigation tabs.
   */
  private const string SECONDARY_TABS_XPATH = '//nav[@aria-labelledby="secondary-tabs-title"]//a';

  /**
   * XPath selector for Canvas template links.
   *
   * Identifies Canvas template links by their URL pattern (canvas/template/),
   * which is the actual change made by preprocessMenuLocalTask().
   */
  private const string TEMPLATE_LINK_XPATH = self::SECONDARY_TABS_XPATH . '[contains(@href, "canvas/template/")]';

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'field_ui',
    'node',
    // The module being tested.
    'canvas',
  ];

  /**
   * {@inheritdoc}
   *
   * @var string
   */
  protected $defaultTheme = 'claro';

  /**
   * The entity view display storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $viewDisplayStorage;

  /**
   * The content template storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $contentTemplateStorage;

  /**
   * The currently logged in user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $entityTypeManager = $this->container->get('entity_type.manager');
    $this->viewDisplayStorage = $entityTypeManager->getStorage('entity_view_display');
    $this->contentTemplateStorage = $entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);

    $currentUser = $this->drupalCreateUser([
      'administer content types',
      'administer node display',
    ]);
    \assert($currentUser instanceof UserInterface);
    $this->currentUser = $currentUser;
    $this->drupalLogin($this->currentUser);

    $this->drupalCreateRole([
      ContentTemplate::ADMIN_PERMISSION,
    ], 'canvas_template_admin');
  }

  /**
   * Tests that Manage Display tabs update when template is created or deleted.
   *
   * @param string $bundle
   *   The node bundle (e.g., 'article').
   * @param string $view_mode_id
   *   The view mode machine name (e.g., 'full').
   * @param string $view_mode_label
   *   The view mode label (e.g., 'Full content').
   */
  #[TestWith(['article', 'full', 'Full content'])]
  #[TestWith(['article', 'teaser', 'Teaser'])]
  public function testViewModeTabVisibilityWithContentTemplate(string $bundle, string $view_mode_id, string $view_mode_label): void {
    $this->createNodeType(['type' => $bundle]);
    $this->createViewDisplay($bundle, $view_mode_id);

    // Test 1: No template exists - should show default tabs only.
    $crawler = $this->getManageDisplayPageCrawler($bundle);
    $this->assertDefaultTabsOnly($crawler);
    $this->assertTemplateLinkCount($crawler, 0, 'The template link should not exist.');

    // Test 2: Template exists but is disabled - should still show default tab.
    $template = $this->createContentTemplate($bundle, $view_mode_id);
    $crawler = $this->getManageDisplayPageCrawler($bundle);
    $this->assertDefaultTabsOnly($crawler);
    $this->assertTemplateLinkCount($crawler, 0, 'The template link should not exist.');

    // Test 3: Template is enabled - should hide view mode tab (no permission).
    $this->setTemplateStatus($template, TRUE);
    $crawler = $this->getManageDisplayPageCrawler($bundle);
    // As there's only 1 tab left, the menu tabs container is not rendered.
    $this->assertTabsCount($crawler, 0, 'No tabs should be visible when template is enabled without permission.');
    $this->assertViewModeTabDoesNotExist($crawler, $view_mode_label);

    // Test 4: Verify direct access to view mode display page is forbidden.
    $this->drupalGet("admin/structure/types/manage/$bundle/display/$view_mode_id");
    $this->assertSession()->statusCodeEquals(403);

    // Test 5: Grant permission - should show template link instead of view
    // mode tab.
    $this->currentUser->addRole('canvas_template_admin')->save();
    $crawler = $this->getManageDisplayPageCrawler($bundle);
    $this->assertDefaultTabsOnly($crawler);
    $this->assertTemplateLinkExists($crawler, $bundle, $view_mode_id, $view_mode_label);

    // Test 6: Verify direct access to view mode display page should redirects
    // to template preview.
    $this->drupalGet("admin/structure/types/manage/$bundle/display/$view_mode_id");
    $this->assertStringContainsString("canvas/template/node/$bundle/$view_mode_id", $this->getSession()->getCurrentUrl());

    // Test 7: Template disabled - should restore default tabs.
    $this->setTemplateStatus($template, FALSE);
    $crawler = $this->getManageDisplayPageCrawler($bundle);
    $this->assertDefaultTabsOnly($crawler);
    $this->assertTemplateLinkCount($crawler, 0, 'The template link should not exist.');

    // Verify direct access to view mode display page is restored.
    $this->drupalGet("admin/structure/types/manage/$bundle/display/$view_mode_id");
    $this->assertStringContainsString("admin/structure/types/manage/$bundle/display/$view_mode_id", $this->getSession()->getCurrentUrl());

    // Test 8: Template deleted should also show default tabs.
    $template->delete();
    $crawler = $this->getManageDisplayPageCrawler($bundle);
    $this->assertDefaultTabsOnly($crawler);
    $this->assertTemplateLinkCount($crawler, 0, 'The template link should not exist.');

    // Verify direct access to view mode display page is restored.
    $this->drupalGet("admin/structure/types/manage/$bundle/display/$view_mode_id");
    $this->assertStringContainsString("admin/structure/types/manage/$bundle/display/$view_mode_id", $this->getSession()->getCurrentUrl());

  }

  /**
   * Creates a view display for the given bundle and view mode.
   */
  private function createViewDisplay(string $bundle, string $view_mode_id): void {
    $view_display_id = "node.$bundle.$view_mode_id";
    $view_display = $this->viewDisplayStorage->load($view_display_id);

    if (!$view_display instanceof EntityViewDisplayInterface) {
      $this->viewDisplayStorage->create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => $view_mode_id,
        'status' => TRUE,
      ])->save();
    }
  }

  /**
   * Creates a content template.
   */
  private function createContentTemplate(string $bundle, string $view_mode_id, bool $enabled = FALSE): ConfigEntityInterface {
    $template = $this->contentTemplateStorage->create([
      'id' => "node.$bundle.$view_mode_id",
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode_id,
      'component_tree' => [],
      'status' => $enabled,
    ]);
    \assert($template instanceof ConfigEntityInterface);
    $template->save();

    return $template;
  }

  /**
   * Sets the status of a content template.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $template
   *   The content template.
   * @param bool $status
   *   TRUE to enable, FALSE to disable.
   */
  private function setTemplateStatus(ConfigEntityInterface $template, bool $status): void {
    $template->setStatus($status);
    $template->save();
  }

  /**
   * Gets a Crawler for the Manage Display page.
   */
  private function getManageDisplayPageCrawler(string $bundle): Crawler {
    $html = $this->drupalGet("admin/structure/types/manage/$bundle/display");
    return new Crawler($html);
  }

  /**
   * Asserts that only the default tabs are present.
   */
  private function assertDefaultTabsOnly(Crawler $crawler): void {
    $this->assertTabsCount($crawler, 2, 'There should be 2 default tabs (Default, Full content).');
  }

  /**
   * Asserts the number of secondary tabs.
   */
  private function assertTabsCount(Crawler $crawler, int $expected_count, string $message = ''): void {
    $tabs = $crawler->filterXPath(self::SECONDARY_TABS_XPATH);
    $this->assertCount($expected_count, $tabs, $message);
  }

  /**
   * Asserts that the template link exists with correct attributes.
   */
  private function assertTemplateLinkExists(Crawler $crawler, string $bundle, string $view_mode_id, string $view_mode_label): void {
    $template_link = $crawler->filterXPath(self::TEMPLATE_LINK_XPATH);
    $this->assertCount(1, $template_link, 'Exactly one template link should exist.');

    $href = $template_link->attr('href');
    $text = $template_link->text();

    $this->assertNotEmpty($href, 'Template link href should not be empty.');
    $this->assertSame($view_mode_label, $text, 'Template link text should match view mode label.');
    $this->assertStringContainsString(
      "canvas/template/node/$bundle/$view_mode_id",
      $href,
      'Template link href should point to Canvas template editor.'
    );
  }

  /**
   * Asserts the number of template links.
   */
  private function assertTemplateLinkCount(Crawler $crawler, int $expected_count, string $message = ''): void {
    $template_links = $crawler->filterXPath(self::TEMPLATE_LINK_XPATH);
    $this->assertCount($expected_count, $template_links, $message);
  }

  /**
   * Asserts that a view mode tab does not exist.
   */
  private function assertViewModeTabDoesNotExist(Crawler $crawler, string $link_text): void {
    $tab_link = $this->findTabByText($crawler, $link_text);
    $this->assertNull($tab_link, "The tab with text '$link_text' should not exist.");
  }

  /**
   * Finds a tab by its text content.
   */
  private function findTabByText(Crawler $crawler, string $link_text): ?Crawler {
    $tab_links = $crawler->filterXPath(self::SECONDARY_TABS_XPATH);

    foreach ($tab_links as $tab_link_element) {
      $tab_link = new Crawler($tab_link_element);
      if ($tab_link->text() === $link_text) {
        return $tab_link;
      }
    }

    return NULL;
  }

  /**
   * Creates a custom content type based on default settings.
   *
   * @param array<string, string> $values
   *   An array of settings to change from the defaults.
   *   Example: 'type' => 'foo'.
   *
   * @return \Drupal\node\NodeTypeInterface
   *   Created content type.
   */
  protected function createNodeType(array $values = []): NodeTypeInterface {
    $values += [
      'type' => $this->randomMachineName(8),
      'name' => $this->randomMachineName(8),
    ];
    $type = $this->container->get('entity_type.manager')
      ->getStorage('node_type')->create($values);
    $status = $type->save();
    $this->assertSame($status, SAVED_NEW);
    return $type;
  }

}
