<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
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

}
