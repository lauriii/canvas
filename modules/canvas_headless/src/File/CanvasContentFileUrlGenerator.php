<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\File;

use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Generates absolute file URLs for Canvas content API responses.
 */
final class CanvasContentFileUrlGenerator implements FileUrlGeneratorInterface {

  public function __construct(
    #[AutowireDecorated]
    private readonly FileUrlGeneratorInterface $inner,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generateString(string $uri): string {
    if ($this->isCanvasContentApiRequest()) {
      return $this->inner->generateAbsoluteString($uri);
    }
    return $this->inner->generateString($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function generateAbsoluteString(string $uri): string {
    return $this->inner->generateAbsoluteString($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function generate(string $uri): Url {
    if ($this->isCanvasContentApiRequest()) {
      return Url::fromUri($this->inner->generateAbsoluteString($uri));
    }
    return $this->inner->generate($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function transformRelative(string $file_url, bool $root_relative = TRUE): string {
    if ($this->isCanvasContentApiRequest()) {
      return $file_url;
    }
    return $this->inner->transformRelative($file_url, $root_relative);
  }

  /**
   * Whether the current request is routed through the Canvas content API.
   */
  private function isCanvasContentApiRequest(): bool {
    return \is_string($this->requestStack->getCurrentRequest()?->attributes->get(
      CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE,
    ));
  }

}
