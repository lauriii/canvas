<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\SpecFinder;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response subscriber that validates an Experience Builder API response.
 *
 * @internal
 */
final class ApiResponseValidator extends ApiMessageValidatorBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onMessage'];
  }

  /**
   * {@inheritdoc}
   */
  protected function validate(
    ValidatorBuilder $validatorBuilder,
    RequestEvent|ResponseEvent $event,
  ): void {
    assert($event instanceof ResponseEvent);
    $request = $event->getRequest();
    $response = $event->getResponse();
    if (!$response instanceof JsonResponse) {
      return;
    }

    $validator = $validatorBuilder->getResponseValidator();

    $operation = new OperationAddress(
      $request->getPathInfo(),
      strtolower($request->getMethod()),
    );

    $psr7_response = $this->httpMessageFactory
      ->createResponse($response);

    $this->performXbValidation($validator, $operation, $response);
    $validator->validate($operation, $psr7_response);
  }

  private function performXbValidation(ResponseValidator $validator, OperationAddress $operation, Response $response): void {
    $schema = $validator->getSchema();
    $spec_finder = new SpecFinder($schema);
    $path = $spec_finder->findPathSpec($operation);

    if ($operation->method() === 'get' && isset($path->get) && isset($path->get->responses[$response->getStatusCode()])) {
      $extensions = $path->get->responses[$response->getStatusCode()]->getExtensions();
      if (isset($extensions['x-xb-validation'])) {
        assert(isset($extensions['x-xb-validation']['method']), 'Method not found in x-xb-validation extension.');
        assert(method_exists(static::class, $extensions['x-xb-validation']['method']));
        $content = $response->getContent();
        assert(is_string($content));
        $args = array_merge([json_decode($content, TRUE)], $extensions['x-xb-validation']['arguments'] ?? []);
        $callback = [$this, $extensions['x-xb-validation']['method']];
        assert(is_callable($callback));
        call_user_func_array($callback, $args);
      }
    }
  }

}
