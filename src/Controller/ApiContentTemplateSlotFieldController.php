<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HTTP API for the `component_tree` fields backing a template's exposed slots.
 *
 * An exposed slot IS a `component_tree` field on the template's bundle. The
 * expose dialog uses these endpoints to create a new backing field (the "create
 * new slot" path) and to list existing `component_tree` fields on the bundle
 * (the "use existing slot" path).
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiContentTemplateSlotFieldController extends ApiControllerBase {

  /**
   * The machine-name prefix for slot fields created from the expose dialog.
   */
  public const string FIELD_NAME_PREFIX = 'canvas_slot_';

  /**
   * The maximum length of a field machine name.
   */
  private const int MAX_FIELD_NAME_LENGTH = 32;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Lists the entity type's reusable slot fields (the "use existing slot" set).
   *
   * Returns every `component_tree` field on this bundle, plus slot fields on
   * *other* bundles of the same entity type (fields can be shared across
   * bundles: they use one field storage). Each is flagged with `onThisBundle`,
   * so the client knows whether selecting it just references an existing field
   * config or must first attach the shared storage to this bundle, and carries
   * `contentCount`: how many of *this bundle's* entities already hold content
   * in it (what a reuse would restore). Cross-bundle fields report 0 (this
   * bundle has no content in them yet). Results are sorted content-first, so
   * the client can default to the most useful existing slot. The client
   * excludes fields already referenced by the working set of exposed slots.
   */
  public function candidates(ContentTemplate $content_template): JsonResponse {
    $entity_type_id = $content_template->getTargetEntityTypeId();
    $bundle = $content_template->getTargetBundle();

    $map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    $fields = [];
    foreach ($map[$entity_type_id] ?? [] as $field_name => $info) {
      $on_this_bundle = \in_array($bundle, $info['bundles'], TRUE);
      // A field on another bundle is reusable here only by attaching its shared
      // storage, which the create path allows only for slot fields; skip any
      // other component_tree field. Fields already on this bundle are listed
      // as-is.
      if (!$on_this_bundle && !\str_starts_with($field_name, self::FIELD_NAME_PREFIX)) {
        continue;
      }
      // Take the label from this bundle's field config, or any bundle's if the
      // field is not on this one yet.
      $label_bundle = $on_this_bundle ? $bundle : \reset($info['bundles']);
      $label = \is_string($label_bundle)
        ? FieldConfig::loadByName($entity_type_id, $label_bundle, $field_name)?->label()
        : NULL;
      $fields[] = [
        'fieldName' => $field_name,
        'label' => $label ?? $field_name,
        'onThisBundle' => $on_this_bundle,
        'contentCount' => $this->countContentEntities($entity_type_id, $bundle, $field_name),
      ];
    }
    // Content-first: the slot fields that would restore the most content lead.
    \usort($fields, static fn(array $a, array $b): int => $b['contentCount'] <=> $a['contentCount']);
    return new JsonResponse(['fields' => $fields]);
  }

  /**
   * Creates a `component_tree` field on the bundle (the "create new" path).
   *
   * The field is not added to any form or view display: its content is the
   * canvas itself, merged into the template's target slot at render time.
   */
  public function create(Request $request, ContentTemplate $content_template): JsonResponse {
    $body = self::decode($request);
    if (!\is_string($body['fieldName'] ?? NULL) || !\is_string($body['label'] ?? NULL)) {
      throw new BadRequestHttpException('A "fieldName" and "label" are required.');
    }
    $field_name = $body['fieldName'];
    $label = \trim($body['label']);
    if ($label === '') {
      throw new BadRequestHttpException('The "label" must not be empty.');
    }

    // The machine name must be a valid, prefixed field name within Drupal's
    // 32-character budget, so it can never collide with or be mistaken for a
    // non-slot field.
    if (
      \strlen($field_name) > self::MAX_FIELD_NAME_LENGTH
      || !\str_starts_with($field_name, self::FIELD_NAME_PREFIX)
      || \preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $field_name) !== 1
    ) {
      throw new BadRequestHttpException(\sprintf('"%s" is not a valid slot field machine name.', $field_name));
    }

    $entity_type_id = $content_template->getTargetEntityTypeId();
    $bundle = $content_template->getTargetBundle();

    if (FieldConfig::loadByName($entity_type_id, $bundle, $field_name) !== NULL) {
      throw new ConflictHttpException(\sprintf('The %s bundle already has a %s field.', $bundle, $field_name));
    }

    $field_storage = FieldStorageConfig::loadByName($entity_type_id, $field_name);
    if ($field_storage !== NULL && $field_storage->getType() !== ComponentTreeItem::PLUGIN_ID) {
      // A same-named storage of another field type cannot back a slot: the
      // exposed-slot save would fail validation afterwards.
      throw new ConflictHttpException(\sprintf('The %s field exists but is not a %s field.', $field_name, ComponentTreeItem::PLUGIN_ID));
    }
    if ($field_storage === NULL) {
      $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type_id,
        'type' => ComponentTreeItem::PLUGIN_ID,
      ]);
      $field_storage->save();
    }

    $field_config = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'label' => $label,
    ]);
    // Mirror canvas_page's symmetric translation for per-entity slot content:
    // tree columns synchronized across translations, inputs translatable per
    // language. Only meaningful when content_translation is installed.
    // @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer
    if ($this->moduleHandler->moduleExists('content_translation')) {
      $field_config->setTranslatable(TRUE);
      $field_config->setThirdPartySetting('content_translation', 'translation_sync', [
        'inputs' => 'inputs',
        'tree' => '0',
      ]);
    }
    $field_config->save();

    // The field map may be stale within this request; refresh it so a follow-up
    // candidates() call and the template save see the new field.
    $this->entityFieldManager->clearCachedFieldDefinitions();

    return new JsonResponse(['fieldName' => $field_name, 'label' => $label], JsonResponse::HTTP_CREATED);
  }

  /**
   * Reports how many of the bundle's entities have overridden a slot.
   *
   * An entity overrides an exposed slot when its backing `component_tree` field
   * holds content (including the empty-slot marker, a deliberate "render
   * nothing"); an entity whose field is empty inherits the template default.
   * The count is derived at read time from stored entity data, so nothing needs
   * invalidating on entity save, publish, or revert.
   *
   * The query joins the (small) backing-field table, so it scales with the
   * number of *overriding* entities, not the bundle size: inheriting entities
   * have no row there and are never scanned. Only the numerator is reported (no
   * "N of M"): a bundle-wide total would full-scan the bundle, because core
   * does not index the bundle column on the data table.
   */
  public function usage(ContentTemplate $content_template, string $field_name): JsonResponse {
    $entity_type_id = $content_template->getTargetEntityTypeId();
    $bundle = $content_template->getTargetBundle();

    $field_config = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
    if ($field_config?->getType() !== ComponentTreeItem::PLUGIN_ID) {
      // A field of another type is not a slot field, and its table has no
      // `uuid` property to count on.
      throw new NotFoundHttpException(\sprintf('The %s bundle has no %s slot field.', $bundle, $field_name));
    }

    return new JsonResponse(['overridden' => $this->countContentEntities($entity_type_id, $bundle, $field_name)]);
  }

  /**
   * Counts a bundle's entities that hold content in a `component_tree` field.
   *
   * The query joins the (small) backing-field table, so it scales with the
   * number of content-bearing entities, not the bundle size: entities with an
   * empty field have no row there and are never scanned. `component_tree` has
   * no main property, so `exists($field_name)` cannot be used; every real row
   * carries a `uuid`, so its presence marks a non-empty field. A slot may hold
   * a multi-row subtree, but the field-table join makes the query non-simple,
   * so the entity query groups by entity id: the count is per-entity, not
   * per-row. Bundle-scoped in case the field storage is shared across bundles.
   * Access-check-free: an aggregate count for a template author leaks only a
   * number, and per-entity grants would add an expensive node-grants join.
   */
  private function countContentEntities(string $entity_type_id, string $bundle, string $field_name): int {
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
      ->accessCheck(FALSE)
      ->exists("$field_name.uuid");
    if ($bundle_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle')) {
      $query->condition($bundle_key, $bundle);
    }
    return (int) $query->count()->execute();
  }

}
