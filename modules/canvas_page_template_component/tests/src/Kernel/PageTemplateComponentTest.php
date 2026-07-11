<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_page_template_component\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_page_template_component\PageTemplateComponentUninstallValidator;
use Drupal\canvas_page_template_component\Plugin\Canvas\ComponentSource\ThemePageTemplate;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests theme page template component generation and uninstall protection.
 *
 * @see \Drupal\canvas_page_template_component\Plugin\Canvas\ComponentSource\ThemePageTemplate
 * @see \Drupal\canvas_page_template_component\PageTemplateComponentUninstallValidator
 */
#[CoversFunction('canvas_page_template_component_ensure_component')]
#[CoversFunction('canvas_page_template_component_install')]
#[Group('canvas')]
final class PageTemplateComponentTest extends CanvasKernelTestBase {

  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::PAGE_TEST_MODULES;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The uninstall path audits component usage in canvas_page entities and
    // triggers user module hooks.
    // @see \Drupal\canvas\ComponentDependencyUninstallValidator
    $this->installPageEntitySchema();
    $this->installSchema('user', ['users_data']);
    // Installs the "Page content" marker component.
    // @see config/install/canvas.component.marker.page_content.yml
    $this->installConfig(['canvas']);
    $this->container->get(ThemeInstallerInterface::class)->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
  }

  /**
   * Tests component generation, idempotency, and the uninstall validator.
   */
  public function testComponentGenerationAndUninstallValidation(): void {
    // Installing the module generates a component for the default theme only.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_page_template_component']);
    // Re-fetch the module installer: the pre-install instance does not know
    // the uninstall validator this module registers.
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $component = Component::load('theme_page_template.stark');
    self::assertInstanceOf(Component::class, $component);
    self::assertEntityIsValid($component);
    self::assertSame('Stark page template', $component->label());
    self::assertSame(ThemePageTemplate::SOURCE_PLUGIN_ID, $component->get('source'));

    // The theme's regions become the component's slots, including the
    // `content` slot where page variants place the "Page content" marker.
    $source = $component->getComponentSource();
    self::assertInstanceOf(ThemePageTemplate::class, $source);
    $slots = \array_keys($source->getSlotDefinitions());
    self::assertContains('content', $slots);
    self::assertContains('sidebar_first', $slots);

    // Re-running the generation returns the existing component.
    $again = canvas_page_template_component_ensure_component('stark');
    self::assertInstanceOf(Component::class, $again);
    self::assertSame($component->uuid(), $again->uuid());

    // A theme that is not installed gets no component.
    self::assertNull(canvas_page_template_component_ensure_component('olivero'));

    // While no page variant uses the component, uninstalling is allowed.
    $validator = $this->container->get(PageTemplateComponentUninstallValidator::class);
    self::assertInstanceOf(PageTemplateComponentUninstallValidator::class, $validator);
    self::assertSame([], $validator->validate('canvas_page_template_component'));

    // Place the component in a page variant: uninstalling is now blocked.
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);
    $template_uuid = 'b53d5c15-4b2f-40b7-8f28-be6a04e0323f';
    $variant = PageVariant::create([
      'id' => 'stark_variant',
      'label' => 'Stark variant',
      'component_tree' => [
        [
          'uuid' => $template_uuid,
          'component_id' => 'theme_page_template.stark',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [],
        ],
        [
          'uuid' => '0f0d5c15-4b2f-40b7-8f28-be6a04e0323f',
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'parent_uuid' => $template_uuid,
          'slot' => 'content',
          'inputs' => [],
        ],
      ],
    ]);
    self::assertEntityIsValid($variant);
    $variant->save();

    $reasons = $validator->validate('canvas_page_template_component');
    self::assertCount(1, $reasons);
    self::assertSame(
      'The <em class="placeholder">Stark page template</em> component is used in 1 page variant. Remove it from that variant first.',
      (string) $reasons[0],
    );
    try {
      $module_installer->uninstall(['canvas_page_template_component']);
      $this->fail('Uninstalling must be blocked while a page variant uses the component.');
    }
    catch (ModuleUninstallValidatorException $e) {
      self::assertStringContainsString('page variant', $e->getMessage());
    }

    // After deleting the variant, uninstalling succeeds and removes the
    // component (it carries an enforced dependency on this module).
    $variant->delete();
    self::assertTrue($module_installer->uninstall(['canvas_page_template_component']));
    self::assertNull(Component::load('theme_page_template.stark'));
  }

}
