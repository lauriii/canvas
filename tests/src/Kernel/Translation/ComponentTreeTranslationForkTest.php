<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

// cspell:ignore Cliquez Klicken

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests per-translation component tree forks (asymmetric translations).
 *
 * A forked translation owns an independent component tree:
 * - it is excluded from symmetric synchronization in both directions,
 * - it is exempt from the symmetric translation constraint,
 * - unforking destructively re-syncs it from the default translation while
 *   preserving its translatable input values for surviving components.
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
 */
#[CoversClass(ComponentTreeFieldSymmetricalTranslationSynchronizer::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class ComponentTreeTranslationForkTest extends ContentComponentTreeSymmetricalTranslationTestBase {

  use ConstraintViolationsTestTrait;

  private const string SECOND_UUID = '22222222-2222-4222-8222-222222222222';
  private const string FORK_ONLY_UUID = '33333333-3333-4333-8333-333333333333';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_dev_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Builds a raw CTA component instance array for the given UUID and inputs.
   *
   * @return array{uuid: string, component_id: string, component_version: string, inputs: array{text: string, href: string, target: string}}
   */
  private static function cta(string $uuid, string $text, string $target = '_self'): array {
    return [
      'uuid' => $uuid,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => [
        'text' => $text,
        'href' => 'https://drupal.org',
        'target' => $target,
      ],
    ];
  }

  /**
   * Returns a translation's component tree item list, typed.
   */
  private static function tree(ContentEntityInterface $translation, string $field_name): ComponentTreeItemList {
    $tree = $translation->get($field_name);
    \assert($tree instanceof ComponentTreeItemList);
    return $tree;
  }

  /**
   * Adds a translation seeded from the default translation's tree.
   */
  private function addSeededTranslation(ContentEntityInterface $entity, string $langcode, string $field_name, string $text): ContentEntityInterface {
    $translation = $entity->addTranslation($langcode);
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($translation)
      ->setSource($entity->getUntranslated()->language()->getId());
    $translation->set('title', "$langcode title");
    $translation->set($field_name, $entity->getUntranslated()->get($field_name)->getValue());
    $item = self::tree($translation, $field_name)->getComponentTreeItemByUuid(self::CTA_UUID);
    self::assertNotNull($item);
    $item->setInput([
      'text' => $text,
      'href' => 'https://drupal.org',
      'target' => '_self',
    ]);
    return $translation;
  }

  #[TestWith(['node', 'article', 'field_canvas_test'], 'bundle field (FieldConfig)')]
  #[TestWith([Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'], 'base field (BaseFieldOverride)')]
  public function test(string $entity_type_id, string $bundle, string $field_name): void {
    $this->setUpSymmetricalContentTranslation($entity_type_id, $bundle, $field_name);
    $entity_storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);

    $entity = $this->createEntityWithDefaultTranslation($entity_type_id, $bundle, $field_name, $entity_storage);
    $entity->save();

    // 1. The fork flag exists (canvas_dev_translation + content_translation
    // are installed) and defaults to FALSE for new translations.
    $fr = $this->addSeededTranslation($entity, 'fr', $field_name, 'Cliquez ici');
    $fr->save();
    $de = $this->addSeededTranslation($entity, 'de', $field_name, 'Klicken Sie');
    $de->save();
    self::assertTrue($fr->hasField(ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME));
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($fr));
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($de));
    // The default translation is never considered forked, even if flagged.
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($entity->getUntranslated()));

    // 2. Fork French. Only the flag flips: the tree is already the fork seed.
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    $fr = $entity->getTranslation('fr');
    $fr->set(ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME, TRUE);
    $fr->save();
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    self::assertTrue(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($entity->getTranslation('fr')));

    // 3. Saving the default translation with a structural edit (new component,
    // changed non-translatable input) syncs the non-forked German sibling but
    // leaves the forked French tree byte-identical.
    $fr_values_before = $entity->getTranslation('fr')->get($field_name)->getValue();
    $default = $entity->getUntranslated();
    $default->set($field_name, self::populateActiveComponentVersionPlaceholders([
      self::cta(self::CTA_UUID, 'Click here updated', '_blank'),
      self::cta(self::SECOND_UUID, 'Second CTA'),
    ]));
    $default->save();

    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    // German followed the default translation: new component, new
    // non-translatable input value, translated text preserved.
    $de_tree = self::tree($entity->getTranslation('de'), $field_name);
    self::assertNotNull($de_tree->getComponentTreeItemByUuid(self::SECOND_UUID));
    self::assertSame('_blank', $de_tree->getComponentTreeItemByUuid(self::CTA_UUID)?->getInputs()['target'] ?? NULL);
    self::assertSame('Klicken Sie', $de_tree->getComponentTreeItemByUuid(self::CTA_UUID)?->getInputs()['text'] ?? NULL);
    // French kept its own tree, byte-identical.
    self::assertSame($fr_values_before, $entity->getTranslation('fr')->get($field_name)->getValue());
    self::assertNull(self::tree($entity->getTranslation('fr'), $field_name)->getComponentTreeItemByUuid(self::SECOND_UUID));

    // 4. Saving the forked French translation with tree changes (remove the
    // shared CTA, add a fork-only component) propagates nothing to the default
    // translation or the German sibling.
    $default_values_before = $entity->getUntranslated()->get($field_name)->getValue();
    $de_values_before = $entity->getTranslation('de')->get($field_name)->getValue();
    $fr = $entity->getTranslation('fr');
    $fr->set($field_name, self::populateActiveComponentVersionPlaceholders([
      self::cta(self::CTA_UUID, 'Cliquez ici', '_self'),
      self::cta(self::FORK_ONLY_UUID, 'Uniquement en français', '_blank'),
    ]));
    $fr->save();

    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    self::assertSame($default_values_before, $entity->getUntranslated()->get($field_name)->getValue());
    self::assertSame($de_values_before, $entity->getTranslation('de')->get($field_name)->getValue());
    self::assertNotNull(self::tree($entity->getTranslation('fr'), $field_name)->getComponentTreeItemByUuid(self::FORK_ONLY_UUID));

    // 5. The symmetric translation constraint exempts the forked translation:
    // its non-translatable 'target' input diverges from the default
    // translation ('_self' vs '_blank'), yet the entity validates cleanly.
    self::assertEntityIsValid($entity);

    // 6. A non-forked translation is still validated: diverging the German
    // non-translatable 'target' raises the existing violation.
    $de_item = self::tree($entity->getTranslation('de'), $field_name)->getComponentTreeItemByUuid(self::CTA_UUID);
    self::assertNotNull($de_item);
    $de_item->setInput(['text' => 'Klicken Sie', 'href' => 'https://drupal.org', 'target' => '_self']);
    $cta_uuid = self::CTA_UUID;
    self::assertSame(
      ['' => "Non-translatable component input key '<em class=\"placeholder\">target</em>' in component '<em class=\"placeholder\">$cta_uuid</em>' differs from the default translation in the '<em class=\"placeholder\">de</em>' translation."],
      self::violationsToArray($entity->validate()),
    );

    // 7. An empty forked tree stays empty when the default translation is
    // saved again.
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    $fr = $entity->getTranslation('fr');
    $fr->set($field_name, []);
    $fr->save();
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    self::assertTrue(self::tree($entity->getTranslation('fr'), $field_name)->isEmpty());
    $default = $entity->getUntranslated();
    $default->set($field_name, self::populateActiveComponentVersionPlaceholders([
      self::cta(self::CTA_UUID, 'Click here again', '_blank'),
      self::cta(self::SECOND_UUID, 'Second CTA'),
    ]));
    $default->save();
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    self::assertTrue(self::tree($entity->getTranslation('fr'), $field_name)->isEmpty());
    self::assertFalse(self::tree($entity->getTranslation('de'), $field_name)->isEmpty());

    // 8. Unfork re-sync: with the fork carrying a translated CTA and a
    // fork-only component, re-syncing replaces the tree with the default
    // translation's, preserves the fork's translatable input values for
    // surviving UUIDs, takes non-translatable values from the default, and
    // discards fork-only components.
    $fr = $entity->getTranslation('fr');
    $fr->set($field_name, self::populateActiveComponentVersionPlaceholders([
      self::cta(self::CTA_UUID, 'Cliquez encore', '_self'),
      self::cta(self::FORK_ONLY_UUID, 'Uniquement en français', '_blank'),
    ]));
    $fr->save();
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    $fr = $entity->getTranslation('fr');
    $fr->set(ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME, FALSE);
    ComponentTreeFieldSymmetricalTranslationSynchronizer::resyncFromDefaultTranslation($fr);

    $fr_tree = self::tree($fr, $field_name);
    // Same structure as the default translation.
    self::assertNull($fr_tree->getComponentTreeItemByUuid(self::FORK_ONLY_UUID));
    self::assertNotNull($fr_tree->getComponentTreeItemByUuid(self::SECOND_UUID));
    $fr_cta = $fr_tree->getComponentTreeItemByUuid(self::CTA_UUID);
    self::assertNotNull($fr_cta);
    // Translatable input preserved from the fork, non-translatable from the
    // default translation.
    self::assertSame('Cliquez encore', $fr_cta->getInputs()['text'] ?? NULL);
    self::assertSame('_blank', $fr_cta->getInputs()['target'] ?? NULL);
    // The second component only existed in the default tree: default inputs.
    self::assertSame('Second CTA', $fr_tree->getComponentTreeItemByUuid(self::SECOND_UUID)?->getInputs()['text'] ?? NULL);

    // After unfork the translation is symmetric again: saving it must satisfy
    // the symmetric constraint and follow future default-translation edits.
    self::assertEntityIsValid($fr);
    $fr->save();
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($entity->getTranslation('fr')));
    $default = $entity->getUntranslated();
    $default_item = self::tree($default, $field_name)->getComponentTreeItemByUuid(self::CTA_UUID);
    self::assertNotNull($default_item);
    $default_item->setInput(['text' => 'Click here again', 'href' => 'https://drupal.org', 'target' => '_self']);
    $default->save();
    $entity = $entity_storage->loadUnchanged($entity->id());
    \assert($entity instanceof ContentEntityInterface);
    self::assertSame('_self', self::tree($entity->getTranslation('fr'), $field_name)->getComponentTreeItemByUuid(self::CTA_UUID)?->getInputs()['target'] ?? NULL);
  }

}
