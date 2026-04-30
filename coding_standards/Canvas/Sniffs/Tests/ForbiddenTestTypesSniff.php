<?php

declare(strict_types=1);

namespace Canvas\Sniffs\Tests;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Disallow Build, FunctionalJavascript, and Component test types.
 *
 * These test types are disallowed not because they are inherently wrong, but
 * because CI is not configured to run them. Without this guard, tests of these
 * types would silently never execute, appearing to pass in CI when they have
 * not run at all. To use one of these types, first add CI support for it, then
 * remove the relevant check here.
 */
class ForbiddenTestTypesSniff implements Sniff {

  public function register() {
    return [T_CLASS];
  }

  public function process(File $phpcsFile, $stackPtr) {
    $filename = $phpcsFile->getFilename();
    if (str_contains($filename, 'tests/src/Build')) {
      $phpcsFile->addError(
        'Build tests are not allowed in this module.',
        $stackPtr,
        'NoBuildTests',
      );
    }
    elseif (str_contains($filename, 'tests/src/FunctionalJavascript')) {
      $phpcsFile->addError(
        'FunctionalJavascript tests are not allowed in this module.',
        $stackPtr,
        'NoFunctionalJavascriptTests',
      );
    }
    elseif (str_contains($filename, 'tests/src/Component')) {
      $phpcsFile->addError(
        'Unit component tests are not allowed in this module.',
        $stackPtr,
        'NoUnitComponentTests',
      );
    }
  }

}
