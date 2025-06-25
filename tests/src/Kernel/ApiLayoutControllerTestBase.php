<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
class ApiLayoutControllerTestBase extends KernelTestBase {

  const REGION_PATTERN = '/<!-- xb-region-start-%1$s -->([\n\s\S]*)<!-- xb-region-end-%1$s -->/';

  use RequestTrait {
    request as parentRequest;
  }
  use UserCreationTrait;

  protected static function decodeResponse(Response $response): array {
    self::assertInstanceOf(JsonResponse::class, $response);
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
   * @return ?string
   */
  protected function getRegion(string $region): ?string {
    $matches = [];

    $content = $this->getRawContent() ?: '';
    // Covers 'application/json' endpoint responses with 'html' property.
    if (json_validate($content)) {
      $decoded = \json_decode($content, TRUE);
      $content = $decoded['html'] ?? $content;
    }

    \preg_match_all(sprintf(self::REGION_PATTERN, $region), $content, $matches);
    return array_key_exists(0, $matches[1]) ? $matches[1][0] : NULL;
  }

  /**
   * Uses regex to find component instances "wrapped" by inline HTML comments.
   *
   * @param ?string $html
   *   The HTML to search; if none provided will use the current raw content.
   *
   * @return array
   */
  protected function getComponentInstances(?string $html): array {
    $html ??= $this->getRawContent();
    // Covers 'application/json' endpoint responses with 'html' property.
    if (json_validate($html)) {
      $decoded = \json_decode($html, TRUE);
      $html = $decoded['html'] ?? $html;
    }
    $matches = [];
    \preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $html, $matches);
    return $matches[2];
  }

  protected function assertResponseAutoSaves(Response $response, array $expectedEntities): void {
    $data = self::decodeResponse($response);
    self::assertArrayHasKey('autoSaves', $data);
    self::assertIsArray($data['autoSaves']);
    self::assertCount(\count($expectedEntities), $data['autoSaves']);
    self::assertCount(\count($expectedEntities), array_filter($data['autoSaves']));
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    foreach ($expectedEntities as $entity) {
      self::assertArrayHasKey(AutoSaveManager::getAutoSaveKey($entity), $data['autoSaves']);
      $autoSaveData = $autoSaveManager->getAutoSaveEntity($entity);
      // Ensure that the auto-saves are not empty are not returned.
      self::assertNotNull($autoSaveData->hash);
      self::assertSame($data['autoSaves'][AutoSaveManager::getAutoSaveKey($entity)], $autoSaveData->hash);
    }
  }

}
