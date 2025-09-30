<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\canvas\Access\CanvasUiAccessCheck;
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
    assert(is_array($modules));
    if (array_key_exists('media_library', $modules)) {
      $container->register('canvas.media_library.opener', MediaLibraryCanvasPropOpener::class)
        ->addArgument(new Reference(CanvasUiAccessCheck::class))
        ->addTag('media_library.opener');
    }
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
    parent::alter($container);
  }

}
