<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves, per analysed file, which Drupal modules its code may safely use.
 *
 * A module's `Drupal\<module>\…` classes are only autoloadable at runtime when
 * that module is enabled: Drupal registers each enabled extension's namespace
 * on the class loader from `core.extension`, and nothing registers a disabled
 * module's namespace.
 *
 * @see \Drupal\Core\DrupalKernel::compileContainer() (`container.namespaces`)
 * @see \Drupal\Core\DrupalKernel::attachSynthetic() (classLoaderAddMultiplePsr4)
 *
 * This helper backs the rules that flag *hard* dereferences (`X::CONST`,
 * `new X`, `X::method()`, `X::$prop`, `extends`/`implements X`) of a class
 * owned by a module the analysed module does not depend on — the constructs
 * that trigger a "Class … not found" fatal. Soft references (`instanceof`,
 * type hints, docblocks) never fatal and are intentionally not covered.
 *
 * The resolution mirrors the runtime: a reference is safe when the owning
 * module either is the referencing module itself, is in its transitive
 * dependency closure (Drupal enables the whole closure together), or is a
 * core namespace. A per-class `@drupalOptionalDependency <module>` docblock
 * annotation marks a deliberately-optional integration (the reference is
 * guarded by `moduleHandler->moduleExists()` or lives in a conditionally
 * registered service/plugin) as sanctioned.
 */
final class ModuleDependencyResolver {

  /**
   * Namespace roots that are always available (core, not module-gated).
   */
  private const ALWAYS_AVAILABLE_ROOTS = [
    'Core',
    'Component',
    'Driver',
    'Tests',
    'TestTools',
    'KernelTests',
    'FunctionalTests',
    'FunctionalJavascriptTests',
    'BuildTests',
  ];

  /**
   * PHP array keys whose `X::class` value is loaded by a module.
   *
   * A module reads these keys from its plugin/field/schema definitions and
   * instantiates the named class itself, so a class assigned to one via
   * `::class` (which never autoloads) is only ever loaded by that module.
   * Detected dynamically so new integrations need no registry entry.
   *
   * @var array<string, string>
   */
  private const DEFINITION_KEY_LOADERS = [
    // config_translation reads `form_element_class` (set in PHP via `::class`).
    // @see src/Plugin/Canvas/ComponentSource/JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator.php
    'form_element_class' => 'config_translation',
    // The tmgmt module reads these processor keys.
    // @see src/Hook/TmgmtHooks.php
    'tmgmt_config_processor' => 'tmgmt_config',
    'tmgmt_field_processor' => 'tmgmt_content',
  ];

  /**
   * Config-schema keys whose class-name string value is loaded by a module.
   *
   * The same wiring as DEFINITION_KEY_LOADERS, but declared statically in
   * `config/schema/*.yml` rather than assigned in PHP.
   *
   * @var array<string, string>
   */
  private const SCHEMA_KEY_LOADERS = [
    // @see config/schema/canvas.schema.yml
    'form_element_class' => 'config_translation',
  ];

  /**
   * Per-module-root map of class FQN => loader module, from scanned wiring.
   *
   * @var array<string, array<string, string>>
   */
  private static array $scannedLoaderMapByRoot = [];

  /**
   * Per-Drupal-root index of module machine name => direct dependency names.
   *
   * @var array<string, array<string, list<string>>>
   */
  private static array $indexByRoot = [];

  /**
   * Per-file resolved context, or FALSE when the file must be skipped.
   *
   * @var array<string, array{available: array<string, true>, index: array<string, list<string>>, owning: string}|false>
   */
  private static array $contextByFile = [];

  /**
   * Resolves the analysis context for a file.
   *
   * @return array{available: array<string, true>, index: array<string, list<string>>, owning: string}|null
   *   NULL when the file is not module code to check (test code, unresolved
   *   layout). Otherwise the owning module, the on-disk module index, and the
   *   set of module machine names the file may reference.
   */
  public static function forFile(string $file): ?array {
    if (isset(self::$contextByFile[$file])) {
      return self::$contextByFile[$file] ?: NULL;
    }
    $context = self::resolve($file);
    self::$contextByFile[$file] = $context ?? FALSE;
    return $context;
  }

