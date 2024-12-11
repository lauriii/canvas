<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use GuzzleHttp\RequestOptions;

final class ApiContentUpdateForDemoControllerTest extends FunctionalTestBase {

  use ApiRequestTrait;
  use TestDataUtilitiesTrait;
  use XBFieldTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpImages();
  }

  public function testSave(): void {
    $node1 = $this->createTestNode();
    $this->assertNodeXbField($node1, [], []);
    $valid_client_json = $this->getValidClientJson();
    // Make a valid client request.
    $this->assertClientRequest(
      $node1,
      $valid_client_json,
      200,
      ['message' => 'Saved successfully.']
    );
    $this->assertValidJsonUpdateNode($node1);

    $node2 = $this->createTestNode();
    $this->assertNodeXbField($node2, [], []);

    // Make a request with invalid heading properties.
    $invalid_heading_client_json = $valid_client_json;
    $invalid_heading_client_json['model'][self::TEST_HEADING_UUID]['style'] = 'not-a-style';
    $this->assertClientRequest(
      $node2,
      $invalid_heading_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'Does not have a value in the enumeration ["primary","secondary"]',
            'source' => [
              'pointer' => 'model.' . self::TEST_HEADING_UUID . '.style',
            ],
          ],
        ],
      ]
    );
    // Ensure none of the entities have been saved.
    $this->assertNodeXbField($node2, [], []);
    $this->assertValidJsonUpdateNode($node1);

    // Make a client request with a non-existent file.
    $invalid_image_client_json = $valid_client_json;
    $invalid_image_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = '/not/a/real/url';
    $this->assertClientRequest(
      $node2,
      $invalid_image_client_json,
      422,
      [
        'errors' => [
            [
              'detail' => "File '/not/a/real/url' not found.",
              'source' => [
                'pointer' => 'model.' . self::TEST_IMAGE_UUID . '.image.src',
              ],
            ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make a client request with a file that is not referenced by any media entity.
    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = $unreferenced_src;
    $this->assertClientRequest(
      $node2,
      $unreferenced_file_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => "No media entity found that uses file '$unreferenced_src'.",
            'source' => [
              'pointer' => 'model.' . self::TEST_IMAGE_UUID . '.image.src',
            ],
          ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make a client request with layout using a missing component.
    $invalid_tree_client_json = $valid_client_json;
    $invalid_tree_client_json['layout']['components'][1]['type'] = 'sdc.experience_builder.missing_component';
    $this->assertClientRequest(
      $node2,
      $invalid_tree_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'The component <em class="placeholder">sdc.experience_builder.missing_component</em> does not exist.',
            'source' => [
              'pointer' => "layout.children[1]",
            ],
          ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make request with all properties missing for the heading component.
    $invalid_missing_heading_props_client_json = $valid_client_json;
    unset($invalid_missing_heading_props_client_json['model'][self::TEST_HEADING_UUID]);
    $this->assertClientRequest(
      $node2,
      $invalid_missing_heading_props_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'The required properties are missing.',
            'source' => [
              'pointer' => 'model.' . self::TEST_HEADING_UUID,
            ],
          ],
        ],
      ]
    );
  }

  private function assertClientRequest(Node $node, array $client_json, int $expected_status, array $expected_json): void {
    $response = $this->makeApiRequest(
      'PATCH',
      Url::fromUri('base:/xb/api/content-update/node/' . $node->id()),
      [RequestOptions::JSON => $client_json]
    );
    $contents = (string) $response->getBody();
    $this->assertJson($contents, "Response is not valid JSON: $contents");
    $this->assertSame(
      $expected_json,
      json_decode($contents, TRUE)
    );
    self::assertSame($expected_status, $response->getStatusCode());
  }

}
