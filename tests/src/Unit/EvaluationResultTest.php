<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests EvaluationResult with all known permutations as test cases.
 */
#[CoversClass(EvaluationResult::class)]
#[Group('canvas')]
class EvaluationResultTest extends UnitTestCase {

  #[DataProvider('provider')]
  public function test(CacheableMetadata $cacheability, mixed $value, CacheableMetadata $expected_cacheability, mixed $expected_value): void {
    // Special case: EvaluationResults containing an entity object, to allow
    // using EvaluationResult objects while traversing a chain of entity refs.
    if ($value === User::class && $expected_value === User::class) {
      $user_prophecy = $this->prophesize(User::class);
      $user_prophecy->getCacheTags()->willReturn(['user:2']);
      $value = $expected_value = $user_prophecy->reveal();
    }
    $test = new EvaluationResult($value, $cacheability);
    self::assertSame($expected_value, $test->value);
    self::assertEqualsCanonicalizing($expected_cacheability->getCacheTags(), $test->getCacheTags());
    self::assertEqualsCanonicalizing($expected_cacheability->getCacheContexts(), $test->getCacheContexts());
    self::assertEqualsCanonicalizing($expected_cacheability->getCacheMaxAge(), $test->getCacheMaxAge());
  }

  /**
   * The unique result shapes for which to generate test cases.
   *
   * @return non-empty-array<string, mixed>
   */
  private static function getUniqueResultShapes(): array {
    $single_cardinality_scalar_result_shapes = [
      'string' => 'Hello, world',
      'integer' => 42,
      'float' => 3.14,
      'boolean' => TRUE,
    ];
    $single_cardinality_object_result_shapes = [
      'single-prop object' => [
        'sole_required_prop' => 'something',
      ],
      'multi-prop object' => [
        'one' => 'of',
        'many' => 42,
        'props' => FALSE,
      ],
    ];
    $single_cardinality_all_result_shapes = [
      ...$single_cardinality_scalar_result_shapes,
      ...$single_cardinality_object_result_shapes,
      // Special case: optional.
      'optional' => NULL,
    ];

    // Generate 1–5 cardinality result shapes based on the single-cardinality
    // result shapes.
    $multiple_cardinality_all_result_shapes = [];
    foreach ($single_cardinality_all_result_shapes as $label => $r) {
      $multiple_cardinality_all_result_shapes["multiple-cardinality $label"] = array_fill(0, rand(1, 5), $r);
    }
    // Special case: optional.
    $multiple_cardinality_all_result_shapes['multiple-cardinality optional'] = [];

    return [
      ...$single_cardinality_all_result_shapes,
      ...$multiple_cardinality_all_result_shapes,
      'entity object while following a reference' => User::class,
    ];
  }

