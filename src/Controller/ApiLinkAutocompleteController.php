<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Page;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Tags;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Autocompletes link props against multiple entity types.
 *
 * Core's LinkWidget only matches nodes. This matches every entity type in
 * ::TARGET_ENTITY_TYPE_IDS, sorted by relevance across entity types, and
 * encodes each suggestion's value as `Label (entity_type_id/entity_id)` so the
 * client can build an `entity:<entity_type_id>/<entity_id>` URI.
 *
 * @see \Drupal\Core\Entity\EntityAutocompleteMatcher
 * @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetSingleElementLinkDefaultWidgetFormAlter()
 * @see ui/src/utils/transforms.ts
 */
final class ApiLinkAutocompleteController extends ApiControllerBase {

  private const array TARGET_ENTITY_TYPE_IDS = ['node', Page::ENTITY_TYPE_ID];

  /**
   * The maximum number of suggestions, as in core's entity autocomplete.
   */
  private const int LIMIT = 10;

  public function __construct(
    private readonly SelectionPluginManagerInterface $selectionManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  public function __invoke(Request $request): JsonResponse {
    $input = $request->query->get('q');
    if (!\is_string($input) || $input === '') {
      return new JsonResponse([]);
    }
    $tag_list = Tags::explode($input);
    $typed_string = \mb_strtolower((string) \array_pop($tag_list));

    $suggestions = [];
    foreach (self::TARGET_ENTITY_TYPE_IDS as $entity_type_id) {
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        continue;
      }
      $handler = $this->selectionManager->getInstance(['target_type' => $entity_type_id, 'handler' => 'default']);
      \assert($handler instanceof SelectionInterface);
      // Query exact matches and labels starting with the typed string
      // separately, so that weaker matches cannot push them out of the limit.
      $ids = [];
      foreach (['=', 'STARTS_WITH', 'CONTAINS'] as $operator) {
        foreach ($handler->getReferenceableEntities($typed_string, $operator, self::LIMIT) as $labels_by_id) {
          $ids += $labels_by_id;
        }
      }
      foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple(\array_keys($ids)) as $entity) {
        // The selection handler only filters out unpublished nodes, not
        // unpublished entities of other types.
        if (!$entity->access('view')) {
          continue;
        }
        $entity = $this->entityRepository->getTranslationFromContext($entity);
        $sort_label = \mb_strtolower((string) $entity->label());
        $suggestions[] = [
          'sort' => [self::rank($sort_label, $typed_string), $sort_label],
          'entity' => $entity,
        ];
      }
    }

    \usort($suggestions, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
    // Build the value and markup only for the suggestions within the limit:
    // the markup needs a generated URL per entity.
    $matches = [];
    foreach (\array_slice($suggestions, 0, self::LIMIT) as ['entity' => $entity]) {
      $label = (string) $entity->label();
      $matches[] = [
        'value' => self::encodeValue($label, $entity->getEntityTypeId(), (string) $entity->id()),
        'label' => $this->buildSuggestionLabel($label, $entity),
      ];
    }
    return new JsonResponse($matches);
  }

  /**
   * Encodes an entity as the value of a suggestion.
   *
   * @param string $label
   *   The entity label.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   *
   * @return string
   *   `Label (entity_type_id/entity_id)`, quoted if the label contains a comma
   *   or a quote.
   */
  public static function encodeValue(string $label, string $entity_type_id, string $entity_id): string {
    return Tags::encode("$label ($entity_type_id/$entity_id)");
  }

  /**
   * Ranks how well a label matches the typed string; lower is better.
   *
   * @param string $label
   *   The lowercased entity label.
   * @param string $typed_string
   *   The lowercased typed string.
   *
   * @return int
   *   0 for an exact match, 1 if the label starts with the typed string, 2 if
   *   a word in the label does, 3 if the label only contains it.
   */
  private static function rank(string $label, string $typed_string): int {
    return match (TRUE) {
      $label === $typed_string => 0,
      \str_starts_with($label, $typed_string) => 1,
      (bool) \preg_match('/(?<![\p{L}\p{N}])' . \preg_quote($typed_string, '/') . '/u', $label) => 2,
      default => 3,
    };
  }

  /**
   * Builds the suggestion markup: label, path and type pill.
   *
   * The path replaces the type pill on hover and keyboard highlight.
   *
   * @see css/autocomplete.extend.css
   */
  private function buildSuggestionLabel(string $label, EntityInterface $entity): string {
    return (string) new FormattableMarkup(
      // A single root element, because the suggestion link is a flex container.
      '<span class="canvas-link-suggestion" title="@type · @path">'
      . '<span class="canvas-link-suggestion__label">@label</span>'
      . '<span class="canvas-link-suggestion__path">@path</span>'
      . '<span class="canvas-link-suggestion__type">@type</span>'
      . '</span>',
      [
        '@label' => $label,
        '@path' => $entity->toUrl()->toString(),
        '@type' => (string) $this->getTypeLabel($entity),
      ],
    );
  }

  /**
   * Gets the bundle label, or the entity type label for unbundled types.
   *
   * The bundle info of an unbundled entity type is a single bundle, named and
   * labeled after the entity type.
   */
  private function getTypeLabel(EntityInterface $entity): string|\Stringable {
    return $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label']
      ?? $entity->getEntityType()->getLabel();
  }

}
