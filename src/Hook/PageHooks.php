<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\pathauto\PathautoItem;
use Drupal\pathauto\PathautoState;

/**
 * @file
 * Hook implementations that makes Canvas's Page content entity type work.
 *
 * @see https://www.drupal.org/project/issues/canvas?component=Page
 * @see docs/adr/0004-page-entity-type.md
 */
final class PageHooks {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    $fields = [];
    if ($entity_type->id() === Page::ENTITY_TYPE_ID) {
      // Modules providing an entity type cannot add dynamic base fields based
      // on other modules. The entity field manager determines if a field should
      // be installed based on its "provider", which is the module providing the
      // field definition. All fields from an entity's `baseFieldDefinitions`
      // are always set to the provider of the entity type.
      //
      // To work around this limitation, we provide the base field definition in
      // this hook, where we can specify the provider as the Metatag module.
      //
      // @see \Drupal\Core\Entity\EntityFieldManager::buildBaseFieldDefinitions()
      // @see \Drupal\Core\Extension\ModuleInstaller::install()
      if ($this->moduleHandler->moduleExists('metatag')) {
        $fields['metatags'] = BaseFieldDefinition::create('metatag')
          ->setLabel(new TranslatableMarkup('Metatags'))
          ->setDescription(new TranslatableMarkup('The meta tags for the entity.'))
          ->setTranslatable(\TRUE)
          ->setDisplayOptions('form', [
            'type' => 'metatag_firehose',
            'settings' => ['sidebar' => \TRUE, 'use_details' => \TRUE],
          ])
          ->setDisplayConfigurable('form', \TRUE)
          ->setDefaultValue(Json::encode([
            'title' => '[canvas_page:title] | [site:name]',
            'description' => '[canvas_page:description]',
            'canonical_url' => '[canvas_page:url]',
            // @see https://stackoverflow.com/a/19274942
            'image_src' => '[canvas_page:image:entity:field_media_image:entity:url]',
          ]))
          ->setInternal(\TRUE)
          ->setProvider('metatag');
      }
    }
    return $fields;
  }

  /**
   * Implements hook_entity_access().
   *
   * Prevents the deletion of entity whose path is set as homepage.
   *
   * @todo Move to non-Page-specific hooks in https://www.drupal.org/i/3498525
   */
  #[Hook('entity_access')]
  public function preventHomepageDeletion(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'delete' && $entity instanceof FieldableEntityInterface) {
      $system_config = $this->configFactory->get('system.site');
      $homepage = $system_config->get('page.front');
      try {
        $url = $entity->toUrl('canonical');
        $path_alias = $url->toString();
        $internal_path = '/' . $url->getInternalPath();
        $paths = array_unique([$path_alias, $internal_path]);
      }
      catch (\Exception) {
        // If the entity does not have a canonical URL, we cannot check the
        // path.
        return AccessResult::neutral();
      }
      if (\in_array($homepage, $paths, TRUE)) {
        return AccessResult::forbidden()
          ->addCacheableDependency($system_config)
          ->addCacheableDependency($entity)
          ->setReason('This entity cannot be deleted because its path is set as the homepage.');
      }
      return AccessResult::neutral()->addCacheableDependency($system_config);
    }
    return AccessResult::neutral();
  }

  /**
   * Implements hook_canvas_page_presave().
   *
   * Programmatically uncheck the "Generate automatic URL alias" checkbox.
   *
   * @see \pathauto_field_info_alter()
   * @see \Drupal\pathauto\PathautoItem::propertyDefinitions()
   * @see \Drupal\pathauto\PathautoWidget::formElement()
   */
  #[Hook('canvas_page_presave')]
  public function ensurePathautoSkipped(Page $page): void {
    if ($this->moduleHandler->moduleExists('pathauto')) {
      // Path items with an explicit empty alias are filtered out as empty
      // before presave hooks run, so pages without an alias need a fresh item
      // here.
      // @see \Drupal\Core\Entity\ContentEntityStorageBase::invokeHook()
      // @see \Drupal\Core\Field\FieldItemList::preSave()
      $pathauto_item = $page->get('path')->first() ?? $page->get('path')->appendItem();
      \assert($pathauto_item instanceof PathautoItem);
      $pathauto_item->set('pathauto', PathautoState::SKIP);
    }
  }

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   *
   * An empty `page_variant` selection means "follow the site default": the
   * page re-resolves whenever the default changes, instead of pinning the
   * current default's id. Present that as "Site default" (with the current
   * default's label) instead of the widget's "- None -".
   *
   * @see \Drupal\canvas\PageVariantResolver
   */
  #[Hook('field_widget_single_element_options_select_form_alter')]
  public function pageVariantSelectionEmptyOption(array &$element, FormStateInterface $form_state, array $context): void {
    $items = $context['items'] ?? NULL;
    if (!$items instanceof FieldItemListInterface
      || $items->getEntity()->getEntityTypeId() !== Page::ENTITY_TYPE_ID
      || $items->getFieldDefinition()->getName() !== 'page_variant'
      || !isset($element['#options']['_none'])) {
      return;
    }
    $default_id = $this->configFactory->get('canvas.settings')->get(PageVariant::DEFAULT_SETTING);
    $default = \is_string($default_id) ? PageVariant::load($default_id) : NULL;
    $element['#options']['_none'] = $default instanceof PageVariant
      ? new TranslatableMarkup('Site default (@label)', ['@label' => $default->label()])
      : new TranslatableMarkup('Site default');
  }

  /**
   * Implements hook_gin_ignore_sticky_form_actions().
   *
   * Make sure the media library works in the Canvas sidebar with Gin.
   *
   * @todo This should be fixed in Gin at https://www.drupal.org/i/3554265.
   * @todo If not, https://www.drupal.org/i/3498525 should generalize this to
   *   all eligible content entity types.
   */
  #[Hook('gin_ignore_sticky_form_actions')]
  public static function ignoreGinStickyForm(): array {
    return ['canvas_page_form'];
  }

}
