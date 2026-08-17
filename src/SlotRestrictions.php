<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;

/**
 * @internal
 *
 * Evaluates the slot restrictions declared by component metadata.
 *
 * Drupal core lets a component's slot declare `expected` (a list of component
 * IDs and/or tags), `minItems` and `maxItems`, but deliberately does not
 * enforce them at the SDC render layer: enforcement is left to display building
 * tools such as Canvas.
 *
 * `minItems` is deliberately NOT evaluated here. Every slot starts out empty,
 * so a minimum can never be enforced at write time without making it impossible
 * to build a page; it is surfaced to the author before publishing instead.
 *
 * @see https://www.drupal.org/i/3514072
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
 */
final class SlotRestrictions {

  /**
   * The rule that was violated: the component does not fit the slot.
   */
  public const string RULE_EXPECTED = 'expected';

  /**
   * The rule that was violated: the slot holds more components than it accepts.
   */
  public const string RULE_MAX_ITEMS = 'maxItems';

  /**
   * Component IDs having a given tag, keyed by tag.
   *
   * Only populated for tags that are actually looked up, and only when a
   * candidate component was already found not to fit: the happy path never
   * enumerates the component library.
   *
   * @var array<string, list<string>>
   */
  private static array $componentIdsByTag = [];

  /**
   * Evaluates a component tree against its parents' slot restrictions.
   *
   * @param array<int, array<string, mixed>> $tree
   *   A component tree: a list of component instances.
   * @param \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage
   *   The Component config entity storage.
   *
   * @return array<string, array{message: string, params: array<string, string>, delta: int, count?: int}>
   *   The violations, keyed so that two evaluations can be compared.
   *
   * @see self::introducedViolations()
   */
  public static function violations(array $tree, ConfigEntityStorageInterface $component_storage): array {
    $violations = [];
    foreach (self::groupBySlot($tree) as $group) {
      $parent_component = $component_storage->load($group['parent_component_id']);
      if (!$parent_component instanceof Component) {
        // A non-existent parent component is reported by the surrounding
        // structural validation; do not pile on.
        continue;
      }
      $slot_definition = self::slotDefinitions($parent_component)[$group['slot']] ?? NULL;
      if ($slot_definition === NULL) {
        // An unknown slot name is reported by the surrounding structural
        // validation.
        continue;
      }

      $expected = self::expectedEntries($slot_definition);
      $max_items = $slot_definition['maxItems'] ?? NULL;
      $slot_title = \is_string($slot_definition['title'] ?? NULL) ? $slot_definition['title'] : $group['slot'];
      $parent_label = self::label($group['parent_component_id'], $component_storage);
      foreach ($group['children'] as $child) {
        if ($expected !== [] && !self::accepts($expected, $child['component_id'], $component_storage)) {
          $violations[self::key(self::RULE_EXPECTED, $child['uuid'], $group)] = [
            'message' => 'Component %component is not expected in the %slot slot of %parent. Expected: %expected.',
            'params' => [
              '%component' => self::label($child['component_id'], $component_storage),
              '%slot' => $slot_title,
              '%parent' => $parent_label,
              '%expected' => self::describeExpected($expected, $component_storage),
            ],
            'delta' => $child['delta'],
          ];
        }
      }
      // TRICKY: `maxItems` is a property of the slot, not of any one child in
      // it. Attributing it to whichever child happens to sit past the limit
      // would key the violation on that child's UUID, and reordering the slot's
      // existing children would then look like a brand new violation — making a
      // page that merely got its cards swapped around unpublishable.
      if (\is_int($max_items) && \count($group['children']) > $max_items) {
        $violations[self::key(self::RULE_MAX_ITEMS, NULL, $group)] = [
          // The count is reported without a noun after it, so that the message
          // needs no plural formula.
          'message' => 'Too many components in the %slot slot of %parent: @count provided, at most @max allowed.',
          'params' => [
            '%slot' => $slot_title,
            '%parent' => $parent_label,
            '@max' => (string) $max_items,
            '@count' => (string) \count($group['children']),
          ],
          // Report against the first child that does not fit.
          'delta' => $group['children'][$max_items]['delta'],
          // How far over the limit the slot is, so that a write which makes an
          // already over-full slot worse can still be told apart from one that
          // leaves it alone or improves it.
          // @see self::introducedViolations()
          'count' => \count($group['children']),
        ];
      }
    }
    return $violations;
  }

