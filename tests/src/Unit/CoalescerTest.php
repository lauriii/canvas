<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use Drupal\canvas\PropExpressions\StructuredData\Coalescer;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
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
   * @param \Closure(list<string>): list<string> $transform
   *   Coalescer::coalesce(...) or Coalescer::expand(...).
   * @param \Closure(): list<\Stringable> $input
   *   Builds the input expressions. A closure because data providers run
   *   before setUp(), so the container is not yet available.
   * @param \Closure(): list<\Stringable> $expected
   *   Builds the expressions the input is expected to map to.
   */
  private static function assertTransform(\Closure $transform, \Closure $input, \Closure $expected): void {
    $to_string = static fn (\Stringable $expression): string => (string) $expression;
    $actual = $transform(\array_map($to_string, $input()));
    $expected_strings = \array_map($to_string, $expected());
    \sort($actual);
    \sort($expected_strings);
    self::assertSame($expected_strings, $actual);
  }

  /**
   * Tests coalescing a list of scalar expressions into its compact form.
   *
   * @param \Closure(): list<\Stringable> $input
   *   Builds the atomic expressions to coalesce.
   * @param \Closure(): list<\Stringable> $expected
   *   Builds the expressions the input is expected to coalesce into.
   */
  #[DataProvider('providerCoalesce')]
  public function testCoalesce(\Closure $input, \Closure $expected): void {
    self::assertTransform(Coalescer::coalesce(...), $input, $expected);
  }

  /**
   * Provides coalescing scenarios, keyed by the pattern under test.
   *
   * @return iterable<string, array{\Closure, \Closure}>
   */
  public static function providerCoalesce(): iterable {
    yield 'different properties of the same field item → one expression' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        return [
          new FieldPropExpression($node, 'field_image', 0, 'alt'),
          new FieldPropExpression($node, 'field_image', 0, 'target_id'),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        return [
          new FieldObjectPropsExpression($node, 'field_image', 0, [
            'alt' => new FieldPropExpression($node, 'field_image', 0, 'alt'),
            'target_id' => new FieldPropExpression($node, 'field_image', 0, 'target_id'),
          ]),
        ];
      },
    ];

    // coalesce() emits a canonical, key-sorted form, so the same set of
    // properties yields the same string regardless of input order. Feeding the
    // properties in reverse is what actually exercises that sort.
    yield 'properties arriving out of order → key-sorted output' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        return [
          new FieldPropExpression($node, 'field_image', 0, 'target_id'),
          new FieldPropExpression($node, 'field_image', 0, 'alt'),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        return [
          new FieldObjectPropsExpression($node, 'field_image', 0, [
            'alt' => new FieldPropExpression($node, 'field_image', 0, 'alt'),
            'target_id' => new FieldPropExpression($node, 'field_image', 0, 'target_id'),
          ]),
        ];
      },
    ];

    // The Coalescer cannot merge a property with itself, so it leaves both
    // entries in the output for the (separate) validation layer to reject as a
    // duplicate.
    $duplicate = static function (): array {
      $node = BetterEntityDataDefinition::create('node', 'article');
      return [
        new FieldPropExpression($node, 'field_image', 0, 'alt'),
        new FieldPropExpression($node, 'field_image', 0, 'alt'),
      ];
    };
    yield 'duplicate property on the same field → left unchanged' => [$duplicate, $duplicate];

    $lone = static function (): array {
      $node = BetterEntityDataDefinition::create('node', 'article');
      return [new FieldPropExpression($node, 'title', 0, 'value')];
    };
    yield 'lone expression → unchanged' => [$lone, $lone];

    yield 'reference chain, same final field → one combined reference' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldObjectPropsExpression($user, 'user_picture', NULL, [
              'alt' => new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
              'target_id' => new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
            ]),
          ),
        ];
      },
    ];

    // No loose pick on `uid`: two references descend through it into the same
    // bundle but pick different final fields, so they merge into one object.
    yield 'reference field consumed only through nested objects → one object expression' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'name', NULL, 'value'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'mail', NULL, 'value'),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new FieldObjectPropsExpression($node, 'uid', NULL, [
            'mail' => new ReferenceFieldPropExpression(
              referencer: $referencer,
              referenced: new FieldPropExpression($user, 'mail', NULL, 'value'),
            ),
            'name' => new ReferenceFieldPropExpression(
              referencer: $referencer,
              referenced: new FieldPropExpression($user, 'name', NULL, 'value'),
            ),
          ]),
        ];
      },
    ];

    yield 'reference chain, different bundles → bundle-specific branches' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new ReferencedBundleSpecificBranches([
              'entity:media:image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
              'entity:media:video' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
            ]),
          ),
        ];
      },
    ];

    $multi_bundle = static function (): array {
      $node = BetterEntityDataDefinition::create('node', 'article');
      $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
      return [
        new ReferenceFieldPropExpression(
          referencer: $referencer,
          referenced: new ReferencedBundleSpecificBranches([
            'entity:media:image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
            'entity:media:video' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
          ]),
        ),
      ];
    };
    yield 'already-combined multi-bundle reference → unchanged' => [$multi_bundle, $multi_bundle];

    $empty = static fn (): array => [];
    yield 'empty list → empty list' => [$empty, $empty];

    // A loose pick and a reference descending through the same field key
    // must be coalesced into one FieldObjectPropsExpression whose reference-
    // derived entry follows the reference (`↝`).
    yield 'loose pick and reference descending through the same field → one object expression' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        return [
          new FieldPropExpression($node, 'uid', NULL, 'target_id'),
          new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
            referenced: new FieldPropExpression($user, 'name', NULL, 'value'),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        return [
          new FieldObjectPropsExpression($node, 'uid', NULL, [
            'name' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
              referenced: new FieldPropExpression($user, 'name', NULL, 'value'),
            ),
            'target_id' => new FieldPropExpression($node, 'uid', NULL, 'target_id'),
          ]),
        ];
      },
    ];

    // The folded entry is named by the final target's developer-facing key:
    // node's `title` field maps to the `label` entity key.
    yield 'folded reference is named by its final target entity key' => [
      static function (): array {
        $article = BetterEntityDataDefinition::create('node', 'article');
        $page = BetterEntityDataDefinition::create('node', 'page');
        return [
          new FieldPropExpression($article, 'field_related', NULL, 'target_id'),
          new ReferenceFieldPropExpression(
            referencer: new FieldPropExpression($article, 'field_related', NULL, 'entity'),
            referenced: new FieldPropExpression($page, 'title', NULL, 'value'),
          ),
        ];
      },
      static function (): array {
        $article = BetterEntityDataDefinition::create('node', 'article');
        $page = BetterEntityDataDefinition::create('node', 'page');
        return [
          new FieldObjectPropsExpression($article, 'field_related', NULL, [
            'label' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression($article, 'field_related', NULL, 'entity'),
              referenced: new FieldPropExpression($page, 'title', NULL, 'value'),
            ),
            'target_id' => new FieldPropExpression($article, 'field_related', NULL, 'target_id'),
          ]),
        ];
      },
    ];

    // Same-chain same-final-field references first coalesce into one reference
    // with an object final target, then fold as a single `↝` entry.
    yield 'loose pick and multi-property descend through the same field → one object expression' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new FieldPropExpression($node, 'uid', NULL, 'target_id'),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'width'),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        return [
          new FieldObjectPropsExpression($node, 'uid', NULL, [
            'target_id' => new FieldPropExpression($node, 'uid', NULL, 'target_id'),
            'user_picture' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
              referenced: new FieldObjectPropsExpression($user, 'user_picture', NULL, [
                'alt' => new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
                'width' => new FieldPropExpression($user, 'user_picture', NULL, 'width'),
              ]),
            ),
          ]),
        ];
      },
    ];

    // The Coalescer cannot merge a loose pick with a folded reference deriving
    // the same name, so it leaves all entries in the output for the (separate)
    // validation layer to reject as a duplicate.
    $name_collision = static function (): array {
      $node = BetterEntityDataDefinition::create('node', 'article');
      $user = BetterEntityDataDefinition::create('user');
      return [
        new FieldPropExpression($node, 'uid', NULL, 'name'),
        new ReferenceFieldPropExpression(
          referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
          referenced: new FieldPropExpression($user, 'name', NULL, 'value'),
        ),
      ];
    };
    yield 'loose pick colliding with a folded reference name → left unchanged' => [$name_collision, $name_collision];

    yield 'mix of all flavors with a lone expression' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          // Two same-field expressions → 1 FieldObjectPropsExpression.
          new FieldPropExpression($node, 'field_image', 0, 'alt'),
          new FieldPropExpression($node, 'field_image', 0, 'target_id'),
          // Two same-chain, same-final-field expressions → 1 reference.
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
          ),
          // Lone expression → unchanged.
          new FieldPropExpression($node, 'title', 0, 'value'),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new FieldObjectPropsExpression($node, 'field_image', 0, [
            'alt' => new FieldPropExpression($node, 'field_image', 0, 'alt'),
            'target_id' => new FieldPropExpression($node, 'field_image', 0, 'target_id'),
          ]),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldObjectPropsExpression($user, 'user_picture', NULL, [
              'alt' => new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
              'target_id' => new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
            ]),
          ),
          new FieldPropExpression($node, 'title', 0, 'value'),
        ];
      },
    ];
  }

  /**
   * Tests expanding a coalesced list back to its atomic leaf expressions.
   *
   * @param \Closure(): list<\Stringable> $input
   *   Builds the coalesced expressions to expand.
   * @param \Closure(): list<\Stringable> $expected
   *   Builds the atomic leaf expressions the input is expected to expand into.
   */
  #[DataProvider('providerExpand')]
  public function testExpand(\Closure $input, \Closure $expected): void {
    self::assertTransform(Coalescer::expand(...), $input, $expected);
  }

  /**
   * Provides expansion scenarios, keyed by the pattern under test.
   *
   * @return iterable<string, array{\Closure, \Closure}>
   */
  public static function providerExpand(): iterable {
    yield 'combined field expression → one leaf per property' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        return [
          new FieldObjectPropsExpression($node, 'field_image', 0, [
            'alt' => new FieldPropExpression($node, 'field_image', 0, 'alt'),
            'target_id' => new FieldPropExpression($node, 'field_image', 0, 'target_id'),
          ]),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        return [
          new FieldPropExpression($node, 'field_image', 0, 'alt'),
          new FieldPropExpression($node, 'field_image', 0, 'target_id'),
        ];
      },
    ];

    yield 'combined reference → one reference leaf per property' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldObjectPropsExpression($user, 'user_picture', NULL, [
              'alt' => new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
              'target_id' => new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
            ]),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
          ),
        ];
      },
    ];

    yield 'multi-bundle reference → one reference leaf per bundle' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new ReferencedBundleSpecificBranches([
              'entity:media:image' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
              'entity:media:video' => new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
            ]),
          ),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
        return [
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
          ),
        ];
      },
    ];

    // An object with a follow-reference (`↝`) entry expands fully: the `↝`
    // entry becomes per-leaf reference expressions, alongside the loose leaf.
    // Object-prop names are dropped; here they are canonical (`user_picture`
    // is the reference's developer-facing key, `target_id` the property name),
    // so coalesce(expand(X)) === X — the round trip is lossless.
    yield 'object with a follow-reference entry → atomic leaves' => [
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        return [
          new FieldObjectPropsExpression($node, 'uid', NULL, [
            'target_id' => new FieldPropExpression($node, 'uid', NULL, 'target_id'),
            'user_picture' => new ReferenceFieldPropExpression(
              referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
              referenced: new FieldObjectPropsExpression($user, 'user_picture', NULL, [
                'alt' => new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
                'width' => new FieldPropExpression($user, 'user_picture', NULL, 'width'),
              ]),
            ),
          ]),
        ];
      },
      static function (): array {
        $node = BetterEntityDataDefinition::create('node', 'article');
        $user = BetterEntityDataDefinition::create('user');
        $referencer = new FieldPropExpression($node, 'uid', NULL, 'entity');
        return [
          new FieldPropExpression($node, 'uid', NULL, 'target_id'),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
          ),
          new ReferenceFieldPropExpression(
            referencer: $referencer,
            referenced: new FieldPropExpression($user, 'user_picture', NULL, 'width'),
          ),
        ];
      },
    ];

    $lone = static function (): array {
      $node = BetterEntityDataDefinition::create('node', 'article');
      return [new FieldPropExpression($node, 'title', 0, 'value')];
    };
    yield 'already-atomic expression → unchanged' => [$lone, $lone];

    $empty = static fn (): array => [];
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
      (string) new FieldPropExpression($node, 'field_image', 0, 'alt'),
      (string) new FieldPropExpression($node, 'field_image', 0, 'target_id'),
      (string) new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
      ),
      (string) new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression($user, 'user_picture', NULL, 'target_id'),
      ),
    ];

    $roundtripped = Coalescer::expand(Coalescer::coalesce($entries));

    // Output order is not part of the contract, so compare as sets.
    \sort($entries);
    \sort($roundtripped);
    self::assertSame($entries, $roundtripped);
  }

  /**
   * Coalesce is idempotent: coalesce(coalesce(x)) === coalesce(x).
   */
  public function testCoalesceIsIdempotent(): void {
    $node = BetterEntityDataDefinition::create('node', 'article');
    $user = BetterEntityDataDefinition::create('user');
    $referencer = new FieldPropExpression($node, 'field_media', NULL, 'entity');
    $entries = [
      (string) new FieldPropExpression($node, 'field_image', 0, 'alt'),
      (string) new FieldPropExpression($node, 'field_image', 0, 'target_id'),
      // This loose pick shares the `uid` field with the reference below: both
      // fold into one FieldObjectPropsExpression with a `↝` entry.
      (string) new FieldPropExpression($node, 'uid', NULL, 'target_id'),
      (string) new ReferenceFieldPropExpression(
        referencer: new FieldPropExpression($node, 'uid', NULL, 'entity'),
        referenced: new FieldPropExpression($user, 'user_picture', NULL, 'alt'),
      ),
      (string) new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'image'), 'name', NULL, 'value'),
      ),
      (string) new ReferenceFieldPropExpression(
        referencer: $referencer,
        referenced: new FieldPropExpression(BetterEntityDataDefinition::create('media', 'video'), 'name', NULL, 'value'),
      ),
    ];

    $once = Coalescer::coalesce($entries);
    $twice = Coalescer::coalesce($once);

    // Output order is not part of the contract, so compare as sets.
    \sort($once);
    \sort($twice);
    self::assertSame($once, $twice);
  }

}
