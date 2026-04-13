<?php

declare(strict_types=1);

namespace Canvas\Sniffs\Tests;

use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\Tests\canvas\Kernel\Audit\ComponentAuditTestBase;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Config\AssetLibraryStorageTest;
use Drupal\Tests\canvas\Kernel\Config\BetterConfigEntityValidationTestBase;
use Drupal\Tests\canvas\Kernel\Config\ConfigWithComponentTreeTestBase;
use Drupal\Tests\canvas\Kernel\EcosystemSupport\EcosystemSupportTestBase;
use Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase;
use Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBaseTestBase;
use Drupal\Tests\canvas\Kernel\PropShapeRepositoryTest;
use Drupal\Tests\canvas\Kernel\PropSource\PropSourceTestBase;
use Drupal\Tests\canvas\Kernel\ShapeMatcher\PropSourceMatcherTestBase;
use Drupal\Tests\canvas_personalization\Kernel\Config\SegmentValidationTest;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use SlevomatCodingStandard\Helpers\NamespaceHelper;
use SlevomatCodingStandard\Helpers\ReferencedName;
use SlevomatCodingStandard\Helpers\ReferencedNameHelper;

class KernelTestBaseSniff implements Sniff {

  public const CODE_REQUIRE_ASSERT_ENTITY_IS_VALID = 'RequireAssertEntityIsValid';

  // A valid reason to not extend CanvasKernelTestBase: because it extends
  // another class that does.
  public const KNOWN_SUBCLASSES = [
    AssetLibraryStorageTest::class,
    ComponentAuditTestBase::class,
    ComponentSourceTestBase::class,
    ConfigWithComponentTreeTestBase::class,
    EcosystemSupportTestBase::class,
    GeneratedFieldExplicitInputUxComponentSourceBaseTestBase::class,
    PropSourceTestBase::class,
    PropShapeRepositoryTest::class,
    PropSourceMatcherTestBase::class,
  ];

  public const ALLOWED_OTHER_BASE_CLASSES = [
    // A valid reason to not extend CanvasKernelTestBase: because the test
    // extends core's ConfigEntityValidationTestBase.
    // @see \Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase
    // @see \Drupal\Tests\canvas\Kernel\Config\BetterConfigEntityValidationTestBase
    ConfigEntityValidationTestBase::class,
    BetterConfigEntityValidationTestBase::class,
    SegmentValidationTest::class,
    // Similarly: when extending contrib tests.
    'Drupal\Tests\simple_oauth\Kernel\AuthorizedRequestBase',
  ];

  public function register() {
    return [T_CLASS];
  }