  /**
   * The violations a write introduces, ignoring those the stored tree has.
   *
   * Adding or narrowing a restriction on a component that is already in use
   * across a site may not make existing content unpublishable, so a violation
   * the stored tree already contains is left alone. What counts as "already
   * contained" is deliberately narrow: an instance that moves to another slot
   * or parent is re-evaluated where it lands, and a slot that is already over
   * `maxItems` may keep the children it has, and may lose some, but may not
   * gain more.
   *
   * @param array<string, array{message: string, params: array<string, string>, delta: int, count?: int}> $violations
   *   The violations of the tree being written.
   * @param array<string, array{message: string, params: array<string, string>, delta: int, count?: int}> $stored_violations
   *   The violations of the tree this write replaces.
   *
   * @return array<string, array{message: string, params: array<string, string>, delta: int, count?: int}>
   *   The violations to report.
   */
  public static function introducedViolations(array $violations, array $stored_violations): array {
    return \array_filter(
      $violations,
      static fn (array $violation, string $key): bool => !\array_key_exists($key, $stored_violations)
        || ($violation['count'] ?? 0) > ($stored_violations[$key]['count'] ?? 0),
      \ARRAY_FILTER_USE_BOTH,
    );
  }

  /**
   * Whether a slot accepts a component, per that slot's `expected` entries.
   *
   * @param list<string> $expected
   *   The slot's `expected` entries.
   * @param string $component_id
   *   The Component config entity ID of the component being placed.
   * @param \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage
   *   The Component config entity storage.
   */
  public static function accepts(array $expected, string $component_id, ConfigEntityStorageInterface $component_storage): bool {
    $tags = [];
    foreach ($expected as $entry) {
      $reference = self::normalizeReference($entry);
      if ($reference === NULL) {
        $tags[] = $entry;
      }
      elseif ($reference === $component_id) {
        return TRUE;
      }
    }
    foreach ($tags as $tag) {
      if (\in_array($component_id, self::componentIdsWithTag($tag, $component_storage), TRUE)) {
        return TRUE;
      }
    }
    // TRICKY: fail open. An `expected` list none of whose entries resolves to
    // an existing component or a used tag is a mistake in the component's
    // metadata (a typo, an uninstalled module, a component not yet ported). It
    // must not make the slot impossible to fill: core describes these
    // restrictions as suggestions, so a metadata mistake may not brick a page.
    return !self::hasResolvableEntry($expected, $component_storage);
  }

  /**
   * Reads a component's current slot definitions.
   *
   * TRICKY: read from the component *source*, not from the Component config
   * entity. Slot restrictions govern authoring rather than the data stored for
   * an instance, so they are deliberately excluded from the component version
   * hash; that in turn means editing only a slot's restrictions does not create
   * a new version and therefore does not re-save the Component config entity,
   * leaving its `fallback_metadata.slot_definitions` stale. The source is
   * always current, and is also what the Canvas UI is served, so both halves of
   * the enforcement agree.
   *
   * A component whose source has degraded still answers here: `Fallback`
   * implements ComponentSourceWithSlotsInterface and serves the
   * `fallback_metadata.slot_definitions` recorded on the Component config
   * entity. Because restrictions are deliberately excluded from the version
   * hash, those recorded definitions are only refreshed when something else
   * changes the hash, so a degraded component enforces whatever restrictions
   * were current at its last hash-changing save. The `catch` below is reached
   * only when the source plugin ID itself is unknown.
   *
   * @see \Drupal\canvas\ComponentSource\ComponentSourceBase::generateVersionHash()
   * @see \Drupal\canvas\Entity\Component::cleanSlotDefinition()
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback::getSlotDefinitions()
   *
   * @return array<string, array<string, mixed>>
   *   The slot definitions, keyed by slot name.
   */
  private static function slotDefinitions(Component $component): array {
    try {
      $source = $component->getComponentSource();
      if ($source instanceof ComponentSourceWithSlotsInterface) {
        return $source->getSlotDefinitions();
      }
    }
    catch (PluginException) {
      // The underlying implementation is gone; the recorded metadata is the
      // best available answer.
    }
    return $component->getSlotDefinitions();
  }

