<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Config;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Url;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\canvas\Functional\FunctionalTestBase;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Component On Dependency Removal.
 *
 * @legacy-covers \Drupal\canvas\Entity\Component::onDependencyRemoval
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class ComponentOnDependencyRemovalTest extends FunctionalTestBase {

  use ApiRequestTrait;
  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'test_theme_child';

  public function switchDefaultTheme(bool $withUninstall): void {
    $theme_installer = $this->container->get(ThemeInstallerInterface::class);
    $theme_installer->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();
    if ($withUninstall) {
      $theme_installer->uninstall(['test_theme_child']);
    }
  }

  public function testComponentDeletedOnThemeUninstallIfUnused(): void {
    $this->assertInstanceOf(Component::class, Component::load('sdc.test_theme_child.test-child'));

    // Install a different theme, set as default, and uninstall our previous theme.
    $this->switchDefaultTheme(TRUE);

    // The component is gone as expected.
    $this->assertNull(Component::load('sdc.test_theme_child.test-child'));
  }

  public function testComponentUsesFallbackOnThemeUninstallIfUsedInContent(): void {
    $page = Page::create([
      'title' => 'My non-empty page',
      'components' => [
        [
          'uuid' => '02b766f7-0edc-4359-98bb-3f489e878330',
          'component_id' => 'sdc.test_theme_child.test-child',
          'inputs' => [
            'title' => 'This component is used.',
          ],
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();

    // Before: one Component version, only content entity usages.
    $component_before = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_before);
    self::assertCount(1, $component_before->getVersions());
    self::assertTrue(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::All));
    self::assertFalse(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::AutoSave));

    // Install a different theme, set as default, and uninstall our previous theme.
    $this->switchDefaultTheme(TRUE);

    // The component has a new fallback version as expected.
    $component_after = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_after);
    self::assertCount(2, $component_after->getVersions());
    $this->assertSame(Fallback::PLUGIN_ID, $component_after->getLoadedVersion());
  }

  public function testComponentUsesFallbackOnThemeUninstallIfUsedInAutoSave(): void {
    $page = Page::create(['title' => 'My empty page']);
    self::assertCount(0, $page->validate());
    $page->save();

    // After saving, use the Component in the tree, validate, and auto-save.
    $page->setComponentTree([
      [
        'uuid' => '02b766f7-0edc-4359-98bb-3f489e878330',
        'component_id' => 'sdc.test_theme_child.test-child',
        'inputs' => [
          'title' => 'This component is used.',
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $auto_save */
    $auto_save = \Drupal::service(AutoSaveManager::class);
    $auto_save->saveEntity($page);

    // Before: one Component version, only auto-save usages.
    $component_before = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_before);
    self::assertCount(1, $component_before->getVersions());
    self::assertFalse(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::All));
    self::assertTrue(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::AutoSave));

    // Install a different theme, set as default, and uninstall our previous theme.
    $this->switchDefaultTheme(TRUE);

    // The component has a new fallback version as expected.
    $component_after = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_after);
    self::assertCount(2, $component_after->getVersions());
    $this->assertSame(Fallback::PLUGIN_ID, $component_after->getLoadedVersion());
  }

  /**
   * Opening the props form of such a component must render, not fatal.
   *
   * Once the SDC's theme is no longer the default theme, Canvas omits the
   * Component from the list of available Components, so the Canvas UI cannot
   * build a client model for it and sends `undefined` when its props form is
   * opened. The Component is still discoverable, so it is not "broken", yet the
   * server must render the Fallback form when the client model is absent, rather
   * than passing the decoded NULL to ::clientModelToInput() (typed `array`),
   * which triggers a fatal TypeError.
   *
   * @see \Drupal\canvas\Form\ComponentInstanceForm::buildForm()
   * @see \Drupal\canvas\Entity\Component::refineListQuery()
   */
  public function testComponentInstanceFormRendersOnThemeNotDefault(): void {
    $component_id = 'sdc.test_theme_child.test-child';
    $component_instance_uuid = '02b766f7-0edc-4359-98bb-3f489e878330';
    $page = Page::create([
      'title' => 'My non-empty page',
      'components' => [
        [
          'uuid' => $component_instance_uuid,
          'component_id' => $component_id,
          'inputs' => [
            'title' => 'This component is used.',
          ],
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();

    // Switch the default theme away from the SDC's theme, but keep it installed.
    $this->switchDefaultTheme(FALSE);

    // The Component is still enabled and is not "broken".
    $component = Component::load($component_id);
    $this->assertInstanceOf(Component::class, $component);
    self::assertFalse($component->getComponentSource()->isBroken());

    // Log in as a user allowed to edit the page's component tree.
    $account = $this->createUser([Page::EDIT_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->drupalLogin($account);

    // Open the component instance props form the way the Canvas UI does, but
    // with `undefined` as the client model, because the UI cannot model this
    // component. Before the fix, the server passed the decoded NULL to
    // ::clientModelToInput() (typed `array`) and this triggered a fatal error.
    // After the fix, the Fallback form is rendered instead.
    $url = Url::fromRoute('canvas.api.form.component_instance', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ]);
    // ComponentInstanceForm reads `form_canvas_tree` and `form_canvas_props`
    // from the request body, so they must be sent as form parameters, not query
    // parameters.
    // @see \Drupal\canvas\Form\ComponentInstanceForm::buildForm()
    $request_options = [
      RequestOptions::FORM_PARAMS => [
        'form_canvas_tree' => Json::encode([
          'nodeType' => 'component',
          'slots' => [],
          'type' => $component_id . '@' . $component->getActiveVersion(),
          'uuid' => $component_instance_uuid,
        ]),
        'form_canvas_props' => 'undefined',
        'form_canvas_selected' => $component_instance_uuid,
      ],
      RequestOptions::HEADERS => [
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
    ];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('Component is missing. Fix the component or copy values to a new component.', (string) $response->getBody());
  }

}
