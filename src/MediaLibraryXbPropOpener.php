<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\media_library\MediaLibraryFieldWidgetOpener;
use Drupal\media_library\MediaLibraryState;

/**
 * The media library opener for XB props.
 *
 * @see \Drupal\experience_builder\Form\ComponentInputsForm
 * @see experience_builder_field_widget_single_element_media_library_widget_form_alter()
 *
 * @internal
 *   This is an internal part of Media Library's Experience Builder integration.
 */
final class MediaLibraryXbPropOpener extends MediaLibraryFieldWidgetOpener {

  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function checkAccess(MediaLibraryState $state, AccountInterface $account) {
    // `field_widget_id` is necessary for the inherited, unaltered
    // `::getSelectionResponse()` method.
    $parameters = $state->getOpenerParameters();
    if (!array_key_exists('field_widget_id', $parameters)) {
      return AccessResult::forbidden("field_widget_id parameter is missing.")->addCacheableDependency($state);
    }

    // No further access checking is necessary: this can only be reached if XB
    // triggered this, plus MediaLibraryState::fromRequest() already validated
    // the hash.
    // @see \Drupal\media_library\MediaLibraryState::fromRequest()
    // @see experience_builder_field_widget_single_element_media_library_widget_form_alter()
    assert($state->isValidHash($state->getHash()));
    // Still, in case this URL is shared, still require that the current session
    // is for a user that has sufficient permissions to use XB.
    return AccessResult::allowedIfHasPermission($account, 'access administration pages');
  }

}