  /**
   * Normalizes an `expected` entry to a Component config entity ID.
   *
   * An entry containing a colon or a dot is a reference to a specific
   * component; anything else is a tag. Both delimiters are accepted because
   * both spellings occur: core's issue describes SDC plugin IDs
   * (`provider:name`), while Canvas's own component IDs use dots
   * (`sdc.provider.name`, `js.name`, `block.plugin.id`).
   *
   * @return string|null
   *   The Component config entity ID, or NULL if the entry is a tag.
   */
  public static function normalizeReference(string $entry): ?string {
    if (\str_contains($entry, ':')) {
      // An SDC plugin ID: `provider:machine_name`.
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::getComponentConfigEntityId()
      return 'sdc.' . \str_replace(':', '.', $entry);
    }
    return \str_contains($entry, '.') ? $entry : NULL;
  }

  /**
   * Reads a slot's `expected` entries.
   *
   * @param array<string, mixed> $slot_definition
   *   A slot definition.
   *
   * @return list<string>
   *   The `expected` entries.
   *
   * @todo Decide whether to also read the legacy `allowedComponents` spelling in https://www.drupal.org/i/3563163. It predates https://www.drupal.org/i/3514072, no Canvas release ever supported it, and supporting it means adding a deprecated key to `type: canvas.slot_definition` so that it survives \Drupal\canvas\Entity\Component::cleanSlotDefinition().
   */
  private static function expectedEntries(array $slot_definition): array {
    $expected = $slot_definition['expected'] ?? [];
    if (!\is_array($expected)) {
      return [];
    }
    $entries = [];
    foreach ($expected as $entry) {
      if (\is_string($entry) && $entry !== '') {
        $entries[] = $entry;
      }
    }
    return $entries;
  }

