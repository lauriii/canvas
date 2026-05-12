<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks canvas ConfigTranslation form element methods as used.
 *
 * Canvas classes in Drupal\canvas\ConfigTranslation\ extend core's
 * FormElementBase (directly or via ListElement) and override
 * getTranslationBuild(), getSourceElement(), and getTranslationElement().
 * ConfigTranslationFormBase::buildForm() calls getTranslationBuild() on
 * ElementInterface-typed objects; the base implementation calls
 * $this->getSourceElement() and $this->getTranslationElement() via polymorphic
 * dispatch. ShipMonk cannot trace these dispatches.
 */
final class DrupalConfigTranslationUsageProvider extends ReflectionBasedMemberUsageProvider {

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    if (\in_array($method->getName(), ['getTranslationBuild', 'getSourceElement', 'getTranslationElement'], TRUE)
      && str_starts_with($method->getDeclaringClass()->getName(), 'Drupal\\canvas\\ConfigTranslation\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called via polymorphic dispatch from ConfigTranslationFormBase::buildForm(): %s::%s().', $method->getDeclaringClass()->getShortName(), $method->getName()),
      );
    }

    return NULL;
  }

}
