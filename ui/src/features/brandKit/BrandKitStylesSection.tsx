import { useState } from 'react';
import { ChevronDownIcon, ChevronRightIcon } from '@radix-ui/react-icons';
import {
  Button,
  Callout,
  Checkbox,
  Flex,
  Select,
  Spinner,
  Text,
  TextField,
} from '@radix-ui/themes';

import {
  fixedOptions,
  offeredOptions,
  toggleOption,
  withEntry,
} from '@/features/brandKit/brandKitStyles';
import { useBrandKitStyles } from '@/features/brandKit/hooks/useBrandKitStyles';
import { groupByCategory } from '@/features/styles/styleUtils';
import {
  useGetStyleUsageQuery,
  useGetUnbrandedStylesQuery,
} from '@/services/styles';

import type {
  BrandKitStyleEntry,
  BrandKitStyles,
  StyleDefinition,
} from '@/types/Styles';

import styles from './BrandKitStylesSection.module.css';

const NO_FALLBACK = '__none__';

const uses = (count: number) =>
  count === 0 ? '' : count === 1 ? '1 use' : `${count} uses`;

/**
 * A text field that saves on blur or Enter, so typing a label does not write
 * a draft per keystroke.
 */
const LabelField = ({
  value,
  placeholder,
  label,
  onCommit,
}: {
  value: string;
  placeholder: string;
  label: string;
  onCommit: (value: string) => void;
}) => {
  const [draft, setDraft] = useState(value);
  const commit = () => {
    if (draft.trim() !== value) {
      onCommit(draft.trim());
    }
  };
  return (
    <TextField.Root
      size="1"
      value={draft}
      placeholder={placeholder}
      aria-label={label}
      className={styles.labelField}
      onChange={(event) => setDraft(event.target.value)}
      onBlur={commit}
      onKeyDown={(event) => {
        if (event.key === 'Enter') {
          commit();
        }
      }}
    />
  );
};

const StyleOptionsEditor = ({
  definition,
  entry,
  usage,
  onChange,
}: {
  definition: StyleDefinition;
  entry: BrandKitStyleEntry | undefined;
  usage: Record<string, number>;
  onChange: (entry: BrandKitStyleEntry) => void;
}) => {
  const offered = offeredOptions(definition, entry);
  return (
    <Flex direction="column" gap="2" className={styles.editor}>
      <Flex align="center" gap="2">
        <Text size="1" color="gray" className={styles.fieldLabel}>
          Name
        </Text>
        <LabelField
          value={entry?.label ?? ''}
          placeholder={definition.originalLabel}
          label={`Name for ${definition.originalLabel}`}
          onCommit={(label) => onChange({ ...entry, label })}
        />
      </Flex>
      {fixedOptions(definition).map((option) => {
        const isOffered = offered.includes(option.id);
        const count = usage[option.id] ?? 0;
        const fallback = entry?.fallbacks?.[option.id];
        return (
          <Flex
            key={option.id}
            direction="column"
            gap="1"
            data-testid={`canvas-brand-kit-style-option-${definition.id}-${option.id}`}
          >
            <Flex align="center" gap="2">
              <Checkbox
                size="1"
                checked={isOffered}
                aria-label={`Offer ${option.originalLabel}`}
                onCheckedChange={(checked) =>
                  onChange(
                    toggleOption(
                      definition,
                      entry,
                      option.id,
                      checked === true,
                      count,
                    ),
                  )
                }
              />
              {isOffered ? (
                <LabelField
                  value={entry?.labels?.[option.id] ?? ''}
                  placeholder={option.originalLabel}
                  label={`Name for ${option.originalLabel}`}
                  onCommit={(label) =>
                    onChange({
                      ...entry,
                      labels: { ...(entry?.labels ?? {}), [option.id]: label },
                    })
                  }
                />
              ) : (
                <Text size="1" color="gray" className={styles.withdrawn}>
                  {entry?.labels?.[option.id] || option.originalLabel}
                </Text>
              )}
              <Text size="1" color="gray" className={styles.uses}>
                {uses(count)}
              </Text>
            </Flex>
            {/* Content using a withdrawn option shows another one instead. */}
            {!isOffered && (count > 0 || fallback) && offered.length > 0 && (
              <Flex align="center" gap="2" className={styles.fallbackRow}>
                <Text size="1" color="gray">
                  Shows as
                </Text>
                <Select.Root
                  size="1"
                  value={fallback ?? NO_FALLBACK}
                  onValueChange={(next) => {
                    const fallbacks = { ...(entry?.fallbacks ?? {}) };
                    if (next === NO_FALLBACK) {
                      delete fallbacks[option.id];
                    } else {
                      fallbacks[option.id] = next;
                    }
                    onChange({ ...entry, fallbacks });
                  }}
                >
                  <Select.Trigger
                    aria-label={`What ${option.originalLabel} shows as`}
                  />
                  <Select.Content>
                    <Select.Item value={NO_FALLBACK}>Nothing</Select.Item>
                    {fixedOptions(definition)
                      .filter((candidate) => offered.includes(candidate.id))
                      .map((candidate) => (
                        <Select.Item key={candidate.id} value={candidate.id}>
                          {entry?.labels?.[candidate.id] ||
                            candidate.originalLabel}
                        </Select.Item>
                      ))}
                  </Select.Content>
                </Select.Root>
              </Flex>
            )}
          </Flex>
        );
      })}
      {definition.tokens && (
        <Text size="1" color="gray">
          Also offers brand kit colors
          {definition.tokens.folders.length > 0
            ? ` from ${definition.tokens.folders.join(', ')}`
            : ''}
          . Manage them in Colors.
        </Text>
      )}
      {definition.custom && (
        <Text as="label" size="1">
          <Flex gap="2" align="center">
            <Checkbox
              size="1"
              checked={entry?.custom !== false}
              onCheckedChange={(checked) =>
                onChange({
                  ...entry,
                  custom: checked === true ? undefined : false,
                })
              }
            />
            Allow custom values (
            {definition.custom.units.join(', ') || 'numbers'})
          </Flex>
        </Text>
      )}
    </Flex>
  );
};

