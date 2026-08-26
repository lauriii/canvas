<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Audit\ColorAudit;
use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiUsageControllers extends ApiControllerBase {

  /**
   * The maximum number of results to return per page.
   */
  public const int MAX_PER_PAGE = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentAudit $componentAudit,
    private readonly ColorAudit $colorAudit,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * Checks if a specific component is in use and returns as a boolean.
   */
  public function component(Component $component): JsonResponse {
    return new JsonResponse(
      data: $this->componentAudit->hasUsages($component),
      status: Response::HTTP_OK
    );
  }

  /**
   * Checks if a component is in use and returns details of where it is used.
   *
   * @todo Add the ability to request the details for a specific version of a `Component`, rather than only the active version of the `Component`.
   * @todo Do not list every revision it is used in, but only the entities it is used in, along with the oldest and newest revision it occurs in, but not a unique array item per revision
   * @todo Add "editUrl" for every listed entity.
   */
  public function componentDetails(Component $component): JsonResponse {
    if ($this->componentAudit->hasUsages($component)) {
      $dependents = [];
      if ($content_dependents = $this->componentAudit->getContentRevisionsUsingAuditTarget($component, [$component->getLoadedVersion()])) {
        foreach ($content_dependents as $content_dependent) {
          $dependents['content'][] = [
            'title' => $content_dependent->label(),
            'type' => $content_dependent->getEntityTypeId(),
            'bundle' => $content_dependent->bundle(),
            'id' => $content_dependent->id(),
            'revision_id' => $content_dependent->getRevisionId(),
          ];
        }
      }

      $config_entity_types = \array_keys(\array_filter(
        $this->entityTypeManager->getDefinitions(),
        static fn (EntityTypeInterface $type): bool => $type instanceof ConfigEntityTypeInterface && $type->entityClassImplements(ComponentTreeEntityInterface::class)
      ));
      foreach ($config_entity_types as $config_entity_type) {
        $config_dependents = $this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, $config_entity_type);
        if ($config_dependents) {
          foreach ($config_dependents as $config_dependent) {
            $dependents[$config_entity_type][] = [
              'title' => $config_dependent->label(),
              'id' => $config_dependent->id(),
            ];
          }
        }
      }

      return new JsonResponse(
        data: $dependents,
        status: Response::HTTP_OK
      );
    }
    return new JsonResponse(data: NULL, status: Response::HTTP_OK);
  }

  /**
   * Returns a paginated list of components and whether they are in use.
   */
  public function componentsList(Request $request): JsonResponse {
    $storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);
    $entity_query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->pager(self::MAX_PER_PAGE);
    $entities = $entity_query->execute();

    $usage_data = [];
    foreach ($entities as $entity) {
      $config_entity = $storage->load($entity);
      if ($config_entity instanceof Component) {
        $usage_data[$entity] = $this->componentAudit->hasUsages($config_entity);
      }
    }

    $base_url = Url::fromRoute('canvas.api.usage.component.list');
    $pager = $this->pagerManager->getPager();
    \assert(!\is_null($pager));
    $current_page = $pager->getCurrentPage();
    return new JsonResponse(
      data: [
        'data' => $usage_data,
        'links' => [
          'prev' => $current_page === 0
            ? NULL
            : $base_url->setRouteParameters($this->pagerManager->getUpdatedParameters([], 0, $current_page - 1))->toString(),
          'next' => $current_page + 1 === $pager->getTotalPages()
            ? NULL
            : $base_url->setRouteParameters($this->pagerManager->getUpdatedParameters([], 0, $current_page + 1))->toString(),
        ],
      ],
      status: Response::HTTP_OK
    );
  }

  /**
   * Checks if a color is in use and returns details of where it is used.
   *
   * Returns structured data distinguishing between:
   * - deletable: whether this color may be deleted right now
   * - current: entities where the color is used in a revision that blocks
   *   deletion, i.e. the default revision or the latest one
   * - prior: entities where the color is only used in superseded revisions
   * - config: config entities using the color
   *
   * Each entry includes a 'usages' array with component-level detail:
   * - component_uuid: The component instance UUID
   * - component_id: The component type ID
   * - label: The user-assigned label (or null)
   * - prop_name: The prop containing the color
   * - ancestor_labels: Array of ancestor component labels (for hierarchy)
   *
   * `deletable` is the authoritative answer for a delete affordance: it is the
   * same access check the delete route enforces, so the client cannot offer a
   * delete that the server would refuse. It still cannot be derived from the
   * lists above: those report no auto-saves, which block deletion too.
   *
   * @see \Drupal\canvas\EntityHandlers\ColorAccessControlHandler::checkAccess()
   */
  public function colorDetails(Color $color): JsonResponse {
    $dependents = ['deletable' => $color->access('delete')];

    // Get content entities with detail, split by revision status.
    $content_split = $this->colorAudit->getContentColorUsagesWithDetailSplit($color);

    // Current revisions (these block deletion).
    if (!empty($content_split['current'])) {
      $dependents['current'] = [];
      foreach ($content_split['current'] as $entry) {
        $entity = $entry['entity'];
        $dependents['current'][] = [
          'title' => (string) $entity->label(),
          'type' => $entity->getEntityTypeId(),
          'bundle' => $entity->bundle(),
          'id' => $entity->id(),
          'revision_id' => $entity->getRevisionId(),
          'usages' => $entry['usages'],
        ];
      }
    }

    // Prior revisions (these trigger a warning but don't block).
    if (!empty($content_split['prior'])) {
      $dependents['prior'] = [];
      foreach ($content_split['prior'] as $entry) {
        $entity = $entry['entity'];
        $dependents['prior'][] = [
          'title' => (string) $entity->label(),
          'type' => $entity->getEntityTypeId(),
          'bundle' => $entity->bundle(),
          'id' => $entity->id(),
          'revision_id' => $entity->getRevisionId(),
          'usages' => $entry['usages'],
        ];
      }
    }

    // Config entities.
    $config_dependents = $this->colorAudit->getConfigColorUsagesWithDetail($color);
    if (!empty($config_dependents)) {
      $dependents['config'] = [];
      foreach ($config_dependents as $entry) {
        $entity = $entry['entity'];
        $dependents['config'][] = [
          'title' => (string) $entity->label(),
          'type' => $entity->getEntityTypeId(),
          'id' => $entity->id(),
          'usages' => $entry['usages'],
        ];
      }
    }

    return new JsonResponse(
      data: $dependents,
      status: Response::HTTP_OK
    );
  }

}
