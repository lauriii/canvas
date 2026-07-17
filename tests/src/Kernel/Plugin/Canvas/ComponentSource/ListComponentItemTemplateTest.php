<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the List element's component-built item template.
 *
 * Covers the deferred slot mechanism end to end: validation of template
 * subtrees in a content tree, prop expression resolution against the
 * iterated result entity, per-result template rendering, and the editor
 * preview's single-annotation behavior.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceWithDeferredSlotsInterface
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ListComponent::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class ListComponentItemTemplateTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string LIST_UUID = '3c2f36ea-3f56-46f4-b267-3ae89aed4bd0';
  private const string HEADING_UUID = '41d532cf-fdbd-4a24-99b4-fd3160e17c02';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_sdc',
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
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: ['access content']);

    foreach ([1, 2, 3] as $i) {
      Node::create([
        'type' => 'article',
        'title' => 'Template article ' . $i,
        'status' => NodeInterface::PUBLISHED,
        'created' => \Drupal::time()->getRequestTime() - $i * 100,
      ])->save();
    }
  }

  /**
   * Builds a canvas_page hosting a List with a heading in its item template.
   */
  private static function createPageWithTemplate(): Page {
    $page = Page::create([
      'title' => 'Item template test page',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::LIST_UUID,
          'component_id' => 'list.list',
          'inputs' => [
            'source' => ['entity_type' => 'node', 'bundle' => 'article'],
            'display' => ['mode' => 'item_template'],
            'limit' => 3,
            'pagination' => ['mode' => 'none', 'page_size' => 10],
            'filters' => ['conjunction' => 'and', 'conditions' => []],
            'sorts' => [['field' => 'created', 'direction' => 'desc']],
            'layout' => ['mode' => 'stack', 'gap' => 'medium'],
          ],
        ],
        [
          'uuid' => self::HEADING_UUID,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'parent_uuid' => self::LIST_UUID,
          'slot' => ListComponent::ITEM_TEMPLATE_SLOT,
          'inputs' => [
            // The template's prop expression targets the list's source
            // bundle, not the host entity (a canvas_page): this is only
            // valid inside the deferred item template slot.
            'text' => [
              'sourceType' => 'entity-field',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'element' => 'h3',
          ],
        ],
      ],
    ]);
    return $page;
  }

  /**
   * Template subtrees validate and render once per result entity.
   */
  public function testItemTemplate(): void {
    $page = self::createPageWithTemplate();

    // Validation of the template subtree: the entity field prop expression
    // targeting the source bundle is allowed inside the deferred slot, and
    // is validated against a representative article rather than the page.
    $violations = $page->validate();
    self::assertSame([], \array_map(
      static fn ($violation): string => $violation->getPropertyPath() . ': ' . $violation->getMessage(),
      \iterator_to_array($violations),
    ));
    $page->save();

    // Live rendering: the template renders once per result, each repetition
    // resolving the expression against its own result entity.
    $html = $this->renderPageTree($page, FALSE);
    self::assertStringContainsString('Template article 1', $html);
    self::assertStringContainsString('Template article 2', $html);
    self::assertStringContainsString('Template article 3', $html);
    self::assertSame(3, \substr_count($html, 'canvas-list__item'));
    // The live page carries no editing annotations.
    self::assertStringNotContainsString('canvas-slot-start-', $html);
    self::assertStringNotContainsString('canvas-start-' . self::HEADING_UUID, $html);

    // Preview rendering: all repetitions render, but only the first carries
    // the editing annotations, so the template's components appear exactly
    // once to the editor; the slot region is annotated for drag and drop.
    $preview_html = $this->renderPageTree($page, TRUE);
    self::assertStringContainsString('Template article 1', $preview_html);
    self::assertStringContainsString('Template article 3', $preview_html);
    self::assertSame(1, \substr_count($preview_html, 'canvas-start-' . self::HEADING_UUID));
    self::assertSame(1, \substr_count($preview_html, \sprintf('canvas-slot-start-%s/%s', self::LIST_UUID, ListComponent::ITEM_TEMPLATE_SLOT)));
  }

  /**
   * An empty item template previews with an annotated, droppable slot region.
   */
  public function testEmptyTemplatePreview(): void {
    $page = self::createPageWithTemplate();
    // Remove the heading: only the List with an empty template remains.
    $values = \array_filter($page->get('components')->getValue(), static fn (array $value): bool => $value['uuid'] === self::LIST_UUID);
    $page->set('components', \array_values($values));
    self::assertEntityIsValid($page);
    $page->save();

    $preview_html = $this->renderPageTree($page, TRUE);
    self::assertSame(1, \substr_count($preview_html, \sprintf('canvas-slot-start-%s/%s', self::LIST_UUID, ListComponent::ITEM_TEMPLATE_SLOT)));
    // The empty-slot placeholder renders inside the slot region.
    self::assertStringContainsString('canvas--slot-empty-placeholder', $preview_html);

    // On the live site an empty template renders no items at all.
    $html = $this->renderPageTree($page, FALSE);
    self::assertStringNotContainsString('canvas--slot-empty-placeholder', $html);
    self::assertStringNotContainsString('Template article', $html);
  }

  /**
   * Renders a page's component tree to HTML.
   */
  private function renderPageTree(Page $page, bool $is_preview): string {
    $components = $page->get('components');
    \assert($components instanceof ComponentTreeItemList);
    $build = $components->toRenderable($page, $is_preview);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
