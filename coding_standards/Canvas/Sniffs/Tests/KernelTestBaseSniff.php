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
      if ($tokens[$i]['code'] !== T_STRING || $tokens[$i]['content'] !== 'assertSame') {
        continue;
      }
      // Find the opening parenthesis after assertSame.
      $openParen = $phpcsFile->findNext(T_WHITESPACE, $i + 1, NULL, TRUE);
      if ($openParen === FALSE || $tokens[$openParen]['code'] !== T_OPEN_PARENTHESIS) {
        continue;
      }
      // Check if the first argument is `[]`.
      $firstArg = $phpcsFile->findNext(T_WHITESPACE, $openParen + 1, NULL, TRUE);
      if ($firstArg === FALSE || $tokens[$firstArg]['code'] !== T_OPEN_SHORT_ARRAY) {
        continue;
      }
      $afterOpenBracket = $phpcsFile->findNext(T_WHITESPACE, $firstArg + 1, NULL, TRUE);
      if ($afterOpenBracket === FALSE || $tokens[$afterOpenBracket]['code'] !== T_CLOSE_SHORT_ARRAY) {
        continue;
      }
      // Check for a comma after `[]`.
      $comma = $phpcsFile->findNext(T_WHITESPACE, $afterOpenBracket + 1, NULL, TRUE);
      if ($comma === FALSE || $tokens[$comma]['code'] !== T_COMMA) {
        continue;
      }
      // Now check if the second argument starts with `self::violationsToArray(`.
      $secondArgStart = $phpcsFile->findNext(T_WHITESPACE, $comma + 1, NULL, TRUE);
      if ($secondArgStart === FALSE || $tokens[$secondArgStart]['code'] !== T_SELF) {
        continue;
      }
      $doubleColon = $phpcsFile->findNext(T_WHITESPACE, $secondArgStart + 1, NULL, TRUE);
      if ($doubleColon === FALSE || $tokens[$doubleColon]['code'] !== T_DOUBLE_COLON) {
        continue;
      }
      $methodName = $phpcsFile->findNext(T_WHITESPACE, $doubleColon + 1, NULL, TRUE);
      if ($methodName === FALSE || $tokens[$methodName]['code'] !== T_STRING || $tokens[$methodName]['content'] !== 'violationsToArray') {
        continue;
      }

      $phpcsFile->addError(
        'Use self::assertEntityIsValid($entity) instead of assertSame([], self::violationsToArray(…)). The assertEntityIsValid() method is provided by CanvasKernelTestBase.',
        $i,
        self::CODE_REQUIRE_ASSERT_ENTITY_IS_VALID,
      );
    }
  }

}
