<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

// cspell:ignore Cliquez

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Hook\ContentTranslationHooks;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraintValidator;
use Drupal\content_translation\BundleTranslationSettingsInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the ComponentTreeSymmetricalTranslation constraint validator.
 *
 * Tests both:
 *  1. with a bundle field (node:article, uses a FieldConfig)
 *  2. with a base field (canvas_page, uses a BaseFieldOverride)
 *
 * Uses the 'my-cta' SDC which has:
 * - text: type: string (translatable)
 * - href: type: string, format: uri (translatable)
 * - target: type: string, enum: [_self, _blank] (NOT translatable — enums)
 */
#[CoversClass(ComponentTreeSymmetricalTranslationConstraintValidator::class)]
#[CoversMethod(ContentTranslationHooks::class, 'entityTypeAlter')]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class ComponentTreeSymmetricalTranslationConstraintValidatorTest extends ContentComponentTreeSymmetricalTranslationTestBase {

  use ConstraintViolationsTestTrait;

  #[TestWith(['node', 'article', 'field_canvas_test'], 'bundle field (FieldConfig)')]
  #[TestWith([Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'], 'base field (BaseFieldOverride)')]
  public function test(string $entity_type_id, string $bundle, string $field_name): void {
    $this->setUpSymmetricalContentTranslation($entity_type_id, $bundle, $field_name);
    $entity_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage($entity_type_id);

    $entity = $this->createEntityWithDefaultTranslation($entity_type_id, $bundle, $field_name, $entity_storage);
    $entity->save();
    $entity_id = $entity->id();
    self::assertNotNull($entity_id);

    $target_violation = [
      '' => "Non-translatable component input key '<em class=\"placeholder\">target</em>' in component '<em class=\"placeholder\">" . self::CTA_UUID . "</em>' differs from the default translation in the '<em class=\"placeholder\">fr</em>' translation.",
    ];

    // 1. All inputs identical to default translation — no violation.
    self::assertEntityIsValid($this->buildFrTranslation($entity_storage, $entity_id, $field_name, [
      'text' => 'Click here',
      'href' => 'https://drupal.org',
      'target' => '_self',
    ]));

    // 2. Non-translatable key differs from default — violation.
    // (Bypassing the synchronizer to simulate a hypothetical direct DB write.)
    self::assertSame($target_violation, self::violationsToArray($this->buildFrTranslation($entity_storage, $entity_id, $field_name, [
      'text' => 'Cliquez ici',
      'href' => 'https://drupal.fr',
      // Differs from default '_self'.
      'target' => '_blank',
    ])->validate()));

    // 3. Translatable inputs differ, non-translatable matches — no violation.
    self::assertEntityIsValid($this->buildFrTranslation($entity_storage, $entity_id, $field_name, [
      // 'text' and 'href' differ from default — OK, they are translatable.
      'text' => 'Cliquez ici',
      'href' => 'https://drupal.fr',
      // Same as default — valid.
      'target' => '_self',
    ]));

    // 4. Non-translatable key absent in non-default translation — violation.
    // NULL differs from the default '_self'.
    self::assertSame($target_violation, self::violationsToArray($this->buildFrTranslation($entity_storage, $entity_id, $field_name, [
      'text' => 'Cliquez ici',
      'href' => 'https://drupal.fr',
      // 'target' intentionally omitted — defaults to NULL, differs from '_self'.
    ])->validate()));
  }

  /**
   * Builds a fresh (unsaved) FR translation for a single validation scenario.
   *
   * Reloads from storage each call to avoid addTranslation() conflicts between
   * scenarios.
   */
  private function buildFrTranslation(mixed $entity_storage, int|string $entity_id, string $field_name, array $fr_inputs): ContentEntityInterface {
    $fresh = $entity_storage->loadUnchanged($entity_id);
    \assert($fresh instanceof ContentEntityInterface);
    $translation = $fresh->addTranslation('fr');
    $this->container->get(BundleTranslationSettingsInterface::class)
      ->getTranslationMetadata($translation)
      ->setSource($fresh->language()->getId());
    $translation->set('title', 'French title')->set($field_name, self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => self::CTA_UUID,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => $fr_inputs,
      ],
    ]));
    return $translation;
  }

}
