<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\content_translation\Plugin\Validation\Constraint\ContentTranslationSynchronizedFieldsConstraint;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityUntranslatableFieldsConstraint;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Runs every check of Canvas data, reusing fresh results incrementally.
 *
 * @phpstan-type Violation array{message: string, property_path: string, code: string|null}
 * @phpstan-type Finding array{label: string, violations: list<Violation>}
 * @phpstan-type CheckItem array{check: string, entity_type: string, key: string, cached: bool, findings: list<Finding>}
 * @phpstan-type SystemCheckResult array{status: string, total: int, healthy: int, details: array<string, mixed>, problems: list<array{label: string, description: string}>}
 *
 * @internal
 * @see docs/adr/0017-data-health-validation-check-based-coverage-with-environment-fingerprinted-incremental-results.md
 */
final class Doctor {

  /**
   * Format string for the entity-level data-item key: entity_type:id.
   *
   * Revision and auto-save keys append a third segment (revision ID or
   * langcode), so deleting an entity prunes all of its rows by key prefix.
   *
   * @see \Drupal\canvas\Hook\HealthHooks::entityDelete()
   */
  public const string KEY_FORMAT = '%s:%s';

  /**
   * Write-path constraints that always fire on non-default revisions.
   */
  private const array WRITE_PATH_ONLY_CONSTRAINTS = [
    EntityChangedConstraint::class,
    EntityUntranslatableFieldsConstraint::class,
    ContentTranslationSynchronizedFieldsConstraint::class,
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Inventory $inventory,
    private readonly CanvasConfigUpdater $configUpdater,
    private readonly HealthRecords $healthRecords,
    private readonly EnvironmentInterface $environment,
    private readonly CacheTagsChecksumInterface $cacheTagsChecksum,
    private readonly ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * Runs one system check, returning the uniform result envelope.
   *
   * @return SystemCheckResult
   *
   * @throws \LogicException
   *   When $check is a data check.
   */
  public function runSystemCheck(HealthCheck $check): array {
    // Every case is listed with no `default`, so PHPStan fails when a new
    // HealthCheck case is added without being classified here. This keeps the
    // system/data split in sync with HealthCheck::isSystemCheck(); data checks
    // throw.
    return match ($check) {
      HealthCheck::CodeTaggedReleases => $this->runCodeTaggedReleasesCheck(),
      HealthCheck::UpdatesExecuted => $this->runUpdatesExecutedCheck(),
      HealthCheck::UpdatesEscapedConfig => $this->runUpdatesEscapedConfigCheck(),
      HealthCheck::ComponentSourceSchemaEvolution => $this->runComponentSourceSchemaEvolutionCheck(),
      HealthCheck::Config,
      HealthCheck::Content,
      HealthCheck::ContentPastRevisions,
      HealthCheck::ContentForwardRevisions,
      HealthCheck::AutoSave => throw new \LogicException(\sprintf('%s is not a system check.', $check->value)),
    };
  }

  /**
   * Assembles a system-check result.
   *
   * Derives `status` and `healthy` from the check's risk classification: a
   * risk leaves every item healthy; a failure reduces the healthy count and
   * marks the run a problem. Assumes one problem entry per unhealthy item.
   *
   * @param list<array{label: string, description: string}> $problems
   * @param array<string, mixed> $details
   *
   * @return SystemCheckResult
   */
  private static function systemCheckResult(HealthCheck $check, int $total, array $problems, array $details): array {
    $failing = \count($problems);
    $is_risk = $check->findingsAreRisks();
    return [
      'status' => $failing === 0 ? 'healthy' : ($is_risk ? 'risk' : 'problem'),
      'total' => $total,
      'healthy' => $is_risk ? $total : $total - $failing,
      'details' => $details,
      'problems' => $problems,
    ];
  }

  /**
   * @return SystemCheckResult
   */
  private function runUpdatesExecutedCheck(): array {
    $applied = $this->environment->getAppliedCanvasPostUpdates();
    $pending = $this->environment->getPendingCanvasPostUpdates();
    $problems = \array_values(\array_map(
      static fn (string $fn): array => ['label' => $fn, 'description' => 'pending post-update'],
      $pending,
    ));
    return self::systemCheckResult(HealthCheck::UpdatesExecuted, \count($applied) + \count($pending), $problems, [
      'schema_version' => $this->environment->getCanvasSchemaVersion(),
      'applied_post_updates' => $applied,
      'pending_post_updates' => $pending,
    ]);
  }

  /**
   * A risk, not a failure: dev checkouts never make the run unhealthy.
   *
   * @return SystemCheckResult
   *
   * @see \Drupal\canvas\Health\Environment::DEV_VERSION
   */
  private function runCodeTaggedReleasesCheck(): array {
    $dev_extensions = $this->environment->getDevExtensions();
    $problems = \array_values(\array_map(
      static fn (string $extension): array => [
        'label' => $extension,
        'description' => 'no release version (development checkout) — disables incremental result reuse',
      ],
      $dev_extensions,
    ));
    return self::systemCheckResult(HealthCheck::CodeTaggedReleases, $this->environment->getInstalledExtensionCount(), $problems, ['dev_extensions' => $dev_extensions]);
  }

  /**
   * Detects config entities that escaped the update path.
   *
   * Partial signal only: update paths implemented without a needs*() detector
   * are invisible here; update_path_executed is authoritative for those.
   *
   * @return SystemCheckResult
   */
  private function runUpdatesEscapedConfigCheck(): array {
    // A needs*() detector emits a deprecation when it finds outdated config, to
    // nudge developers during a real update run. This audit only probes, so
    // silence them here and restore in the finally below.
    $this->configUpdater->setDeprecationsEnabled(FALSE);
    try {
      // CanvasConfigUpdater exposes one needs*() method per detectable
      // data-model migration; they share no interface, so reflect them out.
      $needs_methods = self::getConfigUpdaterNeedsMethods();
      $pending = [];
      foreach ($this->inventory->getItems(HealthCheck::Config) as $item) {
        $entity = $this->entityTypeManager->getStorage($item->entityType->id())->load($item->entityId);
        \assert($entity instanceof ConfigEntityInterface);
        $pending_for_entity = [];
        foreach ($needs_methods as $method) {
          // Their signatures vary, so run only the detectors that accept
          // exactly this entity: skip any that don't type-match $entity or that
          // require more than the one entity argument.
          if (!self::entityMatchesFirstParameter($entity, $method) || !self::hasExactlyOneRequiredParameter($method)) {
            continue;
          }
          // TRUE means this entity still needs that migration.
          $result = $method->invoke($this->configUpdater, $entity);
          if ($result === TRUE) {
            $pending_for_entity[] = $method->getName();
          }
        }
        if ($pending_for_entity !== []) {
          $pending[$entity->getConfigDependencyName()] = $pending_for_entity;
        }
      }
    }
    finally {
      $this->configUpdater->setDeprecationsEnabled(TRUE);
    }
    $total = $this->inventory->count(HealthCheck::Config);
    $problems = [];
    foreach ($pending as $config_name => $methods) {
      $problems[] = ['label' => $config_name, 'description' => \sprintf('pending: %s', \implode(', ', $methods))];
    }
    return self::systemCheckResult(HealthCheck::UpdatesEscapedConfig, $total, $problems, ['pending' => $pending]);
  }

  /**
   * Reports component sources that cannot auto-migrate their instances.
   *
   * Checks every component source plugin (except the fallback) for a
   * ComponentInstanceUpdaterInterface. A source without one cannot migrate
   * existing instances to a new component version when its schema evolves, so
   * a schema change would be breaking for that source. This is a risk, not a
   * failure: such sources stay "healthy" here.
   *
   * @return SystemCheckResult
   *
   * @see https://www.drupal.org/node/3521221
   */
  private function runComponentSourceSchemaEvolutionCheck(): array {
    $sources = [];
    $failing = [];
    foreach ($this->componentSourceManager->getDefinitions() as $id => $definition) {
      if ($id === Fallback::PLUGIN_ID) {
        continue;
      }
      $has_updater = !empty($definition['updater']);
      $sources[$id] = [
        'label' => (string) ($definition['label'] ?? $id),
        'has_updater' => $has_updater,
      ];
      if (!$has_updater) {
        $failing[] = $id;
      }
    }
    $problems = [];
    foreach ($failing as $source_id) {
      $problems[] = [
        'label' => \sprintf('%s (%s)', $sources[$source_id]['label'], $source_id),
        'description' => 'no automatic instance updates — see https://www.drupal.org/node/3521221',
      ];
    }
    return self::systemCheckResult(HealthCheck::ComponentSourceSchemaEvolution, \count($sources), $problems, [
      'failing' => $failing,
      'sources' => $sources,
    ]);
  }

  /**
   * Validates every data item across the requested checks.
   *
   * @param \Drupal\canvas\Health\HealthCheck[] $checks
   *   Defaults to all checks.
   * @param bool $cache
   *   TRUE (default) to reuse stored results while still fresh; FALSE to
   *   ignore them and revalidate every item. Fresh outcomes are recorded for
   *   later reuse either way.
   *
   * @return \Generator<CheckItem>
   */
  public function runDataChecks(array $checks = [], bool $cache = TRUE): \Generator {
    $checks = $checks === [] ? HealthCheck::cases() : $checks;
    foreach ($checks as $check) {
      if ($check->isSystemCheck()) {
        continue;
      }
      // Every case is listed with no `default`, so a new HealthCheck case
      // must be classified here too. System checks are already skipped by the
      // guard above; they are listed only to keep the match exhaustive for
      // PHPStan.
      yield from match ($check) {
        HealthCheck::Config => $this->runConfigCheck(0, NULL, $cache),
        HealthCheck::Content => $this->runContentDefaultCheck(0, NULL, $cache),
        HealthCheck::ContentPastRevisions => $this->runContentPastRevisionsCheck(0, NULL, $cache),
        HealthCheck::ContentForwardRevisions => $this->runContentForwardRevisionsCheck(0, NULL, $cache),
        HealthCheck::AutoSave => $this->runAutoSaveCheck(0, NULL, $cache),
        HealthCheck::CodeTaggedReleases,
        HealthCheck::UpdatesExecuted,
        HealthCheck::UpdatesEscapedConfig,
        HealthCheck::ComponentSourceSchemaEvolution => throw new \LogicException(\sprintf('No data-check handler for %s.', $check->value)),
      };
    }
  }

  /**
   * The number of data items a data check would yield.
   *
   * @throws \LogicException
   *   When $check is a system check.
   */
  public function countCheckItems(HealthCheck $check): int {
    if ($check->isSystemCheck()) {
      throw new \LogicException(\sprintf('No item count for check %s; only data checks support windowed counting.', $check->value));
    }
    return $this->inventory->count($check);
  }

  /**
   * Validates a chunk of data items during cron.
   *
   * Unlike runDataChecks(), does NOT prune orphans.
   *
   * @return array{processed: int, wrapped: bool}
   *   wrapped: TRUE once the cursor should reset to 0.
   */
  public function runCronWindow(int $offset, ?int $limit = NULL, array $checks = []): array {
    $limit ??= Settings::get('entity_update_batch_size', 50);
    $checks = $checks === [] ? HealthCheck::dataChecks() : $checks;
    $data_checks = \array_values(\array_filter($checks, static fn (HealthCheck $check): bool => !$check->isSystemCheck()));
    if ($limit <= 0 || $data_checks === []) {
      return ['processed' => 0, 'wrapped' => TRUE];
    }
    $counts = [];
    $total = 0;
    foreach ($data_checks as $check) {
      $count = $this->countCheckItems($check);
      $counts[] = $count;
      $total += $count;
    }
    if ($offset >= $total) {
      // Cursor at/beyond stream end (e.g. data shrank): signal a wrap.
      return ['processed' => 0, 'wrapped' => TRUE];
    }
    $processed = 0;
    $remaining = $limit;
    $base = 0;
    foreach ($data_checks as $i => $check) {
      if ($remaining <= 0) {
        break;
      }
      $count = $counts[$i];
      if ($count > 0 && $offset < $base + $count) {
        $local_offset = \max(0, $offset - $base);
        $take = \min($count - $local_offset, $remaining);
        if ($take > 0) {
          $inspected = \iterator_count($this->doCheck($check, $local_offset, $take));
          $processed += $inspected;
          $remaining -= $inspected;
        }
      }
      $base += $count;
    }
    return ['processed' => $processed, 'wrapped' => ($offset + $processed) >= $total];
  }

  /**
   * Dispatches to a data check's windowed generator.
   *
   * @return \Generator<CheckItem>
   */
  private function doCheck(HealthCheck $check, int $offset, ?int $limit): \Generator {
    return match ($check) {
      HealthCheck::Config => $this->runConfigCheck($offset, $limit),
      HealthCheck::Content => $this->runContentDefaultCheck($offset, $limit),
      HealthCheck::ContentPastRevisions => $this->runContentPastRevisionsCheck($offset, $limit),
      HealthCheck::ContentForwardRevisions => $this->runContentForwardRevisionsCheck($offset, $limit),
      HealthCheck::AutoSave => $this->runAutoSaveCheck($offset, $limit),
      default => throw new \LogicException(\sprintf('No windowed sub-generator for check %s.', $check->value)),
    };
  }

  /**
   * Validates every config entity's typed data.
   *
   * @return \Generator<CheckItem>
   */
  private function runConfigCheck(int $offset = 0, ?int $limit = NULL, bool $cache = TRUE): \Generator {
    foreach ($this->inventory->getItems(HealthCheck::Config, $offset, $limit) as $item) {
      $entity_type_id = $item->entityType->id();
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $key = \sprintf(self::KEY_FORMAT, $entity_type_id, $item->entityId);
      $config_name = $item->configName();
      // Freshness signal for result reuse: the config's cache-tag checksum,
      // which Drupal bumps on every save. A stored result stays reusable only
      // while this is unchanged.
      // @see ::process()
      $signature = $this->cacheTagSignature(['config:' . $config_name]);
      yield $this->process($key, HealthCheck::Config, $entity_type_id, $signature, $cache, function () use ($storage, $item, $config_name): array {
        // Reached only on a cache miss.
        $entity = $storage->load($item->entityId);
        \assert($entity instanceof ConfigEntityInterface);
        return [self::buildFinding($config_name, $entity->getTypedData()->validate())];
      });
    }
  }

  /**
   * Validates every translation of every default-revision content entity.
   *
   * @return \Generator<CheckItem>
   */
  private function runContentDefaultCheck(int $offset = 0, ?int $limit = NULL, bool $cache = TRUE): \Generator {
    foreach ($this->inventory->getItems(HealthCheck::Content, $offset, $limit) as $item) {
      $entity_type_id = $item->entityType->id();
      $entity_id = $item->entityId;
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $key = \sprintf(self::KEY_FORMAT, $entity_type_id, $entity_id);
      // Freshness signal for result reuse: the entity's cache-tag checksum,
      // which Drupal bumps on every save. A stored result stays reusable only
      // while this is unchanged.
      // @see ::process()
      $signature = $this->cacheTagSignature([$entity_type_id . ':' . $entity_id]);
      yield $this->process($key, HealthCheck::Content, $entity_type_id, $signature, $cache, static function () use ($storage, $entity_type_id, $entity_id): array {
        // Reached only on a cache miss. An unloadable entity yields nothing.
        $entity = $storage->load($entity_id);
        if (!$entity instanceof ContentEntityInterface) {
          return [];
        }
        $findings = [];
        foreach (self::getTranslations($entity) as $langcode => $translation) {
          $label = \sprintf('%s %s (default revision, %s)', $entity_type_id, $entity_id, $langcode);
          $findings[] = self::buildFinding($label, $translation->validate());
        }
        return $findings;
      });
    }
  }

  /**
   * Validates every translation of every non-default, non-latest revision.
   *
   * @return \Generator<CheckItem>
   */
  private function runContentPastRevisionsCheck(int $offset = 0, ?int $limit = NULL, bool $cache = TRUE): \Generator {
    foreach ($this->inventory->getItems(HealthCheck::ContentPastRevisions, $offset, $limit) as $item) {
      $entity_type_id = $item->entityType->id();
      $entity_id = $item->entityId;
      $rid = $item->revisionId;
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      \assert($storage instanceof RevisionableStorageInterface);
      $key = \sprintf('%s:%s:%s', $entity_type_id, $entity_id, $rid);
      // Freshness signal for result reuse: the ENTITY cache-tag checksum, not
      // per-revision (core has no per-revision tag). Saving any revision bumps
      // it, so every revision's stored result is rechecked. That is more work
      // than needed, but safe: erring toward a redundant recheck never reuses a
      // stale result.
      // @see ::process()
      $signature = $this->cacheTagSignature([$entity_type_id . ':' . $entity_id]);
      yield $this->process($key, HealthCheck::ContentPastRevisions, $entity_type_id, $signature, $cache, static function () use ($storage, $entity_type_id, $rid, $entity_id): array {
        // Reached only on a cache miss. An unloadable revision yields nothing.
        $revision = $storage->loadRevision((string) $rid);
        if (!$revision instanceof ContentEntityInterface) {
          return [];
        }
        $findings = [];
        foreach (self::getTranslations($revision) as $langcode => $translation) {
          $label = \sprintf('%s %s (past revision %s, %s)', $entity_type_id, $entity_id, $rid, $langcode);
          $findings[] = self::buildFinding($label, $translation->validate(), self::WRITE_PATH_ONLY_CONSTRAINTS);
        }
        return $findings;
      });
    }
  }

  /**
   * Validates every translation of every latest-but-not-default revision.
   *
   * @return \Generator<CheckItem>
   */
  private function runContentForwardRevisionsCheck(int $offset = 0, ?int $limit = NULL, bool $cache = TRUE): \Generator {
    foreach ($this->inventory->getItems(HealthCheck::ContentForwardRevisions, $offset, $limit) as $item) {
      $entity_type_id = $item->entityType->id();
      $entity_id = $item->entityId;
      $rid = $item->revisionId;
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      \assert($storage instanceof RevisionableStorageInterface);
      $key = \sprintf('%s:%s:%s', $entity_type_id, $entity_id, $rid);
      // Freshness signal for result reuse: the ENTITY cache-tag checksum, not
      // per-revision; see runContentPastRevisionsCheck(). A published draft
      // becomes the default revision, covered by Content.
      // @see ::process()
      $signature = $this->cacheTagSignature([$entity_type_id . ':' . $entity_id]);
      yield $this->process($key, HealthCheck::ContentForwardRevisions, $entity_type_id, $signature, $cache, static function () use ($storage, $entity_type_id, $rid, $entity_id): array {
        // Reached only on a cache miss. An unloadable revision yields nothing.
        $revision = $storage->loadRevision((string) $rid);
        if (!$revision instanceof ContentEntityInterface) {
          return [];
        }
        $findings = [];
        foreach (self::getTranslations($revision) as $langcode => $translation) {
          $label = \sprintf('%s %s (forward revision %s, %s)', $entity_type_id, $entity_id, $rid, $langcode);
          $findings[] = self::buildFinding($label, $translation->validate(), self::WRITE_PATH_ONLY_CONSTRAINTS);
        }
        return $findings;
      });
    }
  }

  /**
   * Validates every auto-save snapshot that still has a loadable entity.
   *
   * @return \Generator<CheckItem>
   */
  private function runAutoSaveCheck(int $offset = 0, ?int $limit = NULL, bool $cache = TRUE): \Generator {
    foreach ($this->inventory->getItems(HealthCheck::AutoSave, $offset, $limit) as $item) {
      $entity = $item->entity;
      \assert($entity instanceof EntityInterface);
      $entity_type_id = $item->entityType->id();
      $key = \sprintf('%s:%s:%s', $entity_type_id, $item->entityId, $item->langcode);
      // Freshness signal for result reuse: the snapshot's precomputed data
      // hash, since auto-save snapshots have no cache tags. A stored result
      // stays reusable only while this is unchanged.
      // @see ::process()
      $signature = (string) $item->dataHash;
      $label = \sprintf('auto-save for %s %s (%s)', $entity_type_id, $item->entityId, $item->langcode);
      // The closure is reached only on a cache miss; it validates the snapshot
      // as the entity it would become when published.
      yield $this->process($key, HealthCheck::AutoSave, $entity_type_id, $signature, $cache, fn (): array => [
        self::buildFinding($label, $entity instanceof ContentEntityInterface ? $entity->validate() : $entity->getTypedData()->validate()),
      ]);
    }
  }

  /**
   * Reuses a fresh stored result, or produces and persists a new one.
   *
   * @param callable(): list<Finding> $produce_findings
   *   Only called when no fresh result exists (or the cache is bypassed).
   *
   * @return CheckItem
   */
  private function process(string $key, HealthCheck $check, string $entity_type_id, string $signature, bool $cache, callable $produce_findings): array {
    if ($cache && $this->healthRecords->isFresh($key, $check, $signature)) {
      return self::buildCheckItem($check, $entity_type_id, $key, TRUE, $this->healthRecords->getFindings($key, $check) ?? []);
    }
    $findings = $produce_findings();
    $this->healthRecords->record($key, $check, $signature, $findings);
    return self::buildCheckItem($check, $entity_type_id, $key, FALSE, $findings);
  }

  /**
   * Builds the CheckItem envelope yielded by run() and runCronWindow().
   *
   * The process() method has two exits — a cache hit and a freshly produced
   * result — that must agree on shape; this is the one place that shape is
   * assembled, so callers of run() see the same envelope regardless of
   * which exit fired.
   *
   * @param list<Finding> $findings
   *
   * @return CheckItem
   */
  private static function buildCheckItem(HealthCheck $check, string $entity_type_id, string $key, bool $cached, array $findings): array {
    return [
      'check' => $check->value,
      'entity_type' => $entity_type_id,
      'key' => $key,
      'cached' => $cached,
      'findings' => $findings,
    ];
  }

  /**
   * Builds a Finding, dropping violations from any given constraint classes.
   *
   * @param class-string[] $filter_constraint_classes
   *   Constraint classes to exclude (e.g. write-path-only guards).
   *
   * @return Finding
   */
  private static function buildFinding(string $label, ConstraintViolationListInterface $violations, array $filter_constraint_classes = []): array {
    if ($filter_constraint_classes !== []) {
      $violations = clone $violations;
      $to_remove = [];
      foreach ($violations as $i => $violation) {
        $constraint = $violation->getConstraint();
        if ($constraint !== NULL && \in_array($constraint::class, $filter_constraint_classes, TRUE)) {
          $to_remove[] = $i;
        }
      }
      foreach (\array_reverse($to_remove) as $i) {
        $violations->remove($i);
      }
    }
    return ['label' => $label, 'violations' => self::normalizeViolations($violations)];
  }

  /**
   * Fingerprints $tags' current state, so process() can detect staleness.
   *
   * Deliberately called from inside each run*Check() loop, right before its
   * process() call — not precomputed by Inventory while it's listing items,
   * and not computed by CheckEntity itself. An item can sit around
   * windowed/paginated for a while before Doctor gets to it (potentially
   * across separate cron runs), so this has to run at the moment Doctor is
   * actually about to check the item, not any earlier, or it would describe
   * how fresh things looked back then instead of now.
   *
   * @param string[] $tags
   */
  private function cacheTagSignature(array $tags): string {
    return (string) $this->cacheTagsChecksum->getCurrentChecksum($tags);
  }

  /**
   * @return array<string, \Drupal\Core\Entity\ContentEntityInterface>
   */
  private static function getTranslations(ContentEntityInterface $entity): array {
    $translations = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translations[$langcode] = $entity->getTranslation($langcode);
    }
    return $translations;
  }

