<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\node\Entity\Node;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use GuzzleHttp\RequestOptions;

final class ApiContentUpdateForDemoControllerTest extends FunctionalTestBase {

  use ApiRequestTrait;
  use TestDataUtilitiesTrait;
  use XBFieldTrait {
    getValidClientJson as traitGetValidClientJson;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder', 'xb_dev_standard'];

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
    $this->assertNodeValues($node1, [], [], (string) $node1->getTitle());
    // Make a valid client request without the CSRF token.
    $response = $this->makeApiRequest(
      'PATCH',
      Url::fromUri('base:/xb/api/content-update/node/' . $node1->id()),
      [RequestOptions::HEADERS => ['Content-Type' => 'application/json']],
    );
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['message' => 'X-CSRF-Token request header is missing'],
      json_decode((string) $response->getBody(), TRUE)
    );
    // Now, make a valid client request with CSRF token.
    $valid_client_json = $this->getValidClientJson(FALSE);
    $this->assertClientRequest(
      $node1,
      $valid_client_json,
      200,
      ['message' => 'Saved successfully.'],
    );
    $this->assertValidJsonUpdateNode($node1, FALSE);

    $node2 = $this->createTestNode();
    $node2_original_title = (string) $node2->getTitle();
    $this->assertNodeValues($node2, [], [], $node2_original_title);

    // Make a request with invalid heading properties.
    $invalid_heading_client_json = $valid_client_json;
    $invalid_heading_client_json['model'][self::TEST_HEADING_UUID]['resolved']['style'] = 'not-a-style';
    $suffix = '';
    if (\version_compare(\Drupal::VERSION, '11.2', '>=') || \version_compare(\Drupal::VERSION, '11.2-dev', '>=')) {
      // The format of component violation messages changed in Drupal 11.2.
      // @see https://drupal.org/i/3462700
      $suffix = '. The provided value is: "not-a-style".';
    }
    $this->assertClientRequest(
      $node2,
      $invalid_heading_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'Does not have a value in the enumeration ["primary","secondary"]' . $suffix,
            'source' => [
              'pointer' => 'model.' . self::TEST_HEADING_UUID . '.style',
            ],
          ],
        ],
      ],
    );
    // Ensure none of the entities have been saved.
    $this->assertNodeValues($node2, [], [], $node2_original_title);
    $this->assertValidJsonUpdateNode($node1, FALSE);

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
      ],
    );
  }

  private function assertClientRequest(Node $node, array $client_json, int $expected_status, array $expected_json): void {
    $this->container->get(AutoSaveManager::class)->save($node, $client_json);
    $response = $this->makeApiRequest(
      'PATCH',
      Url::fromUri('base:/xb/api/content-update/node/' . $node->id()),
      [
        RequestOptions::HEADERS => [
          'Content-Type' => 'application/json',
          'X-CSRF-Token' => $this->drupalGet('session/token'),
        ],
        RequestOptions::BODY => json_encode(new \stdClass()),
      ],
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
