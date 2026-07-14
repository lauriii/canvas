<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates EntityChanged against Live, ignoring the Canvas workspace.
 *
 * Core's validator compares the validated entity's changed timestamp with
 * loadUnchanged(), which is workspace-aware. The Canvas auto-save workspace
 * is active during Canvas API requests, and every staged auto-save flush
 * writes a new draft revision whose changed timestamp advances to that
 * request's time. Comparing a client edit against the staged draft therefore
 * records false "modified by another user" conflicts: a request that started
 * one second before a concurrent request's flush landed always loses. The
 * meaningful comparison is with the Live entity — exactly what core compared
 * against before Canvas staged auto-saves in a workspace. Concurrent edits to
 * the staged draft itself are detected separately, via the autoSaves hashes.
 *
 * @see \Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator
 * @see \Drupal\canvas\Controller\ApiLayoutController::validateAutoSaves()
 * @see \Drupal\canvas\AutoSave\Workspace\DeferredAutoSaveFlusher
 */
final class CanvasAwareEntityChangedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface|null
     */
    private readonly ?object $workspaceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->has('workspaces.manager') ? $container->get('workspaces.manager') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    \assert($constraint instanceof EntityChangedConstraint);
    if (!$entity instanceof ContentEntityInterface || !$entity instanceof EntityChangedInterface || $entity->isNew()) {
      return;
    }
    $load = fn (): ?EntityInterface => $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    // With the Canvas auto-save workspace active, loadUnchanged() returns the
    // staged draft revision; compare against the Live copy instead. Any other
    // active workspace keeps core's behavior.
    $saved_entity = $this->workspaceManager !== NULL
      && $this->workspaceManager->getActiveWorkspace()?->id() === AutoSaveWorkspace::ID
      ? $this->workspaceManager->executeOutsideWorkspace($load)
      : $load();
    if (!$saved_entity instanceof ContentEntityInterface || !$saved_entity instanceof EntityChangedInterface) {
      return;
    }
    // Mirrors the per-translation comparison in core's validator.
    // @see \Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator::validate()
    $common_translation_languages = \array_intersect_key($entity->getTranslationLanguages(), $saved_entity->getTranslationLanguages());
    foreach (\array_keys($common_translation_languages) as $langcode) {
      $saved_translation = $saved_entity->getTranslation($langcode);
      $validated_translation = $entity->getTranslation($langcode);
      \assert($saved_translation instanceof EntityChangedInterface);
      \assert($validated_translation instanceof EntityChangedInterface);
      if ($saved_translation->getChangedTime() > $validated_translation->getChangedTime()) {
        $this->context->addViolation($constraint->message);
        break;
      }
    }
  }

}