  /**
   * @return list<Violation>
   */
  private static function normalizeViolations(ConstraintViolationListInterface $violations): array {
    $normalized = [];
    foreach ($violations as $violation) {
      $normalized[] = [
        // Strip HTML (e.g. <em class="placeholder">) for machine consumers.
        'message' => \strip_tags((string) $violation->getMessage()),
        'property_path' => $violation->getPropertyPath(),
        'code' => $violation->getCode(),
      ];
    }
    return $normalized;
  }

  /**
   * Reflects out CanvasConfigUpdater's public needs*() detector methods.
   *
   * @return \ReflectionMethod[]
   */
  private static function getConfigUpdaterNeedsMethods(): array {
    $reflection = new \ReflectionClass(CanvasConfigUpdater::class);
    return \array_filter(
      $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
      static fn (\ReflectionMethod $method): bool => \str_starts_with($method->getName(), 'needs'),
    );
  }

  /**
   * TRUE if $method has exactly one required parameter.
   *
   * Optional trailing parameters don't count: a needs*($entity, $extra = NULL)
   * method still qualifies as single-argument for callers that only pass
   * $entity.
   */
  private static function hasExactlyOneRequiredParameter(\ReflectionMethod $method): bool {
    $required = \array_filter($method->getParameters(), static fn (\ReflectionParameter $parameter): bool => !$parameter->isOptional());
    return \count($required) === 1;
  }

