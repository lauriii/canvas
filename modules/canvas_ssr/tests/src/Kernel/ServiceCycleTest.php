<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests canvas_ssr's cache tags invalidators don't need what needs them.
 *
 * The cache tags invalidator service is built with every invalidator, and
 * routing needs it. So a service an invalidator needs, directly or not,
 * can't need routing, as Canvas's breadcrumb builder does, without a
 * closure between. A request whose first service needs routing would fail
 * with a circular reference.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_ssr')]
final class ServiceCycleTest extends CanvasSsrKernelTestBase {

  /**
   * Tests no invalidator of canvas_ssr's needs the cache tags invalidator.
   */
  public function testInvalidatorsDoNotNeedTheInvalidator(): void {
    $container = $this->container;
    \assert($container instanceof ContainerBuilder);
    $invalidators = \array_filter(
      \array_keys($container->findTaggedServiceIds('cache_tags_invalidator')),
      static fn (string $id): bool => \str_starts_with($id, 'Drupal\canvas_ssr\\'),
    );
    self::assertNotEmpty($invalidators);

    foreach ($invalidators as $id) {
      $path = $this->pathTo($container, $id, 'cache_tags.invalidator');
      self::assertNull($path, $id . ' needs ' . \implode(' -> ', $path ?? []));
    }
  }

  /**
   * Finds a chain of services one needs to be built with to reach another.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container.
   * @param string $from
   *   The service to start from.
   * @param string $to
   *   The service to look for.
   *
   * @return list<string>|null
   *   The chain, from $from to $to, or NULL if there's none.
   */
  private function pathTo(ContainerBuilder $container, string $from, string $to): ?array {
    $parents = [$from => NULL];
    $queue = [$from];
    while ($queue !== []) {
      $id = \array_shift($queue);
      if ($id === $to) {
        $path = [];
        for ($step = $id; $step !== NULL; $step = $parents[$step]) {
          \array_unshift($path, $step);
        }
        return $path;
      }
      if (!$container->hasDefinition($id)) {
        continue;
      }
      $definition = $container->getDefinition($id);
      $values = [$definition->getArguments(), $definition->getProperties(), $definition->getFactory(), $definition->getConfigurator()];
      foreach ($definition->getMethodCalls() as [, $arguments]) {
        $values[] = $arguments;
      }
      foreach ($this->references($values) as $reference) {
        $next = (string) $reference;
        if ($container->hasAlias($next)) {
          $next = (string) $container->getAlias($next);
        }
        // Kernel tests put a route provider of their own in core's place,
        // and keep core's definition under this name.
        if ($container->hasDefinition('test.' . $next)) {
          $next = 'test.' . $next;
        }
        if (!\array_key_exists($next, $parents)) {
          $parents[$next] = $id;
          $queue[] = $next;
        }
      }
    }

    return NULL;
  }

  /**
   * Lists the references in a definition's values that it's built with.
   *
   * Those behind a closure, an iterator or a locator are built only when
   * used, so they're left out.
   *
   * @param mixed $value
   *   The values.
   *
   * @return list<\Symfony\Component\DependencyInjection\Reference>
   *   The references.
   */
  private function references(mixed $value): array {
    if ($value instanceof Reference) {
      return [$value];
    }
    if ($value instanceof ArgumentInterface) {
      return [];
    }
    if ($value instanceof Definition) {
      $value = [$value->getArguments(), $value->getMethodCalls(), $value->getFactory()];
    }
    if (!\is_array($value)) {
      return [];
    }
    $references = [];
    foreach ($value as $item) {
      \array_push($references, ...$this->references($item));
    }

    return $references;
  }

}
