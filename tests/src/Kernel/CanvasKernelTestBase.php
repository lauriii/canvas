<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\config_translation\Form\ConfigTranslationFormBase;
use Drupal\config_translation\FormElement\ElementInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;

/**
 * Base class for Canvas kernel tests.
 *
 * Provides a standardized environment for low-level tests of Canvas. This:
 * - installs Canvas' dependencies (direct and indirect)
 * - enables strict config validation (which is disabled by default for contrib
 *   modules)
 *
 * Note that this does not install any content entity schemas, not even Canvas'
 * own, so that tests can opt in to installing only the ones they need, and thus
 * avoid the overhead of installing and uninstalling them for every single test.
 *
 * Use this class for every Canvas kernel test except if there is a specific
 * reason *not* to do that. Then that reason should be documented in the test's
 * docblock with a comment that starts with
 * @code
 * Note this cannot use CanvasKernelTestBase because
 * @endcode
 * Most such kernel tests should at least install the modules listed in
 * CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES.
 *
 * @see \Canvas\Sniffs\Tests\KernelTestBaseSniff
 */
abstract class CanvasKernelTestBase extends KernelTestBase {

  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = TRUE;

  /**
   * Minimal set of modules that must be installed for Canvas kernel tests.
   */
  public const CANVAS_KERNEL_TEST_MINIMAL_MODULES = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'canvas',
    // Canvas' dependencies (see canvas.info.yml).
    'block',
    'editor',
    'ckeditor5',
    'filter',
    'text',
    'datetime',
    'file',
    'image',
    'link',
    'media_library',
    'options',
    'path',
    // Canvas' indirect dependencies.
    'filter',
    'media',
    'path_alias',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // Test components.
    'canvas_test_sdc',
  ];

  /**
   * {@inheritdoc}
   *
   * Ensures all of Canvas' hard dependencies are installed, but not any content
   * entity types — not even Canvas' own.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['stark']);
    $this->installConfig([
      // Needed for date formats.
      // @see core/modules/system/config/install/core.date_format.html_date.yml
      // @see core/modules/system/config/install/core.date_format.html_datetime.yml
      // @see \Drupal\datetime\Plugin\Field\FieldWidget\DateTimeDefaultWidget::formElement()
      'system',
      // Canvas' default config includes:
      // - an image style needed by many tests.
      // - the global asset library
      // - …
      'canvas',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    if ($this->strictConfigSchema) {
      // Opt in to config validation, despite this being contrib.
      $container->getDefinition('testing.config_schema_checker')->setArgument(2, TRUE);
    }
  }

  /**
   * Asserts that an entity is valid, with helpful output if it is not.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *
   * @return void
   *
   * @see \Canvas\PHPStan\Rules\KernelTestsMustUseAssertEntityIsValidRule
   */
  protected static function assertEntityIsValid(ContentEntityInterface|ConfigEntityInterface $entity): void {
    $violations = match(TRUE) {
      $entity instanceof ConfigEntityInterface => $entity->getTypedData()->validate(),
      default => $entity->validate(),
    };
    self::assertSame([], self::violationsToArray($violations), $entity->getConfigTarget());
  }

  /**
   * Saves a config entity translation.
   *
   * Respects the architecture of:
   * - Drupal core's config override system (LanguageConfigFactoryOverride)
   * - Drupal core's `language`-provided config storage (LanguageConfigOverride)
   * - Drupal core's `config_translation`-powered "store only relevant subset"
   *
   * @see \Drupal\config_translation\Form\ConfigTranslationFormBase::submitForm()
   * @see \Drupal\config_translation\FormElement\FormElementBase::setConfig()
   * @see \Drupal\language\Config\LanguageConfigFactoryOverride::onConfigSave()
   * @see \Drupal\Core\Config\ConfigFactoryOverrideBase::filterOverride()
   * @see https://git.drupalcode.org/project/tmgmt/-/blob/8.x-1.x/sources/tmgmt_config/src/Plugin/tmgmt/Source/ConfigSource.php?ref_type=heads#L292
   * @see https://www.drupal.org/project/canvas/issues/3582464#comment-16536158
   * @see https://www.drupal.org/project/canvas/issues/3582464#comment-16536240
   */
  protected function saveConfigEntityTranslation(ComponentTreeConfigEntityBase $canvas_config_entity, string $langcode, array $translation_values): void {
    // TRICKY: Config entities always have langcode `en`; this is immutable!
    // @see \Drupal\Core\Config\Entity\ConfigEntityBase::$langcode
    self::assertSame('en', $canvas_config_entity->language()->getId());

    $typed_config = $this->container->get(TypedConfigManagerInterface::class);
    \assert($typed_config instanceof TypedConfigManagerInterface);
    $config_factory = $this->container->get(ConfigFactoryInterface::class);
    \assert($config_factory instanceof ConfigFactoryInterface);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $name = $canvas_config_entity->getConfigDependencyName();
    $schema = $typed_config->get($name);

    // Set configuration values based on translation and original (English)
    // values: save only the actually changed values.
    // @see \Drupal\locale\LocaleConfigManager::filterOverride()
    // @see \Drupal\Core\Config\ConfigFactoryOverrideBase::filterOverride()
    $base_config = $config_factory->getEditable($name);
    $config_translation = $language_manager->getLanguageConfigOverride($langcode, $name);

    $element = ConfigTranslationFormBase::createFormElement($schema);
    \assert($element instanceof ElementInterface);
    // TRICKY: this matches the behavior of \Drupal\Core\Config\ConfigFactoryOverrideBase::filterOverride().
    $element->setConfig($base_config, $config_translation, $translation_values);

    $saved_config = $config_translation->get();
    if (empty($saved_config)) {
      // Nothing to delete if this was auto-generated.
      if ($config_translation->isNew()) {
        return;
      }
      // If zero config entity values are translated, delete the translation.
      $config_translation->delete();
    }
    else {
      $config_translation->save();
    }
  }

}
