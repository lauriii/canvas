<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\Access\ViewModeAccessCheck;
use Drupal\canvas\Config\ThemeSettingsDiscovery;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\CoreBugFix\ConfigEntityQueryFactory;
use Drupal\canvas\CoreBugFix\TypedConfigManagerWithCachePollutionFix;
use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\canvas\Validation\JitSafeRegexValidator;
use Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint;
use Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint;
use Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Theme\Component\ComponentValidator;
use JsonSchema\Constraints\Factory;
use JsonSchema\DraftIdentifiers;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Validator\Constraints\RegexValidator;

class CanvasServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    \assert(\is_array($modules));
    if (\array_key_exists('media_library', $modules)) {
      $container->register('canvas.media_library.opener', MediaLibraryCanvasPropOpener::class)
        ->addArgument(new Reference(CanvasUiAccessCheck::class))
        ->addTag('media_library.opener');
    }

    // Register the theme settings discovery service.
    $container->register(ThemeSettingsDiscovery::class)
      ->setArguments([
        new Reference('theme.initialization'),
        '%app.root%',
        new Reference('cache.discovery'),
      ]);

    // Override Symfony's Regex validator so the `/(.|\r?\n)*/` pattern is not
    // evaluated: it matches every string, but on long values it exhausts the
    // PCRE JIT stack and is then reported as a failed match. Drupal resolves
    // constraint validators by class name via the class resolver, and
    // RegexConstraint::validatedBy() returns the class name with a leading
    // backslash, so the service must be registered under that id — hence the
    // `'\\' .` prefix that `::class` omits. It must be non-shared, like all
    // constraint validators.
    // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\RegexConstraint::validatedBy()
    // @see \Drupal\Core\Validation\ConstraintValidatorFactory::getInstance()
    // @see \Drupal\canvas\Validation\JitSafeRegexValidator
    $container->register('\\' . RegexValidator::class, JitSafeRegexValidator::class)
      ->setPublic(TRUE)
      ->setShared(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $validator = $container->getDefinition(ComponentValidator::class);
    $factory = $container->setDefinition(Factory::class, new Definition(Factory::class));
    // Align the PHP validator with the Ajv default in the UI: both use JSON
    // Schema draft-07.
    // @see ui/src/utils/ajv.ts
    $factory->addMethodCall('setDefaultDialect', [DraftIdentifiers::DRAFT_7]);
    $factory->addMethodCall('setConstraintClass', ['format', UriSchemeAwareFormatConstraint::class]);
    $factory->addMethodCall('setConstraintClass', ['object', ContentEntityReferenceObjectConstraint::class]);
    $factory->addMethodCall('setConstraintClass', ['string', CanvasColorStringConstraint::class]);
    $container->setDefinition(Validator::class, new Definition(Validator::class, [
      new Reference(Factory::class),
    ]));
    // Clear existing calls.
    $validator->setMethodCalls();
    $validator->addMethodCall(
      'setValidator',
      [new Reference(Validator::class)]
    );

    // @todo Remove this once Canvas relies on a Drupal core version that includes https://www.drupal.org/i/3352063.
    $container->getDefinition('plugin.manager.sdc')
      ->setClass(ComponentPluginManager::class);
    // @todo Remove in clean-up follow-up; minimize non-essential changes.
    $container->setAlias(ComponentPluginManager::class, 'plugin.manager.sdc');

    // Decorate the Field UI view mode access check to add content template
    // access logic, ensuring safe handling when the Field UI module is not
    // enabled.
    if ($container->hasDefinition('access_check.field_ui.view_mode')) {
      $definition = (new Definition(ViewModeAccessCheck::class))
        ->setAutowired(TRUE)
        ->setDecoratedService('access_check.field_ui.view_mode');
      $container->setDefinition('canvas.access_check.field_ui.view_mode', $definition);
    }

    // Decorate the content translation synchronizer to perform component tree
    // field type-specific content translation synchronization.
    if ($container->hasDefinition('content_translation.synchronizer')) {
      $definition = (new Definition(ComponentTreeFieldSymmetricalTranslationSynchronizer::class))
        ->setAutowired(TRUE)
        ->setDecoratedService('content_translation.synchronizer');
      $container->setDefinition('canvas.content_translation.synchronizer', $definition);
    }

    // Alter the config entity query factory to fix a bug with sorting by
    // multiple config entity properties.
    // @todo Remove this once Canvas relies on a Drupal core version that includes https://www.drupal.org/i/2862699.
    $container->getDefinition('entity.query.config')
      ->setClass(ConfigEntityQueryFactory::class);

    // Alter the typed config manager to fix a cache pollution bug.
    // @todo Remove this once Canvas relies on a Drupal core version that includes https://www.drupal.org/i/3400181.
    $container->getDefinition('config.typed')
      ->setClass(TypedConfigManagerWithCachePollutionFix::class);

    parent::alter($container);
  }

}
