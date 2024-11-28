<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

abstract class ExperienceBuilderTestBase extends KernelTestBase {

  /**
   * Passes a request to the HTTP kernel and returns a response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param bool $terminate
   *   To handle request termination.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   *
   * @throws \Exception
   */
  protected function request(Request $request, bool $terminate = TRUE): Response {
    $http_kernel = $this->container->get('http_kernel');
    self::assertInstanceOf(HttpKernelInterface::class, $http_kernel);
    $response = $http_kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, FALSE);
    $content = $response->getContent();
    self::assertNotFalse($content);
    $this->setRawContent($content);

    if ($terminate) {
      self::assertInstanceOf(TerminableInterface::class, $http_kernel);
      $http_kernel->terminate($request, $response);
    }

    return $response;
  }

  protected function assertExperienceBuilderMount(string $entity_type, string|int|null $entity_id): void {
    $this->assertTitle('Drupal Experience Builder');
    self::assertCount(1, $this->cssSelect('#experience-builder'));
    self::assertArrayHasKey('xb', $this->drupalSettings);
    self::assertEquals("xb/$entity_type/$entity_id", $this->drupalSettings['xb']['base']);
    self::assertEquals($entity_type, $this->drupalSettings['xb']['entityType']);
    self::assertEquals($entity_id, $this->drupalSettings['xb']['entity']);
  }

}
