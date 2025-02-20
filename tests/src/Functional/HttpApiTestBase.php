<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use GuzzleHttp\RequestOptions;

/**
 * Base class for functional tests of XB's internal HTTP API.
 *
 * Provides helper methods for making API requests and asserting response cacheability.
 */
abstract class HttpApiTestBase extends FunctionalTestBase {

  use ApiRequestTrait;
  use TestDataUtilitiesTrait;

  /**
   * @return ?array
   *   The decoded JSON response, or NULL if there is no body.
   *
   * @throws \JsonException
   */
  protected function assertExpectedResponse(string $method, Url $url, array $request_options, int $expected_status, ?array $expected_cache_contexts, ?array $expected_cache_tags, ?string $expected_page_cache, ?string $expected_dynamic_page_cache, array $additional_expected_response_headers = []): ?array {
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    $response = $this->makeApiRequest($method, $url, $request_options);
    $body = (string) $response->getBody();
    $this->assertSame($expected_status, $response->getStatusCode(), $body);

    // Cacheability headers.
    $this->assertSame($expected_page_cache !== NULL, $response->hasHeader('X-Drupal-Cache'));
    if ($expected_page_cache !== NULL) {
      $this->assertSame($expected_page_cache, $response->getHeader('X-Drupal-Cache')[0], 'Page Cache response header');
    }
    $this->assertSame($expected_dynamic_page_cache !== NULL, $response->hasHeader('X-Drupal-Dynamic-Cache'));
    if ($expected_dynamic_page_cache !== NULL) {
      $this->assertSame($expected_dynamic_page_cache, $response->getHeader('X-Drupal-Dynamic-Cache')[0], 'Dynamic Page Cache response header');
    }
    $this->assertSame($expected_cache_tags !== NULL, $response->hasHeader('X-Drupal-Cache-Tags'));
    if ($expected_cache_tags !== NULL) {
      $this->assertEqualsCanonicalizing($expected_cache_tags, explode(' ', $response->getHeader('X-Drupal-Cache-Tags')[0]));
    }
    $this->assertSame($expected_cache_contexts !== NULL, $response->hasHeader('X-Drupal-Cache-Contexts'));
    if ($expected_cache_contexts !== NULL) {
      $this->assertEqualsCanonicalizing($expected_cache_contexts, explode(' ', $response->getHeader('X-Drupal-Cache-Contexts')[0]));
    }

    // Optionally, additional expected response headers can be validated.
    if ($additional_expected_response_headers) {
      foreach ($additional_expected_response_headers as $header_name => $expected_value) {
        $this->assertSame($response->getHeader($header_name), $expected_value);
      }
    }

    // Response must at least be decodable JSON, let this throw an exception
    // otherwise. (Assertions of the contents happen outside this method.)
    if ($body === '') {
      return NULL;
    }
    $json = json_decode($body, associative: TRUE, flags: JSON_THROW_ON_ERROR);

    return $json;
  }

  /**
   * Asserts the given data can be auto-saved (and retrieved) correctly.
   */
  protected function assertAutoSave(array $data_to_autosave, string $entity_type_id, string $entity_id): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];
    $auto_save_url = Url::fromUri("base:/xb/api/config/auto-save/$entity_type_id/$entity_id");
    $request_options[RequestOptions::JSON] = $data_to_autosave;
    $patch_response = $this->assertExpectedResponse('PATCH', $auto_save_url, $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame([], $patch_response);

    // First GET request: 200 aka auto-save retrieved successfully.
    unset($request_options[RequestOptions::JSON]);
    $auto_save_data = $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 200, ['user.permissions'], ['experience_builder__autosave', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame($data_to_autosave, $auto_save_data);
    // Repeat the same request: 200, but now is a Dynamic Page Cache hit.
    $auto_save_data = $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 200, ['user.permissions'], ['experience_builder__autosave', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame($data_to_autosave, $auto_save_data);

    // The expected array must also match what the AutoSaveManager currently contains.
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage($entity_type_id);
    $entity = $storage->loadUnchanged($entity_id);
    assert($entity instanceof EntityInterface);
    $data = $this->container->get(AutoSaveManager::class)->getAutoSaveData($entity)->data;
    $this->assertSame($data_to_autosave, $data);
  }

}
