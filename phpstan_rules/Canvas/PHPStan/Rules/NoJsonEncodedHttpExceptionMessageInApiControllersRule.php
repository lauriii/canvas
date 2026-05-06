<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Disallows JSON-encoded strings as HttpException messages in API controllers.
 *
 * ApiExceptionSubscriber serializes the exception message into the response
 * body. Passing a json_encode() or Json::encode() call as the message results
 * in double-encoded JSON in the response, producing malformed error output.
 *
 * @implements Rule<New_>
 */
final class NoJsonEncodedHttpExceptionMessageInApiControllersRule implements Rule {

  public function getNodeType(): string {
    return New_::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    // Only enforce in classes named Api* inside Drupal\canvas\Controller.
    $classReflection = $scope->getClassReflection();
    if ($classReflection === NULL) {
      return [];
    }
    $fqn = $classReflection->getName();
    $shortName = $classReflection->getNativeReflection()->getShortName();
    if (
      !str_starts_with($fqn, 'Drupal\\canvas\\Controller\\') ||
      !str_starts_with($shortName, 'Api')
    ) {
      return [];
    }

    // Only flag HttpException subclasses.
    $type = $scope->getType($node);
    if (!(new ObjectType(HttpException::class))->isSuperTypeOf($type)->yes()) {
      return [];
    }

    $args = $node->getArgs();
    if ($args === []) {
      return [];
    }

    if (!$this->isJsonEncodeCall($args[0]->value)) {
      return [];
    }

    return [
      RuleErrorBuilder::message(
        'Do not pass a JSON-encoded string as an HttpException message in an API controller. ' .
        'ApiExceptionSubscriber serializes the message into the response body; wrapping it in ' .
        'json_encode() or Json::encode() produces double-encoded JSON in CLI/API error output.',
      )
        ->identifier('canvas.noJsonInHttpExceptionMessage')
        ->build(),
    ];
  }

  private function isJsonEncodeCall(Expr $expr): bool {
    // json_encode(…)
    if (
      $expr instanceof FuncCall &&
      $expr->name instanceof Name &&
      $expr->name->toString() === 'json_encode'
    ) {
      return TRUE;
    }

    // Json::encode(…) — matches Drupal\Component\Serialization\Json and any
    // class aliased to a name ending in "Json".
    if (
      $expr instanceof StaticCall &&
      $expr->class instanceof Name &&
      $expr->name instanceof Identifier &&
      $expr->name->name === 'encode' &&
      str_ends_with($expr->class->toString(), 'Json')
    ) {
      return TRUE;
    }

    return FALSE;
  }

}