  public function process(File $phpcsFile, $stackPtr) {
    if (!str_contains($phpcsFile->getFilename(), 'tests/src/Kernel')) {
      return;
    }
    $tokens = $phpcsFile->getTokens();
    $className = $phpcsFile->getDeclarationName($stackPtr);

    // This is likely a helper class declared in the same file as an actual
    // kernel test.
    // For example: \Drupal\Tests\canvas\Kernel\AutoSaveManagerTestTime.
    if (!str_ends_with($className, 'Test')) {
      return;
    }

    $extendsPtr = $phpcsFile->findNext(T_EXTENDS, $stackPtr, NULL, FALSE, NULL, TRUE);
    // Every kernel test must extend a base class.
    \assert($extendsPtr !== FALSE);

    $baseClassPtr = $phpcsFile->findNext(T_STRING, $extendsPtr);
    $baseClass = $tokens[$baseClassPtr]['content'] ?? '';

    $baseClassReferencedName = array_find(
      ReferencedNameHelper::getAllReferencedNames($phpcsFile, $stackPtr),
      fn (ReferencedName $n) => $n->getNameAsReferencedInFile() === $baseClass,
    );
    $baseClassFqcn = NamespaceHelper::resolveName(
      $phpcsFile,
      $baseClassReferencedName->getNameAsReferencedInFile(),
      $baseClassReferencedName->getType(),
      $baseClassReferencedName->getStartPointer()
    );
    // Trim the leading backslash.
    $baseClassFqcn = ltrim($baseClassFqcn, '\\');
    $extendsCanvasKernelTestBase = $baseClassFqcn === CanvasKernelTestBase::class
      || \in_array($baseClassFqcn, self::KNOWN_SUBCLASSES, TRUE);

    if (!$extendsCanvasKernelTestBase) {
      // Some other base classes are allowed; typically because they are from
      // core or contrib for testing a complex set of functionality in a generic
      // way.
      if (!\in_array($baseClassFqcn, self::ALLOWED_OTHER_BASE_CLASSES, TRUE)) {
        $php_as_string = file_get_contents($phpcsFile->getFilename());
        // Detect kernel tests that have a documented reason for not extending
        // CanvasKernelTestBase — such as a Recipe that installs the Canvas module.
        if (!str_contains($php_as_string, 'Note this cannot use CanvasKernelTestBase because')) {
          // Detect CanvasTestSetup usage.
          // @todo Remove this early return in https://www.drupal.org/project/canvas/issues/3531679
          if (!\str_contains($php_as_string, 'CanvasTestSetup') && !\str_contains($php_as_string, 'extends ApiLayoutControllerTestBase') && !\str_contains($php_as_string, 'extends AutoSaveConflictConfigTestBase')) {
            if ($baseClass !== 'CanvasKernelTestBase') {
              $phpcsFile->addError(
                "Kernel test class $className must extend CanvasKernelTestBase, not $baseClass.",
                $baseClassPtr,
                'WrongBaseClass'
              );
            }
          }
        }
      }
      return;
    }

    // Impose requirements on CanvasKernelTestBase subclasses, to automate code
    // review.
    $this->requireAssertEntityIsValid($phpcsFile, $stackPtr);
  }

  /**
   * Detects manual `assertSame([], self::violationsToArray(…))` usage.
   *
   * Subclasses of CanvasKernelTestBase should use assertEntityIsValid() instead
   * of manually asserting that violations are empty.
   */
  private function requireAssertEntityIsValid(File $phpcsFile, int $stackPtr): void {
    // Skip CanvasKernelTestBase itself: that's where assertEntityIsValid() is
    // defined.
    if (str_ends_with($phpcsFile->getFilename(), 'CanvasKernelTestBase.php')) {
      return;
    }

    $tokens = $phpcsFile->getTokens();
    for ($i = $stackPtr; $i < $phpcsFile->numTokens; $i++) {
      // Pattern 1: assertSame([], self::violationsToArray(…))
      if ($tokens[$i]['code'] === T_STRING && $tokens[$i]['content'] === 'assertSame') {
        $this->checkAssertSamePattern($phpcsFile, $i);
      }
      // Pattern 2: assertCount(0, $x->validate()) or assertCount(0, $violations, getMessage…)
      if ($tokens[$i]['code'] === T_STRING && $tokens[$i]['content'] === 'assertCount') {
        $this->checkAssertCountPattern($phpcsFile, $i);
      }
    }
  }

