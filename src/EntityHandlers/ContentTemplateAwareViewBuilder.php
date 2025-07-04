<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EntityHandlers;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Decorates a view builder so it can take advantage of content templates.
 *
 * @see \Drupal\experience_builder\Hook\ContentTemplateHooks::entityTypeAlter()
 */
final class ContentTemplateAwareViewBuilder extends EntityViewBuilder {

  /**
   * The key under which we store the original view builder class name.
   *
   * @var string
   */
  public const string DECORATED_HANDLER_KEY = 'xb_original_view_builder';

  /**
   * The decorated view builder.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  private EntityViewBuilderInterface $decorated;

  /**
   * The component tree loader service.
   *
   * @var \Drupal\experience_builder\Storage\ComponentTreeLoader
   */
  private ComponentTreeLoader $componentTreeLoader;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);

    $original_view_builder = $container->get(EntityTypeManagerInterface::class)
      ->getHandler($entity_type->id(), self::DECORATED_HANDLER_KEY);
    assert($original_view_builder instanceof EntityViewBuilderInterface);
    $instance->decorated = $original_view_builder;

    $instance->componentTreeLoader = $container->get(ComponentTreeLoader::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $defaults = parent::getBuildDefaults($entity, $view_mode);
    $keys = NestedArray::getValue($defaults, ['#cache', 'keys']);
    if ($keys !== NULL) {
      // This entity has render caching, so add a cache key indicating whether
      // or not it's opted into XB.
      \assert($entity instanceof FieldableEntityInterface);
      try {
        $this->componentTreeLoader->getXbFieldName($entity);
        $keys[] = 'with-xb';
        // We don't want to use the default theme template as preprocess functions
        // etc might make assumptions about various fields being present.
        // @see template_preprocess_node.
        // @todo Remove in https://www.drupal.org/i/3534128 when https://drupal.org/i/3524738 is fixed
        unset($defaults['#theme']);
      }
      catch (\LogicException) {
        $keys[] = 'without-xb';
      }
      finally {
        NestedArray::setValue($defaults, ['#cache', 'keys'], $keys);
      }
    }
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    foreach ($entities as $entity) {
      $bundle = $entity->bundle();

      // We already have a template which will render this entity.
      if ($displays[$bundle] instanceof ContentTemplate) {
        continue;
      }

      \assert($entity instanceof FieldableEntityInterface);
      try {
        $this->componentTreeLoader->getXbFieldName($entity);
      }
      catch (\LogicException) {
        // This entity isn't opted into XB, so there's nothing else to do.
        continue;
      }

      // See if we can find a template for this entity, in the requested view
      // mode. If we do, use that template to render the entity.
      $template = ContentTemplate::loadForEntity($entity, $view_mode);
      if ($template) {
        $displays[$bundle] = $template;
      }
    }
    // Call the decorated buildComponents() method, just like our parent method
    // would do, to stay as close as possible to the original execution flow.
    // This means `hook_entity_prepare_view()` will still be invoked. Then,
    // `ContentTemplate::buildMultiple()` will be called for the entities that
    // are being rendered by XB, which in turn will call
    // `ComponentTreeHydrated::toRenderable()`.
    // @see \Drupal\Core\Entity\EntityViewBuilder::buildComponents()
    // @see \Drupal\experience_builder\Entity\ContentTemplate::buildMultiple()
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated::toRenderable()
    $this->decorated->buildComponents($build, $entities, $displays, $view_mode);
  }

}
