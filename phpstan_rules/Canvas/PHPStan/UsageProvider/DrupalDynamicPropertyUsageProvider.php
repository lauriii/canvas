<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\Core\Entity\EntityBase;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\Validator\Constraint;

/**
 * Marks properties written by dynamic constructor assignment as written.
 *
 * Some base classes write arbitrary properties via:
 *   foreach ($values as $key => $value) { $this->$key = $value; }
 * ShipMonk cannot trace this dynamic assignment pattern, causing false
 * `shipmonk.deadProperty.neverWritten` reports for all properties declared
 * in subclasses of those base classes.
 *
 * Covered base classes:
 * - Drupal\Core\Entity\EntityBase (entity config/content properties)
 * - Symfony\Component\Validator\Constraint (validation constraint options)
 *
 * PHP #[Attribute] class properties are also marked as read: they are set via
 * constructor named arguments and read via $attr->property after instantiation
 * through ReflectionAttribute::newInstance() — ShipMonk cannot trace this.
 */
final class DrupalDynamicPropertyUsageProvider extends ReflectionBasedMemberUsageProvider {

  private const DYNAMIC_WRITE_BASE_CLASSES = [
    EntityBase::class,
    Constraint::class,
  ];

  protected function shouldMarkPropertyAsRead(\ReflectionProperty $property): ?VirtualUsageData {
    $declaringClass = $property->getDeclaringClass();

    // PHP #[Attribute] class properties are read via $attr->property after
    // ReflectionAttribute::newInstance() — ShipMonk cannot trace this dispatch.
    if (!empty($declaringClass->getAttributes(\Attribute::class))) {
      return VirtualUsageData::withNote(
        \sprintf('Read via ReflectionAttribute::newInstance() on #[Attribute] class %s.', $declaringClass->getShortName()),
      );
    }

    // EntityBase and Constraint subclass properties are read by parent-class
    // getter methods (e.g. EntityBase::id() returns $this->id) or by validator
    // classes accessing $constraint->property. ShipMonk cannot trace reads of
    // a subclass-declared property from a parent-class method or sibling class.
    foreach (self::DYNAMIC_WRITE_BASE_CLASSES as $baseClass) {
      if ($declaringClass->isSubclassOf($baseClass)) {
        return VirtualUsageData::withNote(
          \sprintf('Read by %s via getter methods or sibling validator class.', $baseClass),
        );
      }
    }

    return NULL;
  }

  protected function shouldMarkPropertyAsWritten(\ReflectionProperty $property): ?VirtualUsageData {
    $declaringClass = $property->getDeclaringClass();

    foreach (self::DYNAMIC_WRITE_BASE_CLASSES as $baseClass) {
      if ($declaringClass->isSubclassOf($baseClass)) {
        return VirtualUsageData::withNote(
          \sprintf('Written by %s::__construct() via dynamic $this->$key = $value assignment.', $baseClass),
        );
      }
    }

    return NULL;
  }

}
