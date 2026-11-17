<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsParser;

/**
 * A parsed module's imports and directives.
 *
 * The syntax tree isn't kept, so this is cheap to hold for the request.
 *
 * @see \Drupal\canvas_ssr\JsParser\JsModuleParser
 */
final readonly class ParsedModule {

  /**
   * Constructs a ParsedModule.
   *
   * @param list<array{0: string, 1: int, 2: int}> $imports
   *   See getImports().
   * @param array<int, string> $directives
   *   Its directive prologue, as written.
   * @param list<array{0: int, 1: int}> $dynamicImports
   *   See getDynamicImports().
   */
  public function __construct(
    private array $imports,
    private array $directives,
    private array $dynamicImports = [],
  ) {}

  /**
   * Gets where this module's import() calls are.
   *
   * Every one, whatever it's given, so a call may be inside another.
   *
   * @return list<array{0: int, 1: int}>
   *   The byte offsets where each call starts, at "import", and ends, past
   *   its ")". In source order.
   */
  public function getDynamicImports(): array {
    return $this->dynamicImports;
  }

  /**
   * Gets this module's imports, and where each specifier is.
   *
   * That includes dynamic import() and `export … from`. A specifier
   * imported twice is listed twice.
   *
   * @return list<array{0: string, 1: int, 2: int}>
   *   The specifier, and the byte offsets where its string literal starts
   *   and ends, quotes included. In source order.
   */
  public function getImports(): array {
    return $this->imports;
  }

  /**
   * Gets this module's directive prologue.
   *
   * @return array<int, string>
   *   Its directives, as written, in source order.
   */
  public function getDirectives(): array {
    return $this->directives;
  }

  /**
   * Checks whether the module's directive prologue has a directive.
   *
   * The prologue is the string statements a module starts with. The same
   * string anywhere else isn't a directive.
   *
   * @param string $directive
   *   The directive, such as "use client".
   *
   * @return bool
   *   TRUE if the prologue has it.
   */
  public function hasDirective(string $directive): bool {
    return \in_array($directive, $this->directives, TRUE);
  }

}
