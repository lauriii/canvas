<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use Drupal\canvas\PropExpressions\StructuredData\Coalescer;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;

#[CoversClass(Coalescer::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
final class CoalescerTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $this->prophesize(TypedDataManagerInterface::class)->reveal());
    // Folding a reference into a loose group names the follow-reference entry
    // by its final target's developer-facing key, which needs the target
    // entity type's entity keys.
    // @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedExpressionTrait::getDeveloperFacingKey()
    $node_entity_type = $this->prophesize(EntityTypeInterface::class);
    $node_entity_type->getKeys()->willReturn(['id' => 'nid', 'label' => 'title', 'bundle' => 'type', 'uuid' => 'uuid']);
    $user_entity_type = $this->prophesize(EntityTypeInterface::class);
    $user_entity_type->getKeys()->willReturn(['id' => 'uid', 'uuid' => 'uuid']);
    $entity_type_manager = $this->prophesize(EntityTypeManagerInterface::class);
    $entity_type_manager->getDefinition('node')->willReturn($node_entity_type->reveal());
    $entity_type_manager->getDefinition('user')->willReturn($user_entity_type->reveal());
    // Expression constructors consult ::hasDefinition() for optional
    // validation when the service exists; FALSE skips it, like the absent
    // service did before.
    $entity_type_manager->hasDefinition(Argument::any())->willReturn(FALSE);
    $container->set('entity_type.manager', $entity_type_manager->reveal());
    \Drupal::setContainer($container);
  }

  /**
   * Asserts a Coalescer transform maps the input expressions to the expected.
   *
   * Output order is not part of Coalescer's contract, so the two lists are
   * compared as sets.
   *
   * @param \Closure(list<EntityFieldBasedPropExpressionInterface>): list<EntityFieldBasedPropExpressionInterface> $transform
   *   Coalescer::coalesce(...) or Coalescer::expand(...).
   * @param list<string> $input
   *   The string representations of the input expressions.
   * @param list<string> $expected
   *   The string representations the input is expected to map to.
   */
  private static function assertTransform(\Closure $transform, array $input, array $expected): void {
    // fromString() returns the broad StructuredDataPropExpressionInterface;
    // narrow it to the EntityFieldBasedPropExpressionInterface that the
    // Coalescer operates on. Every expression in these tests is field-based.
    $from_string = static function (string $representation): EntityFieldBasedPropExpressionInterface {
      $expression = StructuredDataPropExpression::fromString($representation);
      \assert($expression instanceof EntityFieldBasedPropExpressionInterface);
      return $expression;
    };
    $to_string = static fn (EntityFieldBasedPropExpressionInterface $expression): string => (string) $expression;
    $actual = \array_map($to_string, $transform(\array_map($from_string, $input)));
    \sort($actual);
    \sort($expected);
    self::assertSame($expected, $actual);
  }

  /**
   * Tests coalescing a list of scalar expressions into its compact form.
   *
   * @param list<string> $input
   *   The string representations of the atomic expressions to coalesce.
   * @param list<string> $expected
   *   The string representations the input is expected to coalesce into.
   */
  #[DataProvider('providerCoalesce')]
  public function testCoalesce(array $input, array $expected): void {
    self::assertTransform(Coalescer::coalesce(...), $input, $expected);
  }

  /**
   * Provides coalescing scenarios, keyed by the pattern under test.
   *
   * @return iterable<string, array{list<string>, list<string>}>
   */
  public static function providerCoalesce(): iterable {
    yield 'different properties of the same field item → one expression' => [
      [
        'ℹ︎␜entity:node:article␝field_image␞0␟alt',
        'ℹ︎␜entity:node:article␝field_image␞0␟target_id',
      ],
      ['ℹ︎␜entity:node:article␝field_image␞0␟{alt↠alt,target_id↠target_id}'],
    ];

    // coalesce() emits a canonical, key-sorted form, so the same set of
    // properties yields the same string regardless of input order. Feeding the
    // properties in reverse is what actually exercises that sort.
    yield 'properties arriving out of order → key-sorted output' => [
      [
        'ℹ︎␜entity:node:article␝field_image␞0␟target_id',
        'ℹ︎␜entity:node:article␝field_image␞0␟alt',
      ],
      ['ℹ︎␜entity:node:article␝field_image␞0␟{alt↠alt,target_id↠target_id}'],
    ];

    // The Coalescer cannot merge a property with itself, so it leaves both
    // entries in the output for the (separate) validation layer to reject as a
    // duplicate.
    $duplicate = [
      'ℹ︎␜entity:node:article␝field_image␞0␟alt',
      'ℹ︎␜entity:node:article␝field_image␞0␟alt',
    ];
    yield 'duplicate property on the same field → left unchanged' => [$duplicate, $duplicate];

    $lone = ['ℹ︎␜entity:node:article␝title␞0␟value'];
    yield 'lone expression → unchanged' => [$lone, $lone];

    yield 'reference chain, same final field → one combined reference' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟target_id',
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{alt↠alt,target_id↠target_id}'],
    ];

    // No loose pick on `uid`: two references descend through it into the same
    // bundle but pick different final fields, so they merge into one object.
    yield 'reference field consumed only through nested objects → one object expression' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝mail␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟{mail↝entity␜␜entity:user␝mail␞␟value,name↝entity␜␜entity:user␝name␞␟value}'],
    ];

    yield 'reference chain, different bundles → bundle-specific branches' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝name␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]'],
    ];

    $multi_bundle = ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]'];
    yield 'already-combined multi-bundle reference → unchanged' => [$multi_bundle, $multi_bundle];

    $empty = [];
    yield 'empty list → empty list' => [$empty, $empty];

    // A loose pick and a reference descending through the same field key
    // must be coalesced into one FieldObjectPropsExpression whose reference-
    // derived entry follows the reference (`↝`).
    yield 'loose pick and reference descending through the same field → one object expression' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟target_id',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟{name↝entity␜␜entity:user␝name␞␟value,target_id↠target_id}'],
    ];

    // The folded entry is named by the final target's developer-facing key:
    // node's `title` field maps to the `label` entity key.
    yield 'folded reference is named by its final target entity key' => [
      [
        'ℹ︎␜entity:node:article␝field_related␞␟target_id',
        'ℹ︎␜entity:node:article␝field_related␞␟entity␜␜entity:node:page␝title␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝field_related␞␟{label↝entity␜␜entity:node:page␝title␞␟value,target_id↠target_id}'],
    ];

    // Same-chain same-final-field references first coalesce into one reference
    // with an object final target, then fold as a single `↝` entry.
    yield 'loose pick and multi-property descend through the same field → one object expression' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟target_id',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟{target_id↠target_id,user_picture↝entity␜␜entity:user␝user_picture␞␟{alt↠alt,width↠width}}'],
    ];

    // The Coalescer cannot merge a loose pick with a folded reference deriving
    // the same name, so it leaves all entries in the output for the (separate)
    // validation layer to reject as a duplicate.
    $name_collision = [
      'ℹ︎␜entity:node:article␝uid␞␟name',
      'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
    ];
    yield 'loose pick colliding with a folded reference name → left unchanged' => [$name_collision, $name_collision];

    yield 'mix of all flavors with a lone expression' => [
      [
        // Two same-field expressions → 1 FieldObjectPropsExpression.
        'ℹ︎␜entity:node:article␝field_image␞0␟alt',
        'ℹ︎␜entity:node:article␝field_image␞0␟target_id',
        // Two same-chain, same-final-field expressions → 1 reference.
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟target_id',
        // Lone expression → unchanged.
        'ℹ︎␜entity:node:article␝title␞0␟value',
      ],
      [
        'ℹ︎␜entity:node:article␝field_image␞0␟{alt↠alt,target_id↠target_id}',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{alt↠alt,target_id↠target_id}',
        'ℹ︎␜entity:node:article␝title␞0␟value',
      ],
    ];
  }

  /**
   * Tests expanding a coalesced list back to its atomic leaf expressions.
   *
   * @param list<string> $input
   *   The string representations of the coalesced expressions to expand.
   * @param list<string> $expected
   *   The string representations of the atomic leaf expressions the input is
   *   expected to expand into.
   */
  #[DataProvider('providerExpand')]
  public function testExpand(array $input, array $expected): void {
    self::assertTransform(Coalescer::expand(...), $input, $expected);
  }

  /**
   * Provides expansion scenarios, keyed by the pattern under test.
   *
   * @return iterable<string, array{list<string>, list<string>}>
   */
  public static function providerExpand(): iterable {
    yield 'combined field expression → one leaf per property' => [
      ['ℹ︎␜entity:node:article␝field_image␞0␟{alt↠alt,target_id↠target_id}'],
      [
        'ℹ︎␜entity:node:article␝field_image␞0␟alt',
        'ℹ︎␜entity:node:article␝field_image␞0␟target_id',
      ],
    ];

    yield 'combined reference → one reference leaf per property' => [
      ['ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{alt↠alt,target_id↠target_id}'],
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟target_id',
      ],
    ];

    yield 'multi-bundle reference → one reference leaf per bundle' => [
      ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]'],
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝name␞␟value',
      ],
    ];

    // An object with a follow-reference (`↝`) entry expands fully: the `↝`
    // entry becomes per-leaf reference expressions, alongside the loose leaf.
    // Object-prop names are dropped; here they are canonical (`user_picture`
    // is the reference's developer-facing key, `target_id` the property name),
    // so coalesce(expand(X)) === X — the round trip is lossless.
    yield 'object with a follow-reference entry → atomic leaves' => [
      ['ℹ︎␜entity:node:article␝uid␞␟{target_id↠target_id,user_picture↝entity␜␜entity:user␝user_picture␞␟{alt↠alt,width↠width}}'],
      [
        'ℹ︎␜entity:node:article␝uid␞␟target_id',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
      ],
    ];

    $lone = ['ℹ︎␜entity:node:article␝title␞0␟value'];
    yield 'already-atomic expression → unchanged' => [$lone, $lone];

    $empty = [];
    yield 'empty list → empty list' => [$empty, $empty];
  }

  /**
   * Expanding a coalesced list restores the original atomic expressions.
   *
   * Order and duplicates aside, `expand(coalesce(x))` yields the same
   * expressions as `x`.
   */
  public function testCoalesceExpandRoundtripsAtomicLeaves(): void {
    $node = BetterEntityDataDefinition::create('node', 'article');
    $user = BetterEntityDataDefinition::create('user');
    $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
    $entries = [
      new FieldPropExpression($node, 'field_image', 0, 'alt'),
      new FieldPropExpression($node, 'field_image', 0, 'target_id'),
      new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
      ),
      new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
      ),
    ];

    $to_string = static fn (\Stringable $expression): string => (string) $expression;
    $entry_strings = \array_map($to_string, $entries);
    $roundtripped = \array_map($to_string, Coalescer::expand(Coalescer::coalesce($entries)));

    // Output order is not part of the contract, so compare as sets.
    \sort($entry_strings);
    \sort($roundtripped);
    self::assertSame($entry_strings, $roundtripped);
  }

  /**
   * Coalesce is idempotent: coalesce(coalesce(x)) === coalesce(x).
   */
  public function testCoalesceIsIdempotent(): void {
    $node = BetterEntityDataDefinition::create('node', 'article');
    $user = BetterEntityDataDefinition::create('user');
    $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
    $entries = [
      new FieldPropExpression($node, 'field_image', 0, 'alt'),
      new FieldPropExpression($node, 'field_image', 0, 'target_id'),
      // This loose pick shares the `uid` field with the reference below: both
      // fold into one FieldObjectPropsExpression with a `↝` entry.
      new FieldPropExpression($node, 'uid', NULL, 'target_id'),
      new ReferenceFieldPropExpression(
        referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
        referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
      ),
      new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
      ),
      new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
      ),
    ];

    $to_string = static fn (\Stringable $expression): string => (string) $expression;
    $once = \array_map($to_string, Coalescer::coalesce($entries));
    $twice = \array_map($to_string, Coalescer::coalesce(Coalescer::coalesce($entries)));

    // Output order is not part of the contract, so compare as sets.
    \sort($once);
    \sort($twice);
    self::assertSame($once, $twice);
  }

}
