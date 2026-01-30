<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Access\ViewModeAccessCheck;
use Drupal\canvas\Config\ThemeSettingsDiscovery;
use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\EventSubscriber\DefaultContentSubscriber;
use Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint;
use Drupal\Core\Theme\Component\ComponentValidator;
use JsonSchema\Constraints\Factory;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class CanvasServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    \assert(is_array($modules));
    if (array_key_exists('media_library', $modules)) {
      $container->register('canvas.media_library.opener', MediaLibraryCanvasPropOpener::class)
        ->addArgument(new Reference(CanvasUiAccessCheck::class))
        ->addTag('media_library.opener');
    }

    // The ability to export default content was added in Drupal 11.3.
    if (class_exists(Exporter::class)) {
      $container->register(DefaultContentSubscriber::class)
        ->setClass(DefaultContentSubscriber::class)
        ->setAutowired(TRUE)
        ->addTag('event_subscriber');
    }

    // Register the theme settings discovery service.
    $container->register(ThemeSettingsDiscovery::class)
      ->setArguments([
        new Reference('theme.initialization'),
        '%app.root%',
        new Reference('cache.discovery'),
      ]);
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $validator = $container->getDefinition(ComponentValidator::class);
    $factory = $container->setDefinition(Factory::class, new Definition(Factory::class));
    $factory->addMethodCall('setConstraintClass', ['format', UriSchemeAwareFormatConstraint::class]);
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
    parent::alter($container);
  }

}
