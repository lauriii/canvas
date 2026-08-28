<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use JsonSchema\Exception\ResourceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Validates Code Component metadata against the target site.
 */
final class ApiCodeComponentMetadataController extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Validates a normalized payload without saving it.
   */
  public function validatePayload(Request $request): Response {
    $data = self::decode($request);
    $storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $existing = isset($data['machineName']) && \is_string($data['machineName'])
      ? $storage->load($data['machineName'])
      : NULL;

    if ($existing instanceof JavaScriptComponent) {
      $candidate = clone $existing;
      $candidate->updateFromClientSide($data);
    }
    else {
      $candidate = JavaScriptComponent::createFromClientSide($data);
    }

    try {
      $violations = $candidate->getTypedData()->validate();
    }
    catch (ResourceNotFoundException $exception) {
      $reference = preg_match('/file_get_contents\((json-schema-definitions:\/\/[^)]+)\)/', $exception->getMessage(), $matches)
        ? $matches[1]
        : 'unknown';
      $violations = new EntityConstraintViolationList($candidate);
      $message = \sprintf('JSON Schema reference "%s" could not be resolved on the target site.', $reference);
      $violations->add(new ConstraintViolation(
        $message,
        $message,
        [],
        $candidate,
        'props',
        $data['props'] ?? NULL,
      ));
    }
    if ($violations->count() > 0) {
      throw new ConstraintViolationException($violations);
    }

    return new Response(status: Response::HTTP_NO_CONTENT);
  }

}
