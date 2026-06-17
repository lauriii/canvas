<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks canvas methods called by Drupal contrib plugin systems.
 *
 * Contrib modules that define plugin systems dispatch plugin methods in ways
 * ShipMonk cannot trace. This provider covers canvas classes that implement
 * contrib plugin interfaces.
 *
 * Covered patterns:
 *
 * 1. Search API Processor plugins: canvas classes in
 *    Drupal\canvas\Plugin\search_api\ implement ProcessorInterface;
 *    search_api calls methods like supportsIndex() and
 *    getPropertyDefinitions() via plugin dispatch.
 *
 * 2. TMGMT Config Processor plugins: canvas classes in Drupal\canvas\Tmgmt\
 *    registered via `tmgmt_config_processor` in config schema definitions;
 *    tmgmt_config calls extractTranslatables() via plugin dispatch.
 *
 * 3. TMGMT Content Field Processor plugins: canvas classes in
 *    Drupal\canvas\Tmgmt\ registered via `tmgmt_field_processor` in
 *    hook_field_info_alter(); tmgmt_content calls extractTranslatableData()
 *    and setTranslations() via plugin dispatch.
 */
final class ContribPluginsUsageProvider extends ReflectionBasedMemberUsageProvider {

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    if (!$method->isConstructor()
      && $method->isPublic()
      && !str_starts_with($method->getName(), '__')
      && str_starts_with($method->getDeclaringClass()->getName(), 'Drupal\\canvas\\Plugin\\search_api\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called by search_api plugin dispatch: %s::%s().', $method->getDeclaringClass()->getShortName(), $method->getName()),
      );
    }

    if ($method->getName() === 'extractTranslatables'
      && str_starts_with($method->getDeclaringClass()->getName(), 'Drupal\\canvas\\Tmgmt\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called by tmgmt_config plugin dispatch via tmgmt_config_processor config schema key: %s::extractTranslatables().', $method->getDeclaringClass()->getShortName()),
      );
    }

    if (\in_array($method->getName(), ['extractTranslatableData', 'setTranslations'], TRUE)
      && str_starts_with($method->getDeclaringClass()->getName(), 'Drupal\\canvas\\Tmgmt\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called by tmgmt_content field processor dispatch via tmgmt_field_processor field info key: %s::%s().', $method->getDeclaringClass()->getShortName(), $method->getName()),
      );
    }

    return NULL;
  }

}
