<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Form;

use Drupal\canvas_personalization\Entity\SegmentInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Personalization Segment rule form.
 *
 * ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @todo Revisit in https://www.drupal.org/i/3527086
 */
final class SegmentRuleForm extends EntityForm {

  public function __construct(protected SegmentConditionManager $segmentConditionManager) {}

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(SegmentConditionManager::class),
    );
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    \assert($this->entity instanceof SegmentInterface);
    $segment_rules = $this->entity->getSegmentRules();

    // A segment can hold at most one instance of each condition type.
    $condition_definitions = \array_filter(
      $this->segmentConditionManager->getDefinitions(),
      fn ($condition_definition) => \is_array($condition_definition) && !\array_key_exists($condition_definition['id'], $segment_rules),
    );
    \ksort($condition_definitions);
    $condition_options = \array_map(fn ($condition_definition) => $condition_definition['label'], $condition_definitions);

    if (!empty($condition_options)) {
      $form['plugin_id'] = [
        '#title' => $this->t('Condition'),
        '#type' => 'select',
        '#options' => $condition_options,
        '#ajax' => [
          'callback' => [$this, 'pluginSelectedCallback'],
          'disable-refocus' => FALSE,
          'event' => 'change',
          'wrapper' => 'edit-settings',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Loading condition settings...'),
          ],
        ],
      ];

      $condition_id = $form_state->getValue('plugin_id') ?? \array_key_first($condition_options);
      $condition = $this->segmentConditionManager->createInstance($condition_id);
      \assert($condition instanceof SegmentConditionInterface);
      $condition_form = $condition->buildConfigurationForm([], $form_state);
      $condition_form['#title'] = $this->t('Settings');
      $form['settings'] = [
        '#prefix' => '<div id="edit-settings">',
        '#type' => 'container',
        '#tree' => TRUE,
        '#suffix' => '</div>',
      ];
      $form['settings'] += $condition_form;
    }
    else {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No applicable conditions found.') . '</p>',
      ];
    }
    return $form;
  }

  public static function pluginSelectedCallback(array &$form, FormStateInterface $form_state): array {
    return $form['settings'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $condition_id = $form_state->getValue('plugin_id');

    // The Segment form puts all plugin form elements in the
    // settings form element, so just pass that to the plugin for submission.
    $sub_form_state = SubformState::createForSubform($form['settings'], $form, $form_state);
    // Call the plugin submit handler.
    $condition = $this->segmentConditionManager->createInstance($condition_id);
    \assert($condition instanceof SegmentConditionInterface);
    $condition->submitConfigurationForm($form, $sub_form_state);

    \assert($this->entity instanceof SegmentInterface);
    $this->entity->addSegmentRule($condition_id, $condition->getConfiguration());
    parent::submitForm($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new personalization segment %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated personalization segment %label.', $message_args),
        // See https://www.drupal.org/i/3264370
        default => $this->t('Saved personalization segment %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));
    return $result;
  }

}
