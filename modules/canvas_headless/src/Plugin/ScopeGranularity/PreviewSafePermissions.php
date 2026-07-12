<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Plugin\ScopeGranularity;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_oauth\Attribute\ScopeGranularity;
use Drupal\simple_oauth\Plugin\ScopeGranularityBase;
use Drupal\user\PermissionHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scope granularity: the preview-safe (view-only) permission ceiling.
 *
 * Simple OAuth's access policy intersects a user-bound token's permissions
 * with its scopes' permissions — scopes narrow, they never grant. This
 * granularity reports the permissions modules have declared preview-safe
 * via hook_canvas_headless_safe_permissions(), so a preview token's
 * effective permissions resolve to *the editor's own permissions, capped
 * to view-only*: write permissions the editor holds (reverting revisions,
 * editing terms, ...) never reach the token.
 *
 * The ceiling is computed, not curated: Drupal permissions carry no
 * machine-readable read/write metadata, so the judgment of what is safe to
 * expose in a read-only preview stays with the module that defines the
 * permission. A hardcoded list would silently strip permissions it does
 * not know about (the intersection model guarantees that failure mode);
 * the hook keeps the ceiling extensible — any module declares its own view
 * permissions and they flow into previews without touching this module.
 * The failure direction is safe: an undeclared permission means a preview
 * shows too little, never too much.
 *
 * The ceiling mediates permission checks and nothing else: access decided
 * outside the permission system (node access grants foremost) follows the
 * token's user binding, untouched by this plugin — see the hook
 * documentation for the boundary.
 *
 * A scope with this granularity grants nothing by itself and must only be
 * issued on tokens bound to a real user (the preview assertion grant
 * guarantees this).
 *
 * @see hook_canvas_headless_safe_permissions()
 * @see \Drupal\canvas_headless\Hook\CanvasHeadlessHooks::safePermissions()
 */
#[ScopeGranularity(
  'canvas_headless_safe_permissions',
  new TranslatableMarkup('Preview-safe permissions (view-only ceiling)'),
)]
final class PreviewSafePermissions extends ScopeGranularityBase implements ContainerFactoryPluginInterface {

  /**
   * The computed ceiling, cached for the request.
   *
   * @var string[]|null
   */
  private ?array $permissions = NULL;

  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    protected ModuleHandlerInterface $moduleHandler,
    protected PermissionHandlerInterface $permissionHandler,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition) {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('module_handler'),
      $container->get('user.permissions'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(array $configuration): void {
    // The ceiling is computed from hook implementations; this granularity
    // stores no configuration. Anything passed in would be silently
    // ignored, so reject it instead.
    if ($configuration !== []) {
      throw new PluginException('The preview-safe permissions granularity accepts no configuration.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission(string $permission): bool {
    return \in_array($permission, $this->getPermissions(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getPermissions(): array {
    if ($this->permissions === NULL) {
      $declared = $this->moduleHandler->invokeAll('canvas_headless_safe_permissions');
      $this->moduleHandler->alter('canvas_headless_safe_permissions', $declared);
      // Only defined permissions: declarations may cover optional modules.
      $this->permissions = array_values(array_intersect(
        array_unique($declared),
        \array_keys($this->permissionHandler->getPermissions()),
      ));
    }
    return $this->permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#markup' => $this->t('This granularity has no configuration: the ceiling is collected from hook_canvas_headless_safe_permissions() implementations.'),
    ];
    return $form;
  }

}