  /**
   * Checks for assertSame([], self::violationsToArray(…)) pattern.
   */
  private function checkAssertSamePattern(File $phpcsFile, int $i): void {
    $tokens = $phpcsFile->getTokens();

    // Find the opening parenthesis after assertSame.
    $openParen = $phpcsFile->findNext(T_WHITESPACE, $i + 1, NULL, TRUE);
    if ($openParen === FALSE || $tokens[$openParen]['code'] !== T_OPEN_PARENTHESIS) {
      return;
    }
    // Check if the first argument is `[]`.
    $firstArg = $phpcsFile->findNext(T_WHITESPACE, $openParen + 1, NULL, TRUE);
    if ($firstArg === FALSE || $tokens[$firstArg]['code'] !== T_OPEN_SHORT_ARRAY) {
      return;
    }
    $afterOpenBracket = $phpcsFile->findNext(T_WHITESPACE, $firstArg + 1, NULL, TRUE);
    if ($afterOpenBracket === FALSE || $tokens[$afterOpenBracket]['code'] !== T_CLOSE_SHORT_ARRAY) {
      return;
    }
    // Check for a comma after `[]`.
    $comma = $phpcsFile->findNext(T_WHITESPACE, $afterOpenBracket + 1, NULL, TRUE);
    if ($comma === FALSE || $tokens[$comma]['code'] !== T_COMMA) {
      return;
    }
    // Now check if the second argument starts with `self::violationsToArray(`.
    $secondArgStart = $phpcsFile->findNext(T_WHITESPACE, $comma + 1, NULL, TRUE);
    if ($secondArgStart === FALSE || $tokens[$secondArgStart]['code'] !== T_SELF) {
      return;
    }
    $doubleColon = $phpcsFile->findNext(T_WHITESPACE, $secondArgStart + 1, NULL, TRUE);
    if ($doubleColon === FALSE || $tokens[$doubleColon]['code'] !== T_DOUBLE_COLON) {
      return;
    }
    $methodName = $phpcsFile->findNext(T_WHITESPACE, $doubleColon + 1, NULL, TRUE);
    if ($methodName === FALSE || $tokens[$methodName]['code'] !== T_STRING || $tokens[$methodName]['content'] !== 'violationsToArray') {
      return;
    }

    // Peek inside violationsToArray(…) to avoid false positives when the
    // argument is a non-entity typed data object's validate() call.
    $violationsOpenParen = $phpcsFile->findNext(T_WHITESPACE, $methodName + 1, NULL, TRUE);
    if ($violationsOpenParen !== FALSE
      && $tokens[$violationsOpenParen]['code'] === T_OPEN_PARENTHESIS
      && isset($tokens[$violationsOpenParen]['parenthesis_closer'])
    ) {
      $isEntityValidate = $this->looksLikeEntityValidateCall(
        $phpcsFile,
        $violationsOpenParen + 1,
        $tokens[$violationsOpenParen]['parenthesis_closer'],
      );
      // FALSE means the argument is recognizably a non-entity typed data
      // object; skip. TRUE or NULL (pre-computed $violations variable, etc.)
      // fall through to addError().
      if ($isEntityValidate === FALSE) {
        return;
      }
    }

    $phpcsFile->addError(
      'Use self::assertEntityIsValid($entity) instead of assertSame([], self::violationsToArray(…)). The assertEntityIsValid() method is provided by CanvasKernelTestBase.',
      $i,
      self::CODE_REQUIRE_ASSERT_ENTITY_IS_VALID,
    );
  }

  /**
   * Checks for assertCount(0, $x->validate()) and assertCount(0, $violations, getMessage…) patterns.
   */
  private function checkAssertCountPattern(File $phpcsFile, int $i): void {
    $tokens = $phpcsFile->getTokens();

    // Find the opening parenthesis after assertCount.
    $openParen = $phpcsFile->findNext(T_WHITESPACE, $i + 1, NULL, TRUE);
    if ($openParen === FALSE || $tokens[$openParen]['code'] !== T_OPEN_PARENTHESIS) {
      return;
    }
    if (!isset($tokens[$openParen]['parenthesis_closer'])) {
      return;
    }
    $closeParen = $tokens[$openParen]['parenthesis_closer'];

    // Check if the first argument is `0`.
    $firstArg = $phpcsFile->findNext(T_WHITESPACE, $openParen + 1, NULL, TRUE);
    if ($firstArg === FALSE || $tokens[$firstArg]['code'] !== T_LNUMBER || $tokens[$firstArg]['content'] !== '0') {
      return;
    }
    // Check for a comma after `0`.
    $firstComma = $phpcsFile->findNext(T_WHITESPACE, $firstArg + 1, NULL, TRUE);
    if ($firstComma === FALSE || $tokens[$firstComma]['code'] !== T_COMMA) {
      return;
    }

    // Find the end of the second argument by scanning for the first top-level
    // comma (depth-aware), so we know where the third argument starts.
    $depth = 0;
    $secondArgEnd = NULL;
    for ($j = $firstComma + 1; $j < $closeParen; $j++) {
      $code = $tokens[$j]['code'];
      if (\in_array($code, [T_OPEN_PARENTHESIS, T_OPEN_SHORT_ARRAY, T_OPEN_SQUARE_BRACKET, T_OPEN_CURLY_BRACKET], TRUE)) {
        $depth++;
      }
      elseif (\in_array($code, [T_CLOSE_PARENTHESIS, T_CLOSE_SHORT_ARRAY, T_CLOSE_SQUARE_BRACKET, T_CLOSE_CURLY_BRACKET], TRUE)) {
        $depth--;
      }
      elseif ($depth === 0 && $code === T_COMMA) {
        $secondArgEnd = $j;
        break;
      }
    }

    // Check whether the second argument is an entity's validate() call.
    $isEntityValidate = $this->looksLikeEntityValidateCall(
      $phpcsFile,
      $firstComma + 1,
      $secondArgEnd ?? $closeParen,
    );
    if ($isEntityValidate === TRUE) {
      $phpcsFile->addError(
        'Use self::assertEntityIsValid($entity) instead of assertCount(0, $entity->validate()). The assertEntityIsValid() method is provided by CanvasKernelTestBase.',
        $i,
        self::CODE_REQUIRE_ASSERT_ENTITY_IS_VALID,
      );
      return;
    }

    // If the second argument was a variable (no validate() call there), check
    // if the third+ arguments contain getMessage, which strongly indicates this
    // is asserting on constraint violations.
    if ($secondArgEnd !== NULL) {
      for ($j = $secondArgEnd + 1; $j < $closeParen; $j++) {
        if ($tokens[$j]['code'] === T_STRING && $tokens[$j]['content'] === 'getMessage') {
          $phpcsFile->addError(
            'Use self::assertEntityIsValid($entity) instead of assertCount(0, $violations, …). The assertEntityIsValid() method is provided by CanvasKernelTestBase.',
            $i,
            self::CODE_REQUIRE_ASSERT_ENTITY_IS_VALID,
          );
          return;
        }
      }
    }
  }

