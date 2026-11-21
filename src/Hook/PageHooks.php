<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\path_alias\AliasRepositoryInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly AliasRepositoryInterface $aliasRepository,
    private readonly CanvasNotificationHandler $notificationHandler,
  ) {
  }

  /**
   * Implements hook_modules_installed().
   *
   * Provides the canvas_page metatag defaults in code, not as optional config:
   * recipes that import all of Canvas' config do not check optional config's
   * dependencies, so they would fail on sites without metatag.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if (!$is_syncing && \array_intersect(['canvas', 'metatag'], $modules)) {
      self::ensureMetatagDefaults($this->moduleHandler, $this->entityTypeManager);
    }
  }

  /**
   * Creates the canvas_page metatag defaults if metatag is installed.
   *
   * The canonical URL is a config default instead of a field default, so
   * metatag's front page defaults can override it for the homepage.
   *
   * @see \canvas_post_update_0031_canvas_page_canonical_url_metatag_default()
   */
  public static function ensureMetatagDefaults(ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager): void {
    if (!$module_handler->moduleExists('metatag')) {
      return;
    }
    $storage = $entity_type_manager->getStorage('metatag_defaults');
    if ($storage->load(Page::ENTITY_TYPE_ID) === NULL) {
      $storage->create([
        'id' => Page::ENTITY_TYPE_ID,
        'label' => 'Canvas page',
        'tags' => ['canonical_url' => '[canvas_page:url]'],
        'dependencies' => ['enforced' => ['module' => ['canvas']]],
      ])->save();
    }
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
   * Implements hook_canvas_page_delete().
   *
   * Removes the deleted page from the Folder that lists it.
   *
   * @see \Drupal\canvas\Entity\Folder::calculateDependencies()
   */
  #[Hook('canvas_page_delete')]
  public static function removeFromFolder(Page $page): void {
    Folder::loadByItemAndConfigEntityTypeId((string) $page->uuid(), Page::ENTITY_TYPE_ID)
      ?->removeItem((string) $page->uuid())
        ->save();
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() and hook_ENTITY_TYPE_update().
   *
   * Keeps the alias of each page in a folder under the URL path of the folder.
   * The new alias is an unpublished change of the page: the live URL changes
   * when the page is published. A notification lists the live URLs that will
   * change.
   *
   * @see docs/adr/0027-page-folders-can-set-the-url-path-of-their-pages.md
   */
  #[Hook('folder_insert')]
  #[Hook('folder_update')]
  public function rewritePageAliases(Folder $folder): void {
    // A config import brings a folder, not the wish to change URLs.
    if ($folder->get('configEntityTypeId') !== Page::ENTITY_TYPE_ID || $folder->isSyncing()) {
      return;
    }
    $original = $folder->getOriginal();
    $old_path = $original instanceof Folder ? $original->getPath() : NULL;
    $old_items = $original instanceof Folder ? $original->get('items') : [];
    $path = $folder->getPath();
    $items = $folder->get('items');

    // Page UUID to the folder path its alias has, and the one it gets.
    $moves = [];
    foreach ($items as $uuid) {
      $moves[$uuid] = [\in_array($uuid, $old_items, TRUE) ? $old_path : NULL, $path];
    }
    foreach (\array_diff($old_items, $items) as $uuid) {
      $moves[$uuid] = [$old_path, NULL];
    }
    $moves = \array_filter($moves, static fn (array $move): bool => $move[0] !== $move[1]);
    if ($moves === []) {
      return;
    }

    // ponytail: one auto-save write per page inside the folder save, and only
    // the default translation. Queue it if folders hold thousands of pages.
    $pages = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID)->loadByProperties(['uuid' => \array_keys($moves)]);
    // The live URLs that change, and those that no longer do, keyed by page
    // UUID.
    $url_changes = [];
    $urls_kept = [];
    foreach ($pages as $page) {
      \assert($page instanceof Page);
      $auto_save = $this->autoSaveManager->getAutoSaveEntity($page);
      // A copy: the stored page must not change in this request.
      $draft = $auto_save->isEmpty() ? clone $page : $auto_save->entity;
      \assert($draft instanceof Page);
      $alias = (string) $draft->get('path')->alias;
      $moved = self::moveAlias($alias, (string) $draft->label(), ...$moves[$page->uuid()]);
      if ($moved === $alias) {
        continue;
      }
      // ponytail: checks stored aliases only. An alias waiting in another
      // page's unpublished changes is not seen, as for aliases typed by hand.
      $internal_path = '/' . $page->toUrl()->getInternalPath();
      $unique = $moved;
      for ($i = 2; ($taken = $this->aliasRepository->lookupByAlias($unique, $page->language()->getId())) && $taken['path'] !== $internal_path; $i++) {
        $unique = "$moved-$i";
      }
      $draft->get('path')->alias = $unique;
      $this->autoSaveManager->saveEntity($draft);
      $live_alias = (string) $page->get('path')->alias;
      if (!$page->isPublished()) {
        continue;
      }
      $change = [
        'title' => (string) $draft->label(),
        'from' => $live_alias === '' ? $internal_path : $live_alias,
        'to' => $unique,
      ];
      if ($live_alias === $unique) {
        $urls_kept[(string) $page->uuid()] = $change;
      }
      else {
        $url_changes[(string) $page->uuid()] = $change;
      }
    }

    // A notification replaces an earlier warning with the same key. Moving a
    // page between two folders saves both folders, and moving it back takes
    // the warning away.
    $key = static fn (array $urls): string => \count($urls) === 1
      ? 'canvas-page-url:' . \array_key_first($urls)
      : 'canvas-folder-path:' . $folder->uuid();
    if (\count($url_changes) === 1) {
      $change = reset($url_changes);
      $this->notificationHandler->create([
        'type' => 'warning',
        'key' => $key($url_changes),
        'title' => \sprintf('%s will get a new URL', $change['title']),
        'message' => \sprintf('It moves from %s to %s when you publish it.', $change['from'], $change['to']),
      ]);
    }
    elseif ($url_changes !== []) {
      $this->notificationHandler->create([
        'type' => 'warning',
        'key' => $key($url_changes),
        'title' => \sprintf('%d pages in %s will get new URLs', \count($url_changes), $folder->label()),
        'message' => $path === NULL
          ? 'They lose the URL path of the folder when you publish them.'
          : \sprintf('They move under %s when you publish them.', $path),
      ]);
    }
    if (\count($urls_kept) === 1) {
      $change = reset($urls_kept);
      $this->notificationHandler->create([
        'type' => 'success',
        'key' => $key($urls_kept),
        'title' => \sprintf('%s keeps its URL', $change['title']),
        'message' => \sprintf('It stays at %s.', $change['to']),
      ]);
    }
    elseif ($urls_kept !== []) {
      $this->notificationHandler->create([
        'type' => 'success',
        'key' => $key($urls_kept),
        'title' => \sprintf('%d pages in %s keep their URLs', \count($urls_kept), $folder->label()),
        'message' => 'Their URLs no longer change.',
      ]);
    }
  }

  /**
   * Moves an alias from the URL path of one folder to that of another.
   *
   * @param string $alias
   *   The alias, or an empty string for a page that has none.
   * @param string $title
   *   The page title: a page that has no alias gets a slug of it.
   * @param string|null $from
   *   The folder path the alias has now, if any.
   * @param string|null $to
   *   The folder path the alias gets, if any.
   */
  public static function moveAlias(string $alias, string $title, ?string $from, ?string $to): string {
    if ($to !== NULL && ($alias === $to || str_starts_with($alias, "$to/"))) {
      // Already there. This is also the landing page of the folder.
      return $alias;
    }
    $own = $alias;
    if ($from !== NULL && ($alias === $from || str_starts_with($alias, "$from/"))) {
      $own = substr($alias, \strlen($from));
    }
    if ($alias === '' && $to !== NULL) {
      // The same steps as getPathAlias() in the path widget, so that the
      // widget recognizes the alias as generated and keeps following the
      // title.
      // @see ui/src/components/form/components/drupal/DrupalPathWidget.tsx
      $slug = (string) preg_replace(['/\s+/', '/[^\w-]+/', '/_+/', '/--+/'], ['-', '', '-', '-'], mb_strtolower($title));
      $slug = trim($slug, '-');
      $own = '/' . ($slug === '' ? 'page' : $slug);
    }
    // A landing page that leaves its folder has no part of its own: it keeps
    // its alias.
    $moved = ($to ?? '') . $own;
    return $moved === '' ? $alias : $moved;
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
   * current default's id. Present that as "Site default" instead of the
   * widget's "- None -".
   *
   * @see \Drupal\canvas\PageVariantResolver
   */
  #[Hook('field_widget_single_element_options_select_form_alter')]
  public static function pageVariantSelectionEmptyOption(array &$element, FormStateInterface $form_state, array $context): void {
    $items = $context['items'] ?? NULL;
    if (!$items instanceof FieldItemListInterface
      || $items->getEntity()->getEntityTypeId() !== Page::ENTITY_TYPE_ID
      || $items->getFieldDefinition()->getName() !== 'page_variant'
      || !isset($element['#options']['_none'])) {
      return;
    }
    $element['#options']['_none'] = new TranslatableMarkup('Site default');
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
