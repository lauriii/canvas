<?php

declare(strict_types=1);

namespace Canvas\Sniffs\Services;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\UseStatementHelper;
use Symfony\Component\Yaml\Yaml;

/**
 * Forbids string-based service IDs in container->get() and \Drupal::service().
 *
 * Requires using ClassName::class instead of a magic string for any service
 * that has an explicit FQCN alias in the scanned *.services.yml files. Only
 * alias entries (e.g. `Drupal\...\FooInterface: '@string_id'`) are considered;
 * the `class:` property alone is NOT sufficient because multiple services can
 * share the same class (e.g. every cache bin uses CacheBackendInterface).
 *
 * Provides an auto-fixer (phpcbf) that replaces the string with the
 * appropriate ::class constant and adds the necessary use statement.
 *
 * Scanned *.services.yml files:
 * - core/core.services.yml
 * - core/modules/(all core modules and their test modules)/*.services.yml
 * - canvas.services.yml
 * - modules/(all Canvas submodules and their test modules)/*.services.yml
 *
 * Limitations:
 * - Services registered dynamically via ServiceProviderInterface::register()
 *   or altered via ServiceModifierInterface::alter() are NOT detected, because
 *   they do not appear in any *.services.yml file.
 * - Services from contrib/custom modules outside of Canvas are not scanned.
 * - Services that have a `class:` property but no FQCN alias are not flagged
 *   (e.g. cache.config, jsonapi.serializer).
 */
class ClassServiceIdSniff implements Sniff {

  /**
   * Cached mapping of string service ID → FQCN.
   *
   * @var array<string, string>|null
   */
  private static ?array $serviceMap = NULL;

  /**
   * {@inheritdoc}
   */
  public function register(): array {
    return [T_CONSTANT_ENCAPSED_STRING];
  }

  /**
   * {@inheritdoc}
   */
  public function process(File $phpcsFile, $stackPtr): void {
    $tokens = $phpcsFile->getTokens();
    $tokenContent = $tokens[$stackPtr]['content'];

    // Only look at single-quoted or double-quoted strings that look like a
    // service ID (contain a dot or underscore, no backslash for class names).
    $serviceId = \substr($tokenContent, 1, -1);
    if ($serviceId === '' || \str_contains($serviceId, '\\')) {
      return;
    }
    // Quick heuristic: service IDs contain dots or underscores.
    if (!\str_contains($serviceId, '.') && !\str_contains($serviceId, '_')) {
      return;
    }

    // Determine if this string is an argument to ->get() or ::service().
    if (!$this->isServiceCall($phpcsFile, $stackPtr)) {
      return;
    }

    // Look up the service ID in our mapping.
    $map = $this->getServiceMap($phpcsFile);
    if (!isset($map[$serviceId])) {
      return;
    }

    $fqcn = $map[$serviceId];
    $shortName = $this->getShortClassName($fqcn);

    $fix = $phpcsFile->addFixableError(
      'Use %s::class instead of the string \'%s\' for service ID.',
      $stackPtr,
      'StringServiceId',
      [$shortName, $serviceId],
    );

    if ($fix) {
      $this->fix($phpcsFile, $stackPtr, $fqcn, $shortName);
    }
  }