  /**
   * Maps a fully-qualified class name to the module machine name to check.
   *
   * @return string|null
   *   The owning module machine name, or NULL when the class is not gated on
   *   module install state (core, test namespaces, or non-Drupal classes).
   */
  public static function moduleForClass(string $fqn): ?string {
    $parts = \explode('\\', \ltrim($fqn, '\\'));
    if (($parts[0] ?? '') !== 'Drupal' || \count($parts) < 2) {
      return NULL;
    }
    $module = $parts[1];
    if (\in_array($module, self::ALWAYS_AVAILABLE_ROOTS, TRUE)) {
      return NULL;
    }
    return $module;
  }

  /**
   * The module that conditionally loads a class via a known wiring, if any.
   *
   * Detects the class wired to a known definition or config-schema key, or
   * registered as a decorator of a module-owned service.
   *
   * @param string $classFqn
   *   The class being asked about.
   * @param string $contextFile
   *   A file in the same module, used to locate the module to scan.
   *
   * @see self::scannedLoaderMap()
   */
  public static function conditionalLoaderModule(string $classFqn, string $contextFile): ?string {
    $moduleRoot = self::moduleRootDir($contextFile);
    if ($moduleRoot === NULL) {
      return NULL;
    }
    return self::scannedLoaderMap($moduleRoot)[\ltrim($classFqn, '\\')] ?? NULL;
  }

  /**
   * The directory of the module owning a file (the one holding its info.yml).
   */
  private static function moduleRootDir(string $file): ?string {
    $dir = \dirname($file);
    while ($dir !== '' && $dir !== '/' && $dir !== \dirname($dir)) {
      if ((\glob($dir . '/*.info.yml') ?: []) !== []) {
        return $dir;
      }
      $dir = \dirname($dir);
    }
    return NULL;
  }

  /**
   * Scans a module's src for wiring that loads a class only via some module.
   *
   * Detects two mechanisms, both of which name the class with `::class` (which
   * never autoloads), so the class is loaded only when the wiring module runs:
   *  - `[$key] = X::class` for a key in DEFINITION_KEY_LOADERS (e.g. tmgmt);
   *  - `(new Definition(X::class))->…->setDecoratedService('<module>.…')`,
   *    a decorator of a service owned by <module>.
   *
   * @return array<string, string>
   *   Class FQN => the module that loads it.
   */
  private static function scannedLoaderMap(string $moduleRoot): array {
    if (isset(self::$scannedLoaderMapByRoot[$moduleRoot])) {
      return self::$scannedLoaderMapByRoot[$moduleRoot];
    }
    $map = [];
    $parser = (new ParserFactory())->createForHostVersion();
    $finder = new NodeFinder();
    $drupalRoot = self::findDrupalRoot($moduleRoot . '/x');
    $index = $drupalRoot !== NULL ? self::index($drupalRoot) : [];
    $needles = [...\array_keys(self::DEFINITION_KEY_LOADERS), 'setDecoratedService'];
    foreach (self::phpFilesMentioning($moduleRoot . '/src', $needles) as $file) {
      try {
        $ast = $parser->parse((string) \file_get_contents($file));
      }
      catch (\Throwable) {
        continue;
      }
      if ($ast === NULL) {
        continue;
      }
      $traverser = new NodeTraverser();
      $traverser->addVisitor(new NameResolver());
      $ast = $traverser->traverse($ast);
      // Mechanism 1: `[$key] = X::class`.
      foreach ($finder->findInstanceOf($ast, Assign::class) as $assign) {
        \assert($assign instanceof Assign);
        $var = $assign->var;
        if (!$var instanceof ArrayDimFetch || !$var->dim instanceof String_) {
          continue;
        }
        $module = self::DEFINITION_KEY_LOADERS[$var->dim->value] ?? NULL;
        if ($module !== NULL && ($class = self::classConstName($assign->expr)) !== NULL) {
          $map[$class] = $module;
        }
      }
      // Mechanism 2: decorator of a `<module>.…` service.
      foreach ($finder->findInstanceOf($ast, MethodCall::class) as $call) {
        \assert($call instanceof MethodCall);
        if (!$call->name instanceof Identifier || $call->name->toString() !== 'setDecoratedService') {
          continue;
        }
        $args = $call->getArgs();
        if (!isset($args[0]) || !$args[0]->value instanceof String_) {
          continue;
        }
        // The decorated service id is `<module>.<name>`; the decorator only
        // loads when that service — hence its module — is present.
        $module = \explode('.', $args[0]->value->value)[0];
        if (!isset($index[$module])) {
          continue;
        }
        $class = self::newDefinitionClass($call->var);
        if ($class !== NULL) {
          $map[$class] = $module;
        }
      }
    }
    // Mechanism 3: `<key>: '\Class'` declared statically in config schema.
    if (self::SCHEMA_KEY_LOADERS !== []) {
      foreach (\glob($moduleRoot . '/config/schema/*.yml') ?: [] as $file) {
        try {
          $data = Yaml::parseFile($file);
        }
        catch (\Throwable) {
          continue;
        }
        if (\is_array($data)) {
          self::collectSchemaKeyClasses($data, $map);
        }
      }
    }
    self::$scannedLoaderMapByRoot[$moduleRoot] = $map;
    return $map;
  }

