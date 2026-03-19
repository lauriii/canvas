<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\PropShape\PersistentPropShapeRepository;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas\PropSource\PropSourceBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base class for prop source matcher kernel tests.
 *
 * Provides a standardized environment for testing the various prop source
 * matcher classes. Installs the necessary entity schemas and creates a
 * representative set of configurable fields used across matcher tests.
 *
 * @phpstan-import-type PropSourceArray from PropSourceBase
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
abstract class PropSourceMatcherTestBase extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // The module providing the sample SDC to test all prop shapes (JSON Schema
    // types + additional constraints such as `format`, `enum` et cetera).
    'sdc_test_all_props',
  ];

  /**
   * The FQCN of the PropSourceMatcher being tested.
   *
   * @var string
   */
  protected string $testedPropSourceMatcherClass;

  /**
   * Expected matches for each unique prop shape.
   *
   * @var array<string, mixed>
   *   Keys are unique prop shapes to match (required and optional variants of
   *   each unique prop shape), and values are a list of matched prop source
   *   arrays. If there is a single match, the value is a single prop source
   *   array.
   *
   * @see \Drupal\canvas\PropShape\PropShape::uniquePropSchemaKey()
   * @see \Drupal\canvas\PropSource\PropSourceBase::toArray())
   */
  protected array $expectedMatches = [];

  /**
   * The matcher being tested.
   *
   * @var \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher|\Drupal\canvas\ShapeMatcher\HostEntityUrlPropSourceMatcher|\Drupal\canvas\ShapeMatcher\AdaptedPropSourceMatcher
   */
  protected $matcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->matcher = $this->container->get($this->testedPropSourceMatcherClass);
  }

  public function test(): void {
    $this->generateComponentConfig();
    $prop_shape_repository = $this->container->get(PropShapeRepositoryInterface::class);
    self::assertInstanceOf(PersistentPropShapeRepository::class, $prop_shape_repository);

    $actual_matches = [];
    foreach ($prop_shape_repository->getUniquePropShapes() as $prop_shape) {
      // Only test matching for which a StaticPropSource also exists: each prop
      // must AT MINIMUM be possible to populate using unstructured data.
      if ($prop_shape_repository->getStorablePropShape($prop_shape) === NULL) {
        continue;
      }

      // For each unique prop shape, matching must be matched against both a
      // required and optional variant.
      $required_matches = $this->performMatch(TRUE, $prop_shape);
      $optional_matches = $this->performMatch(FALSE, $prop_shape);

      // Optional matches MUST include all required matches, since an optional
      // prop can be populated by the same sources as a required prop, plus it
      // can be omitted (which is equivalent to setting it to `null`).
      self::assertEmpty(\array_diff($required_matches, $optional_matches), "🐛 Required matches must be a subset of optional matches — not the case for prop shape " . $prop_shape->uniquePropSchemaKey());

      // List all required matches, but list only the subset of optional matches
      // that are not also required matches, for test maintenance DX.
      $actual_matches[self::getShapeMatchKey(TRUE, $prop_shape)] = $required_matches;
      $actual_matches[self::getShapeMatchKey(FALSE, $prop_shape)] = \array_values(\array_diff($optional_matches, $required_matches));
    }

    // Test maintenance DX: omit unique shapes with zero prop source matches.
    $actual_matches = \array_filter($actual_matches);
    // Test maintenance DX: if a PropSourceBase instance, get the array representation
    $actual_matches = \array_map(
      fn (array $matches): array => \array_map(
        fn (PropSourceBase|string $match): array|string => $match instanceof PropSourceBase
          ? $match->toArray()
          : $match,
        $matches,
      ),
      $actual_matches,
    );
    // Test maintenance DX: if a single match, simplify.
    $actual_matches = \array_map(
      fn (array $matches): string|array => count($matches) === 1 ? reset($matches) : $matches,
      $actual_matches,
    );

    ksort($actual_matches, SORT_FLAG_CASE);

    self::assertSame($this->expectedMatches, $actual_matches);
  }

  /**
   * @param bool $is_required
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *
   * @return list<\Drupal\canvas\PropSource\PropSourceBase|string>
   *   An array of matching prop sources of the tested type — or a string, in
   *   case of adapters (which are not yet fully developed).
   *
   * @todo Tighten return type in https://www.drupal.org/project/canvas/issues/3464003
   */
  protected function performMatch(bool $is_required, PropShape $prop_shape): array {
    // @todo Remove ignore in https://www.drupal.org/project/canvas/issues/3464003
    // @see \Drupal\Tests\canvas\Kernel\ShapeMatcher\AdaptedPropSourceMatcherTest::performMatch
    // @phpstan-ignore-next-line return.type
    return $this->matcher->match($is_required, $prop_shape);
  }

  private static function getShapeMatchKey(bool $is_required, PropShape $prop_shape): string {
    return \sprintf('%s%s',
      $prop_shape->uniquePropSchemaKey(),
      $is_required ? '' : '!optional',
    );
  }

}
