<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Shared check: is a class-name reference into an undeclared module?
 *
 * Used by the hard-reference rules to judge a resolved class name against the
 * analysed module's dependency closure.
 *
 * @see \Canvas\PHPStan\Rules\ModuleDependencyResolver
 */
final class UndeclaredModuleReference {

  /**
   * @param array<string, true> $guardedModules
   *   Modules whose `moduleHandler->moduleExists()` guard is in effect at the
   *   reference, hence safe to reference there.
   *
   * @return list<IdentifierRuleError>
   *   One error when the name resolves to a class from a module outside the
   *   analysed module's dependency closure; empty otherwise.
   */
  public static function check(Name $name, Scope $scope, array $guardedModules = []): array {
    // self/static/parent are never cross-module.
    if ($name->isSpecialClassName()) {
      return [];
    }
    $context = ModuleDependencyResolver::forFile($scope->getFile());
    if ($context === NULL) {
      return [];
    }
    $fqn = $scope->resolveName($name);
    $module = ModuleDependencyResolver::moduleForClass($fqn);
    if ($module === NULL) {
      return [];
    }
    // A reference the module may make: own module or dependency closure.
    if (isset($context['available'][$module])) {
      return [];
    }
    // A live `moduleExists('X')` guard dominates this reference.
    if (isset($guardedModules[$module])) {
      return [];
    }
    // A plugin in `…\Plugin\<module>\<type>\…` is discovered and instantiated
    // only by <module>'s plugin manager (and plugins are never `new`'d
    // directly), so it is never autoloaded when <module> is absent — any
    // reference from it into <module> is safe by construction. This is sound
    // in a way generic inheritance is not: a class that merely `extends` an
    // optional module's base can still be instantiated directly by
    // always-loaded code, which would fatal.
    if (self::pluginOwnerModule($scope) === $module) {
      return [];
    }
    // A class detected as loaded only by <module> via a known non-plugin
    // wiring (config_translation `form_element_class`, tmgmt processor keys, a
    // gated service decoration…) is likewise safe.
    $currentClass = $scope->getClassReflection();
    if ($currentClass !== NULL && ModuleDependencyResolver::conditionalLoaderModule($currentClass->getName(), $scope->getFile()) === $module) {
      return [];
    }
    // Only report modules that actually exist on disk; an unknown first
    // segment is more likely a namespace we do not model than a missing dep.
    if (!isset($context['index'][$module])) {
      return [];
    }
    return [
      RuleErrorBuilder::message(\sprintf(
        "Module '%s' hard-references %s from module '%s', which is not in its dependency closure — a fatal on any site without '%s'. Declare a dependency on '%s' in %s.info.yml, or, if '%s' is a deliberately-optional integration (the reference is guarded by moduleHandler->moduleExists('%s'), or reached only via a conditionally-registered service or plugin), add a '@drupalOptionalDependency %s' class-docblock annotation.",
        $context['owning'],
        $fqn,
        $module,
        $module,
        $module,
        $context['owning'],
        $module,
        $module,
        $module,
      ))
        ->identifier('canvas.undeclaredModuleDependency')
        ->line($name->getStartLine())
        ->build(),
    ];
  }

  /**
   * The module owning the plugin type of the current class, if it is a plugin.
   *
   * A class named `Drupal\<provider>\Plugin\<owner>\<type>\<Name>` is a plugin
   * of the type <owner> defines; <owner>'s plugin manager is the only thing
   * that discovers and instantiates it.
   */
  private static function pluginOwnerModule(Scope $scope): ?string {
    $class = $scope->getClassReflection();
    if ($class === NULL) {
      return NULL;
    }
    $parts = \explode('\\', $class->getName());
    $pluginIndex = \array_search('Plugin', $parts, TRUE);
    if ($pluginIndex === FALSE) {
      return NULL;
    }
    return $parts[$pluginIndex + 1] ?? NULL;
  }

}
