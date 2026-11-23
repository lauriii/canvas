<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Validation\Constraint\ValidExposedSlotConstraint;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * HTTP API for the `component_tree` fields backing a template's exposed slots.
 *
 * Each exposed slot is backed by a `component_tree` field on the template's
 * bundle. The expose dialog creates new backing fields and lists reusable ones.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiContentTemplateSlotFieldController extends ApiControllerBase {

  /**
   * The machine-name prefix for slot fields created from the expose dialog.
   */
  public const string FIELD_NAME_PREFIX = 'canvas_slot_';

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Lists the slot fields a template can reuse.
   *
   * Returns every `component_tree` field on this bundle, plus slot fields on
   * other bundles of the entity type, whose storage can be added here. Each
   * item reports whether it is on this bundle and how many of this bundle's
   * entities have content in it. Fields with the most content come first.
   *
   * Fields that the template's exposed slots already reference are included:
   * the editor filters them out, as it holds the template's auto-saved exposed
   * slots, which can differ from the saved config.
   *
   * @see ui/src/features/layout/exposeSlot/ExposeSlotDialog.tsx
   */
  public function candidates(ContentTemplate $content_template): JsonResponse {
    $entity_type_id = $content_template->getTargetEntityTypeId();
    $bundle = $content_template->getTargetBundle();

    $map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    $fields = [];
    foreach ($map[$entity_type_id] ?? [] as $field_name => $info) {
      $on_this_bundle = \in_array($bundle, $info['bundles'], TRUE);
      // The create path attaches only prefixed slot fields to another bundle.
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
   * Only templates of the entity type and view mode that can expose slots
   * accept new fields.
   */
  public function create(Request $request, ContentTemplate $content_template): JsonResponse {
    if ($content_template->getTargetEntityTypeId() !== ValidExposedSlotConstraint::EXPOSED_SLOTS_ENTITY_TYPE_ID) {
      throw new UnprocessableEntityHttpException(\sprintf('Exposed slots are only allowed for the %s entity type.', ValidExposedSlotConstraint::EXPOSED_SLOTS_ENTITY_TYPE_ID));
    }
    if ($content_template->getMode() !== ValidExposedSlotConstraint::EXPOSED_SLOTS_VIEW_MODE) {
      throw new UnprocessableEntityHttpException(\sprintf('Exposed slots are only allowed in the %s view mode.', ValidExposedSlotConstraint::EXPOSED_SLOTS_VIEW_MODE));
    }
    $body = self::decode($request);
    if (!\is_string($body['fieldName'] ?? NULL) || !\is_string($body['label'] ?? NULL)) {
      throw new BadRequestHttpException('A "fieldName" and "label" are required.');
    }
    $field_name = $body['fieldName'];
    $label = \trim($body['label']);
    if ($label === '') {
      throw new BadRequestHttpException('The "label" must not be empty.');
    }

    // The prefix keeps slot fields distinct from other fields.
    if (
      \strlen($field_name) > FieldStorageConfig::NAME_MAX_LENGTH
      || !\str_starts_with($field_name, self::FIELD_NAME_PREFIX)
      || \preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $field_name) !== 1
    ) {
      throw new BadRequestHttpException(\sprintf('"%s" is not a valid slot field machine name.', $field_name));
    }

    $entity_type_id = $content_template->getTargetEntityTypeId();
    $bundle = $content_template->getTargetBundle();

    // A same-named field of another type can't back a slot (422). An existing
    // `component_tree` field is a 409, which the CLI treats as already created.
    $field_storage = FieldStorageConfig::loadByName($entity_type_id, $field_name);
    if ($field_storage !== NULL && $field_storage->getType() !== ComponentTreeItem::PLUGIN_ID) {
      throw new UnprocessableEntityHttpException(\sprintf('The %s field exists but is not a %s field.', $field_name, ComponentTreeItem::PLUGIN_ID));
    }
    if (FieldConfig::loadByName($entity_type_id, $bundle, $field_name) !== NULL) {
      throw new ConflictHttpException(\sprintf('The %s bundle already has a %s field.', $bundle, $field_name));
    }
    $storage_created = FALSE;
    if ($field_storage === NULL) {
      $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type_id,
        'type' => ComponentTreeItem::PLUGIN_ID,
      ]);
      try {
        $field_storage->save();
        $storage_created = TRUE;
      }
      catch (EntityStorageException $e) {
        // A parallel request can create the shared storage after the check
        // above, for example the CLI pushing templates of two bundles.
        $field_storage = FieldStorageConfig::loadByName($entity_type_id, $field_name);
        if ($field_storage?->getType() !== ComponentTreeItem::PLUGIN_ID) {
          throw $e;
        }
      }
    }

    $field_config = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'label' => $label,
    ]);
    // Mirror canvas_page's symmetric translation for per-entity slot content:
    // tree columns synchronized across translations, inputs translatable per
    // language. Only meaningful when content_translation is installed; if it
    // is installed later, ContentTranslationHooks::modulesInstalled() sets it.
    // A field config is translatable by default, so only `translation_sync` is
    // set.
    // @see \Drupal\Core\Field\FieldConfigBase::$translatable
    if ($this->moduleHandler->moduleExists('content_translation')) {
      ComponentTreeFieldSymmetricalTranslationSynchronizer::setSymmetricalSlotFieldTranslation($field_config);
    }
    // Saving the field config also clears the field definitions and field map.
    // @see \Drupal\Core\Field\FieldConfigBase::postSave()
    try {
      $field_config->save();
    }
    catch (\Exception $e) {
      // A parallel request for the same bundle saved the field first.
      if ($e instanceof EntityStorageException && FieldConfig::load($field_config->id()) !== NULL) {
        throw new ConflictHttpException(\sprintf('The %s bundle already has a %s field.', $bundle, $field_name), $e);
      }
      // Do not leave behind a storage this request created, unless a parallel
      // request added a field to it. The field map does not list a storage
      // without fields, so it would not be offered for reuse.
      $fields = $this->entityTypeManager->getStorage('field_config')->loadByProperties([
        'entity_type' => $entity_type_id,
        'field_name' => $field_name,
      ]);
      if ($storage_created && $fields === []) {
        $field_storage->delete();
      }
      throw $e;
    }

    return new JsonResponse(['fieldName' => $field_name, 'label' => $label], JsonResponse::HTTP_CREATED);
  }

  /**
   * Reports how many of the bundle's entities have overridden a slot.
   *
   * An entity overrides a slot when its slot field holds content, including
   * the empty slot marker.
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
   * The query only scans the field's table, so it scales with the number of
   * overriding entities. `component_tree` has no main property, so it checks
   * `uuid`; the entity query counts entities, not rows. Not access-checked: a
   * count reveals no content, and node grants would add an expensive join.
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
