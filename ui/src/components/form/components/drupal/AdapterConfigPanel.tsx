import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router';
import {
  ChevronDownIcon,
  ChevronUpIcon,
  Cross2Icon,
  PlusIcon,
} from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import {
  Badge,
  Box,
  Button,
  DropdownMenu,
  Flex,
  IconButton,
  SegmentedControl,
  Select,
  Switch,
  Text,
  TextField,
} from '@radix-ui/themes';

import { usePreviewPropSourceMutation } from '@/services/componentAndLayout';

import {
  createStep,
  getPrimaryInputName,
  humanizeInputName,
  isChainComplete,
  isMappingRowsSlot,
  stepsToSource,
  supportsLiteralBinding,
} from './adapterSource';

import type { PropSource } from '@/features/layout/layoutModelSlice';
import type {
  AdapterInputSlot,
  AdapterStep,
  AdapterSuggestion,
  MappingRow,
  SlotBinding,
  SlotMode,
} from './adapterSource';

import styles from './AdapterConfigPanel.module.css';

// A sentinel Select value for a bound source that matches no candidate (e.g.
// the candidate list changed since it was written). Radix Select items may
// not use the empty string.
const PRESERVED_SOURCE_VALUE = '__preserved__';

const DATE_FORMAT_OPTIONS = [
  { value: 'relative', label: 'Relative ("2 days ago")' },
  { value: 'short', label: 'Short' },
  { value: 'medium', label: 'Medium' },
  { value: 'long', label: 'Long' },
];
const DATE_FORMAT_CUSTOM = '__custom__';

const COMBINE_TEXT_PATTERN = /^text_\d+$/;

const formatPreviewValue = (value: unknown): string =>
  typeof value === 'string' ? value : (JSON.stringify(value) ?? '');

