<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\EditableContentDiscovery;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the discovery of Canvas-editable entity type+bundle pairs.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(EditableContentDiscovery::class)]
#[Group('canvas')]
final class EditableContentDiscoveryTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    $this->createContentType(['type' => 'article']);
    $this->createContentType(['type' => 'untemplated']);
    $this->generateComponentConfig();
  }

  public function testDiscovery(): void {
    $discovery = $this->container->get(EditableContentDiscovery::class);
    \assert($discovery instanceof EditableContentDiscovery);

    // canvas_page is always editable, template or not.
    $this->assertSame([Page::ENTITY_TYPE_ID], $discovery->getEditableBundles(Page::ENTITY_TYPE_ID));
    $this->assertTrue($discovery->isEditable(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID));
    $this->assertSame([Page::ENTITY_TYPE_ID => [Page::ENTITY_TYPE_ID]], $discovery->getEditableTypeBundlePairs());

    // Without any template, node bundles are not editable.
    $this->assertSame([], $discovery->getEditableBundles('node'));
    $this->assertFalse($discovery->isEditable('node', 'article'));
    $this->assertFalse($discovery->isEditableEntityType('node'));

    // An enabled `full` view mode template makes its bundle editable, even
    // with no exposed slots (the "no creative freedom" tier).
    $template = $this->createArticleTemplate('full');
    // The loader memoizes per request; a fresh service instance sees the new
    // template like a new request would.
    $discovery = $this->freshDiscovery();
    $this->assertSame(['article'], $discovery->getEditableBundles('node'));
    $this->assertTrue($discovery->isEditable('node', 'article'));
    $this->assertFalse($discovery->isEditable('node', 'untemplated'));
    $this->assertSame(
      [Page::ENTITY_TYPE_ID => [Page::ENTITY_TYPE_ID], 'node' => ['article']],
      $discovery->getEditableTypeBundlePairs(),
    );

    // A disabled template does not qualify its bundle.
    $template->setStatus(FALSE)->save();
    $this->assertSame([], $this->freshDiscovery()->getEditableBundles('node'));

    // A non-`full` view mode template does not qualify its bundle either:
    // per-content editing always resolves the `full` template.
    $template->delete();
    EntityViewMode::create([
      'id' => 'node.teaser2',
      'label' => 'Teaser 2',
      'targetEntityType' => 'node',
    ])->save();
    $this->createArticleTemplate('teaser2');
    $this->assertSame([], $this->freshDiscovery()->getEditableBundles('node'));

    // The cacheability names the tags that invalidate the editable set.
    $tags = $this->freshDiscovery()->getCacheability()->getCacheTags();
    $this->assertContains('content_template_list', $tags);
    $this->assertContains('entity_bundles', $tags);
  }

  /**
   * Creates an enabled article template for the given view mode.
   */
  private function createArticleTemplate(string $view_mode): ContentTemplate {
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => $view_mode,
      'component_tree' => [
        [
          'uuid' => '6ba61b30-e340-49e2-aeb9-b3d3e0bd0430',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    return $template;
  }

  /**
   * A discovery instance without the request-scoped memo of a previous one.
   */
  private function freshDiscovery(): EditableContentDiscovery {
    return new EditableContentDiscovery(
      $this->container->get('entity_type.manager'),
      new ComponentTreeLoader(
        $this->container->get('entity_field.manager'),
        $this->container->get('module_handler'),
        $this->container->get('entity_type.manager'),
      ),
    );
  }

}
