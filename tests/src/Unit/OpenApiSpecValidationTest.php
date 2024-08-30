<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit;

use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\Tests\UnitTestCase;
use DrupalFinder\DrupalFinderComposerRuntime;
use JsonSchema\Validator;

/**
 * Validates this Drupal module's OpenAPI spec against the OpenAPI JSON schema.
 *
 * @group experience_builder.
 *
 * @requires function \cebe\openapi\Reader::readFromYaml
 * @requires function \DrupalFinder\DrupalFinderComposerRuntime::getVendorDir
 * @requires function \League\OpenAPIValidation\Schema\SchemaValidator::validate
 */
final class OpenApiSpecValidationTest extends UnitTestCase {

  use OpenApiSpecTrait;

  /**
   * Path to OpenAPI 3.0 document.
   */
  private ?string $documentLocation = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $possible_start_paths = [dirname(DRUPAL_ROOT), DRUPAL_ROOT];
    $tested_paths = [];
    foreach ($possible_start_paths as $path) {
      $finder = new DrupalFinderComposerRuntime();
      $vendor_directory = $finder->getVendorDir();
      if (!$vendor_directory) {
        continue;
      }
      $document_location = $vendor_directory . '/devizzent/cebe-php-openapi/schemas/openapi-v3.1.json';
      if (!file_exists($document_location)) {
        $tested_paths[] = $document_location;
        continue;
      }
      $this->documentLocation = $document_location;
      break;
    }
    if (!$this->documentLocation) {
      throw new \Exception(sprintf('Could not OpenAPI 3.0 schema at %s.', implode(' or ', $tested_paths)));
    }
  }

  /**
   * Tests OpenAPI specification is valid.
   */
  public function testSpecIsValid(): void {
    $specification = $this->getSpecification();
    $specification->validate();
    $this->assertSame([], $specification->getErrors());
    $validator = new Validator();
    $open_api_data = $specification->getSerializableData();
    $validator->validate($open_api_data, (object) ['$ref' => 'file://' . $this->documentLocation]);
    $this->assertTrue($validator->isValid(), implode(array_map(function (array $error) {
      return sprintf('%s:%s%s', $error['property'], $error['message'], \PHP_EOL);
    }, $validator->getErrors())));
  }

}
