<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Node\Expr\Variable;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallows optional parameters in test methods that use a data provider.
 *
 * When a test method uses @dataProvider or #[DataProvider], all parameters
 * must be required. Optional parameters can easily create a mismatch
 * between the data provider and the test method signature.
 *
 * @implements Rule<ClassMethod>
 */
final class NoOptionalParametersInDataProviderTestsRule implements Rule {

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if (!$this->hasDataProvider($node)) {
      return [];
    }

    $errors = [];
    foreach ($node->params as $param) {
      if ($param->default !== NULL) {
        $param_name = $param->var instanceof Variable ? '$' . $param->var->name : '(unknown)';
        $errors[] = RuleErrorBuilder::message(
          \sprintf(
            'Test method %s() uses a data provider but has optional parameter %s. All parameters should be provided by the data provider.',
            $node->name->name,
            $param_name,
          ),
        )
          ->identifier('canvas.noOptionalParamsInDataProviderTests')
          ->build();
      }
    }

    return $errors;
  }

  private function hasDataProvider(ClassMethod $node): bool {
    // Check PHP 8 attributes.
    foreach ($node->attrGroups as $attrGroup) {
      foreach ($attrGroup->attrs as $attr) {
        $name = $attr->name->toString();
        if ($name === 'PHPUnit\Framework\Attributes\DataProvider'
          || $name === 'DataProvider') {
          return TRUE;
        }
      }
    }

    // Check docblock @dataProvider annotation.
    $docComment = $node->getDocComment();
    if ($docComment !== NULL
      && \str_contains($docComment->getText(), '@dataProvider')) {
      return TRUE;
    }

    return FALSE;
  }

}