  /**
   * Whether $entity satisfies the type-hint of $method's first parameter.
   *
   * CanvasConfigUpdater::needs*() methods don't share a common interface, so
   * this uses reflection to find which of them accept $entity at all, rather
   * than requiring every needs*() method to declare one.
   */
  private static function entityMatchesFirstParameter(EntityInterface $entity, \ReflectionMethod $method): bool {
    $parameters = $method->getParameters();
    if ($parameters === []) {
      return FALSE;
    }
    return self::entityMatchesType($entity, $parameters[0]->getType());
  }

  /**
   * Whether $entity satisfies a reflected type, recursing into its members.
   *
   * A union (A|B) matches if $entity satisfies any one member. An
   * intersection (A&B) matches only if $entity satisfies every member. A
   * DNF type like (A&B)|C is a union with an intersection as one of its
   * members, so those same two rules, applied recursively, handle it too.
   */
  private static function entityMatchesType(EntityInterface $entity, ?\ReflectionType $type): bool {
    if ($type instanceof \ReflectionNamedType) {
      $name = $type->getName();
      return (\class_exists($name) || \interface_exists($name)) && $entity instanceof $name;
    }
    if ($type instanceof \ReflectionUnionType) {
      foreach ($type->getTypes() as $member) {
        if (self::entityMatchesType($entity, $member)) {
          return TRUE;
        }
      }
      return FALSE;
    }
    if ($type instanceof \ReflectionIntersectionType) {
      foreach ($type->getTypes() as $member) {
        if (!self::entityMatchesType($entity, $member)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return FALSE;
  }

}
