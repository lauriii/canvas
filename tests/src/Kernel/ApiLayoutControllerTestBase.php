<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiLayoutControllerTestBase extends KernelTestBase {

  const REGION_PATTERN = '/<!-- xb-region-start-%1$s -->([\n\s\S]*)<!-- xb-region-end-%1$s -->/';

  use RequestTrait {
    request as parentRequest;
  }
  use UserCreationTrait;

  protected static function decodeResponse(Response $response): array {
    self::assertIsString($response->getContent());
    self::assertJson($response->getContent());
    return \json_decode($response->getContent(), TRUE);
  }

  /**
   * Unwrap the JSON response so we can perform assertions on it.
   */
  protected function request(Request $request): Response {
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->parentRequest($request);
    $this->setRawContent(static::decodeResponse($response)['html']);
    return $response;
  }

  /**
   * Omit information received in the GET response that cannot be POSTed.
   */
  protected function filterLayoutForPost(string $content): string {
    $json = \json_decode($content, TRUE);
    unset($json['isNew'], $json['isPublished'], $json['html']);
    return \json_encode($json, JSON_THROW_ON_ERROR);
  }

  /**
   * Uses regex to find regions "wrapped" by inline HTML comments in content.
   *
   * @param string $region
   *
   * @return array
   */
  protected function getRegion(string $region): array {
    $matches = [];

    $content = $this->getRawContent() ?: '';
    // Covers 'application/json' endpoint responses with 'html' property.
    if (json_validate($content)) {
      $decoded = \json_decode($content, TRUE);
      $content = $decoded['html'] ?? $content;
    }

    \preg_match_all(sprintf(self::REGION_PATTERN, $region), $content, $matches);
    return $matches[1];
  }

}
