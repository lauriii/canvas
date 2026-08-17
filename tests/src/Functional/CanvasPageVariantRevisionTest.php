<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests CanvasPageVariant on pages that have no title.
 *
 * The page title can legitimately be empty: `HtmlRenderer::prepare()` passes an
 * empty title when the rendered main content provides no `#title` and the route
 * has no `_title`/`_title_callback`. The node revision route
 * (`entity.node.revision`) is exactly such a route: unlike the canonical route,
 * it has no title callback, so the page title is whatever the rendered node
 * provides. When the node's own title field is hidden (because the page title is
 * rendered via the `page_title_block` instead), that is nothing.
 *
 * @see https://git.drupalcode.org/project/canvas/-/issues/3585787
 */
#[Group('canvas')]
#[CoversClass(CanvasPageVariant::class)]
#[RunTestsInSeparateProcesses]
class CanvasPageVariantRevisionTest extends BrowserTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'canvas',
    'node',
    'canvas_test_configurable_node_title',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An old revision of non-Canvas content whose page title is empty renders.
   */
  public function testViewOldRevisionWithEmptyTitle(): void {
    $this->generateComponentConfig();

    // A content type whose page title is rendered by the `page_title_block`, not
    // by the node's own title field: hide the title field in the full display.
    NodeType::create(['type' => 'utility', 'name' => 'Utility page'])->save();
    $display = EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'utility',
      'mode' => 'full',
      'status' => TRUE,
    ]);
    $display->removeComponent('title')->save();
    self::assertNull($display->getComponent('title'));

    // Enable CanvasPageVariant by creating an enabled PageRegion that renders the
    // page title via a block.
    PageRegion::create([
      'theme' => 'stark',
      'region' => 'header',
      'component_tree' => [
        [
          'uuid' => '5fc4de04-f59c-4f56-b576-4673433381a4',
          'component_id' => 'block.page_title_block',
          'component_version' => Component::load('block.page_title_block')?->getActiveVersion(),
          'inputs' => [
            'label' => '',
            'label_display' => '0',
          ],
        ],
      ],
    ])->save();

    // Create a node with two revisions, mirroring the reproduction steps.
    $node = Node::create(['type' => 'utility', 'title' => 'My utility page', 'status' => TRUE]);
    $node->save();
    $old_vid = $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $node->save();
    self::assertNotSame($old_vid, $node->getRevisionId());

    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'view all revisions',
    ]));

    // The canonical route works because it has a title callback fallback.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // Viewing the old revision must not trigger an AssertionError: the revision
    // route has no title callback, so the page title is empty.
    $this->drupalGet("/node/{$node->id()}/revisions/$old_vid/view");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('AssertionError');
  }

}