  public static function provider(): array {
    $permanent_cacheability = new CacheableMetadata();
    $node_42_cacheability = (new CacheableMetadata())
      ->addCacheTags(['node:42']);
    $user_1337_cacheability = (new CacheableMetadata())
      ->addCacheTags(['user:1337']);

    $cases = [];
    foreach (self::getUniqueResultShapes() as $label => $r) {
      // Tests simplest constructor code path.
      $cases["permanently cacheable, $label"] = [
        $permanent_cacheability,
        $r,
        $permanent_cacheability,
        $r,
      ];

      // Tests basic cacheability support.
      $cases["node 42-invalidated, $label"] = [
        $node_42_cacheability,
        $r,
        $node_42_cacheability,
        $r,
      ];

      // Tests hoisting of evaluation results.
      $cases["permanently cacheable & to-be-hoisted (also permanently cacheable): $label"] = [
        $permanent_cacheability,
        new EvaluationResult($r, $permanent_cacheability),
        $permanent_cacheability,
        $r,
      ];

      // Tests hoisting of cacheability.
      $cases["permanently cacheable & to-be-hoisted (node 42-invalidated): $label"] = [
        // Permanent cacheability specified.
        $permanent_cacheability,
        // The to-be-hoisted evaluation result is invalidated by `node:42`.
        new EvaluationResult($r, $node_42_cacheability),
        $node_42_cacheability,
        $r,
      ];

      // Tests hoisting of cacheability does not overwrite the specified
      // cacheability.
      $cases["node 42-invalidated & wrapped (permanently cacheable): $label"] = [
        // The to-be-hoisted evaluation result is invalidated by `node:42`.
        $node_42_cacheability,
        // The to-be-hoisted evaluation result is permanently cacheable.
        new EvaluationResult($r, $permanent_cacheability),
        $node_42_cacheability,
        $r,
      ];

      // Tests hoisting of cacheability does not overwrite the specified
      // cacheability, but is merged.
      $cases["node 42-invalidated & wrapped (user 1337-invalidated): $label"] = [
        // The to-be-hoisted evaluation result is invalidated by `node:42`.
        $node_42_cacheability,
        // The to-be-hoisted evaluation result is permanently cacheable.
        new EvaluationResult($r, $user_1337_cacheability),
        (new CacheableMetadata())->addCacheTags(['node:42', 'user:1337']),
        $r,
      ];

      if (str_contains($label, 'multiple-cardinality')) {
        // Below tests cases: multiple-cardinality only: these can only be
        // constructed from single-cardinality ones.
        continue;
      }

      // Tests multiple-cardinality result shape being constructed from two
      // result shapes with distinct cacheability.
      $cases["1 hour-cacheable & to-be-hoisted multiple-cardinality (with 2 distinct cacheabilities): $label"] = [
        (new CacheableMetadata())->setCacheMaxAge(3600),
        [
          0 => new EvaluationResult($r, $node_42_cacheability),
          1 => new EvaluationResult($r, $user_1337_cacheability),
        ],
        (new CacheableMetadata())
          ->addCacheTags(['node:42', 'user:1337'])
          ->setCacheMaxAge(3600),
        [
          0 => $r,
          1 => $r,
        ],
      ];

      // Below test cases: multiple-cardinality scalar result shapes only.
      if (str_contains($label, 'object')) {
        continue;
      }

      // Tests object result shape being constructed from two scalar result
      // shapes.
      \assert(!is_array($r));
      $cases["node 42-invalidated & to-be-hoisted object (3 distinct cacheabilities): $label"] = [
        $node_42_cacheability,
        [
          'prop_a' => new EvaluationResult($r, $permanent_cacheability),
          'prop_b' => new EvaluationResult('🤓', $user_1337_cacheability),
          'prop_c' => new EvaluationResult(
            '⏳',
            (new CacheableMetadata())->setCacheMaxAge(59),
          ),
        ],
        (new CacheableMetadata())
          ->addCacheTags(['node:42', 'user:1337'])
          ->setCacheMaxAge(59),
        [
          'prop_a' => $r,
          'prop_b' => '🤓',
          'prop_c' => '⏳',
        ],
      ];
    }

    // The above are all taken from real-world scenarios occurring when
    // evaluating StaticPropSources. But to ensure great DX, Canvas must allow
    // an arbitrary array to be created, with one or more EvaluationResult
    // objects at arbitrary depths, and hoist them up.
    $complex_case_to_deeply_nest = $cases['node 42-invalidated & wrapped (permanently cacheable): multiple-cardinality multi-prop object'];
    $cases['arbitrary nesting'] = [
      new CacheableMetadata(),
      [
        'arbitrarily' => [
          'deep' => [
            'nesting' => [
              'nothing here 🙈',
            ],
            'string key' => TRUE,
            42 => FALSE,
            // Reuse the second parameter of that complex test case: this has
            // merely nested that same complex test case very deeply.
            'surprise evaluation result' => new EvaluationResult(
              $complex_case_to_deeply_nest[1],
              $complex_case_to_deeply_nest[0],
            ),
          ],
        ],
      ],
      $complex_case_to_deeply_nest[2],
      [
        'arbitrarily' => [
          'deep' => [
            'nesting' => [
              'nothing here 🙈',
            ],
            'string key' => TRUE,
            42 => FALSE,
            'surprise evaluation result' => $complex_case_to_deeply_nest[3],
          ],
        ],
      ],
    ];
    return $cases;
  }

}
