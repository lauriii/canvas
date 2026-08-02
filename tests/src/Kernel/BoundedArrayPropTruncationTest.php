<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a `maxItems: N` prop renders a longer field's first N values.
 *
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchEntityPropsForScalar()
 * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::evaluate()
 * @see https://www.drupal.org/i/3522718
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class BoundedArrayPropTruncationTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use GenerateComponentConfigTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  private const string EXPRESSION = 'ℹ︎␜entity:node:article␝field_many␞␟value';

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
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node']);
    $this->installConfig(['canvas']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: ['access content']);

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_many',
      'type' => 'string',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_many',
      'bundle' => 'article',
      'label' => 'Many values',
    ])->save();
  }

  /**
   * Creates an article carrying ten values in `field_many`.
   */
  private function createArticleWithTenValues(): \Drupal\node\NodeInterface {
    return $this->createNode([
      'type' => 'article',
      'title' => 'Ten values',
      'field_many' => \array_map(static fn (int $i): string => "value-$i", \range(1, 10)),
    ]);
  }

  /**
   * The evaluator stops at the window rather than trimming afterwards.
   */
  public function testEvaluatorWindowsBeforeReadingValues(): void {
    $node = $this->createArticleWithTenValues();
    $expression = StructuredDataPropExpression::fromString(self::EXPRESSION);
    // @phpstan-ignore argument.type
    $prop_source = new EntityFieldPropSource($expression);

    $all = $prop_source->evaluate($node, is_required: FALSE)->value;
    \assert(\is_array($all));
    self::assertCount(10, $all);

    $windowed = $prop_source->withMaxValues(3)->evaluate($node, is_required: FALSE)->value;
    \assert(\is_array($windowed));
    self::assertSame(['value-1', 'value-2', 'value-3'], \array_values($windowed));

    // A window wider than the stored values is not padded.
    $wide = $prop_source->withMaxValues(50)->evaluate($node, is_required: FALSE)->value;
    \assert(\is_array($wide));
    self::assertCount(10, $wide);
  }

  /**
   * A `maxItems: 3` prop bound to a 10-value field renders 3 values.
   */
  public function testBoundedPropRendersTheFirstValues(): void {
    $node = $this->createArticleWithTenValues();
    $component = Component::load('sdc.canvas_test_sdc.multivalue-props');
    \assert($component instanceof Component);
    ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => '1f6b4c1a-64ff-4f83-9f2a-3a1cf5a1a0d1',
          'component_id' => 'sdc.canvas_test_sdc.multivalue-props',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            // Required prop: bound to the same field, unbounded.
            'text_required' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => self::EXPRESSION,
            ],
            // Bounded prop: `maxItems: 3`.
            'text_limited' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => self::EXPRESSION,
            ],
          ],
        ],
      ],
    ])->setStatus(TRUE)->save();

    // The template added fields to the bundle: reload the node.
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged((int) $node->id());
    \assert($node instanceof \Drupal\node\NodeInterface);
    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('node')
      ->view($node, 'full');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $limited = self::extractListItems($html, 'text-limited-list');
    self::assertSame(['value-1', 'value-2', 'value-3'], $limited);
    // The unbounded prop bound to the same field is unaffected.
    self::assertCount(10, self::extractListItems($html, 'text-required-list'));
  }

  /**
   * Extracts the text of every list item in the identified list.
   *
   * @return list<string>
   */
  private static function extractListItems(string $html, string $list_id): array {
    $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
    return $crawler->filter("#$list_id li")->each(static fn ($node): string => \trim($node->text()));
  }

}
