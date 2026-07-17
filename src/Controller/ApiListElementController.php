<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\ListBuilder\ListElementSettingsValidator;
use Drupal\canvas\ListBuilder\ListQueryExecutor;
use Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves subsequent pages of a List element.
 *
 * The route accepts only the list's identity: the entity storing the
 * component tree, the component instance UUID, and an offset. Every setting
 * that shapes the result set is read from the stored, validated instance
 * inputs, so the endpoint cannot be coerced into arbitrary queries. The
 * response carries full cache metadata and is cacheable per offset.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent
 * @internal
 */
final class ApiListElementController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ComponentTreeLoader $componentTreeLoader,
    private readonly ListQueryExecutor $queryExecutor,
    private readonly ListElementSettingsValidator $settingsValidator,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ComponentTreeLoader::class),
      $container->get(ListQueryExecutor::class),
      $container->get(ListElementSettingsValidator::class),
      $container->get(RendererInterface::class),
    );
  }

  public function __invoke(string $entity_type, EntityInterface $entity, string $component_instance_uuid, Request $request): CacheableJsonResponse {
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($entity)
      ->addCacheContexts(['url.query_args:offset']);

    self::checkAccess($entity, $cacheability);

    if (!$entity instanceof ComponentTreeEntityInterface && !$entity instanceof FieldableEntityInterface) {
      throw new CacheableNotFoundHttpException($cacheability, 'This entity cannot store a component tree.');
    }

    // Only the live, stored component tree is served: draft (auto-save) state
    // is editor preview territory.
    try {
      $tree = $this->componentTreeLoader->load($entity);
    }
    catch (\LogicException) {
      throw new CacheableNotFoundHttpException($cacheability, 'This entity does not store a component tree.');
    }
    $item = $tree->getComponentTreeItemByUuid($component_instance_uuid);
    $source = $item?->getComponent()?->getComponentSource();
    if ($item === NULL || !$source instanceof ListComponent) {
      throw new CacheableNotFoundHttpException($cacheability, 'No List element with this UUID exists in this entity.');
    }

    $settings = $item->getInputs() ?? [];
    if (\count($this->settingsValidator->validate($settings)) > 0 || $settings['pagination']['mode'] === 'none') {
      throw new CacheableNotFoundHttpException($cacheability, 'This List element is not paginated.');
    }

    $offset = $request->query->getInt('offset');
    if ($offset < 1) {
      throw new CacheableNotFoundHttpException($cacheability, 'The offset must be a positive integer.');
    }

    $result = $this->queryExecutor->execute($settings, $offset);
    $cacheability->addCacheableDependency($result->cacheability);

    $template_subtree = $settings['display']['mode'] === 'item_template'
      ? ListComponent::extractTemplateSubtree($tree, $component_instance_uuid)
      : [];
    $build = $source->renderItems($settings, $result->entities, $template_subtree);
    $context = new RenderContext();
    $html = (string) $this->renderer->executeInRenderContext($context, fn (): string => (string) $this->renderer->render($build));
    if (!$context->isEmpty()) {
      $cacheability->addCacheableDependency($context->pop());
    }

    $response = new CacheableJsonResponse([
      'html' => $html,
      'more' => $result->hasMore,
      // Clients advance by consumed query rows, not rendered items: the
      // render-time access guard may drop entities, and pages must not
      // overlap.
      'next_offset' => $offset + $result->consumed,
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Checks that the requester may see this entity's rendered output.
   */
  private static function checkAccess(EntityInterface $entity, CacheableMetadata $cacheability): void {
    // Component trees stored in config entities (global regions, content
    // templates) render for every visitor; an enabled entity is public.
    if ($entity instanceof ComponentTreeEntityInterface && $entity instanceof ConfigEntityInterface) {
      if (!$entity->status()) {
        throw new CacheableAccessDeniedHttpException($cacheability);
      }
      return;
    }
    if ($entity instanceof FieldableEntityInterface) {
      $access = $entity->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        throw new CacheableAccessDeniedHttpException($cacheability);
      }
      return;
    }
    throw new CacheableAccessDeniedHttpException($cacheability);
  }

}
