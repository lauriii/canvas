<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates non-translatable component inputs match the default translation.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraint
 */
final class ComponentTreeSymmetricalTranslationConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly FieldTranslationSynchronizerInterface $synchronizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(FieldTranslationSynchronizerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ComponentTreeSymmetricalTranslationConstraint);

    if (!$value instanceof ContentEntityInterface) {
      return;
    }

    $translations = $value->getTranslationLanguages();
    if (\count($translations) < 2) {
      return;
    }

    $default_entity = $value->getUntranslated();
    $default_langcode = $default_entity->language()->getId();

    foreach ($default_entity->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      if (!ComponentTreeFieldSymmetricalTranslationSynchronizer::isSymmetricallyTranslated($this->synchronizer, $field_definition)) {
        continue;
      }

      $default_tree = $default_entity->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);

      foreach ($translations as $langcode => $language) {
        if ($langcode === $default_langcode) {
          continue;
        }
        $translation = $value->getTranslation($langcode);
        // Forked translations own an independent tree; their per-field
        // constraints still validate it in isolation.
        if (ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($translation)) {
          continue;
        }
        $target_tree = $translation->get($field_name);
        \assert($target_tree instanceof ComponentTreeItemList);

        foreach ($target_tree->componentTreeItemsIterator() as $target_item) {
          \assert($target_item instanceof ComponentTreeItem);
          $uuid = $target_item->getUuid();
          $default_item = $default_tree->getComponentTreeItemByUuid($uuid);
          if ($default_item === NULL) {
            // Structural mismatch (missing UUID in default) is a different
            // invariant, covered by other constraints.
            continue;
          }

          $default_inputs = $default_item->getInputs() ?? [];
          $target_inputs = $target_item->getInputs() ?? [];

          $inputs_data = $default_item->get('inputs');
          \assert($inputs_data instanceof ComponentInputs);
          $translatable_keys = \array_flip($inputs_data->getTranslatableInputKeys());
          $non_translatable_default = \array_diff_key($default_inputs, $translatable_keys);

          foreach ($non_translatable_default as $key => $expected_value) {
            $actual_value = $target_inputs[$key] ?? NULL;
            if ($actual_value !== $expected_value) {
              $this->context->addViolation($constraint->message, [
                '%key' => $key,
                '%uuid' => $uuid,
                '%langcode' => $langcode,
              ]);
            }
          }
        }
      }
    }
  }

}
