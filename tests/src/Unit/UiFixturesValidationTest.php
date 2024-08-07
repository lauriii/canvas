<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Validate the fixtures in the UI against the OpenAPI schema.
 *
 * @group experience_builder
 *
 * @requires function \cebe\openapi\Reader::readFromYaml
 * @requires function \League\OpenAPIValidation\Schema\SchemaValidator::validate
 */
class UiFixturesValidationTest extends UnitTestCase {

  use OpenApiSpecTrait;

  /**
   * Gets the UI fixture data.
   *
   * @param string $filename
   *   Filename.
   *
   * @return array
   *   Fixture data.
   */
  protected function getUiFixtureData(string $filename): array {
    $fixturesDirectory = dirname(__FILE__, 4) . '/ui/src/mocks/fixtures';
    $json = file_get_contents(sprintf('%s/%s', $fixturesDirectory, $filename));
    assert(is_string($json));
    return Json::decode($json);
  }

  /**
   * Tests the components.json UI Fixture.
   */
  public function testUiComponentsFixture(): void {
    $uiFixture = $this->getUiFixtureData('components.json');
    foreach ($uiFixture as $fixture) {
      $this->assertDataCompliesWithApiSpecification($fixture, 'Component');
    }
  }

  /**
   * Tests the layout-default.json UI Fixture.
   */
  public function testUiLayoutDefaultFixture(): void {
    $uiFixture = $this->getUiFixtureData('layout-default.json');

    // Assert the main layout structure.
    $this->assertArrayHasKey('layout', $uiFixture);
    $this->assertDataCompliesWithApiSpecification($uiFixture['layout'], 'Layout');

    // Assert the layout children recursively.
    $this->assertLayoutChildren($uiFixture['layout']['children']);

    // Assert the model structure.
    $this->assertArrayHasKey('model', $uiFixture);
    $this->assertDataCompliesWithApiSpecification($uiFixture['model'], 'Model');
  }

  /**
   * Helper function to traverse the layout children and validate them.
   *
   * @param array $children
   *   Array of layout children.
   */
  protected function assertLayoutChildren(array $children): void {
    foreach ($children as $child) {
      $this->assertDataCompliesWithApiSpecification($child, 'Layout');
      if (!empty($child['children'])) {
        $this->assertLayoutChildren($child['children']);
      }
    }
  }

}
