<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\ConfigImportValidateEventSubscriberBase;
use Drupal\Core\Config\TypedConfigManagerInterface;

/**
 * Rejects importing asymmetrical translation config for Canvas Pages.
 *
 * Config imports do not run validation constraints, so without this
 * subscriber an import could silently put the `components` base field of
 * Canvas Pages in the unsupported asymmetrical translation mode (component
 * tree marked translatable). The constraints in
 * config/schema/canvas_symmetrical_translations_only.schema.yml are the
 * single source of truth: this subscriber validates the incoming config and
 * reports only the violations for the `translation_sync` setting.
 *
 * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3571130
 */
final class SymmetricalTranslationsConfigImportValidator extends ConfigImportValidateEventSubscriberBase {

  private const string CONFIG_NAME = 'core.base_field_override.canvas_page.canvas_page.components';

  private const string PROPERTY_PATH_PREFIX = 'third_party_settings.content_translation.translation_sync';

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onConfigImporterValidate(ConfigImporterEvent $event): void {
    $config_importer = $event->getConfigImporter();
    $storage_comparer = $config_importer->getStorageComparer();
    $change_list = $storage_comparer->getChangelist();
    if (!\in_array(self::CONFIG_NAME, [...$change_list['create'], ...$change_list['update']], TRUE)) {
      return;
    }
    $data = $storage_comparer->getSourceStorage()->read(self::CONFIG_NAME);
    if (!\is_array($data)) {
      return;
    }
    $violations = $this->typedConfigManager
      ->createFromNameAndData(self::CONFIG_NAME, $data)
      ->validate();
    foreach ($violations as $violation) {
      if (\str_starts_with((string) $violation->getPropertyPath(), self::PROPERTY_PATH_PREFIX)) {
        $config_importer->logError((string) $this->t('Unable to import @config_name (@property_path: @message): Canvas Pages support only symmetrical translations — component input values are translatable, the component tree is shared across languages.', [
          '@config_name' => self::CONFIG_NAME,
          '@property_path' => $violation->getPropertyPath(),
          '@message' => (string) $violation->getMessage(),
        ]));
      }
    }
  }

}
