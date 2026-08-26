<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\canvas\CanvasConfigUpdater;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids CanvasConfigUpdater::needs*() detectors from mutating their entity.
 *
 * The data-health "escaped config" audit
 * (\Drupal\canvas\Health\Doctor::runUpdatesEscapedConfigCheck()) reflects every
 * public needs*() method off CanvasConfigUpdater and runs them all against one
 * shared, loaded config entity to decide which data-model migrations are still
 * pending. Those detectors must therefore be side-effect-free: if one mutates
 * the entity it is passed, the change leaks into every detector that runs after
 * it on the same entity, silently corrupting the audit's result. This has
 * already bitten twice — one detector mutated the entity via
 * setComponentTree()/set() (since reworked into a separate mutator), and
 * another had to defensively clone() before calling calculateDependencies()
 * (which rewrites the dependency list in place).
 *
 * This rule enforces that read-only contract statically. For each public
 * needs*() method on CanvasConfigUpdater, it flags any call to a known
 * state-changing method — or any property/offset write — made directly on the
 * entity parameter (the first parameter, which is what the audit passes the
 * entity as). Working on a clone, e.g. `$probe = clone $entity;
 * $probe->calculateDependencies();`, is fine: the call receiver is `$probe`,
 * not the parameter, so it does not trigger.
 *
 * Note that loadVersion() is deliberately NOT on the blocklist: existing
 * detectors switch a Component's active version and restore the originally
 * loaded one before returning, which is an accepted (net no-op) pattern.
 *
 * Known limitation: only *direct* mutation of the parameter variable is
 * detected. Indirect mutation — through a helper that receives the entity,
 * through a by-reference alias, or by mutating an object reachable from the
 * entity's field graph — is out of scope, matching the pragmatic scope of the
 * other Canvas rules. Reassigning the parameter to a clone under the same name
 * (`$entity = clone $entity;`) and then mutating it would be a false positive,
 * but no detector is written that way; the convention is a distinctly named
 * `$probe`.
 *
 * @see \Drupal\canvas\CanvasConfigUpdater
 * @see \Drupal\canvas\Health\Doctor::runUpdatesEscapedConfigCheck()
 *
 * @implements Rule<ClassMethod>
 */
final class NeedsDetectorMustNotMutateEntityRule implements Rule {

  /**
   * Methods whose invocation on the entity parameter mutates it.
   *
   * Kept deliberately small and explicit: these are the config-entity /
   * component-tree writers a detector could plausibly reach for. loadVersion()
   * is intentionally absent — see the class docblock.
   */
  private const MUTATING_METHODS = [
    'set',
    'setComponentTree',
    'setInput',
    'setInputs',
    'setSettings',
    'setStatus',
    'setOriginalId',
    'setThirdPartySetting',
    'unsetThirdPartySetting',
    'enforceIsNew',
    'enable',
    'disable',
    'createVersion',
    'calculateDependencies',
    'save',
    'delete',
  ];

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    \assert($node instanceof ClassMethod);

    // Only the public needs*() detectors on CanvasConfigUpdater are reflected
    // and run by the escaped-config audit, so only they carry the contract.
    if (!$node->isPublic() || !\str_starts_with($node->name->toString(), 'needs')) {
      return [];
    }
    $class = $scope->getClassReflection();
    if ($class === NULL || $class->getName() !== CanvasConfigUpdater::class) {
      return [];
    }

    // The entity is passed as the first argument by the audit, so the first
    // parameter is the variable a mutation would target.
    $firstParam = $node->params[0] ?? NULL;
    if ($firstParam === NULL || !$firstParam->var instanceof Variable || !\is_string($firstParam->var->name)) {
      return [];
    }
    $entityVar = $firstParam->var->name;

    $errors = [];
    $finder = new NodeFinder();
    $body = $node->stmts ?? [];

