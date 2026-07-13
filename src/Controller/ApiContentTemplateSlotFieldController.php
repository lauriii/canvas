<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
  ) {}

  /**
   * Lists the bundle's `component_tree` fields (the "use existing slot" set).
   *
   * The client excludes fields already referenced by the working set of exposed
   * slots; the server returns every `component_tree` field on the bundle.
   */
  public function candidates(ContentTemplate $content_template): JsonResponse {
    $entity_type_id = $content_template->getTargetEntityTypeId();
    $bundle = $content_template->getTargetBundle();

    $map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    $fields = [];
    foreach ($map[$entity_type_id] ?? [] as $field_name => $info) {
      if (!\in_array($bundle, $info['bundles'], TRUE)) {
        continue;
      }
      $field_config = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
      $fields[] = [
        'fieldName' => $field_name,
        'label' => $field_config?->label() ?? $field_name,
      ];
    }
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

}
