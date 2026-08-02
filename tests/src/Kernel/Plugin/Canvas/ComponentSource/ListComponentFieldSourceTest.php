<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\ListBuilder\ListElementSettingsValidator;
use Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropSource\AmbientItemContext;
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
   * The source is two controls: a kind, then only the one it applies to.
   *
   * Grouped `#options` are not an option: Canvas renders Drupal selects through
   * React, and that renderer flattens `#options` to one level and emits every
   * entry as an `<option>`, so a grouped select would reach the browser as two
   * unselectable group labels with every real choice dropped — and the form
   * would post a group label, which matches no source kind.
   *
   * @see ui/src/components/form/components/Select.tsx
   */
  public function testSourceIsAKindPlusItsOwnControl(): void {
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

    // The kind, and only the control the chosen kind uses.
    self::assertSame(['query', 'field'], \array_keys($form['source']['kind']['#options']));
    self::assertSame('field', $form['source']['kind']['#default_value']);
    self::assertArrayHasKey('field_name', $form['source']);
    self::assertArrayNotHasKey('bundle', $form['source']);

    $field_options = $form['source']['field_name']['#options'];
    self::assertSame([], \array_filter($field_options, \is_array(...)), 'Option groups are not rendered by Canvas.');
    self::assertArrayHasKey('field_captions', $field_options);
    self::assertArrayHasKey('field_topics', $field_options);
    // Single-cardinality fields are not lists and are never offered.
    self::assertArrayNotHasKey('title', $field_options);
    // The stored field is one of the offered options, so the select can round
    // trip it instead of falling back to its first choice.
    self::assertArrayHasKey($form['source']['field_name']['#default_value'], $field_options);
  }

  /**
   * Switching the kind keeps the list valid without naming a source yet.
   *
   * The control for the new kind does not exist in the form that produced the
   * submitted values, so the conversion has to choose a starting point rather
   * than store a source that names nothing.
   */
  public function testSwitchingSourceKind(): void {
    self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $node = self::createArticle(['One caption']);
    $template = ContentTemplate::load('node.article.full');
    \assert($template instanceof ContentTemplate);
    $component = Component::load('list.list');
    \assert($component instanceof Component);
    $source = $component->getComponentSource();
    \assert($source instanceof ListComponent);
    $item = $template->getComponentTree()->getComponentTreeItemByUuid(self::LIST_UUID);
    \assert($item !== NULL);
    $validator = $this->container->get(ListElementSettingsValidator::class);
    $host_context = ['entity_type' => 'node', 'bundle' => 'article'];

    // Field to content, with no bundle submitted yet.
    $query_settings = self::switchKind($source, $component, $item->getInputs() ?? [], 'query', $node);
    self::assertSame('query', ListElementSettingsValidator::sourceKind($query_settings));
    \assert(\is_array($query_settings['source']));
    self::assertNotSame('', $query_settings['source']['bundle']);
    self::assertCount(0, $validator->validate($query_settings, $host_context));

    // And back, with no field submitted yet.
    $field_settings = self::switchKind($source, $component, $query_settings, 'field', $node);
    self::assertSame('field', ListElementSettingsValidator::sourceKind($field_settings));
    \assert(\is_array($field_settings['source']));
    self::assertNotSame('', $field_settings['source']['field_name']);
    self::assertCount(0, $validator->validate($field_settings, $host_context));
  }

  /**
   * Round-trips settings through the client model with only the kind changed.
   *
   * @param array<string, mixed> $settings
   *
   * @return array<string, mixed>
   */
  private static function switchKind(ListComponent $source, Component $component, array $settings, string $kind, NodeInterface $node): array {
    $client_model = $source->inputToClientModel($settings);
    \assert(\is_array($client_model['resolved']));
    // Only the kind select exists in the form that produced these values; the
    // control naming the new source has not been built yet.
    $client_model['resolved']['source'] = ['kind' => $kind];
    // The List reuses the client model's `source` key for its settings'
    // structural signature rather than for prop sources, which the interface's
    // type does not describe.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent::inputToClientModel()
    // @phpstan-ignore argument.type
    return $source->clientModelToInput(self::LIST_UUID, $component, $client_model, $node);
  }

  /**
   * A stale item binding degrades to nothing instead of breaking the tree.
   *
   * Switching a List from a field source to a content query leaves the item
   * template's item prop sources behind with no item to resolve against. They
   * must evaluate to nothing, exactly as an empty field would, rather than
   * reporting a missing context — which would take down the whole layout the
   * template belongs to, not just the one binding.
   */
  public function testStaleItemBindingDegrades(): void {
    $template = self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $node = self::createArticle(['One caption']);

    // Switch the List to a content query, stranding the item bindings.
    $values = $template->get('component_tree');
    foreach ($values as $delta => $value) {
      if (($value['uuid'] ?? '') === self::LIST_UUID) {
        $values[$delta]['inputs']['source'] = ['entity_type' => 'node', 'bundle' => 'article'];
      }
    }
    $template->set('component_tree', $values)->save();

    $html = $this->renderNode($node);
    // The host-entity binding in the same subtree still resolves.
    self::assertStringContainsString('The host article', $html);
    // The stranded item binding contributes nothing, and nothing crashed.
    self::assertStringNotContainsString('One caption', $html);
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

  /**
   * An item-bound prop survives client model conversion without an item.
   *
   * An item prop source stores a mapping, not a value, so converting a client
   * model back to stored input has nothing to evaluate — and nothing to
   * evaluate against, because a host entity whose field holds no values has no
   * item at all. Dropping the prop here strands the component instance without
   * a required input, which its form then refuses to build.
   */
  public function testItemBoundPropSurvivesConversionWithoutAnItem(): void {
    self::createTemplate('field_captions', (string) new FieldTypePropExpression('string', 'value'));
    $node = self::createArticle(['One caption']);
    $template = ContentTemplate::load('node.article.full');
    \assert($template instanceof ContentTemplate);
    $tree = $template->getComponentTree($node);
    $item = $tree->getComponentTreeItemByUuid(self::ITEM_HEADING_UUID);
    \assert($item !== NULL);
    $component = Component::load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    $component_source = $component->getComponentSource();
    \assert($component_source instanceof SingleDirectoryComponent);

    ['entity' => $context_entity, 'item' => $context_item] = $tree->resolveDeferredSlotContext($item, $node);
    self::assertNotNull($context_item, 'The premise: the template iterates a populated field.');
    $client_model = AmbientItemContext::within(
      $context_item,
      static fn (): array => $component_source->inputToClientModel(
        $component_source->getExplicitInput(self::ITEM_HEADING_UUID, $item, $context_entity),
      ),
    );

    // Deliberately converted with no ambient item bound.
    $converted = $component_source->clientModelToInput(self::ITEM_HEADING_UUID, $component, $client_model, $context_entity);
    self::assertArrayHasKey('text', $converted, 'The item-bound required prop must survive conversion.');
    \assert(\is_array($converted['text']));
    self::assertSame(PropSource::Item->value, $converted['text']['sourceType']);
  }

}
