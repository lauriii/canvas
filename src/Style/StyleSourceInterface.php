<?php

declare(strict_types=1);

namespace Drupal\canvas\Style;

/**
 * Where style definitions come from: core's Style API, or Canvas' polyfill.
 *
 * Canvas applies styles itself in both cases (see
 * ComponentStyles::resolveWithDefinitions()), with the same algorithm core
 * uses, so the source only provides definitions.
 *
 * @see \Drupal\canvas\Style\CoreStyleSource
 * @see \Drupal\canvas_style_polyfill\PolyfillStyleSource
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md
 *
 * @phpstan-type SourceOption array{label: string, description: string, value: string, attribute: string, target: string, status: string, icon: ?string}
 * @phpstan-type SourceDefinition array{id: string, provider: string, canvas_library: ?string, enabled: bool, label: string, description: string, category: string, weight: int, status: string, library: string, options: array<string, SourceOption>, tokens?: mixed, custom?: mixed}
 *
 * @internal
 */
interface StyleSourceInterface {

  /**
   * Whether these definitions come from core's Style API.
   */
  public function isCore(): bool;

  /**
   * Style definitions with core's defaults applied, keyed by style ID.
   *
   * Includes Canvas-managed style libraries, marked with `canvas_library`.
   *
   * @return array<string, SourceDefinition>
   */
  public function definitions(): array;

  /**
   * Clears cached definitions, for example after a style library is saved.
   */
  public function clearCachedDefinitions(): void;

  /**
   * The cache tag invalidated when definitions change.
   */
  public function cacheTag(): string;

}