// Literal select for format_date's `format` slot: the known Drupal date
// format ids plus `relative`, with a free-text fallback for any other date
// format config entity id.
const DateFormatSelect = ({
  value,
  onChange,
}: {
  value: SlotBinding['value'];
  onChange: (value: string) => void;
}) => {
  const stringValue = typeof value === 'string' ? value : '';
  const isKnown = DATE_FORMAT_OPTIONS.some(
    (option) => option.value === stringValue,
  );
  const [custom, setCustom] = useState(() => stringValue !== '' && !isKnown);
  return (
    <Flex direction="column" gap="1">
      <Select.Root
        size="1"
        value={custom ? DATE_FORMAT_CUSTOM : isKnown ? stringValue : ''}
        onValueChange={(next) => {
          if (next === DATE_FORMAT_CUSTOM) {
            setCustom(true);
            onChange('');
          } else {
            setCustom(false);
            onChange(next);
          }
        }}
      >
        <Select.Trigger placeholder="Select a format" />
        <Select.Content>
          {DATE_FORMAT_OPTIONS.map((option) => (
            <Select.Item key={option.value} value={option.value}>
              {option.label}
            </Select.Item>
          ))}
          <Select.Item value={DATE_FORMAT_CUSTOM}>Custom…</Select.Item>
        </Select.Content>
      </Select.Root>
      {custom && (
        <TextField.Root
          size="1"
          placeholder="Date format ID"
          value={stringValue}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Flex>
  );
};

// Key/value rows editor for the mapping adapter's `cases` slot. Serializes to
// a JSON object string, e.g. {"blue":"primary"}.
const MappingRowsEditor = ({
  rows,
  onChange,
}: {
  rows: MappingRow[];
  onChange: (rows: MappingRow[]) => void;
}) => {
  const updateRow = (index: number, patch: Partial<MappingRow>) => {
    onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  };
  return (
    <Flex direction="column" gap="1">
      {rows.map((row, index) => (
        <Flex key={index} gap="1" align="center">
          <TextField.Root
            size="1"
            placeholder="Case"
            aria-label={`Case ${index + 1}`}
            value={row.key}
            onChange={(event) => updateRow(index, { key: event.target.value })}
          />
          <Text size="1" color="gray" aria-hidden>
            →
          </Text>
          <TextField.Root
            size="1"
            placeholder="Output"
            aria-label={`Output ${index + 1}`}
            value={row.value}
            onChange={(event) =>
              updateRow(index, { value: event.target.value })
            }
          />
          <IconButton
            variant="ghost"
            size="1"
            aria-label={`Remove case ${index + 1}`}
            disabled={rows.length === 1}
            onClick={() => onChange(rows.filter((_, i) => i !== index))}
          >
            <Cross2Icon />
          </IconButton>
        </Flex>
      ))}
      <Box>
        <Button
          size="1"
          variant="ghost"
          onClick={() => onChange([...rows, { key: '', value: '' }])}
        >
          <PlusIcon />
          Add case
        </Button>
      </Box>
    </Flex>
  );
};

export interface AdapterConfigPanelProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  propName: string;
  // The step chain the panel opens with: a single fresh step when starting a
  // new transform, or the unwrapped chain when editing an existing one.
  initialSteps: AdapterStep[];
  adapterSuggestions: AdapterSuggestion[];
  onApply: (source: PropSource) => void;
}

/**
 * Floating panel for configuring component prop adapters (no-code value
 * transforms).
 *
 * Uses the same anchored popover pattern as the multivalue prop forms
 * (see DrupalInputMultivalueForm): the panel floats next to the prop being
 * edited so the canvas preview stays visible while configuring, instead of a
 * centered blocking modal.
 *
 * Presents the transform chain as a linear ordered list of steps. Each step
 * renders one row per adapter input slot; slots are bound to a field
 * candidate or a literal value. Step 1's primary input is user-bound; the
 * primary input of every later step is locked to the previous step's output.
 */
const AdapterConfigPanel = ({
  open,
  onOpenChange,
  propName,
  initialSteps,
  adapterSuggestions,
  onApply,
}: AdapterConfigPanelProps) => {
  const [steps, setSteps] = useState<AdapterStep[]>(initialSteps);
  // Incremented each time the panel is (re)seeded. Used as a render key so
  // literal editor components with local state (e.g. DateFormatSelect's
  // custom mode) remount instead of leaking state between transforms.
  const [seedKey, setSeedKey] = useState(0);
  const { entityType, previewEntityId } = useParams();
  const [previewPropSource, previewState] = usePreviewPropSourceMutation();

  // Re-seed the editing state each time the panel opens.
  useEffect(() => {
    if (open) {
      setSteps(initialSteps);
      setSeedKey((previous) => previous + 1);
    }
  }, [open, initialSteps]);

  const complete = isChainComplete(steps);
  // Serialize to JSON so the debounced preview effect below only re-fires on
  // actual configuration changes, not on unrelated re-renders.
  const serializedJson = useMemo(() => {
    if (!isChainComplete(steps)) {
      return null;
    }
    const source = stepsToSource(steps);
    return source ? JSON.stringify(source) : null;
  }, [steps]);

  // Live preview: evaluate the configured chain against the host preview
  // entity, debounced while the user edits.
  useEffect(() => {
    if (!open || !entityType || !previewEntityId || !serializedJson) {
      return;
    }
    const timer = setTimeout(() => {
      previewPropSource({
        entityTypeId: entityType,
        entityId: previewEntityId,
        source: JSON.parse(serializedJson),
      });
    }, 400);
    return () => clearTimeout(timer);
  }, [open, entityType, previewEntityId, serializedJson, previewPropSource]);

  const previewError = useMemo(() => {
    const { error } = previewState;
    if (!error) {
      return null;
    }
    const data =
      'data' in error
        ? (error.data as { errors?: string[] } | undefined)
        : undefined;
    if (data?.errors?.length) {
      return data.errors.join(' ');
    }
    return 'The preview could not be evaluated.';
  }, [previewState]);

  const updateBinding = (
    stepIndex: number,
    slotName: string,
    patch: Partial<SlotBinding>,
  ) => {
    setSteps((prev) =>
      prev.map((step, index) =>
        index === stepIndex
          ? {
              ...step,
              bindings: {
                ...step.bindings,
                [slotName]: { ...step.bindings[slotName], ...patch },
              },
            }
          : step,
      ),
    );
  };

  const removeStep = (index: number) => {
    setSteps((prev) => prev.filter((_, i) => i !== index));
  };

  const moveStep = (index: number, delta: number) => {
    setSteps((prev) => {
      const target = index + delta;
      if (target < 0 || target >= prev.length) {
        return prev;
      }
      const next = [...prev];
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  const addStep = (suggestion: AdapterSuggestion) => {
    setSteps((prev) => [...prev, createStep(suggestion)]);
  };

  const changeMode = (
    stepIndex: number,
    step: AdapterStep,
    slot: AdapterInputSlot,
    mode: SlotMode,
  ) => {
    const binding = step.bindings[slot.name];
    const patch: Partial<SlotBinding> = { mode };
    if (mode === 'literal') {
      if (slot.schema?.type === 'boolean' && binding.value === undefined) {
        patch.value = false;
      }
      if (
        isMappingRowsSlot(step.adapter.id, slot.name) &&
        binding.rows === undefined
      ) {
        patch.rows = [{ key: '', value: '' }];
      }
    }
    updateBinding(stepIndex, slot.name, patch);
  };

  const renderFieldControl = (
    stepIndex: number,
    slot: AdapterInputSlot,
    binding: SlotBinding,
  ) => {
    if (slot.candidates.length === 0 && !binding.source) {
      return (
        <Select.Root size="1" value="" disabled>
          <Select.Trigger placeholder="No matching fields available" />
          <Select.Content />
        </Select.Root>
      );
    }
    const selectValue = binding.candidateId
      ? binding.candidateId
      : binding.source
        ? PRESERVED_SOURCE_VALUE
        : '';
    return (
      <Select.Root
        size="1"
        value={selectValue}
        onValueChange={(candidateId) => {
          if (candidateId === PRESERVED_SOURCE_VALUE) {
            return;
          }
          const candidate = slot.candidates.find(
            (item) => item.id === candidateId,
          );
          if (candidate) {
            updateBinding(stepIndex, slot.name, {
              candidateId: candidate.id,
              source: candidate.source,
            });
          }
        }}
      >
        <Select.Trigger placeholder="Select a field" />
        <Select.Content>
          {/* Keep a bound source that matches no candidate selectable so the
              configuration survives an edit without rebinding. */}
          {binding.source && !binding.candidateId && (
            <Select.Item value={PRESERVED_SOURCE_VALUE}>
              Currently bound value
            </Select.Item>
          )}
          {slot.candidates.map((candidate) => (
            <Select.Item key={candidate.id} value={candidate.id}>
              {candidate.label}
            </Select.Item>
          ))}
        </Select.Content>
      </Select.Root>
    );
  };

  const renderLiteralControl = (
    stepIndex: number,
    step: AdapterStep,
    slot: AdapterInputSlot,
    binding: SlotBinding,
  ) => {
    if (isMappingRowsSlot(step.adapter.id, slot.name)) {
      return (
        <MappingRowsEditor
          rows={binding.rows ?? [{ key: '', value: '' }]}
          onChange={(rows) => updateBinding(stepIndex, slot.name, { rows })}
        />
      );
    }
    if (step.adapter.id === 'format_date' && slot.name === 'format') {
      return (
        <DateFormatSelect
          value={binding.value}
          onChange={(value) => updateBinding(stepIndex, slot.name, { value })}
        />
      );
    }
    if (slot.schema?.type === 'boolean') {
      return (
        <Switch
          size="1"
          checked={binding.value === true}
          onCheckedChange={(checked) =>
            updateBinding(stepIndex, slot.name, { value: checked })
          }
          aria-label={humanizeInputName(slot.name)}
        />
      );
    }
    if (slot.schema?.enum) {
      const stringValue =
        binding.value === undefined || binding.value === null
          ? ''
          : String(binding.value);
      return (
        <Select.Root
          size="1"
          value={stringValue}
          onValueChange={(value) => {
            // Radix Select yields strings: match back to the typed enum
            // entry so integer/number enums do not serialize as strings.
            const typed = slot.schema?.enum?.find(
              (option) => String(option) === value,
            );
            updateBinding(stepIndex, slot.name, { value: typed ?? value });
          }}
        >
          <Select.Trigger placeholder="Select a value" />
          <Select.Content>
            {slot.schema.enum.map((option) => (
              <Select.Item key={String(option)} value={String(option)}>
                {humanizeInputName(String(option))}
              </Select.Item>
            ))}
          </Select.Content>
        </Select.Root>
      );
    }
    if (slot.schema?.type === 'integer' || slot.schema?.type === 'number') {
      return (
        <TextField.Root
          size="1"
          type="number"
          value={typeof binding.value === 'number' ? String(binding.value) : ''}
          onChange={(event) => {
            const raw = event.target.value;
            const parsed =
              slot.schema?.type === 'integer'
                ? parseInt(raw, 10)
                : parseFloat(raw);
            updateBinding(stepIndex, slot.name, {
              value: raw === '' || Number.isNaN(parsed) ? null : parsed,
            });
          }}
        />
      );
    }
    return (
      <TextField.Root
        size="1"
        placeholder="Enter a value"
        value={typeof binding.value === 'string' ? binding.value : ''}
        onChange={(event) =>
          updateBinding(stepIndex, slot.name, { value: event.target.value })
        }
      />
    );
  };

  const renderSlotRow = (
    stepIndex: number,
    step: AdapterStep,
    slot: AdapterInputSlot,
    isLockedPrimary: boolean,
  ) => {
    const binding = step.bindings[slot.name];
    const label = humanizeInputName(slot.name);
    return (
      <Flex
        key={slot.name}
        direction="column"
        gap="1"
        className={styles.slotRow}
        data-testid={`adapter-input-${slot.name}`}
      >
        <Flex align="center" justify="between" gap="2">
          <Text size="1" weight="medium">
            {label}
            {slot.required && (
              <span className={styles.requiredIndicator} aria-hidden>
                {' '}
                *
              </span>
            )}
          </Text>
          <Flex align="center" gap="2">
            {isLockedPrimary ? (
              <Badge color="gray">Previous step</Badge>
            ) : (
              // Object- and array-shaped slots (and slots without a static
              // template) cannot take a literal: field binding only.
              supportsLiteralBinding(slot) && (
                <SegmentedControl.Root
                  size="1"
                  value={binding.mode}
                  onValueChange={(mode) =>
                    changeMode(stepIndex, step, slot, mode as SlotMode)
                  }
                >
                  <SegmentedControl.Item value="field">
                    Field
                  </SegmentedControl.Item>
                  <SegmentedControl.Item value="literal">
                    Value
                  </SegmentedControl.Item>
                </SegmentedControl.Root>
              )
            )}
            {!slot.required && (
              <IconButton
                variant="ghost"
                size="1"
                aria-label={`Clear ${label}`}
                onClick={() =>
                  updateBinding(stepIndex, slot.name, {
                    enabled: false,
                    candidateId: undefined,
                    source: undefined,
                    value: undefined,
                    rows: undefined,
                  })
                }
              >
                <Cross2Icon />
              </IconButton>
            )}
          </Flex>
        </Flex>
        {!isLockedPrimary &&
          (binding.mode === 'field'
            ? renderFieldControl(stepIndex, slot, binding)
            : renderLiteralControl(stepIndex, step, slot, binding))}
      </Flex>
    );
  };

  const renderStep = (step: AdapterStep, stepIndex: number) => {
    const primary = getPrimaryInputName(step.adapter);
    const isCombine = step.adapter.id === 'combine';
    // For combine, hidden optional text_N slots are revealed one at a time by
    // the "Add input" button below rather than one adder per slot.
    const hiddenCombineSlots = isCombine
      ? step.adapter.inputs.filter(
          (slot) =>
            COMBINE_TEXT_PATTERN.test(slot.name) &&
            !slot.required &&
            !step.bindings[slot.name].enabled,
        )
      : [];

    return (
      <Box
        // The seed key remounts the step subtree when the panel is reseeded,
        // resetting any local state in the literal editors.
        key={`${seedKey}-${stepIndex}`}
        className={styles.step}
        data-testid={`adapter-step-${stepIndex}`}
      >
        <Flex align="center" justify="between" className={styles.stepHeader}>
          <Text size="1" weight="bold">
            {steps.length > 1 ? `Step ${stepIndex + 1}: ` : ''}
            {step.label}
          </Text>
          <Flex align="center" gap="2">
            <IconButton
              variant="ghost"
              size="1"
              aria-label={`Move step ${stepIndex + 1} up`}
              disabled={stepIndex === 0}
              onClick={() => moveStep(stepIndex, -1)}
            >
              <ChevronUpIcon />
            </IconButton>
            <IconButton
              variant="ghost"
              size="1"
              aria-label={`Move step ${stepIndex + 1} down`}
              disabled={stepIndex === steps.length - 1}
              onClick={() => moveStep(stepIndex, 1)}
            >
              <ChevronDownIcon />
            </IconButton>
            <IconButton
              variant="ghost"
              size="1"
              aria-label={`Remove step ${stepIndex + 1}`}
              onClick={() => removeStep(stepIndex)}
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        </Flex>
        {step.adapter.inputs.map((slot) => {
          const isLockedPrimary = stepIndex > 0 && slot.name === primary;
          const binding = step.bindings[slot.name];
          if (!slot.required && !binding.enabled && !isLockedPrimary) {
            // Combine's hidden text_N slots share the single "Add input"
            // button below.
            if (isCombine && COMBINE_TEXT_PATTERN.test(slot.name)) {
              return null;
            }
            return (
              <Box key={slot.name} className={styles.slotRow}>
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() =>
                    updateBinding(stepIndex, slot.name, { enabled: true })
                  }
                >
                  <PlusIcon />
                  {humanizeInputName(slot.name)}
                </Button>
              </Box>
            );
          }
          return renderSlotRow(stepIndex, step, slot, isLockedPrimary);
        })}
        {hiddenCombineSlots.length > 0 && (
          <Box className={styles.slotRow}>
            <Button
              size="1"
              variant="ghost"
              onClick={() =>
                updateBinding(stepIndex, hiddenCombineSlots[0].name, {
                  enabled: true,
                })
              }
            >
              <PlusIcon />
              Add input
            </Button>
          </Box>
        )}
      </Box>
    );
  };

  const handleApply = () => {
    const source = stepsToSource(steps);
    if (!source) {
      return;
    }
    onApply(source);
  };

  return (
    <Popover.Root open={open} onOpenChange={onOpenChange}>
      {/* Invisible anchor next to the prop's linker icon: the panel floats
          to the side of the prop being edited, like the multivalue prop
          popovers. */}
      <Popover.Anchor className={styles.anchor} />
      <Popover.Content
        side="left"
        align="start"
        sideOffset={36}
        collisionPadding={12}
        className={styles.panel}
        // A multi-field configuration must not be discarded by a stray click
        // on the canvas (or by interacting with portaled child widgets such
        // as selects): the panel only closes via Apply, Cancel, the close
        // button, or Escape.
        onInteractOutside={(event) => event.preventDefault()}
        aria-label={`Transform ${humanizeInputName(propName)}`}
        data-testid="adapter-config-panel"
      >
        {/* Panel header */}
        <Flex justify="between" align="center" className={styles.panelHeader}>
          <Text size="1" weight="medium" className={styles.panelLabel}>
            Transform {humanizeInputName(propName)}
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>
        {/* Long chains scroll inside the panel; the preview row and the
            Apply/Cancel footer below stay visible. */}
        <Box className={styles.scrollArea}>
          {steps.map((step, index) => renderStep(step, index))}
          <Box mt="2">
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <Button size="1" variant="soft" data-testid="adapter-add-step">
                  <PlusIcon />
                  Add step
                </Button>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content>
                {adapterSuggestions.map((suggestion, index) => (
                  <DropdownMenu.Item
                    key={suggestion.id ?? index}
                    data-transform-option={suggestion.adapter.id}
                    onClick={() => addStep(suggestion)}
                  >
                    {suggestion.label}
                  </DropdownMenu.Item>
                ))}
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          </Box>
        </Box>
        {entityType && previewEntityId && (
          <Flex
            direction="column"
            gap="1"
            className={styles.previewRow}
            data-testid="adapter-preview"
          >
            <Text size="1" weight="bold">
              Preview
            </Text>
            {!complete ? (
              <Text size="1" color="gray">
                Configure all required inputs to see a preview.
              </Text>
            ) : previewState.isLoading ? (
              <Text size="1" color="gray">
                Evaluating…
              </Text>
            ) : previewError ? (
              <Text size="1" color="red" className={styles.previewValue}>
                {previewError}
              </Text>
            ) : previewState.data ? (
              <Text size="1" className={styles.previewValue}>
                {formatPreviewValue(previewState.data.value)}
              </Text>
            ) : null}
          </Flex>
        )}
        <Flex gap="2" justify="end" mt="3">
          <Button
            variant="outline"
            size="1"
            onClick={() => onOpenChange(false)}
          >
            Cancel
          </Button>
          <Button
            size="1"
            disabled={!complete}
            onClick={handleApply}
            data-testid="adapter-apply"
          >
            Apply
          </Button>
        </Flex>
      </Popover.Content>
    </Popover.Root>
  );
};

export default AdapterConfigPanel;
