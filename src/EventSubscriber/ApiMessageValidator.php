<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use League\OpenAPIValidation\PSR7\Exception\NoPath;
use League\OpenAPIValidation\PSR7\Exception\Validation\AddressValidationFailed;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\SpecFinder;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP message subscriber that validates an Experience Builder API message.
 *
 * This functionality only takes effect in the presence of the
 * league/openapi-psr7-validator Composer library with PHP assertions enabled
 * for local development or CI purposes.
 *
 * @see self::isValidationEnabled()
 *
 * @internal
 */
final class ApiMessageValidator implements EventSubscriberInterface {

  /**
   * The OpenAPI validator.
   *
   * This property will only be set if the validator library is available.
   * Don't access it directly. Use {@see self::getConfiguredValidatorBuilder}
   * instead.
   *
   * @var \League\OpenAPIValidation\PSR7\ValidatorBuilder|null
   */
  private ?ValidatorBuilder $validatorBuilder;

  /**
   * Constructs an API Message Validator object.
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
   * Sets the OpenAPI validator builder service if available.
   */
  public function setValidatorBuilder(?ValidatorBuilder $validator = NULL): void {
    if ($validator instanceof ValidatorBuilder) {
      $this->validatorBuilder = $validator;
    }
    elseif (class_exists(ValidatorBuilder::class)) {
      $this->validatorBuilder = new ValidatorBuilder();
    }
  }

  /**
   * Validates Experience Builder API messages.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent|\Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   *
   * @throws \League\OpenAPIValidation\PSR7\Exception\ValidationFailed
   *    See docblock on {@see self::validate()}.
   */
  public function onMessage(RequestEvent|ResponseEvent $event): void {
    if (!$this->shouldValidate()) {
      return;
    }

    try {
      $validatorBuilder = $this->getConfiguredValidatorBuilder();
      // @phpstan-ignore match.unhandled
      match (get_class($event)) {
        RequestEvent::class => $this->validateRequest($validatorBuilder, $event),
        ResponseEvent::class => $this->validateResponse($validatorBuilder, $event),
      };
    }
    catch (NoPath $e) {
      // @todo Temporarily log and ignore missing paths. Once 'openapi.yml' is
      //   is complete, remove this to treat them as failures.
      $this->logger->debug($e->getMessage());
    }
    catch (ValidationFailed $e) {
      $this->logFailure($e);
      // @todo Surface exception details better for front-end display.
      // @see https://www.drupal.org/project/experience_builder/issues/3470321
      throw $e;
    }
  }

  /**
   * Determines whether the message should be validated.
   */
  private function shouldValidate(): bool {
    return !$this->isProd()
      && $this->isExperienceBuilderMessage()
      && $this->isValidationEnabled();
  }

  /**
   * Determines whether the application is in production.
   */
  private function isProd(): bool {
    $is_prod = TRUE;

    // Assertions are assumed to be disabled in prod, so this assignment will
    // never take place there.
    // @phpstan-ignore booleanNot.alwaysTrue
    assert(!($is_prod = FALSE));

    return $is_prod;
  }

  /**
   * Determines whether a given message is from this module.
   */
  private function isExperienceBuilderMessage(): bool {
    return str_starts_with(
      $this->currentRouteMatch->getRouteName() ?? '',
      'experience_builder.',
    );
  }

  /**
   * Determines whether validation is enabled.
   *
   * Validation is implicitly enabled if the league/openapi-psr7-validator
   * Composer library is present. To add it to your project, require it as a dev
   * dependency:
   *
   * ```
   * composer require --dev league/openapi-psr7-validator
   * ```
   */
  private function isValidationEnabled(): bool {
    // The builder won't be set if league/openapi-psr7-validator is absent.
    /** @see self::setValidatorBuilder() */
    return $this->validatorBuilder instanceof ValidatorBuilder;
  }

  /**
   * Validates a request message.
   *
   * @throws \League\OpenAPIValidation\PSR7\Exception\ValidationFailed
   *   If validation fails.
   */
  protected function validateRequest(ValidatorBuilder $validatorBuilder, RequestEvent $event): void {
    $validator = $validatorBuilder->getRequestValidator();

    $psr7_request = $this->httpMessageFactory
      ->createRequest($event->getRequest());

    $validator->validate($psr7_request);
  }

  /**
   * Validates a response message.
   *
   * @throws \League\OpenAPIValidation\PSR7\Exception\ValidationFailed
   *   If validation fails.
   */
  protected function validateResponse(ValidatorBuilder $validatorBuilder, ResponseEvent $event): void {
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

  /**
   * Gets the validator builder configured with the module's OpenAPI schema.
   *
   * @return \League\OpenAPIValidation\PSR7\ValidatorBuilder
   *   The validator builder configured with the module's OpenAPI schema.
   */
  private function getConfiguredValidatorBuilder(): ValidatorBuilder {
    $openapi_spec_file = sprintf(
      '%s/%s/openapi.yml',
      $this->appRoot,
      $this->moduleHandler
        ->getModule('experience_builder')
        ->getPath(),
    );

    assert($this->validatorBuilder instanceof ValidatorBuilder);

    return $this->validatorBuilder
      ->fromYamlFile($openapi_spec_file);
  }

  public function logFailure(ValidationFailed $e): void {
    // AddressValidationFailed provides additional helpful details.
    // @see https://github.com/thephpleague/openapi-psr7-validator/pull/184
    $message = $e instanceof AddressValidationFailed
      ? $e->getVerboseMessage()
      : $e->getMessage();
    $this->logger->debug($message);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => 'onMessage',
      KernelEvents::RESPONSE => 'onMessage',
    ];
  }

}
