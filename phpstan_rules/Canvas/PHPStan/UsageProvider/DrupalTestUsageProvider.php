<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\BrowserTestBase;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks Drupal test properties and methods as used.
 *
 * Three false-positive patterns are covered:
 *
 * 1. neverRead: properties declared in subclasses but read by a parent test
 *    base class — either via static::$property (late static binding) or via
 *    $this->property (instance override of a parent declaration).
 *
 * 2. neverWritten: properties declared without a default in an abstract test
 *    base class and read there, but written only in concrete subclasses via
 *    property redeclaration with a default value.
 *
 * 3. deadMethod: methods called by Drupal's test framework via $this->method()
 *    dynamic dispatch from a parent class. ShipMonk does not trace through
 *    polymorphic dispatch, so overriding implementations appear unused.
 */
final class DrupalTestUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Maps property name → base class(es) that read it from subclasses.
   *
   * Covers both static LSB reads ($class::$prop / static::$prop) and instance
   * reads ($this->prop) where the subclass overrides a parent declaration.
   */
  private const PROPERTY_TO_BASE_CLASSES = [
    // Drupal\KernelTests\KernelTestBase and Drupal\Tests\BrowserTestBase read
    // these via static::$modules / static::$defaultTheme.
    'modules' => [KernelTestBase::class, BrowserTestBase::class],
    'defaultTheme' => [KernelTestBase::class, BrowserTestBase::class],
    // KernelTestBase reads via $class::$configSchemaCheckerExclusions.
    'configSchemaCheckerExclusions' => [KernelTestBase::class],
    // FunctionalTestSetupTrait (used by BrowserTestBase) reads via
    // $this->profile.
    'profile' => [BrowserTestBase::class],
    // KernelTestBase and FunctionalTestSetupTrait read via
    // $this->usesSuperUserAccessPolicy.
    'usesSuperUserAccessPolicy' => [KernelTestBase::class, BrowserTestBase::class],
    // ConfigEntityValidationTestBase reads these via $class::$prop.
    'propertiesWithRequiredKeys' => [ConfigEntityValidationTestBase::class],
    'propertiesWithOptionalValues' => [ConfigEntityValidationTestBase::class],
    // ConfigEntityValidationTestBase reads via $this->hasLabel.
    'hasLabel' => [ConfigEntityValidationTestBase::class],
  ];

  /**
   * Maps method name → base class(es) that call it via dynamic $this dispatch.
   *
   * Each base class calls $this->method() in setUp() or a similar lifecycle
   * hook, relying on subclasses to override it. ShipMonk does not trace
   * polymorphic dispatch, so the overriding implementations appear unused.
   */
  private const METHOD_TO_BASE_CLASSES = [
    // UpdatePathTestBase::setUp() calls $this->setDatabaseDumpFiles(), which is
    // abstract there and must be implemented by every update path test.
    'setDatabaseDumpFiles' => [UpdatePathTestBase::class],
    // BrowserTestBase::setUp() calls $this->registerSessions(); the base
    // provides an empty default, subclasses override to add sessions.
    'registerSessions' => [BrowserTestBase::class],
    // KernelTestBase::bootKernel() calls $this->setUpFilesystem(); the base
    // provides a default, subclasses override to configure the virtual FS.
    'setUpFilesystem' => [KernelTestBase::class],
  ];

  protected function shouldMarkPropertyAsRead(\ReflectionProperty $property): ?VirtualUsageData {
    $baseClasses = self::PROPERTY_TO_BASE_CLASSES[$property->getName()] ?? NULL;
    if ($baseClasses === NULL) {
      return NULL;
    }

    $declaringClass = $property->getDeclaringClass();
    foreach ($baseClasses as $baseClass) {
      if ($declaringClass->isSubclassOf($baseClass)) {
        return VirtualUsageData::withNote(
          \sprintf('Read by %s from $%s declared in subclass.', $baseClass, $property->getName()),
        );
      }
    }

    return NULL;
  }

  protected function shouldMarkPropertyAsWritten(\ReflectionProperty $property): ?VirtualUsageData {
    // Abstract test base classes declare properties without a default value,
    // read them in base methods, and rely on concrete subclasses to supply the
    // value via property redeclaration (e.g. `protected string $foo = 'bar';`).
    // ShipMonk attributes that write to the subclass property, leaving the
    // abstract base property with zero writes — a false positive.
    $declaringClass = $property->getDeclaringClass();
    if (!$declaringClass->isAbstract() || $property->hasDefaultValue()) {
      return NULL;
    }

    foreach ([KernelTestBase::class, BrowserTestBase::class] as $baseClass) {
      if ($declaringClass->isSubclassOf($baseClass)) {
        return VirtualUsageData::withNote(
          \sprintf('Written by concrete subclasses of %s via property redeclaration.', $declaringClass->getName()),
        );
      }
    }

    return NULL;
  }

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $declaringClass = $method->getDeclaringClass();

    $baseClasses = self::METHOD_TO_BASE_CLASSES[$method->getName()] ?? NULL;
    if ($baseClasses !== NULL) {
      foreach ($baseClasses as $baseClass) {
        if ($declaringClass->isSubclassOf($baseClass)) {
          return VirtualUsageData::withNote(
            \sprintf('Called by %s via $this->%s().', $baseClass, $method->getName()),
          );
        }
      }
    }

    // Test subclass overrides ancestor method: parent calls $this->method() and
    // polymorphic dispatch reaches this override. ShipMonk only records the
    // call on the ancestor, leaving the override with zero calls.
    if ($method->getName() !== '__construct'
      && $this->isDrupalTestSubclass($declaringClass)
      && $this->methodExistsInAncestor($method)
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Override of ancestor method; called via $this->%s() polymorphic dispatch.', $method->getName()),
      );
    }

    // Test double overrides production class: production code calls the
    // ancestor method, polymorphic dispatch reaches this test-namespace
    // override at runtime.
    if ($this->isInTestNamespace($declaringClass->getName())
      && $this->methodExistsInNonTestAncestor($method)
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Test double overrides non-test ancestor; called via polymorphic dispatch of %s().', $method->getName()),
      );
    }

    // PHPUnit calls public test methods via reflection (naming convention or
    // #[Test] attribute). ShipMonk cannot trace this dispatch, so any call
    // made from inside a test method appears transitively dead.
    if ($method->isPublic()
      && str_starts_with($method->getName(), 'test')
      && $this->isDrupalTestSubclass($declaringClass)
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called by PHPUnit via reflection as a test method: %s::%s().', $declaringClass->getShortName(), $method->getName()),
      );
    }

    return NULL;
  }

  private function isDrupalTestSubclass(\ReflectionClass $class): bool {
    return $class->isSubclassOf(KernelTestBase::class)
      || $class->isSubclassOf(BrowserTestBase::class);
  }

  private function isInTestNamespace(string $className): bool {
    return str_starts_with($className, 'Drupal\\Tests\\')
      || str_starts_with($className, 'Drupal\\KernelTests\\')
      || str_starts_with($className, 'Drupal\\FunctionalTests\\')
      || str_starts_with($className, 'Drupal\\FunctionalJavascriptTests\\');
  }

  private function methodExistsInAncestor(\ReflectionMethod $method): bool {
    $class = $method->getDeclaringClass()->getParentClass();
    while ($class !== FALSE) {
      if ($class->hasMethod($method->getName())) {
        return TRUE;
      }
      $class = $class->getParentClass();
    }
    return FALSE;
  }

  private function methodExistsInNonTestAncestor(\ReflectionMethod $method): bool {
    $class = $method->getDeclaringClass()->getParentClass();
    while ($class !== FALSE) {
      if ($class->hasMethod($method->getName())
        && !$this->isInTestNamespace($class->getName())
      ) {
        return TRUE;
      }
      $class = $class->getParentClass();
    }
    return FALSE;
  }

}
