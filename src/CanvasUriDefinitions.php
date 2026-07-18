<?php

declare(strict_types=1);

namespace Drupal\canvas;

/**
 * Provides link relations for the internal HTTP API.
 *
 * @see https://jsonapi.org/format/#document-links
 * @see https://tools.ietf.org/html/rfc8288
 * @see https://www.iana.org/assignments/link-relations/link-relations.xhtml
 *
 * @internal
 */
class CanvasUriDefinitions {

  const string LINK_REL_CREATE = 'create';
  const string LINK_REL_EDIT = 'edit-form';
  const string LINK_REL_DELETE = 'delete-form';
  const string LINK_REL_DUPLICATE = 'https://drupal.org/project/canvas#link-rel-duplicate';
  const string LINK_REL_SET_AS_HOMEPAGE = 'https://drupal.org/project/canvas#link-rel-set-as-homepage';
  // Use core's disable/enable link relations for unpublishing and publishing
  // Canvas Pages. Canvas Pages implement EntityPublishedInterface and use the
  // standard `status` field, making these core link relations semantically
  // appropriate.
  // @see \Drupal\Core\Entity\EntityPublishedInterface
  // @see core/core.link_relation_types.yml
  const string LINK_REL_UNPUBLISH = 'disable';
  const string LINK_REL_PUBLISH = 'enable';
  // @see \Drupal\canvas\Controller\ApiUsageControllers::componentDetails()
  // @todo Add it in \Drupal\canvas\Entity\JavaScriptComponent::getEntityOperations()
  // @phpstan-ignore-next-line shipmonk.deadConstant
  const string LINK_REL_USAGE_DETAILS = 'https://drupal.org/project/canvas#link-rel-usage-details';
  const string LINK_REL_TYPED_DATA_BROWSER = 'https://drupal.org/project/canvas#link-rel-typed-data-browser';
  // Fork/unfork a translation's component tree.
  // @see \Drupal\canvas\Controller\ApiTranslationControllers::fork()
  // @see \Drupal\canvas\Controller\ApiTranslationControllers::unfork()
  const string LINK_REL_FORK = 'https://drupal.org/project/canvas#link-rel-fork';
  const string LINK_REL_UNFORK = 'https://drupal.org/project/canvas#link-rel-unfork';

}
