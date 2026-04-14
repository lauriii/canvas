<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallows calling drupal_flush_all_caches().
 *
 * @implements Rule<FuncCall>
 */
final class DisallowDrupalFlushAllCachesRule implements Rule {

  public function getNodeType(): string {
    return FuncCall::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if (!$node->name instanceof Name) {
      return [];
    }

    if ($node->name->toString() !== 'drupal_flush_all_caches') {
      return [];
    }

    return [
      RuleErrorBuilder::message(
        'drupal_flush_all_caches() must not be called. It is an overly broad function that can have unexpected side effects.',
      )
        ->identifier('canvas.disallowDrupalFlushAllCaches')
        ->build(),
    ];
  }

}
