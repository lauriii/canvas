<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Core\DefaultContent\ExportMetadata;
use Drupal\Core\DefaultContent\PreEntityImportEvent;
use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\Core\DefaultContent\PreImportEvent;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to default content-related events.
 */
final class DefaultContentSubscriber implements EventSubscriberInterface {

  private const string EXPORT_ENTITY_REFERENCE_KEY = 'CANVAS_ENTITY_REFERENCE';

  private array $componentTreeFieldMap;

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreExportEvent::class => 'preExport',
      PreImportEvent::class => 'preImport',
      PreEntityImportEvent::class => 'preEntityImport',
    ];
  }

  public function preExport(PreExportEvent $event): void {
    $event->setCallback(
      'field_item:' . ComponentTreeItem::PLUGIN_ID,
      function (ComponentTreeItem $item, ExportMetadata $metadata): array {
        // Find all entities referenced in the component tree and add them as
        // dependencies of the entity being exported.
        $dependencies = $item->calculateFieldItemValueDependencies($item->getEntity());
        foreach ($dependencies['content'] ?? [] as $dependency) {
          // @see \Drupal\Core\Entity\EntityBase::getConfigDependencyName()
          [$entity_type_id,, $uuid] = explode(':', $dependency);
          $dependency = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
          if ($dependency instanceof ContentEntityInterface) {
            $metadata->addDependency($dependency);
          }
        }

        // Don't export any empty properties; they're not valid for import.
        $values = array_filter($item->getValue());

        // Remove the component version as this may have changed on the import
        // site. If the component has changed in ways in which the props or
        // slots in the export no longer are valid the import will fail.
        unset($values['component_version']);
        // Export `inputs` not as stored in the DB (JSON), but as an array.
        // @see \Drupal\experience_builder\Plugin\DataType\ComponentInputs::getValues()
        $item_inputs = $item->get('inputs')->getValues();
        // We need to convert any entity reference inputs to store UUID and
        // entity_type instead target_id.
        $component_source = $item->getComponent()?->getComponentSource();
        \assert($component_source instanceof ComponentSourceInterface);
        $inputs = $component_source->getDefaultExplicitInput();
        foreach ($inputs as $prop_name => $input) {
          // @todo Per https://www.drupal.org/i/3560543#comment-16406290,
          //   considering refactoring this to use
          //   ComponentInputs::getPropSourcesUsingExpressionClass(), but only
          //   once https://www.drupal.org/i/3566720 is resolved.
          if (!$component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
            continue;
          }
          $prop_source = PropSource::parse($input);
          // Only a static prop source will store a reference to a specific
          // entity. Dynamic prop sources would store a reference to field on
          // the host entity where the actual entity would depend on the host
          // entity.
          if (!$prop_source instanceof StaticPropSource) {
            continue;
          }
          $field_item_list = $prop_source->fieldItemList;
          if ($field_item_list instanceof EntityReferenceFieldItemList && isset($item_inputs[$prop_name]['target_id'])) {
            $entity_type = $field_item_list->getFieldDefinition()->getSetting('target_type');
            $referenced_entity = $this->entityTypeManager->getStorage($entity_type)->load($item_inputs[$prop_name]['target_id']);
            \assert($referenced_entity instanceof EntityInterface);
            // Store the UUID and entity type instead of target_id because the
            // serial id will change on import.
            // Instead of just adding the `target_uuid` key add the metadata
            // that will be needed on import under a unique key. This means:
            // 1. The import does not have to inspect the component prop sources
            //    to determine if an input should be converted back to
            //    'target_id'
            // 2. Other future or custom component inputs besides entity
            //    reference fields are free to use the 'target_uuid' and the
            //    import logic will not affect those inputs.
            // 3. By also storing 'target_type' the target entity will be able
            //    to be loaded without inspecting the component source.
            $item_inputs[$prop_name][self::EXPORT_ENTITY_REFERENCE_KEY] = [
              'target_uuid' => $referenced_entity->uuid(),
              'target_type' => $referenced_entity->getEntityTypeId(),
            ];
            unset($item_inputs[$prop_name]['target_id']);
          }
        }
        $values['inputs'] = $item_inputs;
        return $values;
      },
    );
  }

  public function preImport(): void {
    // Set the field map at the beginning of an import as it should not change
    // once the import has started.
    $this->componentTreeFieldMap = $this->fieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
  }

  public function preEntityImport(PreEntityImportEvent $event): void {
    \assert(isset($event->metadata['entity_type']));
    \assert(is_string($event->metadata['entity_type']));

    if (!isset($this->componentTreeFieldMap[$event->metadata['entity_type']])) {
      // If the entity type does not have any component tree fields then no
      // processing is needed.
      return;
    }

    $componentTreeFields = array_keys($this->componentTreeFieldMap[$event->metadata['entity_type']]);
    foreach ($event->data as &$translation_data) {
      foreach ($componentTreeFields as $field_name) {
        // Skip if field not present.
        if (!isset($translation_data[$field_name])) {
          continue;
        }

        foreach ($translation_data[$field_name] as &$item_data) {
          // Skip if no inputs.
          if (!isset($item_data['inputs'])) {
            continue;
          }

          foreach ($item_data['inputs'] as &$prop_input) {
            if (is_array($prop_input)) {
              $prop_input = $this->processComponentInputOnImport($prop_input);
            }
          }
        }
      }
    }
  }

  private function processComponentInputOnImport(array $prop_input): array {
    if (!isset($prop_input[self::EXPORT_ENTITY_REFERENCE_KEY])) {
      return $prop_input;
    }
    \assert(is_array($prop_input[self::EXPORT_ENTITY_REFERENCE_KEY]));
    $export_data = $prop_input[self::EXPORT_ENTITY_REFERENCE_KEY];
    \assert(array_key_exists('target_uuid', $export_data));
    \assert(array_key_exists('target_type', $export_data));
    $entity = $this->entityRepository->loadEntityByUuid($export_data['target_type'], $export_data['target_uuid']);
    \assert($entity instanceof EntityInterface);
    $prop_input['target_id'] = $entity->id();
    unset($prop_input[self::EXPORT_ENTITY_REFERENCE_KEY]);
    return $prop_input;
  }

}