  /**
   * Determines if the string token is an argument to a service retrieval call.
   *
   * Matches:
   *   - $this->container->get('...')
   *   - $container->get('...')
   *   - \Drupal::service('...')
   */
  private function isServiceCall(File $phpcsFile, int $stackPtr): bool {
    $tokens = $phpcsFile->getTokens();

    // The string must be inside parentheses: find the opening parenthesis.
    $openParen = $phpcsFile->findPrevious(
      T_WHITESPACE,
      $stackPtr - 1,
      NULL,
      TRUE,
    );
    if ($openParen === FALSE || $tokens[$openParen]['code'] !== T_OPEN_PARENTHESIS) {
      return FALSE;
    }

    // Ensure it's the only (first) argument — no comma before it.
    $prevNonWhitespace = $phpcsFile->findPrevious(
      T_WHITESPACE,
      $openParen - 1,
      NULL,
      TRUE,
    );
    if ($prevNonWhitespace === FALSE) {
      return FALSE;
    }

    // The token before `(` should be the method name: `get` or `service`.
    if ($tokens[$prevNonWhitespace]['code'] !== T_STRING) {
      return FALSE;
    }
    $methodName = $tokens[$prevNonWhitespace]['content'];

    if ($methodName === 'service') {
      // Verify it's \Drupal::service() — look for :: before `service`.
      $doubleColon = $phpcsFile->findPrevious(
        T_WHITESPACE,
        $prevNonWhitespace - 1,
        NULL,
        TRUE,
      );
      if ($doubleColon === FALSE || $tokens[$doubleColon]['code'] !== T_DOUBLE_COLON) {
        return FALSE;
      }
      // Verify it's the Drupal class.
      $classToken = $phpcsFile->findPrevious(
        T_WHITESPACE,
        $doubleColon - 1,
        NULL,
        TRUE,
      );
      if ($classToken === FALSE) {
        return FALSE;
      }
      // Could be T_STRING "Drupal" or T_NS_SEPARATOR before it.
      $className = $tokens[$classToken]['content'];
      if ($className === 'Drupal') {
        return TRUE;
      }
      // Check for \Drupal (the backslash is the token before).
      if ($tokens[$classToken]['code'] === T_NS_SEPARATOR) {
        $beforeNs = $phpcsFile->findPrevious(
          T_WHITESPACE,
          $classToken - 1,
          NULL,
          TRUE,
        );
        // If nothing before \ or it's not part of another name, it's \Drupal.
        if ($beforeNs !== FALSE && $tokens[$beforeNs]['content'] === 'Drupal') {
          return TRUE;
        }
      }
      return FALSE;
    }

    if ($methodName === 'get') {
      // Verify it's ->get() on a container-like object.
      $arrow = $phpcsFile->findPrevious(
        T_WHITESPACE,
        $prevNonWhitespace - 1,
        NULL,
        TRUE,
      );
      if ($arrow === FALSE || $tokens[$arrow]['code'] !== T_OBJECT_OPERATOR) {
        return FALSE;
      }
      // The token before -> should be `container` (variable or property).
      $objectToken = $phpcsFile->findPrevious(
        T_WHITESPACE,
        $arrow - 1,
        NULL,
        TRUE,
      );
      if ($objectToken === FALSE) {
        return FALSE;
      }
      // Match $container->get() or ...->container->get().
      if ($tokens[$objectToken]['code'] === T_VARIABLE && $tokens[$objectToken]['content'] === '$container') {
        return TRUE;
      }
      if ($tokens[$objectToken]['code'] === T_STRING && $tokens[$objectToken]['content'] === 'container') {
        // Preceded by ->: $this->container->get or $foo->container->get.
        $prevArrow = $phpcsFile->findPrevious(
          T_WHITESPACE,
          $objectToken - 1,
          NULL,
          TRUE,
        );
        if ($prevArrow !== FALSE && $tokens[$prevArrow]['code'] === T_OBJECT_OPERATOR) {
          return TRUE;
        }
      }
      return FALSE;
    }

    return FALSE;
  }

  /**
   * Applies the fix: replaces the string with ::class and adds use statement.
   */
  private function fix(File $phpcsFile, int $stackPtr, string $fqcn, string $shortName): void {
    $fixer = $phpcsFile->fixer;

    // Check if the FQCN is already imported.
    $existingUseStatements = UseStatementHelper::getFileUseStatements($phpcsFile);
    $alreadyImported = FALSE;
    $importedShortName = $shortName;
    foreach ($existingUseStatements as $useStatements) {
      foreach ($useStatements as $useStatement) {
        if ($useStatement->getFullyQualifiedTypeName() === $fqcn) {
          $alreadyImported = TRUE;
          $importedShortName = $useStatement->getNameAsReferencedInFile();
          break 2;
        }
      }
    }

    // Check for short name collisions with existing imports.
    $hasCollision = FALSE;
    if (!$alreadyImported) {
      foreach ($existingUseStatements as $useStatements) {
        foreach ($useStatements as $useStatement) {
          if ($useStatement->getNameAsReferencedInFile() === $shortName) {
            $hasCollision = TRUE;
            break 2;
          }
        }
      }
    }

    $fixer->beginChangeset();

    // Replace the string token with ShortName::class.
    if ($alreadyImported || !$hasCollision) {
      $fixer->replaceToken($stackPtr, $importedShortName . '::class');
    }
    else {
      // Collision: use FQCN with leading backslash.
      $fixer->replaceToken($stackPtr, '\\' . $fqcn . '::class');
      $fixer->endChangeset();
      return;
    }

    // Add use statement if not already imported.
    if (!$alreadyImported) {
      $this->addUseStatement($phpcsFile, $fqcn);
    }

    $fixer->endChangeset();
  }

