<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\PropSource\ListFieldContext;
use Drupal\canvas\PropSource\ListFieldPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Cache\CacheableMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @see \Drupal\canvas\PropSource\ListFieldPropSource
 */
#[CoversClass(ListFieldPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class ListFieldPropSourceTest extends PropSourceTestBase {

  public function testParseRoundTrip(): void {
    $stored = ['sourceType' => 'list-field', 'field' => 'title'];
    $source = PropSource::parse($stored);
    self::assertInstanceOf(ListFieldPropSource::class, $source);
    self::assertSame('title', $source->fieldName);
    self::assertSame($stored, $source->toArray());
    self::assertSame('list-field', $source->getSourceType());
  }

  public function testParseRejectsMissingField(): void {
    $this->expectException(\LogicException::class);
    PropSource::parse(['sourceType' => 'list-field']);
  }

  public function testEvaluatesToNullOutsideIteration(): void {
    $source = new ListFieldPropSource('title');
    self::assertNull($source->evaluate(NULL, FALSE)->value);
  }

  public function testEvaluatesFromInnermostIterationFrame(): void {
    $context = $this->container->get(ListFieldContext::class);
    \assert($context instanceof ListFieldContext);
    $source = new ListFieldPropSource('title');

    $outer_cacheability = (new CacheableMetadata())->addCacheTags(['outer']);
    $context->push(['title' => 'Outer row'], $outer_cacheability);
    self::assertSame('Outer row', $source->evaluate(NULL, FALSE)->value);

    // Nested repetition: the innermost frame wins, and popping restores the
    // outer one.
    $context->push(['title' => 'Inner row']);
    self::assertSame('Inner row', $source->evaluate(NULL, FALSE)->value);
    $context->pop();

    $result = $source->evaluate(NULL, FALSE);
    self::assertSame('Outer row', $result->value);
    self::assertContains('outer', $result->getCacheTags());
    $context->pop();

    // A field the frame does not declare resolves to NULL.
    $context->push(['other' => 'x']);
    self::assertNull($source->evaluate(NULL, FALSE)->value);
    $context->pop();
  }

  public function testUnbalancedPopThrows(): void {
    $context = $this->container->get(ListFieldContext::class);
    \assert($context instanceof ListFieldContext);
    $this->expectException(\LogicException::class);
    $context->pop();
  }

  public function testCalculateDependenciesIsEmpty(): void {
    self::assertSame([], (new ListFieldPropSource('title'))->calculateDependencies());
  }

}
