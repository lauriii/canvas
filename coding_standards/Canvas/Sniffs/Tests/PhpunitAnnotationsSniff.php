<?php

declare(strict_types=1);

namespace Canvas\Sniffs\Tests;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * Disallows PHPUnit annotations in favor of PHP attributes.
 *
 * Detects `@group`, `@covers`/`@coversClass` and `@dataProvider` annotations
 * and requires the use of `#[Group(…)]`, `#[CoversClass(…)]` and
 * `#[DataProvider(…)]` attributes respectively.
 *
 * @see https://www.drupal.org/node/3447698
 *
 * @todo Delete this sniff once Canvas requires Drupal 12: Drupal 12 will
 *   require these PHPUnit attributes and forbid these annotations.
 */
class PhpunitAnnotationsSniff implements Sniff {

  /**
   * Map of deprecated annotations to their attribute replacements.
   */
  private const ANNOTATION_TO_ATTRIBUTE = [
    '@group' => '#[Group(\'…\')]',
    '@covers' => '#[CoversClass(…)]',
    '@coversClass' => '#[CoversClass(…)]',
    '@dataProvider' => '#[DataProvider(\'…\')]',
  ];

  public function register() {
    return [T_DOC_COMMENT_TAG];
  }

  public function process(File $phpcsFile, $stackPtr) {
    $tokens = $phpcsFile->getTokens();
    $annotation = $tokens[$stackPtr]['content'];

    if (!isset(self::ANNOTATION_TO_ATTRIBUTE[$annotation])) {
      return;
    }

    $attribute = self::ANNOTATION_TO_ATTRIBUTE[$annotation];
    $phpcsFile->addError(
      'Use the %s attribute instead of the %s annotation. See https://www.drupal.org/node/3447698',
      $stackPtr,
      'AnnotationFound',
      [$attribute, $annotation]
    );
  }

}