  /**
   * Checks if a token range contains an entity's validate() call.
   *
   * Scans tokens between $start and $end for ->validate() or
   * ->getTypedData()->validate(), applying a variable-name heuristic to
   * distinguish entity validate() calls from non-entity typed data ones.
   *
   * @return bool|null
   *   TRUE if the range contains what appears to be an entity's validate()
   *   call, FALSE if it appears to be a non-entity typed data validate() call
   *   (e.g. a field item list), or NULL if no validate() call was found or the
   *   type cannot be determined.
   */
  private function looksLikeEntityValidateCall(File $phpcsFile, int $start, int $end): ?bool {
    $tokens = $phpcsFile->getTokens();
    $hasGetTypedData = FALSE;
    $validatePos = NULL;
    for ($j = $start; $j < $end; $j++) {
      $code = $tokens[$j]['code'];
      if ($code === T_STRING && $tokens[$j]['content'] === 'getTypedData') {
        $hasGetTypedData = TRUE;
      }
      if ($code === T_STRING && $tokens[$j]['content'] === 'validate') {
        $validatePos = $j;
      }
    }
    if ($validatePos === NULL) {
      return NULL;
    }
    // ->getTypedData()->validate(): unambiguously a config entity.
    if ($hasGetTypedData) {
      return TRUE;
    }
    // Direct $var->validate() or method-chain->validate(): use the variable
    // name before -> to distinguish entity from typed data.
    $objOp = $phpcsFile->findPrevious(T_OBJECT_OPERATOR, $validatePos - 1, $start);
    if ($objOp === FALSE) {
      return NULL;
    }
    $beforeArrow = $phpcsFile->findPrevious(T_WHITESPACE, $objOp - 1, $start, TRUE);
    if ($beforeArrow === FALSE) {
      return NULL;
    }
    if ($tokens[$beforeArrow]['code'] === T_VARIABLE) {
      $varName = ltrim($tokens[$beforeArrow]['content'], '$');
      // Variable names containing 'item' or 'tree' indicate typed data objects
      // (e.g. $new_items, $item_list, $component_tree), not entities.
      if (str_contains($varName, 'item') || str_contains($varName, 'tree')) {
        return FALSE;
      }
      return TRUE;
    }
    if ($tokens[$beforeArrow]['code'] === T_CLOSE_PARENTHESIS) {
      // Chained call like $page->getComponentTree()->validate(): the receiver
      // is a method result, not identifiable as an entity without getTypedData().
      return FALSE;
    }
    return NULL;
  }

}
