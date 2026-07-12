<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Hook;

use Drupal\canvas\Controller\CanvasController;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Hook implementations for Drupal Canvas Headless.
 */
class CanvasHeadlessHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_canvas_headless_safe_permissions().
   *
   * The module's own baseline: viewing content, which covers published
   * Canvas pages and the Custom Elements API rendering. Declarations for
   * optional modules are harmless — the granularity plugin drops undefined
   * permissions. Two of them come from content_moderation ('view any
   * unpublished content', 'view latest version'), so on sites without that
   * module the baseline narrows to node's own 'view own unpublished
   * content': previews show other authors' unpublished nodes only where a
   * module provides — and declares — a broader view permission.
   *
   * The baseline is a literal list because it cannot be computed. An entity
   * type definition links an access control handler, but the handler does
   * not enumerate the permission strings it consults — permissions carry no
   * machine-readable relationship to entity types, nor to read vs. write.
   * Only the module defining a permission knows whether it is view-only,
   * and that is exactly the judgment the hook delegates: modules providing
   * other entity types declare their own view permissions there.
   *
   * Access decided outside the permission system — node access grants via
   * hook_node_grants() foremost — is outside the ceiling entirely and
   * follows the token's user binding instead; see the hook documentation.
   */
  #[Hook('canvas_headless_safe_permissions')]
  public function safePermissions(): array {
    $permissions = [
      'access content',
      'view media',
      'view own unpublished content',
      'view any unpublished content',
      'view latest version',
      'view all revisions',
      // Unpublished Canvas pages have no view-only permission: the access
      // handler derives their view access from these write permissions, so
      // declaring them preview-safe is the only way a preview token can
      // render an unpublished Canvas page. This is the ceiling's one
      // deliberate exception to view-only; the intersection still limits
      // the token to permissions the editor already holds. See ADR-0014.
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      Page::DELETE_PERMISSION,
    ];
    // Per-bundle revision viewing, for editors who hold it instead of the
    // site-wide permission. The node module is optional here, hence the
    // entity type guard.
    if ($this->entityTypeManager->hasDefinition('node_type')) {
      $node_types = $this->entityTypeManager->getStorage('node_type')
        ->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      foreach ($node_types as $type) {
        $permissions[] = "view $type revisions";
      }
    }
    return $permissions;
  }

  /**
   * Implements hook_js_settings_alter().
   *
   * Tells the Canvas editor UI to embed the configured frontend app in the
   * editor frame instead of the Drupal-rendered preview. Injected on every
   * Canvas boot route, for users who may mint preview assertions. Every
   * value is static configuration — the edited entity is unknown to the
   * server here (CanvasPathProcessor rewrites all editor paths to /canvas,
   * so the React app resolves the entity from the browser URL), and nothing
   * session-bound travels here either: the UI fetches assertions from the
   * CSRF-protected minting endpoint, passing the entity it is editing.
   *
   * Matched by controller rather than an enumerated route list: the same
   * single-page app boots from every CanvasController route (the empty,
   * entity, and extension deep-link routes), and client-side navigation
   * moves between them without another server request, so the settings must
   * be available wherever the app boots.
   *
   * @see \Drupal\canvas\PathProcessor\CanvasPathProcessor
   * @see \Drupal\canvas\Controller\CanvasController
   */
  #[Hook('js_settings_alter')]
  public function jsSettingsAlter(array &$settings): void {
    $route = $this->routeMatch->getRouteObject();
    $controller = $route === NULL ? '' : (string) $route->getDefault('_controller');
    if ($controller !== CanvasController::class && !\str_starts_with($controller, CanvasController::class . '::')) {
      return;
    }
    if (!$this->currentUser->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)) {
      return;
    }

    $config = $this->configFactory->get('canvas_headless.settings');
    // The URL becomes the editor frame's iframe src, carrying the activation
    // assertion in its query string, so it must be an unambiguous web URL —
    // never an executable scheme, never a host a browser would resolve
    // differently than PHP. The schema enforces this on save; this is the
    // runtime backstop for values that never went through validation,
    // failing closed by not embedding anything. Both the embedded URL and
    // the origin messages are checked against come from one canonical parse,
    // so the two can never describe different sites.
    $frontend = FrontendUrl::fromConfig((string) $config->get('frontend_url'));
    if ($frontend === NULL) {
      return;
    }

    $settings['canvas']['headless'] = [
      'frontendOrigin' => $frontend->origin,
      'draftUrl' => $frontend->baseUrl . $config->get('draft_path'),
      'assertionUrl' => Url::fromRoute('canvas_headless.assertion')->toString(TRUE)->getGeneratedUrl(),
    ];
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for consumer forms.
   *
   * Locks the fields of the module's consumer that redemption is keyed to,
   * leaving the rest — label, description, logo, expiration, enabled —
   * editable:
   * - the client ID: assertions name it in their "azp" claim and the grant
   *   refuses an assertion issued to a different client, so renaming the
   *   consumer strands every exchange,
   * - the grant types: the preview assertion exchange must stay enabled or
   *   redemption stops, and adding any other grant would widen what this
   *   client can do at the token endpoint,
   * - the confidential flag: a confidential client must authenticate with a
   *   secret the frontend app does not have — the signed assertion is the
   *   credential.
   * Locking grant types also keeps the grant-specific sections (scopes,
   * redirect URIs, PKCE, service user, refresh tokens) permanently hidden,
   * since their visibility follows the grant checkboxes.
   *
   * The secret and third-party fields are hidden outright instead: a secret
   * is never validated for a public client, and third-party consent only
   * exists in redirect-based flows this client can never use, so both
   * fields could only mislead. The expiration field stays editable — the
   * grant caps the issued lifetime regardless — and gets a note saying so.
   *
   * Matched on the client_id rather than the provisioned-entity UUID in
   * state: redemption looks consumers up by client_id, so an adopted
   * pre-existing consumer carries the same constraints as a provisioned
   * one. Ordered after simple_oauth, whose alter adds the new_secret
   * element this one hides.
   */
  #[Hook('form_consumer_form_alter', order: new OrderAfter(['simple_oauth']))]
  public function formConsumerFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface || $form_object->getOperation() === 'delete') {
      return;
    }
    $consumer = $form_object->getEntity();
    if (!$consumer instanceof ConsumerInterface || $consumer->getClientId() !== PreviewAssertionFactory::CLIENT_ID) {
      return;
    }

    // #disabled inherits to the nested widgets and makes the form builder
    // reuse the stored values on submit, so the locked fields still render
    // what the consumer is configured as.
    $form['client_id']['#disabled'] = TRUE;
    $form['client_id']['generate']['#access'] = FALSE;
    $form['client_id']['widget'][0]['value']['#description'] = $this->t('Managed by Drupal Canvas Headless. Preview assertions are issued to this client ID, so it cannot change.');

    $form['grant_types']['#disabled'] = TRUE;
    $form['grant_types']['widget']['#description'] = $this->t('Managed by Drupal Canvas Headless. The preview assertion exchange is the only grant this client may use.');

    $form['confidential']['#disabled'] = TRUE;
    $form['confidential']['widget']['value']['#description'] = $this->t('Managed by Drupal Canvas Headless. This is a public client: the signed preview assertion is its credential, so there is no secret to authenticate with.');

    if (isset($form['new_secret'])) {
      $form['new_secret']['#access'] = FALSE;
    }
    $form['third_party']['#access'] = FALSE;

    $expiration = &$form['access_token_expiration']['widget'][0]['value'];
    $cap_note = $this->t('Drupal Canvas Headless caps preview tokens at @minutes minutes at issuance, so a larger value here has no effect.', [
      '@minutes' => intdiv(PreviewAssertionGrant::MAX_TOKEN_TTL_SECONDS, 60),
    ]);
    $expiration['#description'] = trim(($expiration['#description'] ?? '') . ' ' . $cap_note);
  }

}
