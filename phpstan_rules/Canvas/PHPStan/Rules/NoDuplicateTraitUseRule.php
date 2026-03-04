<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallows using a trait that is already used by a parent class.
 *
 * Only enforced for trait-use statements written directly in a class, not
 * for trait-use statements inside traits. Traits need more flexibility:
 * they may legitimately use a trait that a consuming class (or its parent)
 * also uses.
 *
 * Traits that contain only private methods are also exempt: private methods
 * are scoped to the using class and do not conflict.
 *
 * @implements Rule<TraitUse>
 */
final class NoDuplicateTraitUseRule implements Rule {

  /**
   * @var array<string, bool> */
  private array $onlyPrivateMethodsCache = [];

  public function __construct(
    private readonly ReflectionProvider $reflectionProvider,
  ) {}

  public function getNodeType(): string {
    return TraitUse::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    $classReflection = $scope->getClassReflection();
    if ($classReflection === NULL) {
      return [];
    }

    // When PHPStan analyses a trait-use inside a trait, it reports it in
    // the context of each consuming class. We only want to flag trait-uses
    // written directly in a class definition. Detect this by checking
    // whether the trait being analysed is the class itself: if not, the
    // trait-use originates from a different (trait) file and should be
    // skipped.
    if ($scope->isInTrait()) {
      return [];
    }

    $parentClass = $classReflection->getParentClass();
    if ($parentClass === NULL) {
      return [];
    }

    $errors = [];

    foreach ($node->traits as $traitName) {
      $traitFqn = $traitName->toString();

      // hasTraitUse() checks the full class hierarchy including the
      // parent's parents and traits used by traits, exactly what we need.
      if ($parentClass->hasTraitUse($traitFqn)) {
        // Allow duplicate use if the trait contains only private methods.
        if ($this->hasOnlyPrivateMethods($traitFqn)) {
          continue;
        }

        $errors[] = RuleErrorBuilder::message(
              \sprintf(
                  'Trait %s is already used by parent class %s.',
                  $traitFqn,
                  $this->findAncestorUsingTrait($parentClass, $traitFqn),
              )
          )
          ->identifier('canvas.duplicateTraitUse')
          ->build();
      }
    }

    return $errors;
  }

  /**
   * Check whether a trait contains only private methods.
   */
  private function hasOnlyPrivateMethods(string $traitFqn): bool {
    if (\array_key_exists($traitFqn, $this->onlyPrivateMethodsCache)) {
      return $this->onlyPrivateMethodsCache[$traitFqn];
    }

    $result = $this->computeHasOnlyPrivateMethods($traitFqn);
    $this->onlyPrivateMethodsCache[$traitFqn] = $result;
    return $result;
  }

  private function computeHasOnlyPrivateMethods(string $traitFqn): bool {
    if (!$this->reflectionProvider->hasClass($traitFqn)) {
      return FALSE;
    }

    $traitReflection = $this->reflectionProvider->getClass($traitFqn)
      ->getNativeReflection();

    // Only consider methods declared directly on this trait (not inherited
    // from other traits it uses).
    $methods = $traitReflection->getMethods();

    // Must have at least one method to qualify.
    if ($methods === []) {
      return FALSE;
    }

    // If any non-private method exists, it's not "only private".
    foreach ($methods as $method) {
      if ($method->getDeclaringClass()->getName() !== $traitFqn) {
        continue;
      }
      if (!$method->isPrivate()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Find the closest ancestor class that directly uses the given trait.
   */
  private function findAncestorUsingTrait(ClassReflection $classReflection, string $traitFqn): string {
    $current = $classReflection;

    while ($current !== NULL) {
      // Check traits directly used by this class.
      foreach ($current->getTraits() as $trait) {
        if ($trait->getName() === $traitFqn) {
          return $current->getName();
        }
        // Also check traits used by traits (recursive).
        if ($trait->hasTraitUse($traitFqn)) {
          return $current->getName();
        }
      }
      $current = $current->getParentClass();
    }

    // Fallback: return the immediate parent's name.
    return $classReflection->getName();
  }

}
