<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\ShapeMatcher\PropSourceSuggester;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a multi-value field of the host entity as a List element source.
 *
 * @see \Drupal\canvas\PropSource\ItemPropSource
 * @see docs/adr/0021-item-template-data-context-is-a-field-item.md
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ListComponent::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class ListComponentFieldSourceTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string LIST_UUID = '9a0b1c2d-3e4f-4a5b-8c7d-6e5f4a3b2c1d';
  private const string ITEM_HEADING_UUID = '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e';
  private const string PAGE_HEADING_UUID = '2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_sdc',
    'node',
    'field',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    $this->installConfig(['canvas']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_captions',
      'type' => 'string',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_captions',
      'bundle' => 'article',
      'label' => 'Captions',
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_topics',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_topics',
      'bundle' => 'article',
      'label' => 'Topics',
      'settings' => ['handler_settings' => ['target_bundles' => ['topics' => 'topics']]],
    ])->save();

    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  /**
   * Creates a content template iterating a field, with an item template.
   *
   * @param string $field_name
   *   The field the List iterates.
   * @param string $item_expression
   *   The field-item-rooted expression the item heading binds to.
   * @param int $limit
   *   The maximum number of items.
   */
  private static function createTemplate(string $field_name, string $item_expression, int $limit = 10): ContentTemplate {
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::LIST_UUID,
          'component_id' => 'list.list',
          'component_version' => self::activeVersion('list.list'),
          'inputs' => [
            'source' => ['kind' => 'field', 'field_name' => $field_name],
            'display' => ['mode' => 'item_template'],
            'limit' => $limit,
            'pagination' => ['mode' => 'none', 'page_size' => 10],
            'filters' => ['conjunction' => 'and', 'conditions' => []],
            'sorts' => [],
            'layout' => ['mode' => 'stack', 'gap' => 'medium'],
          ],
        ],
        [
          'uuid' => self::ITEM_HEADING_UUID,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => self::activeVersion('sdc.canvas_test_sdc.heading'),
          'parent_uuid' => self::LIST_UUID,
          'slot' => ListComponent::ITEM_TEMPLATE_SLOT,
          'inputs' => [
            // Resolves against the current field item.
            'text' => [
              'sourceType' => PropSource::Item->value,
              'expression' => $item_expression,
            ],
            'element' => 'h3',
          ],
        ],
        [
          'uuid' => self::PAGE_HEADING_UUID,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => self::activeVersion('sdc.canvas_test_sdc.heading'),
          'parent_uuid' => self::LIST_UUID,
          'slot' => ListComponent::ITEM_TEMPLATE_SLOT,
          'inputs' => [
            // Resolves against the tree's host entity, in the same subtree.
            'text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => (string) new FieldPropExpression(
                BetterEntityDataDefinition::create('node', 'article'),
                'title',
                NULL,
                'value',
              ),
            ],
            'element' => 'h4',
          ],
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    return $template;
  }

  /**
   * Creates an article with captions and topics.
   *
   * @param list<string> $captions
   */
  private static function createArticle(array $captions = [], array $topic_ids = []): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => 'The host article',
      'status' => NodeInterface::PUBLISHED,
      'field_captions' => $captions,
      'field_topics' => $topic_ids,
    ]);
    $node->save();
    return $node;
  }

  private static function activeVersion(string $component_id): string {
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    return $component->getActiveVersion();
  }

  private function renderNode(NodeInterface $node): string {
    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('node')
      ->view($node, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * A string field renders once per delta, in delta order, beside host data.
   */
  public function testDeltaOrderAndTwoContexts(): void {
    self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $node = self::createArticle(['First caption', 'Second caption', 'Third caption']);

    $html = $this->renderNode($node);
    self::assertSame(3, \substr_count($html, 'canvas-list__item'));
    // Delta order is the order the content editor arranged them in.
    self::assertLessThan(\strpos($html, 'Second caption'), \strpos($html, 'First caption'));
    self::assertLessThan(\strpos($html, 'Third caption'), \strpos($html, 'Second caption'));
    // The host entity stays reachable: an entity field prop source in the same
    // subtree resolves against it, once per item.
    self::assertSame(3, \substr_count($html, 'The host article'));
  }

  /**
   * The limit windows the field before any item template renders.
   */
  public function testLimitWindowsBeforeRendering(): void {
    self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'), 2);
    $node = self::createArticle(['One', 'Two', 'Three', 'Four', 'Five']);

    $html = $this->renderNode($node);
    self::assertSame(2, \substr_count($html, 'canvas-list__item'));
    self::assertStringContainsString('One', $html);
    self::assertStringNotContainsString('Three', $html);
  }

  /**
   * An empty field renders no items live, and the empty state in the editor.
   */
  public function testEmptyField(): void {
    $template = self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $node = self::createArticle([]);

    $html = $this->renderNode($node);
    self::assertStringNotContainsString('canvas-list__item', $html);

    $preview_build = $template->getComponentTree($node)->toRenderable($node, TRUE);
    $preview = (string) $this->container->get('renderer')->renderInIsolation($preview_build);
    self::assertStringContainsString('This field has no values.', $preview);
  }

  /**
   * A field removed from the bundle puts the List in its misconfigured state.
   */
  public function testRemovedFieldIsMisconfigured(): void {
    $template = self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $node = self::createArticle(['Only caption']);
    FieldConfig::loadByName('node', 'article', 'field_captions')?->delete();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged((int) $node->id());
    \assert($node instanceof NodeInterface);

    self::assertStringNotContainsString('Only caption', $this->renderNode($node));

    $preview_build = $template->getComponentTree($node)->toRenderable($node, TRUE);
    $preview = (string) $this->container->get('renderer')->renderInIsolation($preview_build);
    self::assertStringContainsString('canvas-list-element--warning', $preview);
  }

  /**
   * An item template over a reference field reaches the referenced entity.
   */
  public function testReferenceItemReachesReferencedEntity(): void {
    $terms = [];
    foreach (['Alpha', 'Beta'] as $name) {
      $term = Term::create(['vid' => 'topics', 'name' => $name]);
      $term->save();
      $terms[] = ['target_id' => $term->id()];
    }
    $expression = new ReferenceFieldTypePropExpression(
      new FieldTypePropExpression('entity_reference', 'entity'),
      new FieldPropExpression(
        BetterEntityDataDefinition::create('taxonomy_term', 'topics'),
        'name',
        NULL,
        'value',
      ),
    );
    self::createTemplate('field_topics', (string) $expression);
    $node = self::createArticle([], $terms);

    $html = $this->renderNode($node);
    self::assertSame(2, \substr_count($html, 'canvas-list__item'));
    self::assertStringContainsString('Alpha', $html);
    self::assertStringContainsString('Beta', $html);
  }

  /**
   * A field source needs a bundle-specific host entity, which a page lacks.
   */
  public function testFieldSourceRejectedOnAPage(): void {
    $page = Page::create([
      'title' => 'A page',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::LIST_UUID,
          'component_id' => 'list.list',
          'inputs' => [
            'source' => ['kind' => 'field', 'field_name' => 'field_captions'],
            'display' => ['mode' => 'item_template'],
            'limit' => 10,
            'pagination' => ['mode' => 'none', 'page_size' => 10],
            'filters' => ['conjunction' => 'and', 'conditions' => []],
            'sorts' => [],
            'layout' => ['mode' => 'stack', 'gap' => 'medium'],
          ],
        ],
      ],
    ]);
    $messages = \array_map(
      static fn ($violation): string => (string) $violation->getMessage(),
      \iterator_to_array($page->validate()),
    );
    self::assertContains(
      'A field source needs a host entity to read the field from, so it is only available in a content template.',
      $messages,
    );
  }

  /**
   * The source select offers every bundle and every multi-value field, flatly.
   *
   * Canvas renders Drupal selects through React, and that renderer flattens
   * `#options` to one level and emits every entry as an `<option>`. Grouped
   * options would therefore reach the browser as two unselectable group labels
   * with every real choice dropped, so the source could never be saved: the
   * form would post a group label, which matches no source kind, and the List
   * would silently collapse to a query with no bundle.
   *
   * @see ui/src/components/form/components/Select.tsx
   */
  public function testSourceSelectIsFlat(): void {
    self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $template = ContentTemplate::load('node.article.full');
    \assert($template instanceof ContentTemplate);
    $component = Component::load('list.list');
    \assert($component instanceof Component);
    $item = $template->getComponentTree()->getComponentTreeItemByUuid(self::LIST_UUID);
    \assert($item !== NULL);

    $form = $component->getComponentSource()->buildComponentInstanceForm(
      [],
      new FormState(),
      $component,
      self::LIST_UUID,
      $item->getInputs() ?? [],
      $template,
      $component->get('settings'),
    );
    $options = $form['source']['selection']['#options'];

    self::assertSame([], \array_filter($options, \is_array(...)), 'The source select must not use option groups.');
    self::assertArrayHasKey('bundle:article', $options);
    self::assertArrayHasKey('field:field_captions', $options);
    self::assertArrayHasKey('field:field_topics', $options);
    // Single-cardinality fields are not lists and are never offered.
    self::assertArrayNotHasKey('field:title', $options);
    // The stored source is one of the offered options, so the select can round
    // trip it instead of falling back to its first choice.
    self::assertArrayHasKey($form['source']['selection']['#default_value'], $options);
  }

  /**
   * A required prop can be bound to an item of an optional multi-value field.
   *
   * Requiredness restricts a host entity binding to a field that always has a
   * value. An item template is the opposite: it renders once per value that
   * exists, so the item is guaranteed. Forwarding requiredness would leave
   * every required prop of every component in a gallery template unbindable.
   */
  public function testRequiredPropsAreBindableToItems(): void {
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'article');
    self::assertFalse($fields['field_captions']->isRequired(), 'The premise: the iterated field is optional.');

    $component = Component::load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    $component_source = $component->getComponentSource();
    \assert($component_source instanceof SingleDirectoryComponent);
    $suggestions = $this->container->get(PropSourceSuggester::class)->suggest(
      'canvas_test_sdc:heading',
      $component_source->getMetadata(),
      EntityDataDefinition::create('node', 'article'),
      $fields['field_captions'],
    );

    $text = $suggestions['⿲canvas_test_sdc:heading␟text'];
    self::assertTrue($text['required'], 'The premise: the prop under test is required.');
    self::assertNotSame([], $text[PropSource::Item->value]);
  }

}