  /**
   * Adds a use statement for the given FQCN in alphabetical order.
   */
  private function addUseStatement(File $phpcsFile, string $fqcn): void {
    $fixer = $phpcsFile->fixer;
    $useStatement = 'use ' . $fqcn . ';';

    // Find existing use statements to insert alphabetically.
    $existingUsePointers = [];
    $usePtr = $phpcsFile->findNext(T_USE, 0);
    while ($usePtr !== FALSE) {
      if (UseStatementHelper::isImportUse($phpcsFile, $usePtr)) {
        $existingUsePointers[] = $usePtr;
      }
      $usePtr = $phpcsFile->findNext(T_USE, $usePtr + 1);
    }

    if ($existingUsePointers === []) {
      // No use statements at all: add after namespace declaration.
      $namespacePtr = $phpcsFile->findNext(T_NAMESPACE, 0);
      if ($namespacePtr !== FALSE) {
        $semicolonPtr = $phpcsFile->findNext(T_SEMICOLON, $namespacePtr);
        if ($semicolonPtr !== FALSE) {
          $fixer->addContent($semicolonPtr, "\n\n" . $useStatement);
        }
      }
      return;
    }

    // Find the correct insertion point (alphabetical).
    $insertBefore = NULL;
    foreach ($existingUsePointers as $existingUsePtr) {
      $existingFqcn = UseStatementHelper::getFullyQualifiedTypeNameFromUse($phpcsFile, $existingUsePtr);
      if (\strcasecmp($fqcn, $existingFqcn) < 0) {
        $insertBefore = $existingUsePtr;
        break;
      }
    }

    if ($insertBefore !== NULL) {
      // Insert before this use statement.
      $fixer->addContentBefore($insertBefore, $useStatement . "\n");
    }
    else {
      // Insert after the last use statement.
      $lastUsePtr = \end($existingUsePointers);
      $semiPtr = $phpcsFile->findNext(T_SEMICOLON, $lastUsePtr);
      if ($semiPtr !== FALSE) {
        $fixer->addContent($semiPtr, "\n" . $useStatement);
      }
    }
  }

  /**
   * Builds or returns the cached service ID → FQCN map.
   *
   * @return array<string, string>
   *   The service map.
   */
  private function getServiceMap(File $phpcsFile): array {
    if (self::$serviceMap !== NULL) {
      return self::$serviceMap;
    }

    self::$serviceMap = [];

    $canvasRoot = $this->findCanvasRoot($phpcsFile);
    if ($canvasRoot === NULL) {
      return self::$serviceMap;
    }

    // Determine the Drupal core root.
    $drupalRoot = $this->findDrupalRoot($canvasRoot);
    if ($drupalRoot === NULL) {
      return self::$serviceMap;
    }

    // Collect all services.yml files to parse.
    $ymlFiles = [];

    // 1. core.services.yml
    $coreServicesYml = $drupalRoot . '/core/core.services.yml';
    if (\file_exists($coreServicesYml)) {
      $ymlFiles[] = $coreServicesYml;
    }

    // 2. Core modules.
    $coreModulesDir = $drupalRoot . '/core/modules';
    if (\is_dir($coreModulesDir)) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($coreModulesDir, \FilesystemIterator::SKIP_DOTS)
      );
      foreach ($iterator as $file) {
        if ($file->isFile() && \str_ends_with($file->getFilename(), '.services.yml')) {
          $ymlFiles[] = $file->getPathname();
        }
      }
    }

    // 3. Canvas and its submodules.
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($canvasRoot, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && \str_ends_with($file->getFilename(), '.services.yml')) {
        $ymlFiles[] = $file->getPathname();
      }
    }

    // Parse all YAML files and build the mapping.
    $interfaceAliases = [];

    foreach ($ymlFiles as $ymlFile) {
      $parsed = Yaml::parseFile($ymlFile, Yaml::PARSE_CUSTOM_TAGS);
      if (!isset($parsed['services']) || !\is_array($parsed['services'])) {
        continue;
      }
      foreach ($parsed['services'] as $id => $definition) {
        if ($id === '_defaults') {
          continue;
        }
        // FQCN aliases: `Drupal\...\FooInterface: '@string_id'`.
        // Only these provide a guaranteed 1:1 mapping. The `class:` property
        // is NOT sufficient because multiple services can share the same
        // class (e.g., every cache bin uses CacheBackendInterface).
        if (\is_string($definition) && \str_starts_with($definition, '@')) {
          $referencedId = \substr($definition, 1);
          // Only treat as alias if the key looks like a FQCN.
          if (\str_contains($id, '\\')) {
            $interfaceAliases[$referencedId] = $id;
          }
        }
      }
    }

    // Map string service IDs to their FQCN alias.
    foreach ($interfaceAliases as $stringId => $fqcn) {
      self::$serviceMap[$stringId] = $fqcn;
    }

    return self::$serviceMap;
  }

  /**
   * Finds the Canvas module root directory.
   */
  private function findCanvasRoot(File $phpcsFile): ?string {
    // Walk up from the file being checked to find canvas.services.yml.
    $dir = \dirname($phpcsFile->getFilename());
    for ($i = 0; $i < 20; $i++) {
      if (\file_exists($dir . '/canvas.services.yml')) {
        return $dir;
      }
      $parent = \dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return NULL;
  }

  /**
   * Finds the Drupal root directory from the Canvas module root.
   */
  private function findDrupalRoot(string $canvasRoot): ?string {
    $dir = $canvasRoot;
    for ($i = 0; $i < 10; $i++) {
      if (\file_exists($dir . '/core/core.services.yml')) {
        return $dir;
      }
      $parent = \dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return NULL;
  }

  /**
   * Gets the short class name from a FQCN.
   */
  private function getShortClassName(string $fqcn): string {
    $parts = \explode('\\', $fqcn);
    return \end($parts);
  }

}
