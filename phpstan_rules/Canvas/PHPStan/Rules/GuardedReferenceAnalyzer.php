<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Throw_ as ThrowExpr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Throw_ as ThrowStmt;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PHPStan\Analyser\Scope;

/**
 * Reports hard references to undeclared modules, honouring moduleExists guards.
 *
 * Walks a function body statement by statement, tracking which modules a
 * `moduleHandler->moduleExists('X')` check has proven present at each point,
 * so a properly-guarded reference is not reported. Two guard shapes are
 * recognised:
 *  - enclosing positive guard: `if ($h->moduleExists('X')) { … ref … }`
 *    (including `moduleExists('X') && …`), and
 *  - negative early-return guard: `if (!$h->moduleExists('X')) { return; }`
 *    followed by the reference in the same block.
 *
 * Nested function-likes (closures, arrow functions) are not descended into —
 * they are analysed on their own and do not inherit the guard scope.
 *
 * @see \Canvas\PHPStan\Rules\UndeclaredModuleReference
 */
final class GuardedReferenceAnalyzer {

  /**
   * @param list<Node\Stmt> $stmts
   *
   * @return list<\PHPStan\Rules\IdentifierRuleError>
   */
  public static function analyze(array $stmts, Scope $scope): array {
    $errors = [];
    self::walkStmtList($stmts, [], $scope, $errors);
    return $errors;
  }

  /**
   * @param list<Node\Stmt> $stmts
   * @param array<string, true> $guarded
   * @param list<\PHPStan\Rules\IdentifierRuleError> $errors
   */
  private static function walkStmtList(array $stmts, array $guarded, Scope $scope, array &$errors): void {
    foreach ($stmts as $stmt) {
      if ($stmt instanceof If_) {
        self::checkTree($stmt->cond, $guarded, $scope, $errors);
        $positive = self::positiveGuardModules($stmt->cond);
        self::walkStmtList($stmt->stmts, $guarded + $positive, $scope, $errors);
        foreach ($stmt->elseifs as $elseif) {
          self::checkTree($elseif->cond, $guarded, $scope, $errors);
          self::walkStmtList($elseif->stmts, $guarded + self::positiveGuardModules($elseif->cond), $scope, $errors);
        }
        if ($stmt->else !== NULL) {
          self::walkStmtList($stmt->else->stmts, $guarded, $scope, $errors);
        }
        // A `if (!moduleExists('X')) { return; }` guard covers the statements
        // that follow it in this same block.
        $negative = self::negativeGuardModule($stmt->cond);
        if ($negative !== NULL && $stmt->elseifs === [] && $stmt->else === NULL && self::terminates($stmt->stmts)) {
          $guarded[$negative] = TRUE;
        }
        continue;
      }
      self::walkStmt($stmt, $guarded, $scope, $errors);
    }
  }

  /**
   * @param array<string, true> $guarded
   * @param list<\PHPStan\Rules\IdentifierRuleError> $errors
   */
  private static function walkStmt(Stmt $stmt, array $guarded, Scope $scope, array &$errors): void {
    switch (TRUE) {
      case $stmt instanceof Foreach_:
        self::checkTree($stmt->expr, $guarded, $scope, $errors);
        self::walkStmtList($stmt->stmts, $guarded, $scope, $errors);
        return;

      case $stmt instanceof For_:
        foreach ([...$stmt->init, ...$stmt->cond, ...$stmt->loop] as $expr) {
          self::checkTree($expr, $guarded, $scope, $errors);
        }
        self::walkStmtList($stmt->stmts, $guarded, $scope, $errors);
        return;

      case $stmt instanceof While_:
      case $stmt instanceof Do_:
        self::checkTree($stmt->cond, $guarded, $scope, $errors);
        self::walkStmtList($stmt->stmts, $guarded, $scope, $errors);
        return;

      case $stmt instanceof Switch_:
        self::checkTree($stmt->cond, $guarded, $scope, $errors);
        foreach ($stmt->cases as $case) {
          if ($case->cond !== NULL) {
            self::checkTree($case->cond, $guarded, $scope, $errors);
          }
          self::walkStmtList($case->stmts, $guarded, $scope, $errors);
        }
        return;

      case $stmt instanceof TryCatch:
        self::walkStmtList($stmt->stmts, $guarded, $scope, $errors);
        foreach ($stmt->catches as $catch) {
          self::walkStmtList($catch->stmts, $guarded, $scope, $errors);
        }
        if ($stmt->finally !== NULL) {
          self::walkStmtList($stmt->finally->stmts, $guarded, $scope, $errors);
        }
        return;

      default:
        // A leaf statement (assignment, return, echo…): its expression subtree
        // holds no nested statement lists, so check it directly.
        self::checkTree($stmt, $guarded, $scope, $errors);
    }
  }

