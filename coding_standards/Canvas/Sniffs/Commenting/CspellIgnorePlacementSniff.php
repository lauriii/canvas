<?php

declare(strict_types=1);

namespace Canvas\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Ensures file-level cspell:ignore comments are after the namespace.
 *
 * Specifically, they must be placed before any use statements. Inline
 * cspell:ignore comments (inside a class/function body) are allowed anywhere.
 */
class CspellIgnorePlacementSniff implements Sniff {

  public function register(): array {
    return [T_COMMENT];
  }

  public function process(File $phpcsFile, $stackPtr): void {
    $tokens = $phpcsFile->getTokens();
    $content = $tokens[$stackPtr]['content'];

    if (\stripos($content, 'cspell:ignore') === FALSE) {
      return;
    }

    if ($this->isInsideScope($phpcsFile, $stackPtr)) {
      return;
    }

    $namespacePtr = $phpcsFile->findNext(T_NAMESPACE, 0);
    if ($namespacePtr === FALSE) {
      return;
    }

    $namespaceSemicolon = $phpcsFile->findNext(T_SEMICOLON, $namespacePtr);
    if ($namespaceSemicolon === FALSE) {
      return;
    }

    $expectedLine = $tokens[$namespaceSemicolon]['line'] + 2;

    if ($tokens[$stackPtr]['line'] !== $expectedLine) {
      $fix = $phpcsFile->addFixableError(
        'File-level cspell:ignore comments must be placed on the line after the namespace declaration, before use statements (expected line %d, found line %d).',
        $stackPtr,
        'WrongLine',
        [$expectedLine, $tokens[$stackPtr]['line']],
      );

      if ($fix) {
        $fixer = $phpcsFile->fixer;
        $fixer->beginChangeset();

        $commentText = \rtrim($content);

        $fixer->replaceToken($stackPtr, '');

        $next = $stackPtr + 1;
        if (isset($tokens[$next]) && $tokens[$next]['code'] === T_WHITESPACE
            && \str_starts_with($tokens[$next]['content'], "\n")) {
          $fixer->replaceToken($next, \substr($tokens[$next]['content'], 1));
        }

        $wsAfterNs = $namespaceSemicolon + 1;
        if (isset($tokens[$wsAfterNs]) && $tokens[$wsAfterNs]['code'] === T_WHITESPACE) {
          $fixer->replaceToken($wsAfterNs, "\n\n" . $commentText . "\n");
        }
        else {
          $fixer->addContent($namespaceSemicolon, "\n\n" . $commentText . "\n");
        }

        $fixer->endChangeset();
      }
    }
  }

  private function isInsideScope(File $phpcsFile, int $stackPtr): bool {
    $tokens = $phpcsFile->getTokens();

    foreach ($tokens[$stackPtr]['conditions'] ?? [] as $type) {
      if (\in_array($type, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CLOSURE, T_ANON_CLASS], TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
