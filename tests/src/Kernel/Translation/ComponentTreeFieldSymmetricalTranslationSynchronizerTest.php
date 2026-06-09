<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

// cspell:ignore Cliquez

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests non-translatable input keys are synced from the default translation.
 *
 * This means it asserts the values stored in the `inputs` field property for
 * all translations. Since that field property provides the source of truth for
 * the `inputs_resolved` field property, that is tested too (even though its
 * logic has no language-awareness: full confidence in its results is crucial).
 *
 * Tests both:
 *  1. with a bundle field (node:article, uses a FieldConfig)
 *  2. with a base field (canvas_page, uses a BaseFieldOverride)
 *
 * Uses the 'my-cta' SDC which has:
 * - text: type: string (translatable)
 * - href: type: string, format: uri (translatable)
 * - target: type: string, enum: [_self, _blank] (NOT translatable — enums)
 *
 * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::getTranslatableInputKeys()
 */
#[CoversClass(ComponentTreeFieldSymmetricalTranslationSynchronizer::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class ComponentTreeFieldSymmetricalTranslationSynchronizerTest extends CanvasKernelTestBase {

  use CanvasFieldCreationTrait;
  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'content_translation',
    'field',
    'language',
    'node',
  ];

  /**
   * @var string
   * @todo if this ever needs to use multiple different components, drop this in favor of DataProviderWithComponentTreeTrait.
   */
  private string $version;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'language', 'node']);

    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $component = Component::load('sdc.canvas_test_sdc.my-cta');
    self::assertNotNull($component);
    $this->version = $component->getActiveVersion();
  }

  #[TestWith(['node', 'article', 'field_canvas_test'], 'bundle field (FieldConfig)')]
  #[TestWith([Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'], 'base field (BaseFieldOverride)')]
  public function test(string $entity_type_id, string $bundle, string $field_name): void {
    $this->setUpSymmetricalContentTranslation($entity_type_id, $bundle, $field_name);
    $entity_storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);

    // Create either content entity, with an initial default translation.
    $cta_uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
    $cta_instance_raw = [
      'uuid' => $cta_uuid,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => $this->version,
      'inputs' => [
        'text' => 'Click here',
        'href' => 'https://drupal.org',
        'target' => '_self',
      ],
    ];
    $initial_component_tree_raw = [$cta_instance_raw];
    $entity = $entity_storage->create(match ($entity_type_id) {
      'node' => [
        'type' => $bundle,
        'title' => 'Test entity',
        $field_name => $initial_component_tree_raw,
      ],
      Page::ENTITY_TYPE_ID => [
        'title' => 'Test entity',
        $field_name => $initial_component_tree_raw,
      ],
      default => throw new \OutOfRangeException(),
    });
    \assert($entity instanceof ContentEntityInterface);
    self::assertEntityIsValid($entity);
    self::assertTrue($entity->isDefaultTranslation());
    self::assertSame('en', $entity->language()->getId());
    $entity->save();

    // Closure to enable each stage in this test to elegantly test both the
    // in-memory and stored representations, to ensure they are always in sync.
    $memory_and_stored = function () use ($entity, $entity_storage): array {
      // In-memory (runtime) representation; entity object modified during test.
      $in_memory = $entity;

      // Database storage (at rest) representation; fresh entity object.
      self::assertNotNull($entity->id());
      $stored = $entity_storage->loadUnchanged($entity->id());
      self::assertInstanceOf(ContentEntityInterface::class, $stored);

      return [$in_memory, $stored];
    };

    // Verify which keys are translatable per the config schema.
    $list = $entity->get($field_name);
    \assert($list instanceof ComponentTreeItemList);
    self::assertSame(
      ['text', 'href'],
      $list->getComponentTreeItemByUuid($cta_uuid)?->get('inputs')->getTranslatableInputKeys(),
    );

    // 1. Create a French translation. The non-translatable 'target' is set to a
    // different value here — it must be overwritten by the synchronizer on save.
    $translation = $entity->addTranslation('fr');
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($translation)
      ->setSource($entity->language()->getId());
    $translation->set('title', 'French title')->set($field_name, [
      [
        'uuid' => $cta_uuid,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $this->version,
        'inputs' => [
          'text' => 'Cliquez ici',
          'href' => 'https://drupal.fr',
          // Different from default ('_self') — synchronizer must correct it.
          'target' => '_blank',
        ],
      ],
    ]);
    self::assertEntityIsValid($translation);
    $translation->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame([
        'text' => 'Cliquez ici',
        'href' => 'https://drupal.fr',
        // Synchronized from default ('_self').
        'target' => '_self',
      ], $fr_list->getComponentTreeItemByUuid($cta_uuid)?->getInputs());
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 2. Change non-translatable key in default translation. Must propagate.
    $cta_with_blank = $cta_instance_raw;
    $cta_with_blank['inputs']['target'] = '_blank';
    $entity->set($field_name, [$cta_with_blank]);
    self::assertEntityIsValid($entity);
    $entity->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame([
        'text' => 'Cliquez ici',
        'href' => 'https://drupal.fr',
        // Updated from default to '_blank'.
        'target' => '_blank',
      ], $fr_list->getComponentTreeItemByUuid($cta_uuid)?->getInputs());
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 3. Non-translatable key changed in non-default translation is corrected
    // on save — the synchronizer restores the default translation's value.
    $fr_translation = $entity->getTranslation('fr');
    $fr_translation->set($field_name, [
      [
        'uuid' => $cta_uuid,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $this->version,
        'inputs' => [
          'text' => 'Cliquez ici',
          'href' => 'https://drupal.fr',
          // Wrong: default is '_blank'. Synchronizer must correct on save.
          'target' => '_self',
        ],
      ],
    ]);
    self::assertEntityIsValid($fr_translation);
    $fr_translation->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame([
        'text' => 'Cliquez ici',
        'href' => 'https://drupal.fr',
        // Corrected back to '_blank'.
        'target' => '_blank',
      ], $fr_list->getComponentTreeItemByUuid($cta_uuid)?->getInputs());
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 4. Omitting an optional non-translatable key in a non-default translation
    // is corrected on save: the synchronizer adds it from default.
    $fr_translation->set($field_name, [
      [
        'uuid' => $cta_uuid,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $this->version,
        'inputs' => [
          'text' => 'Cliquez ici',
          'href' => 'https://drupal.fr',
          // 'target' intentionally omitted.
        ],
      ],
    ]);
    self::assertEntityIsValid($fr_translation);
    $fr_translation->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame([
        'text' => 'Cliquez ici',
        'href' => 'https://drupal.fr',
        // Added from default.
        'target' => '_blank',
      ], $fr_list->getComponentTreeItemByUuid($cta_uuid)?->getInputs());
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 5. New component instance added to default after non-default translations
    // exist. The tree column-group sync copies the full item (English inputs) to
    // non-default translations; the decorator propagates non-translatable keys.
    // @todo This proves the concern at https://git.drupalcode.org/project/canvas/-/merge_requests/882#note_1078674 is actually present in the current synchronizer!
    $new_uuid = 'b2c3d4e5-f6a7-8901-bcde-f01234567891';
    $new_instance_raw = [
      'uuid' => $new_uuid,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => $this->version,
      'inputs' => [
        'text' => 'New link',
        'href' => 'https://new.example.com',
        'target' => '_self',
      ],
    ];
    $entity->set($field_name, [$cta_with_blank, $new_instance_raw]);
    self::assertEntityIsValid($entity);
    $entity->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      $fr_new = $fr_list->getComponentTreeItemByUuid($new_uuid);
      self::assertNotNull($fr_new, 'New instance must be present in French translation.');
      self::assertSame([
        // English translatable values — no French-specific values exist yet.
        'text' => 'New link',
        'href' => 'https://new.example.com',
        // Non-translatable synced from default.
        'target' => '_self',
      ], $fr_new->getInputs());
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 6. Reorder instances in default translation — order propagates to all
    // non-default translations. Non-translatable keys follow the UUID, not
    // the delta position.
    $entity->set($field_name, [$new_instance_raw, $cta_with_blank]);
    self::assertEntityIsValid($entity);
    $entity->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame(
        [$new_uuid, $cta_uuid],
        \array_map(
          fn (ComponentTreeItem $i) => $i->getUuid(),
          iterator_to_array($fr_list->componentTreeItemsIterator()),
        ),
        'UUID order in French must match reordered default.',
      );
      // Non-translatable 'target' per UUID must match default translation's,
      // regardless of which delta each UUID now occupies.
      self::assertSame(['_self', '_blank'], \array_map(
        // @phpstan-ignore-next-line offsetAccess.notFound
        fn (ComponentTreeItem $i) => $i->getInputs()['target'],
        iterator_to_array($fr_list->componentTreeItemsIterator()),
      ));
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 7. Reorder instances in default translation AND modify an input key (undo
    // what step 2 did: `target: _blank` -> `target: _self`).
    $entity->set($field_name, [$cta_instance_raw, $new_instance_raw]);
    self::assertEntityIsValid($entity);
    $entity->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame(
        [$cta_uuid, $new_uuid],
        \array_map(
          fn (ComponentTreeItem $i) => $i->getUuid(),
          iterator_to_array($fr_list->componentTreeItemsIterator()),
        ),
        'UUID order in French must match reordered default.',
      );
      // Non-translatable 'target' per UUID must match default translation's,
      // regardless of which delta each UUID now occupies.
      self::assertSame(['_self', '_self'], \array_map(
        // @phpstan-ignore-next-line offsetAccess.notFound
        fn (ComponentTreeItem $i) => $i->getInputs()['target'],
        iterator_to_array($fr_list->componentTreeItemsIterator()),
      ));
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 8. Prepend a new component instance.
    $new_prepended_uuid = 'b2c3d4e5-f6a7-8901-bcde-f01234567892';
    $new_prepended_instance_raw = [
      'uuid' => $new_prepended_uuid,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => $this->version,
      'inputs' => [
        'text' => 'Prepended link',
        'href' => 'https://prepended.example.com',
        'target' => '_blank',
      ],
    ];
    $entity->set($field_name, [$new_prepended_instance_raw, $cta_instance_raw, $new_instance_raw]);
    self::assertEntityIsValid($entity);
    $entity->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame(
        [$new_prepended_uuid, $cta_uuid, $new_uuid],
        \array_map(
          fn (ComponentTreeItem $i) => $i->getUuid(),
          iterator_to_array($fr_list->componentTreeItemsIterator()),
        ),
        'UUID order in French must match updated default.',
      );
      // Assert inputs for each instance in the French translation.
      self::assertSame(
        [
          [
            'text' => 'Prepended link',
            'href' => 'https://prepended.example.com',
            'target' => $new_prepended_instance_raw['inputs']['target'],
          ],
          [
            'text' => 'Cliquez ici',
            'href' => 'https://drupal.fr',
            'target' => $cta_instance_raw['inputs']['target'],
          ],
          [
            'text' => 'New link',
            'href' => 'https://new.example.com',
            'target' => $new_instance_raw['inputs']['target'],
          ],
        ],
        \array_map(
          fn (ComponentTreeItem $i) => $i->getInputs(),
          iterator_to_array($fr_list->componentTreeItemsIterator()),
        ),
      );
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 8. Delete instance from default — removed from all non-default
    // translations.
    $entity->set($field_name, [$new_prepended_instance_raw, $cta_instance_raw]);
    self::assertEntityIsValid($entity);
    $entity->save();
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertCount(2, $fr_list);
      self::assertNull($fr_list->getComponentTreeItemByUuid($new_uuid), 'Deleted instance must be absent.');
      self::assertNotNull($fr_list->getComponentTreeItemByUuid($new_prepended_uuid), 'Surviving instance #1 must be present.');
      self::assertNotNull($fr_list->getComponentTreeItemByUuid($cta_uuid), 'Surviving instance #2 must be present.');
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // 9. Revisions: changing a non-translatable input in a new EN revision must
    // sync to the new revision's FR translation, while the previous revision's
    // FR translation remains unchanged.
    $old_revision_id = $entity->getRevisionId();
    self::assertNotNull($old_revision_id);

    $entity->setNewRevision(TRUE);
    $entity->set($field_name, [$cta_with_blank]);
    self::assertEntityIsValid($entity);
    $entity->save();

    $new_revision_id = $entity->getRevisionId();
    self::assertNotSame($old_revision_id, $new_revision_id, 'A new revision must have been created.');

    // New revision: FR target must be synced to '_blank'.
    foreach ($memory_and_stored() as $either) {
      $fr_list = $either->getTranslation('fr')->get($field_name);
      \assert($fr_list instanceof ComponentTreeItemList);
      self::assertSame([$cta_uuid], \array_map(
        fn (ComponentTreeItem $i) => $i->getUuid(),
        iterator_to_array($fr_list->componentTreeItemsIterator()),
      ));
      // @phpstan-ignore-next-line offsetAccess.notFound
      self::assertSame('_blank', $fr_list->getComponentTreeItemByUuid($cta_uuid)?->getInputs()['target'],
        'New revision FR target must be synced from EN.',
      );
      self::assertResolvedAndStoredInputsAreIdentical($fr_list);
    }

    // Old revision: FR target must still be '_self' (unchanged by new revision
    // save).
    \assert($entity_storage instanceof RevisionableStorageInterface);
    $old_revision = $entity_storage->loadRevision($old_revision_id);
    self::assertNotNull($old_revision);
    \assert($old_revision instanceof ContentEntityInterface);
    $old_fr_list = $old_revision->getTranslation('fr')->get($field_name);
    \assert($old_fr_list instanceof ComponentTreeItemList);
    self::assertSame([$new_prepended_uuid, $cta_uuid], \array_map(
      fn (ComponentTreeItem $i) => $i->getUuid(),
      iterator_to_array($old_fr_list->componentTreeItemsIterator()),
    ));
    // @phpstan-ignore-next-line offsetAccess.notFound
    self::assertSame('_self', $old_fr_list->getComponentTreeItemByUuid($cta_uuid)?->getInputs()['target'],
      'Old revision FR target must remain unchanged.',
    );
  }

  private static function assertResolvedAndStoredInputsAreIdentical(ComponentTreeItemList $tree): void {
    $instances = iterator_to_array($tree->componentTreeItemsIterator());
    self::assertSame(
      \array_map(fn (ComponentTreeItem $i) => $i->getInputs(), $instances),
      \array_map(fn (ComponentTreeItem $i) => $i->get('inputs_resolved')->getValue(), $instances),
    );
  }

  /**
   * Sets up translation sync for the given entity type + field.
   *
   * For configurable fields (node): uses FieldConfig third-party settings.
   * For base fields (canvas_page): uses BaseFieldOverride third-party settings.
   * BaseFieldOverride implements ThirdPartySettingsInterface, which is what
   * FieldTranslationSynchronizer::getFieldSynchronizationSettings() checks.
   */
  private function setUpSymmetricalContentTranslation(string $entity_type_id, string $bundle, string $field_name): void {
    if ($entity_type_id === 'node') {
      $this->createComponentTreeField('node', 'article', $field_name);
      $this->enableContentTranslation('node', 'article');
      $field_config = FieldConfig::loadByName('node', 'article', $field_name);
      \assert($field_config instanceof FieldConfig);
      $field_config->setTranslatable(TRUE);
      $field_config->setThirdPartySetting('content_translation', 'translation_sync', [
        'inputs' => 'inputs',
        'tree' => 0,
      ]);
      self::assertEntityIsValid($field_config);
      $field_config->save();
    }
    else {
      // canvas_page uses the 'components' base field (already translatable).
      // Create a BaseFieldOverride to store the translation_sync setting.
      // BaseFieldOverride implements ThirdPartySettingsInterface, which is what
      // FieldTranslationSynchronizer::getFieldSynchronizationSettings() checks.
      $this->enableContentTranslation($entity_type_id, $bundle);
      $override = BaseFieldOverride::createFromBaseFieldDefinition(
        // @todo Remove this ignore once core's getBaseFieldDefinitions() return type is fixed.
        // @phpstan-ignore-next-line argument.type
        $this->container->get('entity_field.manager')
          ->getBaseFieldDefinitions($entity_type_id)[$field_name],
        $bundle,
      );
      $override->setThirdPartySetting('content_translation', 'translation_sync', [
        'inputs' => 'inputs',
        'tree' => 0,
      ]);
      self::assertEntityIsValid($override);
      $override->save();
    }
  }

}