  /**
   * Reports hard references in a subtree, not descending into function-likes.
   *
   * @param array<string, true> $guarded
   * @param list<\PHPStan\Rules\IdentifierRuleError> $errors
   */
  private static function checkTree(Node $node, array $guarded, Scope $scope, array &$errors): void {
    // Closures/arrow functions are analysed independently; their bodies do not
    // share this guard scope.
    if ($node instanceof FunctionLike) {
      return;
    }
    $name = self::hardReferenceName($node);
    if ($name !== NULL) {
      foreach (UndeclaredModuleReference::check($name, $scope, $guarded) as $error) {
        $errors[] = $error;
      }
    }
    foreach ($node->getSubNodeNames() as $subNodeName) {
      $sub = $node->{$subNodeName};
      if ($sub instanceof Node) {
        self::checkTree($sub, $guarded, $scope, $errors);
      }
      elseif (\is_array($sub)) {
        foreach ($sub as $item) {
          if ($item instanceof Node) {
            self::checkTree($item, $guarded, $scope, $errors);
          }
        }
      }
    }
  }

  /**
   * Returns the referenced class Name if the node is a hard dereference.
   */
  private static function hardReferenceName(Node $node): ?Name {
    $classNode = match (TRUE) {
      $node instanceof New_ => $node->class,
      $node instanceof ClassConstFetch => ($node->name instanceof Identifier && \strtolower($node->name->toString()) === 'class') ? NULL : $node->class,
      $node instanceof StaticCall => $node->class,
      $node instanceof StaticPropertyFetch => $node->class,
      default => NULL,
    };
    return $classNode instanceof Name ? $classNode : NULL;
  }

  /**
   * Modules proven present when an expression is truthy (positive guard).
   *
   * @return array<string, true>
   */
  private static function positiveGuardModules(Node $cond): array {
    if ($cond instanceof BooleanAnd) {
      return self::positiveGuardModules($cond->left) + self::positiveGuardModules($cond->right);
    }
    $module = self::moduleExistsArgument($cond);
    return $module !== NULL ? [$module => TRUE] : [];
  }

  /**
   * The module in a `!moduleExists('X')` negative guard, if any.
   */
  private static function negativeGuardModule(Node $cond): ?string {
    if ($cond instanceof BooleanNot) {
      return self::moduleExistsArgument($cond->expr);
    }
    return NULL;
  }

  /**
   * The literal module name of a `…->moduleExists('X')` call, if this is one.
   */
  private static function moduleExistsArgument(Node $node): ?string {
    if (!$node instanceof MethodCall) {
      return NULL;
    }
    if (!$node->name instanceof Identifier || \strtolower($node->name->toString()) !== 'moduleexists') {
      return NULL;
    }
    $args = $node->getArgs();
    if (isset($args[0]) && $args[0]->value instanceof String_) {
      return $args[0]->value->value;
    }
    return NULL;
  }

  /**
   * Whether a block ends in a control-flow exit (return/throw/continue/break).
   *
   * @param list<Node\Stmt> $stmts
   */
  private static function terminates(array $stmts): bool {
    $last = \end($stmts);
    if ($last === FALSE) {
      return FALSE;
    }
    return $last instanceof Return_
      || $last instanceof ThrowStmt
      || $last instanceof Continue_
      || $last instanceof Break_
      || ($last instanceof Expression && $last->expr instanceof ThrowExpr);
  }

}
