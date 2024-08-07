<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use League\OpenAPIValidation\PSR7\Exception\Validation\AddressValidationFailed;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response subscriber that validates an Experience Builder API response.
 *
 * @internal
 *
 * @see \Drupal\jsonapi\EventSubscriber\ResourceResponseValidator
 */
final class ApiResponseValidator implements EventSubscriberInterface {

  /**
   * The OpenAPI validator.
   *
   * This property will only be set if the validator library is available.
   *
   * @var \League\OpenAPIValidation\PSR7\ValidatorBuilder|null
   */
  protected $validator;

  /**
   * Constructs an ApiResponseValidator object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The Experience Builder logger channel.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteMatchInterface $currentRouteMatch
   *   The current route match.
   * @param \Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface $httpMessageFactory
   *   The PSR7 HTTP message factory.
   * @param string $appRoot
   *   The application's root file path.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RouteMatchInterface $currentRouteMatch,
    private readonly HttpMessageFactoryInterface $httpMessageFactory,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::RESPONSE][] = ['onResponse'];
    return $events;
  }

  /**
   * Sets the validator service if available.
   */
  public function setValidator(?ValidatorBuilder $validator = NULL): void {
    if ($validator) {
      $this->validator = $validator;
    }
    elseif (class_exists(ValidatorBuilder::class)) {
      $this->validator = new ValidatorBuilder();
    }
  }

  /**
   * Validates Experience Builder API responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof JsonResponse) {
      return;
    }
    if (!str_starts_with($this->currentRouteMatch->getRouteName() ?? '', 'experience_builder.')) {
      return;
    }

    // Wraps validation in an assert to prevent execution in production.
    assert($this->validateResponse($response, $event->getRequest()), 'An Experience Builder response failed validation (see the logs for details). Report this in the Drupal issue queue at https://www.drupal.org/project/issues/experience_builder');
  }

  /**
   * Validates a response against this module's OpenAPI specification.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to validate.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing info about what to validate.
   *
   * @return bool
   *   FALSE if the response failed validation, otherwise TRUE.
   */
  protected function validateResponse(Response $response, Request $request) {
    // If the validator isn't set, then the validation library is not installed.
    if (!$this->validator) {
      return TRUE;
    }

    $openapi_spec_file = sprintf(
      '%s/%s/openapi.yml',
      $this->appRoot,
      $this->moduleHandler->getModule('experience_builder')->getPath(),
    );

    $validator = (new ValidatorBuilder())->fromYamlFile($openapi_spec_file)->getResponseValidator();
    $operation = new OperationAddress($request->getPathInfo(), strtolower($request->getMethod()));
    $psr7_response = $this->httpMessageFactory->createResponse($response);

    try {
      $validator->validate($operation, $psr7_response);
      return TRUE;
    }
    catch (ValidationFailed $e) {
      $message = $e instanceof AddressValidationFailed
        // @see https://github.com/thephpleague/openapi-psr7-validator/pull/184
        ? $e->getVerboseMessage()
        : $e->getMessage();
      $this->logger->debug($message);
      // @todo Before 1.0, stop re-throwing. For now, explicit failures help iterate faster.
      throw new ValidationFailed($message, $e->getCode(), $e);
      // phpcs:disable
      // @phpstan-ignore-next-line
      return FALSE;
      // phpcs:enable
    }
  }

}