const summary = (definition: StyleDefinition, offered: number) => {
  const total = fixedOptions(definition).length;
  if (total === 0) return definition.tokens ? 'Brand colors' : 'Custom only';
  if (offered === 0) return 'Not offered';
  if (offered === total) return 'All options';
  return `${offered} of ${total}`;
};

/**
 * Which style options this site offers, what they are called, and what
 * withdrawn options show as. One brand per site: every page uses these.
 */
const BrandKitStylesSection = () => {
  const { data, isLoading } = useGetUnbrandedStylesQuery();
  const { data: usageData } = useGetStyleUsageQuery();
  const { styles: brandStyles, save, errorMessage } = useBrandKitStyles();
  const [open, setOpen] = useState<string | null>(null);

  if (isLoading) {
    return <Spinner />;
  }
  if (!data?.active) {
    return (
      <Text size="1" color="gray">
        Component styles are not enabled on this site.
      </Text>
    );
  }

  const usage = usageData?.usage ?? {};
  // Two extensions can use the same label; name the provider for those.
  const labelCounts = data.styles.reduce<Record<string, number>>(
    (counts, definition) => ({
      ...counts,
      [definition.originalLabel]: (counts[definition.originalLabel] ?? 0) + 1,
    }),
    {},
  );
  const change = (definition: StyleDefinition, entry: BrandKitStyleEntry) =>
    save(withEntry(brandStyles, definition, entry));
  const reset = (styleId: string) => {
    const next: BrandKitStyles = { ...brandStyles };
    delete next[styleId];
    save(next);
  };

  return (
    <Flex direction="column" gap="3" data-testid="canvas-brand-kit-styles">
      <Text size="1" color="gray">
        Choose which options editors see on this site. Content using a withdrawn
        option keeps it, and shows as the option you pick.
      </Text>
      {errorMessage && (
        <Callout.Root size="1" color="red">
          <Callout.Text>{errorMessage}</Callout.Text>
        </Callout.Root>
      )}
      {groupByCategory(data.styles).map(({ category, styles: group }) => (
        <Flex key={category} direction="column" gap="1">
          <Text size="1" weight="bold">
            {category}
          </Text>
          {group.map((definition) => {
            const entry = brandStyles[definition.id];
            const offeredCount = offeredOptions(definition, entry).length;
            const isOpen = open === definition.id;
            return (
              <div
                key={definition.id}
                className={styles.style}
                data-testid={`canvas-brand-kit-style-${definition.id}`}
              >
                <button
                  type="button"
                  className={styles.styleHeader}
                  aria-expanded={isOpen}
                  onClick={() => setOpen(isOpen ? null : definition.id)}
                >
                  {isOpen ? <ChevronDownIcon /> : <ChevronRightIcon />}
                  <Text size="1" className={styles.styleName}>
                    {entry?.label || definition.originalLabel}
                    {labelCounts[definition.originalLabel] > 1 && (
                      <Text color="gray"> ({definition.providerLabel})</Text>
                    )}
                  </Text>
                  <Text
                    size="1"
                    color={offeredCount === 0 ? 'amber' : 'gray'}
                    className={styles.summary}
                  >
                    {summary(definition, offeredCount)}
                  </Text>
                </button>
                {isOpen && (
                  <>
                    <StyleOptionsEditor
                      definition={definition}
                      entry={entry}
                      usage={usage[definition.id] ?? {}}
                      onChange={(next) => change(definition, next)}
                    />
                    {entry && (
                      <Button
                        size="1"
                        variant="ghost"
                        color="gray"
                        className={styles.reset}
                        onClick={() => reset(definition.id)}
                      >
                        Use every option as defined
                      </Button>
                    )}
                  </>
                )}
              </div>
            );
          })}
        </Flex>
      ))}
    </Flex>
  );
};

export default BrandKitStylesSection;
