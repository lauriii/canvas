<?php

declare(strict_types=1);

namespace Drupal\canvas\Command;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists where Canvas components are used.
 *
 * Requires Drupal >= 11.4 (`dr` CLI). Thin wrapper over ComponentAudit, which
 * also powers the component audit page.
 *
 * @phpstan-type Usage array{component: string, version: string, kind: 'content'|'config'|'auto-save', entity_type: string, id: string, revision: string|null, default: bool|null, latest: bool|null, label: string|null}
 * @internal
 * @see docs/cli.md
 * @see docs/component-audit.schema.json
 * @see \Drupal\canvas\Controller\ComponentAuditController
 * @see https://www.drupal.org/node/3584928
 */
#[AsCommand(
  name: 'canvas:component:audit',
  description: 'Lists where components are used: by component source, component ID, or component ID and version.',
)]
final class ComponentAuditCommand extends Command {

  /**
   * Version of the --format=json envelope. Bumped only on breaking changes.
   *
   * Adding a key to the envelope or to a usage is not a breaking change.
   *
   * @see docs/component-audit.schema.json
   */
  public const int REPORT_VERSION = 1;

  public function __construct(
    private readonly ComponentAudit $componentAudit,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly AutoSaveManager $autoSaveManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('target', InputArgument::OPTIONAL, 'A component source plugin ID (for example: sdc), a component ID (for example: sdc.olivero.teaser), or a component ID and version (for example: sdc.olivero.teaser@a1b2c3d4e5f60718). Asked for interactively when omitted.')
      ->addOption('revisions', NULL, InputOption::VALUE_REQUIRED, 'Which content entity revisions to search: all (default), default or latest. Config entities and auto-saves are always searched.', RevisionAuditEnum::All->value)
      ->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'Output format: table (default) or json.', 'table');
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output): void {
    if ($input->getArgument('target') !== NULL) {
      return;
    }
    $io = new SymfonyStyle($input, $output);
    // The fallback source has no components of its own.
    $sources = \array_diff(\array_keys($this->componentSourceManager->getDefinitions()), [Fallback::PLUGIN_ID]);
    $source = (string) $io->choice('Component source', \array_values($sources));
    $storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);
    $component_ids = \array_values($storage->getQuery()->accessCheck(FALSE)->condition('source', $source)->sort('id')->execute());
    $component_id = (string) $io->choice('Component', ['all' => \sprintf('all components from the %s source', $source)] + \array_combine($component_ids, $component_ids), 'all');
    if ($component_id === 'all') {
      $input->setArgument('target', $source);
      return;
    }
    $component = $storage->load($component_id);
    \assert($component instanceof ComponentInterface);
    $versions = $component->getVersions();
    $version = \count($versions) > 1
      ? (string) $io->choice('Version', ['all' => 'all versions'] + \array_combine($versions, $versions), 'all')
      : 'all';
    $input->setArgument('target', $version === 'all' ? $component_id : "$component_id@$version");
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    if ($input->getArgument('target') === NULL) {
      $io->error('Pass a target: a component source plugin ID, a component ID, or a component ID and version. Run without --no-interaction to choose one.');
      return self::INVALID;
    }

    $format = $input->getOption('format');
    if (!\in_array($format, ['table', 'json'], TRUE)) {
      $io->error(\sprintf('Unknown format "%s". Use table or json.', $format));
      return self::INVALID;
    }
    $which_revisions = RevisionAuditEnum::tryFrom((string) $input->getOption('revisions'));
    // Auto-saves are not revisions of the entity: they are always searched.
    if (!\in_array($which_revisions, [RevisionAuditEnum::All, RevisionAuditEnum::Default, RevisionAuditEnum::Latest], TRUE)) {
      $io->error(\sprintf('Unknown revisions value "%s". Use all, default or latest.', $input->getOption('revisions')));
      return self::INVALID;
    }

    try {
      $targets = $this->resolveTargets((string) $input->getArgument('target'));
    }
    catch (\InvalidArgumentException $e) {
      $io->error($e->getMessage());
      return self::INVALID;
    }

    $usages = [];
    foreach ($targets as [$component, $versions]) {
      \array_push($usages, ...$this->findUsages($component, $versions, $which_revisions));
    }

    if ($format === 'json') {
      $report = [
        'report_version' => self::REPORT_VERSION,
        'target' => $input->getArgument('target'),
        'revisions' => $which_revisions->value,
        'usages' => $usages,
      ];
      $output->writeln((string) \json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
      return self::SUCCESS;
    }

    if ($usages === []) {
      $io->success(\sprintf('No usages found for %s.', $input->getArgument('target')));
      return self::SUCCESS;
    }
    $io->table(
      ['Component', 'Version', 'Kind', 'Entity type', 'ID', 'Revision', 'Label'],
      \array_map(static fn (array $usage): array => [
        $usage['component'],
        $usage['version'],
        $usage['kind'],
        $usage['entity_type'],
        $usage['id'],
        self::formatRevision($usage),
        $usage['label'],
      ], $usages),
    );
    $io->note(\sprintf('%d usage(s) found.', \count($usages)));
    return self::SUCCESS;
  }

  /**
   * Resolves the target argument to components and the versions to audit.
   *
   * @return array<array{0: \Drupal\canvas\Entity\ComponentInterface, 1: string[]}>
   *   Pairs of a component and the versions of it to audit.
   *
   * @throws \InvalidArgumentException
   *   When the target matches no component source, component or version.
   */
  private function resolveTargets(string $target): array {
    $storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);

    if (\str_contains($target, '@')) {
      [$component_id, $version] = \explode('@', $target, 2);
      $component = $storage->load($component_id);
      if (!$component instanceof ComponentInterface) {
        throw new \InvalidArgumentException(\sprintf('No component with ID "%s" exists.', $component_id));
      }
      if (!\in_array($version, $component->getVersions(), TRUE)) {
        throw new \InvalidArgumentException(\sprintf('Component "%s" has no version "%s". Available versions: %s.', $component_id, $version, \implode(', ', $component->getVersions())));
      }
      return [[$component, [$version]]];
    }

    $component = $storage->load($target);
    if ($component instanceof ComponentInterface) {
      return [[$component, $component->getVersions()]];
    }

    if ($this->componentSourceManager->hasDefinition($target)) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('source', $target)->sort('id')->execute();
      return \array_map(
        static fn (ComponentInterface $component): array => [$component, $component->getVersions()],
        \array_values($storage->loadMultiple($ids)),
      );
    }

    throw new \InvalidArgumentException(\sprintf('"%s" is neither a component ID nor a component source plugin ID. Available component sources: %s.', $target, \implode(', ', \array_keys($this->componentSourceManager->getDefinitions()))));
  }

  /**
   * Gets every usage of the given versions of a component.
   *
   * Each kind of search (content/config/auto-save) first runs once for all
   * given versions, so a component without usages for any of the given
   * versions only costs one search of each kind. Only when that finds a usage
   * does each kind run again once per given version.
   *
   * @param \Drupal\canvas\Entity\ComponentInterface $component
   *   The component to audit.
   * @param string[] $versions
   *   The versions of the component to audit.
   * @param \Drupal\canvas\Audit\RevisionAuditEnum $which_revisions
   *   Which content entity revisions to search.
   *
   * @return list<Usage>
   */
  private function findUsages(ComponentInterface $component, array $versions, RevisionAuditEnum $which_revisions): array {
    $component_id = (string) $component->id();
    $has_content_usages = \array_filter($this->componentAudit->getContentRevisionIdsUsingAuditTarget($component, $versions, $which_revisions)) !== [];
    $has_config_usages = $this->componentAudit->getConfigEntityUsageCount($component) > 0;
    $auto_saves_using_any_version = $this->componentAudit->getAutoSavesUsingAuditTarget($component, $versions);
    $usages = [];

    foreach ($versions as $version) {
      $revisions = $has_content_usages
        ? $this->componentAudit->getContentRevisionsUsingAuditTarget($component, [$version], $which_revisions)
        : [];
      foreach ($revisions as $revision) {
        $is_revisionable = $revision->getEntityType()->isRevisionable();
        $usages[] = [
          'component' => $component_id,
          'version' => $version,
          'kind' => 'content',
          'entity_type' => $revision->getEntityTypeId(),
          'id' => (string) $revision->id(),
          'revision' => $is_revisionable ? (string) $revision->getRevisionId() : NULL,
          'default' => $is_revisionable ? $revision->isDefaultRevision() : NULL,
          'latest' => $is_revisionable ? $revision->isLatestRevision() : NULL,
          'label' => self::label($revision),
        ];
      }

      $config_entity_type_ids = $has_config_usages ? $this->componentAudit->getComponentTreeConfigEntityTypeIds() : [];
      foreach ($config_entity_type_ids as $config_entity_type_id) {
        foreach ($this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, $config_entity_type_id, [$version]) as $config_entity) {
          $usages[] = [
            'component' => $component_id,
            'version' => $version,
            'kind' => 'config',
            'entity_type' => $config_entity_type_id,
            'id' => (string) $config_entity->id(),
            'revision' => NULL,
            'default' => NULL,
            'latest' => NULL,
            'label' => self::label($config_entity),
          ];
        }
      }

      $auto_saves = $auto_saves_using_any_version === [] || \count($versions) === 1
        ? $auto_saves_using_any_version
        : $this->componentAudit->getAutoSavesUsingAuditTarget($component, [$version]);
      foreach ($auto_saves as $entity_type_id => $entity_ids) {
        foreach ($entity_ids as $entity_id) {
          $usages[] = [
            'component' => $component_id,
            'version' => $version,
            'kind' => 'auto-save',
            'entity_type' => $entity_type_id,
            'id' => (string) $entity_id,
            'revision' => NULL,
            'default' => NULL,
            'latest' => NULL,
            'label' => $this->getAutoSaveLabel($entity_type_id, $entity_id),
          ];
        }
      }
    }

    return $usages;
  }

  /**
   * Gets the label of an entity as its auto-save has it.
   */
  private function getAutoSaveLabel(string $entity_type_id, int|string $entity_id): ?string {
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    if ($entity === NULL) {
      return NULL;
    }
    return self::label($this->autoSaveManager->getAutoSaveEntity($entity)->entity ?? $entity);
  }

  private static function label(EntityInterface $entity): ?string {
    $label = $entity->label();
    return $label !== NULL ? (string) $label : NULL;
  }

  /**
   * Formats a usage's revision ID and flags for the table format.
   *
   * @param Usage $usage
   *   A usage.
   */
  private static function formatRevision(array $usage): ?string {
    $flags = \array_keys(\array_filter([
      'default' => $usage['default'],
      'latest' => $usage['latest'],
    ]));
    return $usage['revision'] !== NULL && $flags !== []
      ? \sprintf('%s (%s)', $usage['revision'], \implode(', ', $flags))
      : $usage['revision'];
  }

}