    // 1. A blocklisted state-changing method called directly on the parameter.
    foreach ($finder->findInstanceOf($body, MethodCall::class) as $call) {
      \assert($call instanceof MethodCall);
      if ($this->isMutatingCallOnParameter($call, $entityVar)) {
        $errors[] = $this->buildError(\sprintf(
          '%s::%s() is a needs*() detector but calls $%s->%s(); the escaped-config health check runs every detector against one shared entity, so mutating the entity it is passed corrupts the audit for the detectors that run after it. Work on a clone (e.g. `$probe = clone $%s;`) instead.',
          CanvasConfigUpdater::class,
          $node->name->toString(),
          $entityVar,
          $call->name instanceof Identifier ? $call->name->toString() : (string) $call->name,
          $entityVar,
        ));
      }
    }
    foreach ($finder->findInstanceOf($body, NullsafeMethodCall::class) as $call) {
      \assert($call instanceof NullsafeMethodCall);
      if ($this->isMutatingCallOnParameter($call, $entityVar)) {
        $errors[] = $this->buildError(\sprintf(
          '%s::%s() is a needs*() detector but calls $%s?->%s(); mutating the entity it is passed corrupts the shared-entity escaped-config audit. Work on a clone instead.',
          CanvasConfigUpdater::class,
          $node->name->toString(),
          $entityVar,
          $call->name instanceof Identifier ? $call->name->toString() : (string) $call->name,
        ));
      }
    }

    // 2. A property or array-offset write on the parameter, e.g.
    //    `$entity->foo = …;` or `$entity->foo['bar'] = …;`.
    foreach ($finder->find($body, static fn (Node $n): bool => $n instanceof Assign || $n instanceof AssignRef || $n instanceof AssignOp) as $assign) {
      \assert($assign instanceof Assign || $assign instanceof AssignRef || $assign instanceof AssignOp);
      if ($this->isWriteToParameter($assign->var, $entityVar)) {
        $errors[] = $this->buildError(\sprintf(
          '%s::%s() is a needs*() detector but writes to a property of $%s; mutating the entity it is passed corrupts the shared-entity escaped-config audit. Work on a clone instead.',
          CanvasConfigUpdater::class,
          $node->name->toString(),
          $entityVar,
        ));
      }
    }

    return $errors;
  }

  /**
   * Whether a (nullsafe) method call invokes a blocklisted mutator on $param.
   */
  private function isMutatingCallOnParameter(MethodCall|NullsafeMethodCall $call, string $param): bool {
    return $call->name instanceof Identifier
      && \in_array($call->name->toString(), self::MUTATING_METHODS, TRUE)
      && $call->var instanceof Variable
      && $call->var->name === $param;
  }

  /**
   * Whether an assignment target writes into the parameter object.
   *
   * Matches a write to one of the parameter's properties (`$entity->p = …`,
   * nested `$entity->p->q = …`, or `$entity->p['k'] = …`) and a direct offset
   * write (`$entity[…] = …`). A plain rebind of the variable itself
   * (`$entity = …`) is not a mutation of the passed object and is ignored.
   */
  private function isWriteToParameter(Expr $target, string $param): bool {
    // Peel array-offset layers: `$entity->p['k']['j'] = …`.
    $sawOffset = FALSE;
    while ($target instanceof ArrayDimFetch) {
      $sawOffset = TRUE;
      $target = $target->var;
    }

    if ($target instanceof PropertyFetch || $target instanceof NullsafePropertyFetch) {
      return $this->rootVariableName($target->var) === $param;
    }

    // `$entity[…] = …` — an offset write directly on the parameter variable.
    if ($sawOffset && $target instanceof Variable) {
      return $target->name === $param;
    }

    return FALSE;
  }

  /**
   * The name of the variable at the root of a property/offset access chain.
   */
  private function rootVariableName(Expr $expr): ?string {
    while ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch || $expr instanceof ArrayDimFetch) {
      $expr = $expr->var;
    }
    return $expr instanceof Variable && \is_string($expr->name) ? $expr->name : NULL;
  }

  private function buildError(string $message): RuleError {
    return RuleErrorBuilder::message($message)
      ->identifier('canvas.needsDetectorMustNotMutate')
      ->build();
  }

}
