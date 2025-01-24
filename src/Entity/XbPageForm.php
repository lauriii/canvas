<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the XbPage entity forms.
 *
 * Overrides the default form to add SEO settings and other customizations.
 */
final class XbPageForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $group = 'seo_settings';
    $form[$group] = [
      '#type' => 'details',
      '#group' => 'advanced',
      '#weight' => -10,
      '#title' => $this->t('SEO settings'),
      '#tree' => TRUE,
      // Not keeping it open messes up media library ajax
      // when rendering the form in XB UI.
      // @todo remove this once https://www.drupal.org/project/experience_builder/issues/3501626 lands.
      '#open' => TRUE,
    ];
    $form[$group]['image'] = $form['image'];
    $form[$group]['image']['#weight'] = -10;
    // TRICKY: it seems there's a Drupal core bug wrt #group, long-term fix TBD.
    // @see https://git.drupalcode.org/project/experience_builder/-/merge_requests/501#note_448716
    unset($form['image']);
    $form[$group]['description'] = $form['description'];
    $form[$group]['description']['#weight'] = 10;
    unset($form['description']);
    if (isset($form['metatags']['widget'][0]['basic']['title'])) {
      $form['metatags']['widget'][0]['basic']['title']['#group'] = $group;
    }
    return $form;
  }

}
