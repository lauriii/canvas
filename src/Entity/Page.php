<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\media\Entity\MediaType;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the page entity class.
 *
 * @todo change add-form and edit-form links to use `page` instead of `xb_page`.
 *    This requires updating the UI to use the values from `drupalSettings.xb`
 *    without them matching the URL path. If they don't routing in the UI is
 *    broken and the UI never renders. See `empty-canvas.cy.js`.
 *    Fix after https://www.drupal.org/project/experience_builder/issues/3489775
 *
 * @ContentEntityType(
 *   id = "xb_page",
 *   label = @Translation("Page"),
 *   label_collection = @Translation("Pages"),
 *   label_singular = @Translation("page"),
 *   label_plural = @Translation("pages"),
 *   label_count = @PluralTranslation(
 *     singular = "@count page",
 *     plural = "@count pages",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = \Drupal\experience_builder\Entity\PageAccessControlHandler::class,
 *     "view_builder" = "Drupal\experience_builder\Entity\PageViewBuilder",
 *     "views_data" = "Drupal\Core\Entity\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\experience_builder\Entity\XbPageForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "revision-delete" = \Drupal\Core\Entity\Form\RevisionDeleteForm::class,
 *       "revision-revert" = \Drupal\Core\Entity\Form\RevisionRevertForm::class,
 *     },
 *     "route_provider" = {
 *       "html" = \Drupal\experience_builder\Entity\Routing\XbHtmlRouteProvider::class,
 *       "revision" = \Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider::class,
 *     }
 *   },
 *   admin_permission = "administer xb_page",
 *   collection_permission = "administer xb_page",
 *   base_table = "xb_page",
 *   revision_table = "xb_page_revision",
 *   data_table = "xb_page_field_data",
 *   revision_data_table = "xb_page_field_revision",
 *   show_revision_ui = TRUE,
 *   links = {
 *     "canonical" = "/page/{xb_page}",
 *     "delete-form" = "/page/{xb_page}/delete",
 *     "edit-form" = "/xb/xb_page/{xb_page}",
 *     "add-form" = "/xb/xb_page",
 *     "revision-delete-form" = "/page/{xb_page}/revisions/{xb_page_revision}/delete",
 *     "revision-revert-form" = "/page/{xb_page}/revisions/{xb_page_revision}/revert",
 *     "version-history" = "/page/{xb_page}/revisions",
 *   },
 *   translatable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "revision" = "revision_id",
 *     "label" = "title",
 *     "langcode" = "langcode",
 *     "published" = "status",
 *     "owner" = "owner",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log"
 *   },
 * )
 */
final class Page extends EditorialContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += self::ownerBaseFieldDefinitions($entity_type);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
      ])
      ->setDisplayConfigurable('form', TRUE);
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Meta description'))
      ->setDescription(t('The meta description of the page.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'text_textfield',
      ])
      ->setDisplayConfigurable('form', TRUE);
    $fields['components'] = BaseFieldDefinition::create('component_tree')
      ->setLabel(t('Components'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'component_tree',
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'experience_builder_naive_render_sdc_tree',
      ]);
    // @see path_entity_base_field_info().
    $fields['path'] = BaseFieldDefinition::create('path')
      ->setLabel(t('URL alias'))
      ->setTranslatable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'path',
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setComputed(TRUE);
    $fields['status']
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time the page was created.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValueCallback(self::class . '::getRequestTime');
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the page was last edited.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);
    $fields['image'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Image'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'media')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => self::getImageMediaTypes(),
      ])
      ->setDisplayOptions('form', [
        'type' => 'media_library_widget',
        'settings' => [
          // Leave empty so that the allowed media types are delegated to the
          // `handler_settings.target_bundles` setting.
          'media_types' => [],
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);
    return $fields;
  }

  /**
   * Gets the request time.
   */
  public static function getRequestTime(): int {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * Gets the media type IDs that use the `image` field type.
   *
   * @return array
   *   The media type IDs that use the `image` field type.
   */
  private static function getImageMediaTypes(): array {
    $media_types = MediaType::loadMultiple();
    $target_bundles = [];
    foreach ($media_types as $media_type) {
      /** @var array{allowed_field_types: list<string>} $media_source_plugin_definition */
      $media_source_plugin_definition = $media_type->getSource()->getPluginDefinition();
      if (in_array('image', $media_source_plugin_definition['allowed_field_types'], TRUE)) {
        $target_bundles[] = $media_type->id();
      }
    }
    return $target_bundles;
  }

}
