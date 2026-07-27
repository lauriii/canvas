<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Renders the Drupal Canvas Dev AI calls.
 *
 * @todo Replace the mocked response with the real hop loop in https://git.drupalcode.org/project/canvas/-/work_items/3591777
 *
 * @internal
 */
final class CanvasDevAiBuilder extends ControllerBase {

  public function __construct(
    protected CsrfTokenGenerator $csrfTokenGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('csrf_token'),
    );
  }

  /**
   * Returns a mocked Drupal Canvas Dev AI response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function render(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?? '';
    if (!$this->csrfTokenGenerator->validate($token, 'canvas_ai.canvas_builder')) {
      throw new AccessDeniedHttpException('Invalid CSRF token');
    }

    return new JsonResponse([
      'status' => TRUE,
      'should_continue' => FALSE,
      'message' => 'This is a mocked response from the Canvas Dev AI controller.',
      'progress' => '',
    ]);
  }

}
