<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\experience_builder\Access\XbUiAccessCheck;
use Drupal\experience_builder\Validation\UriFormatAwareFormatConstraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ExperienceBuilderServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    assert(is_array($modules));
    if (array_key_exists('media_library', $modules)) {
      $container->register('experience_builder.media_library.opener', MediaLibraryXbPropOpener::class)
        ->addArgument(new Reference(XbUiAccessCheck::class))
        ->addTag('media_library.opener');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // @todo Remove this when we require version 6 of justinrainbow/json-schema
    //   https://www.drupal.org/project/drupal/issues/3516348
    $validator = $container->getDefinition(ComponentValidator::class);
    $factory = $container->setDefinition(Factory::class, new Definition(Factory::class));
    $factory->addMethodCall('setConstraintClass', ['format', UriFormatAwareFormatConstraint::class]);
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