  /**
   * Recursively collects SCHEMA_KEY_LOADERS class-name values into the map.
   *
   * @param array<mixed> $data
   * @param array<string, string> $map
   */
  private static function collectSchemaKeyClasses(array $data, array &$map): void {
    foreach ($data as $key => $value) {
      if (\is_string($key) && \is_string($value) && isset(self::SCHEMA_KEY_LOADERS[$key])) {
        $map[\ltrim($value, '\\')] = self::SCHEMA_KEY_LOADERS[$key];
      }
      elseif (\is_array($value)) {
        self::collectSchemaKeyClasses($value, $map);
      }
    }
  }

  /**
   * The FQN in an `X::class` expression, or NULL if the node is not one.
   */
  private static function classConstName(mixed $node): ?string {
    if ($node instanceof ClassConstFetch
      && $node->name instanceof Identifier
      && \strtolower($node->name->toString()) === 'class'
      && $node->class instanceof Name) {
      return \ltrim($node->class->toString(), '\\');
    }
    return NULL;
  }

  /**
   * Walks a method-call chain to a `new Definition(X::class)` and returns X.
   */
  private static function newDefinitionClass(mixed $node): ?string {
    // Descend the fluent chain: `(new …)->a()->b()` — the base is the `new`.
    while ($node instanceof MethodCall) {
      $node = $node->var;
    }
    if (!$node instanceof New_) {
      return NULL;
    }
    $args = $node->getArgs();
    return isset($args[0]) ? self::classConstName($args[0]->value) : NULL;
  }

