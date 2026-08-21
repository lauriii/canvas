<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\PreviewRenderableInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists self-rendering template config entities for the editor's Templates UI.
 *
 * One group per config entity type whose class implements
 * PreviewRenderableInterface, listing the entities the current user may edit.
 * The client decides where each group surfaces; entity types with a dedicated
 * surface of their own (patterns) are its call to omit.
 */
final class ApiSelfRenderingTemplatesController extends ApiControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(EntityTypeManagerInterface::class));
  }

  public function list(): CacheableJsonResponse {
    $groups = [];
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['user.permissions']);
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!\is_a($definition->getClass(), PreviewRenderableInterface::class, TRUE)) {
        continue;
      }
      $cacheability->addCacheTags($definition->getListCacheTags());
      $templates = [];
      foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple() as $entity) {
        $access = $entity->access('update', NULL, TRUE);
        $cacheability->addCacheableDependency($access);
        if (!$access->isAllowed()) {
          continue;
        }
        $templates[] = [
          'id' => (string) $entity->id(),
          'label' => (string) $entity->label(),
        ];
      }
      if ($templates === []) {
        continue;
      }
      \usort($templates, static fn (array $a, array $b): int => \strcasecmp($a['label'], $b['label']));
      $groups[$entity_type_id] = [
        'label' => (string) $definition->getCollectionLabel(),
        'templates' => $templates,
      ];
    }
    return (new CacheableJsonResponse($groups))->addCacheableDependency($cacheability);
  }

}
