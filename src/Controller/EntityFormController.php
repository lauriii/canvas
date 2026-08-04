<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class EntityFormController extends ControllerBase {

  use EntityFormTrait;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly RequestStack $requestStack,
    protected ThemeHandlerInterface $themeHandler,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
  ) {
  }

  public function form(string $entity_type, FieldableEntityInterface $entity, string $entity_form_mode): array {
    // @phpstan-ignore-next-line property.notFound
    if (!$this->themeHandler->themeExists('canvas_stark') || !$this->themeHandler->listInfo()['canvas_stark']->status) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('The canvas_stark theme must be enabled for this form to work.'),
      ];
    }

    // The 'default' value sent to
    // `\Drupal\Core\Entity\EntityTypeManagerInterface::getFormObject`
    // is for 'operation' not form mode.
    $form = $this->entityTypeManager()->getFormObject($entity_type, 'default');
    $form_entity = $entity;
    // The form structure is fetched from Canvas via a GET request. Any
    // subsequent updates to the form via AJAX use Drupal's standard POST
    // request. We only want to fetch the entity from auto-save if we're
    // requesting the original form. If the form is being updated by AJAX, the
    // entity field values in form-state should be used instead.
    if ($this->requestStack->getCurrentRequest()?->getMethod() === 'GET') {
      $autoSave = $this->autoSaveManager->getAutoSaveEntity($entity);
      if (!$autoSave->isEmpty()) {
        \assert($autoSave->entity instanceof FieldableEntityInterface);
        $form_entity = $autoSave->entity;
      }
    }
    elseif ($entity->id() !== NULL) {
      // AJAX POST rebuild: core appended `workspace` + `token` query
      // parameters to the form's AJAX URL because the GET above rendered the
      // form while the Canvas auto-save workspace was active, so
      // QueryParameterWorkspaceNegotiator re-activates that workspace before
      // routing and the upcast $entity is the staged pending revision. Field
      // widgets size their multi-value tables from the entity's item count
      // (items_count), and the staged revision already contains drafted items
      // the client's form still shows in its trailing empty row — rebuilding
      // from it renders extra empty rows (e.g. "Add new" appears to add two).
      // The submitted form values carry the client's state, so build from the
      // Live copy, exactly as this endpoint did before workspace staging.
      // @see \Drupal\workspaces\Negotiator\QueryParameterWorkspaceNegotiator
      // @see \Drupal\Core\Field\WidgetBase::formMultipleElements()
      $live = $this->workspaceAutoSave->loadUnchangedOutsideWorkspace($entity->getEntityTypeId(), $entity->id());
      if ($live instanceof ContentEntityInterface && $entity instanceof ContentEntityInterface && $live->hasTranslation($entity->language()->getId())) {
        $live = $live->getTranslation($entity->language()->getId());
      }
      if ($live instanceof FieldableEntityInterface) {
        $form_entity = $live;
      }
    }
    $form_state = $this->buildFormState($form, $form_entity, $entity_form_mode);

    return $this->formBuilder()->buildForm($form, $form_state);
  }

}
