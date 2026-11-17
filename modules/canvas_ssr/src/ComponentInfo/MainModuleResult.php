<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo;

/**
 * What an invoke of a main module returned.
 *
 * The main module returns JSON, since an invoke returns only a string. It
 * holds either the markup or what the render threw.
 *
 * @see \Drupal\canvas_ssr\ComponentInfo\MainModule::source()
 */
final readonly class MainModuleResult {

  /**
   * Constructs a MainModuleResult.
   *
   * @param string|null $html
   *   The markup, or NULL if the render failed.
   * @param string|null $error
   *   Why the render failed, or NULL if it didn't.
   * @param bool $importsUnsettled
   *   Whether a component made an import() that may not have settled, so
   *   the main module left the island open.
   * @param string|null $hydrationData
   *   A hydrated island's hydration data, encoded by devalue, or NULL if it
   *   has none.
   */
  private function __construct(
    public ?string $html,
    public ?string $error,
    public bool $importsUnsettled = FALSE,
    public ?string $hydrationData = NULL,
  ) {}

  /**
   * Reads what an invoke returned.
   *
   * @param string $result
   *   What the invoke returned.
   *
   * @return self
   *   The result. One that isn't the main module's JSON counts as a failed
   *   render.
   */
  public static function fromInvoke(string $result): self {
    try {
      $decoded = \json_decode($result, TRUE, 2, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $decoded = NULL;
    }
    if (!\is_array($decoded)) {
      return new self(NULL, 'The main module returned something other than its result.');
    }
    $imports_unsettled = ($decoded['imports'] ?? NULL) === TRUE;
    if (\is_string($decoded['error'] ?? NULL)) {
      return new self(NULL, $decoded['error'], $imports_unsettled);
    }
    if (\is_string($decoded['html'] ?? NULL)) {
      $hydration_data = $decoded['hydrationData'] ?? NULL;
      return new self($decoded['html'], NULL, $imports_unsettled, \is_string($hydration_data) ? $hydration_data : NULL);
    }

    return new self(NULL, 'The main module returned neither markup nor an error.');
  }

}
