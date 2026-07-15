<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests mapping entity fields onto custom object props ("groups").
 *
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher
 * @see docs/adr/0021-object-props-in-code-components.md
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ObjectPropsFieldMappingTest extends CanvasKernelTestBase {

  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'datetime',
    'datetime_range',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    foreach ([
      'field_street' => 'string',
      'field_city' => 'string',
      'field_duration' => 'daterange',
    ] as $field_name => $field_type) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $field_type,
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'article',
        'label' => $field_name,
        'required' => $field_name === 'field_street',
      ])->save();
    }
    // The suggester (and hence the matcher's structural expectations) rely on
    // a form display existing.
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')
      ->setComponent('field_street', ['type' => 'string_textfield'])
      ->setComponent('field_city', ['type' => 'string_textfield'])
      ->setComponent('field_duration', ['type' => 'daterange_default'])
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition(EntityFieldPropSourceMatcher::class)->setPublic(TRUE);
  }

  /**
   * @return list<string>
   */
  private static function match(array $schema): array {
    $matcher = \Drupal::service(EntityFieldPropSourceMatcher::class);
    \assert($matcher instanceof EntityFieldPropSourceMatcher);
    $matches = $matcher->match(FALSE, PropShape::normalize($schema), 'node', 'article');
    return \array_map(
      static fn (EntityFieldPropSource $source): string => (string) $source->expression,
      $matches,
    );
  }

  /**
   * A custom group shape is matched via its scalar leaves.
   */
  public function testMatchesCustomObjectShapeViaScalars(): void {
    // A group whose sub-properties both live in one field's properties gets a
    // complete per-field object expression.
    $expressions = self::match([
      'type' => 'object',
      'properties' => [
        'from' => ['type' => 'string', 'format' => 'date'],
        'to' => ['type' => 'string', 'format' => 'date'],
      ],
      'required' => ['from', 'to'],
    ]);
    $this->assertContains('ℹ︎␜entity:node:article␝field_duration␞␟{from↠value,to↠end_value}', $expressions);

    // A group whose sub-properties live in different entity fields gets one
    // suggestion per field that covers the required sub-properties; the
    // remaining sub-properties are matched per scalar.
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchEntityPropsForObjectUsingScalars()
    $expressions = self::match([
      'type' => 'object',
      'properties' => [
        'street' => ['type' => 'string'],
        'city' => ['type' => 'string'],
      ],
      'required' => ['street'],
    ]);
    $this->assertContains('ℹ︎␜entity:node:article␝field_street␞␟{street↠value}', $expressions);
    $this->assertContains('ℹ︎␜entity:node:article␝field_city␞␟{street↠value}', $expressions);
  }

  /**
   * A stored object prop expression evaluates to one composed object.
   */
  public function testFieldObjectPropsExpressionEvaluates(): void {
    $this->setUpCurrentUser([], ['access content']);
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Test node',
      'field_duration' => [
        'value' => '2026-07-01',
        'end_value' => '2026-07-15',
      ],
    ]);
    $node->save();

    $source = EntityFieldPropSource::parse([
      'sourceType' => 'entity-field',
      'expression' => 'ℹ︎␜entity:node:article␝field_duration␞␟{from↠value,to↠end_value}',
    ]);
    $result = $source->evaluate($node, is_required: TRUE);
    $this->assertSame([
      'from' => '2026-07-01',
      'to' => '2026-07-15',
    ], $result->value);
  }

}
