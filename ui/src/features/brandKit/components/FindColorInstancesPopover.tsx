import { useState } from 'react';
import * as Collapsible from '@radix-ui/react-collapsible';
import {
  ArrowRightIcon,
  ChevronRightIcon,
  Cross2Icon,
  MagnifyingGlassIcon,
} from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import {
  Badge,
  Box,
  Button,
  Flex,
  IconButton,
  Link,
  Separator,
  Spinner,
  Text,
  TextField,
} from '@radix-ui/themes';

import {
  countUniqueCurrentAndConfigUsages,
  deduplicateUsagesByComponentUuid,
} from '@/features/brandKit/colorUsage';
import { useGetColorUsageDetailsQuery } from '@/services/brandKit';
import { getCssColorValue } from '@/utils/brandKitColor';

import type { Measurable } from '@radix-ui/rect';
import type {
  ColorComponentUsage,
  ConfigEntityUsage,
  ContentEntityUsage,
} from '@/services/brandKit';
import type { BrandKitColor } from '@/types/CodeComponent';

import styles from './FindColorInstancesPopover.module.css';

/**
 * Group usages by their last ancestor label (immediate parent).
 * Returns a Map of ancestorLabel -> usages, with top-level usages under ''.
 */
function groupUsagesByAncestor<T extends ColorComponentUsage>(
  usages: T[],
): Map<string, T[]> {
  const groups = new Map<string, T[]>();
  for (const usage of usages) {
    const ancestorLabel =
      usage.ancestor_labels.length > 0
        ? usage.ancestor_labels[usage.ancestor_labels.length - 1]
        : '';
    const existing = groups.get(ancestorLabel) ?? [];
    existing.push(usage);
    groups.set(ancestorLabel, existing);
  }
  return groups;
}

type LabeledUsage = ColorComponentUsage & { displayLabel: string };

/**
 * Deduplicate usages by component_uuid, then append (1), (2), … to every
 * occurrence of a name when that name appears on more than one unique component.
 */
function deduplicateAndLabelUsages(
  usages: ColorComponentUsage[],
): LabeledUsage[] {
  // 1. Deduplicate by UUID – a component may use the same color multiple times.
  const unique = deduplicateUsagesByComponentUuid(usages);

  // 2. Count how many distinct UUIDs share the same display name.
  const nameCounts = new Map<string, number>();
  for (const u of unique) {
    const name = u.label ?? u.component_id;
    nameCounts.set(name, (nameCounts.get(name) ?? 0) + 1);
  }

  // 3. Assign disambiguated display labels.
  const nameIndex = new Map<string, number>();
  return unique.map((u) => {
    const name = u.label ?? u.component_id;
    if ((nameCounts.get(name) ?? 1) === 1) {
      return { ...u, displayLabel: name };
    }
    const idx = (nameIndex.get(name) ?? 0) + 1;
    nameIndex.set(name, idx);
    return { ...u, displayLabel: `${name} (${idx})` };
  });
}

/**
 * Build editor URL for a content entity with a specific component selected.
 */
function buildContentEntityUrl(
  entity: ContentEntityUsage,
  componentUuid: string,
): string {
  return `/canvas/editor/${entity.type}/${entity.id}/component/${componentUuid}`;
}