  /**
   * Whether at least one `expected` entry resolves to something that exists.
   *
   * Only ever called for a component that was already found not to fit, so the
   * cost of enumerating tags is paid on the unhappy path only.
   *
   * @param list<string> $expected
   *   The slot's `expected` entries.
   * @param \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage
   *   The Component config entity storage.
   */
  private static function hasResolvableEntry(array $expected, ConfigEntityStorageInterface $component_storage): bool {
    foreach ($expected as $entry) {
      $reference = self::normalizeReference($entry);
      $resolved = $reference === NULL
        ? self::componentIdsWithTag($entry, $component_storage) !== []
        : self::loadAvailable($reference, $component_storage) !== NULL;
      if ($resolved) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads a Component only if an author could actually place it.
   *
   * TRICKY: a disabled Component is not served to the Canvas UI, so the client
   * cannot resolve an `expected` entry naming one. Resolving it here anyway
   * would invert the rule between the two halves of the enforcement: the client
   * would see a slot none of whose entries resolves and treat it as
   * unrestricted, while the server would refuse everything but the component
   * the author cannot reach.
   *
   * @see \Drupal\canvas\Controller\ApiConfigControllers::list()
   */
  private static function loadAvailable(string $component_id, ConfigEntityStorageInterface $component_storage): ?Component {
    $component = $component_storage->load($component_id);
    return $component instanceof Component && $component->status() ? $component : NULL;
  }

  /**
   * Lists the components labeled with a tag.
   *
   * @param string $tag
   *   The tag.
   * @param \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage
   *   The Component config entity storage.
   *
   * @return list<string>
   *   The Component config entity IDs.
   */
  private static function componentIdsWithTag(string $tag, ConfigEntityStorageInterface $component_storage): array {
    if (!\array_key_exists($tag, self::$componentIdsByTag)) {
      $matches = [];
      foreach ($component_storage->loadMultiple() as $id => $component) {
        \assert($component instanceof Component);
        if (!$component->status()) {
          // A disabled component is not served to the Canvas UI, so it may not
          // count towards a tag here either.
          // @see self::loadAvailable()
          continue;
        }
        try {
          $tags = $component->getComponentSource()->getTags();
        }
        catch (PluginException) {
          // A component whose implementation is gone carries no tags.
          continue;
        }
        if (\in_array($tag, $tags, TRUE)) {
          $matches[] = (string) $id;
        }
      }
      self::$componentIdsByTag[$tag] = $matches;
    }
    return self::$componentIdsByTag[$tag];
  }

  /**
   * Groups a tree's component instances by the slot they live in.
   *
   * @param array<int, array<string, mixed>> $tree
   *   A component tree.
   *
   * @return list<array{parent_uuid: string, parent_component_id: string, slot: string, children: list<array{delta: int, uuid: string, component_id: string}>}>
   *   One entry per occupied slot, listing that slot's child instances in tree
   *   order together with each child's delta in the tree.
   */
  private static function groupBySlot(array $tree): array {
    // A tree malformed enough to be missing any of these is reported by the
    // surrounding structural validation; skip it rather than adding noise.
    $component_id_by_uuid = [];
    foreach ($tree as $item) {
      if (\is_array($item) && self::isNonEmptyString($item['uuid'] ?? NULL) && self::isNonEmptyString($item['component_id'] ?? NULL)) {
        $component_id_by_uuid[$item['uuid']] = $item['component_id'];
      }
    }
    $grouped = [];
    foreach ($tree as $delta => $item) {
      if (!\is_array($item)
        || !self::isNonEmptyString($item['uuid'] ?? NULL)
        || !self::isNonEmptyString($item['component_id'] ?? NULL)
        || !self::isNonEmptyString($item['parent_uuid'] ?? NULL)
        || !self::isNonEmptyString($item['slot'] ?? NULL)
      ) {
        continue;
      }
      // The parent can live outside this tree, for instance when an entity's
      // subtree hangs off a content template's component. Such a placement is
      // not the responsibility of this tree's validation.
      if (!\array_key_exists($item['parent_uuid'], $component_id_by_uuid)) {
        continue;
      }
      $key = $item['parent_uuid'] . "\0" . $item['slot'];
      $grouped[$key] ??= [
        'parent_uuid' => $item['parent_uuid'],
        'parent_component_id' => $component_id_by_uuid[$item['parent_uuid']],
        'slot' => $item['slot'],
        'children' => [],
      ];
      $grouped[$key]['children'][] = [
        'delta' => (int) $delta,
        'uuid' => $item['uuid'],
        'component_id' => $item['component_id'],
      ];
    }
    return \array_values($grouped);
  }

  /**
   * Builds the key that makes two evaluations of a tree comparable.
   *
   * @param string $rule
   *   The rule that was violated.
   * @param string|null $child_uuid
   *   The UUID of the child instance in violation, or NULL for a rule that
   *   applies to the slot as a whole rather than to any one child.
   * @param array{parent_uuid: string, parent_component_id: string, slot: string, children: list<array{delta: int, uuid: string, component_id: string}>} $group
   *   The slot the child sits in.
   */
  private static function key(string $rule, ?string $child_uuid, array $group): string {
    // Moving an instance changes its parent or slot, and therefore its key: a
    // placement that was tolerated where it stood is re-evaluated when the
    // author moves it.
    return \implode("\0", [$rule, $child_uuid ?? '', $group['parent_uuid'], $group['slot']]);
  }

  /**
   * Whether a value is a non-empty string.
   *
   * @phpstan-assert-if-true non-empty-string $value
   */
  private static function isNonEmptyString(mixed $value): bool {
    return \is_string($value) && $value !== '';
  }

  /**
   * Renders the human-readable label of a component.
   */
  private static function label(string $component_id, ConfigEntityStorageInterface $component_storage): string {
    $component = $component_storage->load($component_id);
    return $component instanceof Component ? (string) $component->label() : $component_id;
  }

  /**
   * Renders a slot's `expected` entries for an author.
   *
   * @param list<string> $expected
   *   The slot's `expected` entries.
   * @param \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage
   *   The Component config entity storage.
   */
  private static function describeExpected(array $expected, ConfigEntityStorageInterface $component_storage): string {
    $described = \array_map(
      static function (string $entry) use ($component_storage): string {
        $reference = self::normalizeReference($entry);
        return $reference === NULL ? $entry : self::label($reference, $component_storage);
      },
      $expected,
    );
    if (\count($described) > 5) {
      $remaining = \count($described) - 5;
      $described = \array_slice($described, 0, 5);
      $described[] = \sprintf('and %d more', $remaining);
    }
    return \implode(', ', $described);
  }

  /**
   * Forgets which components carry which tags.
   *
   * @internal
   *   Only for tests: the tag lookup is a per-request memoization of config
   *   that cannot change within a request.
   */
  public static function reset(): void {
    self::$componentIdsByTag = [];
  }

}
