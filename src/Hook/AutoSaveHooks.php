<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\AutoSave\AutoSaveManager;

/**
 * @file
 * Hook implementations for XB's auto-save functionality.
 *
 * @see \Drupal\experience_builder\AutoSave\AutoSaveManager
 */
class AutoSaveHooks {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->autoSaveManager->delete($entity);
  }

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (str_ends_with($form_id, '_revision_revert') || str_ends_with($form_id, '_revision_revert_confirm')) {
      assert($form_state->getFormObject() instanceof EntityFormInterface);
      $entity = $form_state->getFormObject()->getEntity();
      if (!$this->autoSaveManager->getAutoSaveEntity($entity)->isEmpty()) {
        $form['actions']['submit']['#submit'][] = [self::class, 'revisionRevertSubmit'];
        $form['xb_auto_save_warning'] = [
          '#theme' => 'status_messages',
          '#message_list' => [
            'warning' => [
              new TranslatableMarkup('This page has unpublished changed in Experience Builder. Reverting to this revision will delete the auto-saved changes.'),
            ],
          ],
          '#status_headings' => [
            'warning' => new TranslatableMarkup('Warning'),
          ],
          '#weight' => -10,
        ];
      }
    }
  }

  /**
   * Submit handler for the revision revert form.
   *
   * Deletes the auto-saved version of the entity when reverting a revision.
   */
  public static function revisionRevertSubmit(array &$form, FormStateInterface $form_state): void {
    assert($form_state->getFormObject() instanceof EntityFormInterface);
    $entity = $form_state->getFormObject()->getEntity();
    if ($entity instanceof EntityInterface) {
      // Delete the auto-saved version of the entity.
      \Drupal::service(AutoSaveManager::class)->delete($entity);
    }
  }

}
