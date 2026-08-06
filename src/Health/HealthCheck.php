<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

/**
 * The distinct checks of Canvas data that Doctor runs.
 *
 * @internal
 */
enum HealthCheck: string {

  case CodeTaggedReleases = 'code_tagged_releases';
  // @see canvas.post_update.php
  case UpdatesExecuted = 'update_path_executed';
  // @see \Drupal\canvas\CanvasConfigUpdater::needs*()
  case UpdatesEscapedConfig = 'update_path_escaped_config';
  case Config = 'config';
  case Content = 'content';
  case ContentPastRevisions = 'content_past_revisions';
  case ContentForwardRevisions = 'content_forward_revisions';
  // @see \Drupal\canvas\AutoSave\AutoSaveManager
  case AutoSave = 'auto_save';
  // @see \Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface
  case ComponentSourceSchemaEvolution = 'component_source_supports_schema_evolution';

  /**
   * Whether this is a system check or a data check.
   *
   * System checks run via Doctor::run*Check(); data checks are streamed by
   * Doctor::run(). Single source of truth for that split.
   */
  public function isSystemCheck(): bool {
    return match ($this) {
      self::CodeTaggedReleases,
      self::UpdatesExecuted,
      self::UpdatesEscapedConfig,
      self::ComponentSourceSchemaEvolution => TRUE,
      self::Config,
      self::Content,
      self::ContentPastRevisions,
      self::ContentForwardRevisions,
      self::AutoSave => FALSE,
    };
  }

  /**
   * Whether this check's findings are advisory risks rather than failures.
   *
   * Risks never make a run unhealthy. Auto-save snapshots capture incomplete,
   * mid-edit work and are allowed to be invalid by design (publishing is the
   * validation gate); dev checkouts and missing instance updaters are hazards,
   * not broken data. Single source of truth for the risk/problem split.
   */
  public function findingsAreRisks(): bool {
    return match ($this) {
      self::CodeTaggedReleases,
      self::AutoSave,
      self::ComponentSourceSchemaEvolution => TRUE,
      self::UpdatesExecuted,
      self::UpdatesEscapedConfig,
      self::Config,
      self::Content,
      self::ContentPastRevisions,
      self::ContentForwardRevisions => FALSE,
    };
  }

  /**
   * The canonical description of each check: what it verifies, how to act.
   */
  public function explanation(): string {
    return match ($this) {
      self::CodeTaggedReleases => 'Whether every installed extension is at a tagged release. Development checkouts are an advisory risk: they disable incremental result reuse.',
      self::UpdatesExecuted => 'Canvas schema version and applied vs. pending post-updates. Pending updates make all results untrustworthy.',
      self::UpdatesEscapedConfig => 'Config entities with a pending data-model migration detectable via CanvasConfigUpdater::needs*().',
      self::ComponentSourceSchemaEvolution => 'Whether each ComponentSource plugin can auto-update existing instances to a new component version — a risk, not a failure. See https://www.drupal.org/node/3521221.',
      self::Config => 'Validates every Canvas config entity, and its translations transitively (via CanvasConfigEntityTranslationsAreValidConstraint).',
      self::Content => 'Validates the default revision of every content entity holding a component_tree field, and each translation.',
      self::ContentPastRevisions => 'Validates past (superseded) revisions. Failures are often unresolvable (the content was deliberately changed); a separate check so they cannot drown out the default-revision report.',
      self::ContentForwardRevisions => 'Validates forward (pending/draft) revisions; an invalid one is a deferred invalid entity that will fail when published.',
      self::AutoSave => 'Validates unpublished auto-save snapshots; an invalid one is an advisory risk, not a failure — drafts are allowed to be incomplete, and publishing is the gate that keeps them from going live.',
    };
  }

  /**
   * The actionable remedy for this check, or NULL when none applies.
   *
   * NULL is deliberate for past revisions (often unresolvable — weigh whether
   * it matters) and auto-saves (publishing already rejects invalid snapshots);
   * see ::explanation().
   */
  public function prescription(): ?string {
    return match ($this) {
      self::CodeTaggedReleases => 'Install tagged releases of the flagged extensions to re-enable incremental result reuse.',
      self::UpdatesExecuted => 'Apply pending updates: <info>drush updatedb</info>',
      self::UpdatesEscapedConfig => 'Manually re-save these config entities.',
      self::Config,
      self::Content,
      self::ContentForwardRevisions => 'Fix the value at the reported property path (run with <info>--details</info>) via the editor UI, config import, or a targeted update, then re-run.',
      self::ComponentSourceSchemaEvolution => 'Avoid schema changes to the flagged component sources, or implement ComponentInstanceUpdaterInterface. (For blocks: blocked on Drupal core issue: https://www.drupal.org/project/drupal/issues/3521221.)',
      self::ContentPastRevisions,
      self::AutoSave => NULL,
    };
  }

  /**
   * What hook_cron computes and the site status report surfaces.
   *
   * System checks are computed live and never stored.
   *
   * @return \Drupal\canvas\Health\HealthCheck[]
   */
  public static function dataChecks(): array {
    return \array_values(\array_filter(
      self::cases(),
      static fn (HealthCheck $check): bool => !$check->isSystemCheck(),
    ));
  }

}
