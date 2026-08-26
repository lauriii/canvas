<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use cebe\openapi\json\JsonPointer;
use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use Drupal\Tests\UnitTestCase;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the Canvas Headless OpenAPI specification.
 */
#[RequiresMethod(Reader::class, 'readFromYamlFile')]
#[Group('canvas_headless')]
final class OpenApiSpecValidationTest extends UnitTestCase {

  /**
   * Tests that the specification is valid OpenAPI 3.1.
   */
  public function testSpecIsValid(): void {
    $specification = self::getSpecification();
    $specification->validate();
    $this->assertSame([], $specification->getErrors());

    $validator = new Validator();
    $open_api_data = $specification->getSerializableData();
    $validator->validate(
      $open_api_data,
      (object) ['$ref' => 'file://' . self::openApiSchemaPath()],
    );
    $this->assertTrue($validator->isValid(), implode(\array_map(
      static fn (array $error): string => \sprintf('%s:%s%s', $error['property'], $error['message'], \PHP_EOL),
      $validator->getErrors(),
    )));
  }

  /**
   * Tests that every headless route and method has exactly one operation.
   */
  public function testRouteCompleteness(): void {
    $routes = Yaml::parseFile(self::moduleRoot() . '/canvas_headless.routing.yml');
    $openapi = Yaml::parseFile(self::specificationPath());
    \assert(\is_array($routes));
    \assert(\is_array($openapi));

    $route_operations = [];
    foreach ($routes as $route_name => $route) {
      $this->assertIsArray($route, "The $route_name route is valid.");
      $this->assertArrayHasKey('methods', $route, "The $route_name route specifies methods.");
      foreach ($route['methods'] as $method) {
        $route_operations[] = $route['path'] . ' ' . strtoupper((string) $method);
      }
    }

    $documented_operations = [];
    foreach ($openapi['paths'] as $path => $path_item) {
      foreach (['delete', 'get', 'head', 'options', 'patch', 'post', 'put', 'trace'] as $method) {
        if (isset($path_item[$method])) {
          $documented_operations[] = $path . ' ' . strtoupper($method);
        }
      }
    }

    sort($route_operations);
    sort($documented_operations);
    $this->assertSame($route_operations, $documented_operations);
  }

  /**
   * Reads the specification and resolves local and cross-file references.
   */
  private static function getSpecification(): OpenApi {
    $specification = Reader::readFromYamlFile(self::specificationPath());
    $context = new ReferenceContext($specification, '/');
    $context->throwException = FALSE;
    $context->mode = ReferenceContext::RESOLVE_MODE_ALL;
    $specification->resolveReferences($context);
    $specification->setDocumentContext($specification, new JsonPointer(''));
    return $specification;
  }

  /**
   * Returns the Canvas Headless module root.
   */
  private static function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Returns the Canvas Headless OpenAPI specification path.
   */
  private static function specificationPath(): string {
    return self::moduleRoot() . '/openapi.yml';
  }

  /**
   * Returns the OpenAPI 3.1 JSON schema path.
   */
  private static function openApiSchemaPath(): string {
    return dirname(__DIR__, 5) . '/openapi-v3.1.json';
  }

}
