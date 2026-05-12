<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks canvas trait methods as used under the trait's own class name.
 *
 * PHP 8.0+ changed ReflectionMethod::getDeclaringClass() so that for trait
 * methods accessed via a using class, it returns the USING class (not the
 * trait). ReflectionBasedMemberUsageProvider::getMethodUsages() only calls
 * shouldMarkMethodAsUsed() for methods whose declaring class equals the class
 * being analyzed — so when processing a using class, the trait method IS
 * processed (declaring class = using class). But the VirtualUsage created by
 * createMethodUsage() uses getDeclaringClass(), which references the using
 * class, not the trait. ShipMonk tracks the dead code candidate under the
 * TRAIT name, so the VirtualUsage for the using class never clears it.
 *
 * The fix: iterate over traits directly and emit ClassMethodUsage objects
 * referencing the TRAIT class name, so ShipMonk can match them to candidates.
 */
final class CanvasTraitUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * @return list<\ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage>
   */
  public function getUsages(Node $node, Scope $scope): array {
    $usages = parent::getUsages($node, $scope);

    // @phpstan-ignore phpstanApi.instanceofAssumption
    if (!$node instanceof InClassNode) {
      return $usages;
    }

    $nativeReflection = $node->getClassReflection()->getNativeReflection();

    foreach ($nativeReflection->getTraits() as $trait) {
      if (!str_starts_with($trait->getName(), 'Drupal\\canvas\\')) {
        continue;
      }
      $data = VirtualUsageData::withNote(
        \sprintf('Declared in canvas trait %s; calls recorded under using class.', $trait->getShortName()),
      );
      foreach ($trait->getMethods() as $traitMethod) {
        $usages[] = new ClassMethodUsage(
          UsageOrigin::createVirtual($this, $data),
          new ClassMethodRef(
            $trait->getName(),
            $traitMethod->getName(),
            possibleDescendant: FALSE,
          ),
        );
      }
    }

    return $usages;
  }

}