  /**
   * PHP files under a directory whose source mentions any of the needles.
   *
   * @param list<string> $needles
   *
   * @return list<string>
   */
  private static function phpFilesMentioning(string $dir, array $needles): array {
    if (!\is_dir($dir)) {
      return [];
    }
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $c): bool => $c->isDir() || \str_ends_with($c->getFilename(), '.php'),
      ),
    );
    foreach ($iterator as $fileInfo) {
      \assert($fileInfo instanceof \SplFileInfo);
      if (!$fileInfo->isFile()) {
        continue;
      }
      $source = (string) \file_get_contents($fileInfo->getPathname());
      foreach ($needles as $needle) {
        if (\str_contains($source, $needle)) {
          $files[] = $fileInfo->getPathname();
          break;
        }
      }
    }
    return $files;
  }

  /**
   * @return array{available: array<string, true>, index: array<string, list<string>>, owning: string}|null
   */
  private static function resolve(string $file): ?array {
    // Test code is exempt: at test runtime every module namespace is
    // registered, so such references never fatal, and tests legitimately
    // reference any module.
    if (\str_contains($file, '/tests/') || \str_contains($file, '/Tests/')) {
      return NULL;
    }
    $root = self::findDrupalRoot($file);
    if ($root === NULL) {
      return NULL;
    }
    $owning = self::owningModule($file);
    if ($owning === NULL) {
      return NULL;
    }
    $index = self::index($root);
    $available = self::dependencyClosure($owning, $index);
    $available[$owning] = TRUE;
    foreach (self::optionalDependencies($file) as $module) {
      $available[$module] = TRUE;
    }
    return ['available' => $available, 'index' => $index, 'owning' => $owning];
  }

  /**
   * Walks up from a file to the Drupal root (the dir holding core/lib).
   */
  private static function findDrupalRoot(string $file): ?string {
    $dir = \dirname($file);
    while ($dir !== '' && $dir !== '/' && $dir !== \dirname($dir)) {
      if (\is_file($dir . '/core/lib/Drupal.php')) {
        return $dir;
      }
      $dir = \dirname($dir);
    }
    return NULL;
  }

  /**
   * Resolves the machine name of the module a file belongs to.
   */
  private static function owningModule(string $file): ?string {
    $dir = \dirname($file);
    while ($dir !== '' && $dir !== '/' && $dir !== \dirname($dir)) {
      $matches = \glob($dir . '/*.info.yml') ?: [];
      if ($matches !== []) {
        $names = \array_map(
          static fn (string $p): string => \basename($p, '.info.yml'),
          $matches,
        );
        $dirName = \basename($dir);
        if (\in_array($dirName, $names, TRUE)) {
          return $dirName;
        }
        return $names[0];
      }
      $dir = \dirname($dir);
    }
    return NULL;
  }

  /**
   * Builds (once per root) machine name => direct dependency machine names.
   *
   * @return array<string, list<string>>
   */
  private static function index(string $root): array {
    if (isset(self::$indexByRoot[$root])) {
      return self::$indexByRoot[$root];
    }
    $index = [];
    $scanDirs = [
      $root . '/core/modules',
      $root . '/core/profiles',
      $root . '/modules',
      $root . '/profiles',
    ];
    foreach ($scanDirs as $scanDir) {
      if (!\is_dir($scanDir)) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
          new \RecursiveDirectoryIterator($scanDir, \FilesystemIterator::SKIP_DOTS),
          static function (\SplFileInfo $current): bool {
            if ($current->isDir()) {
              return !\in_array($current->getFilename(), ['node_modules', 'vendor', '.git', 'dist', 'build'], TRUE);
            }
            return \str_ends_with($current->getFilename(), '.info.yml');
          },
        ),
      );
      foreach ($iterator as $fileInfo) {
        \assert($fileInfo instanceof \SplFileInfo);
        $machineName = \basename($fileInfo->getFilename(), '.info.yml');
        try {
          $info = Yaml::parseFile($fileInfo->getPathname());
        }
        catch (\Throwable) {
          continue;
        }
        $deps = [];
        foreach ((array) ($info['dependencies'] ?? []) as $dep) {
          if (\is_string($dep)) {
            $deps[] = self::normalizeDependency($dep);
          }
        }
        $index[$machineName] = $deps;
      }
    }
    self::$indexByRoot[$root] = $index;
    return $index;
  }

  /**
   * Normalizes a dependency string, e.g. "drupal:block (>=1.0)" => "block".
   */
  private static function normalizeDependency(string $dependency): string {
    $dependency = \trim(\preg_replace('/\(.*\)/', '', $dependency) ?? $dependency);
    if (($pos = \strpos($dependency, ':')) !== FALSE) {
      $dependency = \substr($dependency, $pos + 1);
    }
    return \trim($dependency);
  }

  /**
   * Computes the transitive dependency closure for a module.
   *
   * @param array<string, list<string>> $index
   *
   * @return array<string, true>
   */
  private static function dependencyClosure(string $module, array $index): array {
    $available = [];
    $queue = $index[$module] ?? [];
    while ($queue !== []) {
      $dep = \array_pop($queue);
      if (isset($available[$dep])) {
        continue;
      }
      $available[$dep] = TRUE;
      foreach ($index[$dep] ?? [] as $transitive) {
        if (!isset($available[$transitive])) {
          $queue[] = $transitive;
        }
      }
    }
    return $available;
  }

  /**
   * Reads `@drupalOptionalDependency <module>` markers from a file's source.
   *
   * @return list<string>
   */
  private static function optionalDependencies(string $file): array {
    $source = \is_file($file) ? (\file_get_contents($file) ?: '') : '';
    if (\preg_match_all('/@drupalOptionalDependency\s+([a-z0-9_]+)/', $source, $m)) {
      return \array_values(\array_unique($m[1]));
    }
    return [];
  }

}
