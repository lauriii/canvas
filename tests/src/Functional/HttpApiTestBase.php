<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\Tests\ApiRequestTrait;
use GuzzleHttp\RequestOptions;

/**
 * Base class for functional tests of XB's internal HTTP API.
 *
 * Provides helper methods for making API requests and asserting response cacheability.
 *
 * @group experience_builder
 */
abstract class HttpApiTestBase extends FunctionalTestBase {

  use ApiRequestTrait;

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
  protected function performAutoSave(array $data_to_auto_save, string $entity_type_id, string $entity_id): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];
    $auto_save_url = Url::fromUri("base:/xb/api/v0/config/auto-save/$entity_type_id/$entity_id");
    $request_options[RequestOptions::JSON] = $data_to_auto_save;
    $patch_response = $this->assertExpectedResponse('PATCH', $auto_save_url, $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame([], $patch_response);

    $this->assertCurrentAutoSave(200, $data_to_auto_save, $entity_type_id, $entity_id);
  }

  /**
   * Asserts the current auto-save data for the given entity.
   */
  protected function assertCurrentAutoSave(int $expected_status_code, ?array $expected_auto_save, string $entity_type_id, string $entity_id): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];
    $auto_save_url = Url::fromUri("base:/xb/api/v0/config/auto-save/$entity_type_id/$entity_id");

    // First GET request: auto-save retrieved successfully?
    // - 200 if there is a current auto-save
    // - 204 if there isn't one
    // - 404 if this entity does not exist (anymore)
    if ($expected_status_code < 400) {
      $auto_save_data = $this->assertExpectedResponse('GET', $auto_save_url, $request_options, $expected_status_code, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
      $this->assertSame($expected_auto_save, $auto_save_data);
    }
    else {
      $this->assertExpectedResponse('GET', $auto_save_url, $request_options, $expected_status_code, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    }

    if ($expected_status_code < 400) {
      // Repeat the same request: same status code, but now is a Dynamic Page
      // Cache hit.
      $auto_save_data = $this->assertExpectedResponse('GET', $auto_save_url, $request_options, $expected_status_code, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
      $this->assertSame($expected_auto_save, $auto_save_data);
    }

    // The expected array must also match what the AutoSaveManager currently contains.
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage($entity_type_id);
    $entity = $storage->loadUnchanged($entity_id);
    // When the underlying entity has been deleted, parameter upcasting fails
    // and a 404 is the result: no auto-saves for deleted entities.
    if ($expected_status_code === 404) {
      // The entity is deleted.
      $this->assertNull($entity);
      // No corresponding auto-save entries exists.
      $auto_save_keys = \array_keys($this->container->get(AutoSaveManager::class)->getAllAutoSaveList());
      // Auto save keys start with the entity type ID and ID, but could also
      // include a language ID if the entity supports translation.
      self::assertCount(0, \array_filter($auto_save_keys, static fn (string $key): bool => \str_starts_with($key, "$entity_type_id:$entity_id")));
      return;
    }
    assert($entity instanceof EntityInterface);
    $data = $this->container->get(AutoSaveManager::class)->getAutoSaveData($entity)->data;
    $this->assertSame($expected_auto_save, $data);
  }

}
