<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\custom_elements\CustomElement;
use Drupal\custom_elements\CustomElementGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Exception\InvalidParameterException;

/**
 * Renders routed content entities from Canvas auto-saves when available.
 */
final class AutoSavePreviewController {

  public function __construct(
    #[Autowire(service: 'current_route_match')]
    private readonly RouteMatchInterface $currentRouteMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'custom_elements.generator')]
    private readonly CustomElementGenerator $customElementGenerator,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Renders the routed entity's Canvas auto-save in Custom Elements format.
   */
  public function entityView(string $view_mode = 'full'): CustomElement {
    $parameters = $this->currentRouteMatch->getRawParameters()->all();
    $entity_type_id = \array_key_first($parameters);
    if ($entity_type_id === NULL || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      throw new \LogicException('No entity type found, but required.');
    }

    $stored_entity = $this->currentRouteMatch->getParameter($entity_type_id);
    if (!$stored_entity instanceof ContentEntityInterface) {
      throw new InvalidParameterException('Missing content entity parameter.');
    }

    $auto_save = $this->autoSaveManager->getAutoSaveEntityForPreview($stored_entity);
    $entity = $stored_entity;
    $access = NULL;
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof ContentEntityInterface);
      $entity = $auto_save->entity;
      $access = $entity->access('view', $this->currentUser->getAccount(), TRUE);
      if (!$access->isAllowed()) {
        $cacheability = (new CacheableMetadata())
          ->addCacheableDependency($auto_save)
          ->addCacheableDependency($access)
          ->addCacheContexts(['oauth2_scopes']);
        throw new CacheableAccessDeniedHttpException($cacheability, 'The auto-saved entity is not viewable.');
      }
    }

    $custom_element = $this->customElementGenerator->generate(
      $entity,
      $view_mode,
      account: $this->currentUser->getAccount(),
    );
    $custom_element->addCacheTags([$entity_type_id . '_view']);
    $custom_element->addCacheableDependency($auto_save);
    $custom_element->addCacheContexts(['oauth2_scopes']);
    if ($access !== NULL) {
      $custom_element->addCacheableDependency($access);
    }
    return $custom_element;
  }

}
