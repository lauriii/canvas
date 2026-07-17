<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues the CSRF token used to authorize Canvas AI requests.
 *
 * This lives in its own controller, separate from CanvasBuilder, so that
 * fetching the token only depends on the core CSRF token generator. The AI
 * wizard fetches the token first thing on mount; binding it to CanvasBuilder
 * made it fail whenever any AI service that controller injects was unavailable
 * (for example a partial or version-mismatched AI stack install), surfacing as
 * "The controller ... is not callable". See https://www.drupal.org/i/3550891.
 */
final class CsrfTokenController extends ControllerBase {

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
   * Returns the CSRF token for Canvas AI requests.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object containing the token.
   */
  public function getCsrfToken(): Response {
    return new Response($this->csrfTokenGenerator->get('canvas_ai.canvas_builder'));
  }

}
