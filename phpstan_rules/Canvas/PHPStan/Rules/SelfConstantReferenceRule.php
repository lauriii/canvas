<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires self:: for constants defined in the analyzed class' own hierarchy.
 *
 * Referencing an inherited (or own) parent-class constant by an explicit class
 * name — e.g. `ParentClass::SOME_CONST` from within a subclass — is redundant;
 * `self::` is clearer and survives class renames. This rule reports such
 * references and suggests `self::`.
 *
 * Deliberately NOT flagged:
 * - References inside a trait body: `self::` in a trait resolves against each
 *   using class, so the explicit reference is a valid, clearer choice there.
 * - Interface constants (`SomeInterface::CONST`): naming the interface
 *   documents which contract the constant comes from, a legitimate pattern.
 * - Enum cases (`SomeEnum::Case`): referencing a case by the enum name is the
 *   idiomatic style, including from within the enum itself.
 * - `SomeClass::class`: `Ancestor::class` is not equal to `self::class` (they
 *   resolve to different FQCNs).
 * - Shadowed constants: when an intermediate class overrides the constant,
 *   `self::CONST` and `Ancestor::CONST` resolve to different values, so the
 *   explicit reference is intentional.
 *
 * @implements Rule<ClassConstFetch>
 */
final class SelfConstantReferenceRule implements Rule {

  public function __construct(
    private readonly ReflectionProvider $reflectionProvider,
  ) {}

  public function getNodeType(): string {
    return ClassConstFetch::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
      return [];
    }
    // Leave trait bodies alone: self:: resolves against the using class.
    if ($scope->getTraitReflection() !== NULL) {
      return [];
    }
    $referenced = $node->class->toString();
    if (\in_array(\strtolower($referenced), ['self', 'static', 'parent'], TRUE)) {
      return [];
    }
    $constName = $node->name->toString();
    // `Ancestor::class` is not equivalent to `self::class`.
    if (\strtolower($constName) === 'class') {
      return [];
    }
    if ($this->reflectionProvider->hasClass($referenced)) {
      $referencedClass = $this->reflectionProvider->getClass($referenced);
      // Referencing an interface constant by the interface name is allowed: it
      // documents which contract the constant belongs to.
      if ($referencedClass->isInterface()) {
        return [];
      }
      // Referencing an enum case by the enum name is the idiomatic style.
      if ($referencedClass->isEnum() && $referencedClass->hasEnumCase($constName)) {
        return [];
      }
    }
    $class = $scope->getClassReflection();
    if ($class === NULL) {
      return [];
    }
    // Only when the referenced class is the analyzed class or one of its
    // ancestors (i.e. the constant is reachable via self::).
    if ($class->getName() !== $referenced && !$class->isSubclassOf($referenced)) {
      return [];
    }
    // Only when self:: resolves to the same definition (constant not shadowed
    // by an override between the referenced class and the analyzed class).
    if (!$class->hasConstant($constName) || $class->getConstant($constName)->getDeclaringClass()->getName() !== $referenced) {
      return [];
    }
    return [
      RuleErrorBuilder::message(\sprintf(
        "Use self::%s instead of %s::%s for a constant from this class' own hierarchy.",
        $constName,
        $referenced,
        $constName,
      ))
        ->identifier('canvas.selfConstantReference')
        ->build(),
    ];
  }

}