interface FindColorInstancesPopoverProps {
  color: BrandKitColor;
  anchorRef: React.RefObject<Measurable>;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const FindColorInstancesPopover = ({
  color,
  anchorRef,
  open,
  onOpenChange,
}: FindColorInstancesPopoverProps) => {
  const { data, isLoading, error } = useGetColorUsageDetailsQuery(color.id, {
    skip: !open,
  });

  const [expandedPages, setExpandedPages] = useState<Set<string>>(new Set());
  const [searchQuery, setSearchQuery] = useState('');

  const allCurrentUsages = data?.current ?? [];
  const allConfigUsages = data?.config ?? [];

  const normalizedQuery = searchQuery.trim().toLowerCase();
  const currentUsages = normalizedQuery
    ? allCurrentUsages.filter((e) =>
        e.title.toLowerCase().includes(normalizedQuery),
      )
    : allCurrentUsages;
  const configUsages = normalizedQuery
    ? allConfigUsages.filter((e) =>
        e.title.toLowerCase().includes(normalizedQuery),
      )
    : allConfigUsages;

  const totalInstances = countUniqueCurrentAndConfigUsages({
    current: currentUsages,
    config: configUsages,
  });

  const totalPages = currentUsages.length;

  const togglePage = (pageId: string) => {
    setExpandedPages((prev) => {
      const next = new Set(prev);
      if (next.has(pageId)) {
        next.delete(pageId);
      } else {
        next.add(pageId);
      }
      return next;
    });
  };

  const renderEntityUsage = (
    entity: ContentEntityUsage | ConfigEntityUsage,
    renderUsage: (usage: LabeledUsage) => React.ReactNode,
  ) => {
    const isOpen = expandedPages.has(entity.id);
    const labeled = deduplicateAndLabelUsages(entity.usages);
    const grouped = groupUsagesByAncestor(labeled);
    const hasTopLevel = grouped.has('');
    const hasSections = grouped.size > (hasTopLevel ? 1 : 0);

    return (
      <Collapsible.Root
        key={entity.id}
        open={isOpen}
        onOpenChange={() => togglePage(entity.id)}
        className={styles.pageCollapsible}
      >
        <Flex asChild justify="between" align="center" width="100%">
          <Collapsible.Trigger asChild className={styles.pageTrigger}>
            <button>
              <Flex align="center" gap="2">
                <ChevronRightIcon
                  className={isOpen ? styles.chevronOpen : styles.chevron}
                  aria-hidden
                />
                <Text size="1" weight="medium">
                  {entity.title}
                </Text>
              </Flex>
              <Badge
                size="1"
                variant="soft"
                color="gray"
                className={styles.triggerBadge}
              >
                {labeled.length}
              </Badge>
            </button>
          </Collapsible.Trigger>
        </Flex>
        <Collapsible.Content className={styles.pageContent}>
          <Flex direction="column" gap="2" pl="4" pt="2">
            {hasTopLevel &&
              (grouped.get('') ?? []).map((usage) => (
                <Box key={usage.component_uuid}>{renderUsage(usage)}</Box>
              ))}
            {hasSections &&
              Array.from(grouped.entries()).map(([ancestorLabel, usages]) => {
                if (ancestorLabel === '') return null;
                return (
                  <Box key={ancestorLabel} className={styles.sectionGroup}>
                    <Text size="1" weight="medium" color="gray">
                      {ancestorLabel}
                    </Text>
                    <Flex direction="column" gap="1" pl="2">
                      {usages.map((usage) => (
                        <Box key={usage.component_uuid}>
                          {renderUsage(usage)}
                        </Box>
                      ))}
                    </Flex>
                  </Box>
                );
              })}
          </Flex>
        </Collapsible.Content>
      </Collapsible.Root>
    );
  };

  const hasError = error != null;
  const hasNoUsages =
    currentUsages.length === 0 &&
    configUsages.length === 0 &&
    !isLoading &&
    !hasError;

  return (
    <Popover.Root open={open} onOpenChange={onOpenChange}>
      <Popover.Anchor virtualRef={anchorRef} />
      <Popover.Portal
        container={
          document.querySelector<HTMLElement>('.radix-themes') ?? document.body
        }
      >
        <Popover.Content
          side="bottom"
          align="start"
          sideOffset={4}
          className={styles.popoverContent}
          data-testid="canvas-find-color-instances-popover"
          onInteractOutside={(e) => {
            const target = e.target as Element | null;
            if (target?.hasAttribute('data-radix-menu-content')) {
              e.preventDefault();
            }
          }}
        >
          {/* Header with title and close button */}
          <Flex
            justify="between"
            align="center"
            className={styles.header}
            px="3"
            py="2"
          >
            <Text
              size="1"
              weight="bold"
              data-testid="find-color-instances-title"
            >
              Color usage
            </Text>
            <Popover.Close asChild>
              <IconButton
                variant="ghost"
                size="1"
                aria-label="Close"
                data-testid="find-color-instances-close-button"
              >
                <Cross2Icon />
              </IconButton>
            </Popover.Close>
          </Flex>

          <Box px="3" py="2" className={styles.body}>
            <Flex direction="column" gap="3">
              {/* Color summary row */}
              <Flex align="center" gap="3">
                <Box
                  className={styles.colorSwatch}
                  style={{ backgroundColor: getCssColorValue(color.value) }}
                />
                <Flex justify="between" align="center" flexGrow="1">
                  <Text size="1" weight="medium">
                    {color.name}
                  </Text>
                  <Text size="1" color="gray">
                    {getCssColorValue(color.value)}
                  </Text>
                </Flex>
              </Flex>

              {/* Search by page */}
              <TextField.Root
                placeholder="Search usages"
                size="1"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              >
                <TextField.Slot>
                  <MagnifyingGlassIcon height="12" width="12" />
                </TextField.Slot>
              </TextField.Root>

              {/* Error state */}
              {hasError && (
                <Text size="1" color="red">
                  Unable to load usage information for this color.
                </Text>
              )}

              {/* Loading state */}
              {isLoading && (
                <Flex justify="center" py="4">
                  <Spinner />
                </Flex>
              )}

              {/* No usages message */}
              {!isLoading && hasNoUsages && (
                <Text size="1" color="gray">
                  This color is not currently in use.
                </Text>
              )}

              {/* Current revisions */}
              {!isLoading && currentUsages.length > 0 && (
                <Box>
                  <Flex justify="between" align="center" mb="1">
                    <Text size="1" color="gray" mb="1">
                      {totalPages} page{totalPages !== 1 ? 's' : ''} found
                    </Text>
                    <Text align="right" size="1" color="gray" mb="1">
                      {totalInstances} instance{totalInstances !== 1 ? 's' : ''}
                    </Text>
                  </Flex>
                  <Separator size="4" mb="2" />
                  <Flex direction="column" gap="2">
                    {currentUsages.map((entity) =>
                      renderEntityUsage(entity, (usage) => (
                        <Flex justify="between" align="start" gap="2">
                          <Text
                            size="1"
                            color="gray"
                            className={styles.componentName}
                            title={usage.displayLabel}
                          >
                            {usage.displayLabel}
                          </Text>
                          <Link
                            href={buildContentEntityUrl(
                              entity,
                              usage.component_uuid,
                            )}
                            target="_blank"
                            rel="noopener noreferrer"
                            color="gray"
                            className={styles.componentLink}
                            title="Open in editor"
                          >
                            <ArrowRightIcon />
                          </Link>
                        </Flex>
                      )),
                    )}
                  </Flex>
                </Box>
              )}

              {/* Config entities */}
              {!isLoading && configUsages.length > 0 && (
                <Box>
                  <Text size="1" color="gray" mb="1">
                    Configuration
                  </Text>
                  <Separator size="4" mb="2" />
                  <Flex direction="column" gap="2">
                    {configUsages.map((entity) =>
                      renderEntityUsage(entity, (usage) => (
                        <Text
                          size="1"
                          color="gray"
                          className={styles.componentName}
                          title={usage.displayLabel}
                        >
                          {usage.displayLabel}
                        </Text>
                      )),
                    )}
                  </Flex>
                </Box>
              )}
            </Flex>
          </Box>

          {/* Footer with close button */}
          <Flex gap="2" justify="end" px="3" py="2" className={styles.footer}>
            <Popover.Close asChild>
              <Button
                variant="outline"
                size="1"
                data-testid="find-color-instances-footer-close-button"
              >
                Close
              </Button>
            </Popover.Close>
          </Flex>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
};

export default FindColorInstancesPopover;
