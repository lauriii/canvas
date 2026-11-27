import { useEffect, useRef, useState } from 'react';
import {
  ChevronDownIcon,
  ResetIcon,
  TextAlignCenterIcon,
  TextAlignJustifyIcon,
  TextAlignLeftIcon,
  TextAlignRightIcon,
} from '@radix-ui/react-icons';
import {
  DropdownMenu,
  Flex,
  Popover,
  SegmentedControl,
  Select,
  Slider,
  Switch,
  Text,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import { useStyleHoverPreview } from '@/features/styles/stylePreview';
import {
  accessibleName,
  allowedHere,
  chooseControl,
  convertCustom,
  customAllowed,
  customValue,
  isCustomValue,
  parseCustomValue,
  rangeOf,
  scrubPixelsPerStep,
  stepCustom,
  stepOf,
  visibleOptions,
} from '@/features/styles/styleUtils';

import type { ReactNode, PointerEvent as ReactPointerEvent } from 'react';
import type { StyleRowDecoration } from '@/features/styles/styleDecorations';
import type { StyleDefinition, StyleLimit, StyleOption } from '@/types/Styles';

import styles from './StyleTab.module.css';

/** Icons for common toolbar icon names; other names show the label. */
const ICONS: Record<string, ReactNode> = {
  'align-left': <TextAlignLeftIcon />,
  'align-center': <TextAlignCenterIcon />,
  'align-right': <TextAlignRightIcon />,
  'align-justify': <TextAlignJustifyIcon />,
};

/** The select item that clears the style. */
const UNSET = '__unset__';

const optionLabel = (option: StyleOption) =>
  option.status === 'deprecated'
    ? `${option.label} (Being retired)`
    : option.label;

interface StyleControlProps {
  definition: StyleDefinition;
  value: string | undefined;
  readOnly: boolean;
  decoration?: StyleRowDecoration;
  onChange: (optionId: string | undefined) => void;
  /** This component's limit on the style's choices. */
  limit?: StyleLimit;
  /** Whether the user may enter custom values. */
  canUseCustom?: boolean;
  /** The selection key, when it isn't the style ID: `part.style_id`. */
  selectionKey?: string;
  /** The named part this control styles, for its accessible name. */
  partLabel?: string;
  /** The component instance, for previewing choices on hover. */
  uuid?: string;
}

/** A small round sample of a brand kit color. */
const Swatch = ({ color }: { color: string | null }) => (
  <span
    className={styles.swatch}
    style={{ background: color ?? 'transparent' }}
    aria-hidden="true"
  />
);

/** How long typing pauses before a custom value applies. */
const TYPING_DELAY_MS = 250;

/**
 * One row for styles that accept custom values, as in Framer: a slider when
 * the style has bounds, and a compact field. The field's menu holds the unit
 * and the style's options. Valid values apply as you type, drag, or press the
 * arrow keys (Shift for ten steps); rapid changes undo in one step.
 */
const CustomValueControl = ({
  definition,
  options,
  value,
  readOnly,
  onChange,
  hover,
}: {
  definition: StyleDefinition;
  options: StyleOption[];
  value: string | undefined;
  readOnly: boolean;
  onChange: (value: string | undefined) => void;
  hover: ReturnType<typeof useStyleHoverPreview>;
}) => {
  const custom = definition.custom!;
  const parsed = isCustomValue(value)
    ? parseCustomValue(value, custom)
    : undefined;
  const option = options.find((candidate) => candidate.id === value);
  const shown = parsed ? String(parsed.number) : (option?.label ?? '');
  const [text, setText] = useState(shown);
  const [unit, setUnit] = useState(parsed?.unit ?? custom.units[0]);
  const [focused, setFocused] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

  // Follow changes made elsewhere (slider, scrubbing, options, undo) unless
  // typing.
  useEffect(() => {
    if (!focused) {
      setText(shown);
      if (parsed) setUnit(parsed.unit);
    }
  }, [shown, focused]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => () => clearTimeout(timer.current), []);

  const toValue = (raw: string, withUnit: string) => {
    const number = Number(raw.trim().replace(',', '.'));
    if (raw.trim() === '' || Number.isNaN(number)) return undefined;
    const next = customValue(number, withUnit);
    return parseCustomValue(next, custom) ? next : undefined;
  };
  const isValid =
    text.trim() === '' ||
    text === option?.label ||
    toValue(text, unit) !== undefined;

  const apply = (next: string | undefined) => {
    if (next !== undefined && next !== value) onChange(next);
  };
  const type = (raw: string) => {
    setText(raw);
    clearTimeout(timer.current);
    const next = toValue(raw, unit);
    timer.current = setTimeout(() => apply(next), TYPING_DELAY_MS);
  };

  // Each unit has its own bounds: 1–10 rem is 16–160 px.
  const bounds = rangeOf(custom, unit);
  const hasRange = bounds.min !== null && bounds.max !== null;
  const range = hasRange ? `${bounds.min}–${bounds.max} ${unit}` : unit;
  const hasMenu = custom.units.length > 1 || options.length > 0;
  // Options, and an empty field, have no unit of their own.
  const unitShown = option || (!parsed && text.trim() === '') ? '' : unit;

  const menu = hasMenu ? (
    <DropdownMenu.Root onOpenChange={(open) => !open && hover.end()}>
      <DropdownMenu.Trigger disabled={readOnly}>
        <button
          type="button"
          className={styles.customMenuTrigger}
          aria-label={`Unit and options for ${definition.label}`}
        >
          {unitShown}
          <ChevronDownIcon aria-hidden="true" />
        </button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Content size="1" align="end">
        {custom.units.length > 1 && (
          <DropdownMenu.RadioGroup
            value={parsed?.unit ?? unit}
            onValueChange={(next) => {
              setUnit(next);
              // Keep the size: 4rem becomes 64px, not 4px.
              if (parsed) {
                apply(convertCustom(parsed.number, parsed.unit, next, custom));
              }
            }}
          >
            {custom.units.map((candidate) => (
              <DropdownMenu.RadioItem key={candidate} value={candidate}>
                {candidate}
              </DropdownMenu.RadioItem>
            ))}
          </DropdownMenu.RadioGroup>
        )}
        {custom.units.length > 1 && options.length > 0 && (
          <DropdownMenu.Separator />
        )}
        {options.map((candidate) => (
          <DropdownMenu.CheckboxItem
            key={candidate.id}
            onFocus={() => hover.preview(candidate.id)}
            checked={candidate.id === value}
            onSelect={() => onChange(candidate.id)}
          >
            {optionLabel(candidate)}
          </DropdownMenu.CheckboxItem>
        ))}
        {value !== undefined && (
          <>
            <DropdownMenu.Separator />
            <DropdownMenu.Item
              className={styles.unsetItem}
              onFocus={() => hover.preview(undefined)}
              onSelect={() => onChange(undefined)}
            >
              (Unset)
            </DropdownMenu.Item>
          </>
        )}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  ) : (
    unitShown && (
      <Text size="1" color="gray" className={styles.customUnitText}>
        {unitShown}
      </Text>
    )
  );

  return (
    <Flex gap="2" align="center" className={styles.customControl}>
      {hasRange && (
        <Slider
          size="1"
          min={bounds.min!}
          max={bounds.max!}
          step={stepOf(custom, unit)}
          disabled={readOnly}
          // An option has no number: the thumb rests at the start, muted.
          value={[parsed ? parsed.number : bounds.min!]}
          className={
            parsed
              ? styles.customSlider
              : `${styles.customSlider} ${styles.sliderIdle}`
          }
          aria-label={`${definition.label} (${range})`}
          onValueChange={([number]) => {
            setText(String(number));
            apply(customValue(number, unit));
          }}
        />
      )}
      <TextField.Root
        size="1"
        value={text}
        inputMode="decimal"
        placeholder="Not set"
        disabled={readOnly}
        color={isValid ? undefined : 'red'}
        aria-invalid={!isValid}
        aria-label={`${definition.label}: an option, or a number (${range})`}
        className={hasRange ? styles.customFieldCompact : styles.customField}
        onFocus={(event) => {
          setFocused(true);
          event.currentTarget.select();
        }}
        onBlur={() => {
          setFocused(false);
          clearTimeout(timer.current);
          apply(toValue(text, unit));
          setText(shown);
        }}
        onChange={(event) => type(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            clearTimeout(timer.current);
            const steps =
              (event.key === 'ArrowUp' ? 1 : -1) * (event.shiftKey ? 10 : 1);
            const base = toValue(text, unit) ?? value;
            const next = stepCustom(base, custom, steps);
            setText(String(parseCustomValue(next, custom)?.number ?? ''));
            apply(next);
          }
          if (event.key === 'Enter') {
            clearTimeout(timer.current);
            apply(toValue(text, unit));
          }
          if (event.key === 'Escape') {
            clearTimeout(timer.current);
            setText(shown);
          }
        }}
      >
        <TextField.Slot side="right">{menu}</TextField.Slot>
      </TextField.Root>
    </Flex>
  );
};

/**
 * One style as a property row: label and control. Unset is no selection,
 * never a "Default" option. A set style has a tiny dot in the left margin,
 * which turns into a reset on hover; choosing the selected option again, or
 * "(Unset)" in a select, also clears it. The label opens the description
 * and Reset.
 */
const StyleControl = ({
  definition,
  value,
  readOnly,
  decoration,
  onChange,
  limit,
  canUseCustom = false,
  selectionKey = definition.id,
  partLabel,
  uuid,
}: StyleControlProps) => {
  // Hovering or arrowing through a list previews each choice on the page.
  const hover = useStyleHoverPreview(uuid, selectionKey, definition, value);
  // This component's limit applies; the stored value stays visible.
  const options = visibleOptions(definition, value).filter(
    (option) => allowedHere(option, limit) || option.id === value,
  );
  const offersCustom =
    customAllowed(definition, limit) && canUseCustom && !readOnly;
  const control = chooseControl(options);
  const isSet = value !== undefined;
  const pick = (next: string) => onChange(next === UNSET ? undefined : next);
  const name = partLabel
    ? `${partLabel}, ${accessibleName(definition, value)}`
    : accessibleName(definition, value);
  const descriptionId = `style-${selectionKey.replace('.', '-')}-description`;

  let input: ReactNode;
  if ((offersCustom || isCustomValue(value)) && definition.custom) {
    input = (
      <CustomValueControl
        hover={hover}
        definition={definition}
        options={options}
        value={value}
        readOnly={readOnly || !offersCustom}
        onChange={onChange}
      />
    );
  } else if (control === 'swatches') {
    const fixed = options.filter((option) => option.kind !== 'token');
    const folders = new Map<string, StyleOption[]>();
    for (const option of options.filter((o) => o.kind === 'token')) {
      const folder = option.folder ?? 'Colors';
      folders.set(folder, [...(folders.get(folder) ?? []), option]);
    }
    input = (
      <Select.Root
        size="1"
        value={value ?? ''}
        disabled={readOnly}
        onValueChange={pick}
        onOpenChange={(open) => !open && hover.end()}
      >
        <Select.Trigger
          aria-label={name}
          aria-describedby={definition.description ? descriptionId : undefined}
          className={isSet ? styles.select : styles.selectUnset}
          placeholder="Not set"
        />
        <Select.Content>
          {isSet && (
            <>
              <Select.Item
                value={UNSET}
                className={styles.unsetItem}
                onFocus={() => hover.preview(undefined)}
              >
                (Unset)
              </Select.Item>
              <Select.Separator />
            </>
          )}
          {fixed.map((option) => (
            <Select.Item
              key={option.id}
              value={option.id}
              onFocus={() => hover.preview(option.id)}
            >
              {optionLabel(option)}
            </Select.Item>
          ))}
          {[...folders].map(([folder, colors]) => (
            <Select.Group key={folder}>
              {(fixed.length > 0 || folders.size > 1) && (
                <Select.Label>{folder}</Select.Label>
              )}
              {colors.map((option) => (
                <Select.Item
                  key={option.id}
                  value={option.id}
                  onFocus={() => hover.preview(option.id)}
                >
                  <span className={styles.swatchItem}>
                    <Swatch color={option.swatch} />
                    {option.label}
                  </span>
                </Select.Item>
              ))}
            </Select.Group>
          ))}
        </Select.Content>
      </Select.Root>
    );
  } else if (control === 'switch') {
    input = (
      <Switch
        size="1"
        checked={isSet}
        disabled={readOnly}
        aria-label={name}
        aria-describedby={definition.description ? descriptionId : undefined}
        onCheckedChange={(checked) =>
          onChange(checked ? options[0].id : undefined)
        }
      />
    );
  } else if (control === 'toolbar' || control === 'segmented') {
    input = (
      <SegmentedControl.Root
        size="1"
        value={value ?? ''}
        aria-label={name}
        aria-describedby={definition.description ? descriptionId : undefined}
        aria-disabled={readOnly || undefined}
        data-disabled={readOnly || undefined}
        className={styles.segmented}
        onValueChange={(next) => !readOnly && pick(next)}
      >
        {options.map((option) => (
          <SegmentedControl.Item
            key={option.id}
            value={option.id}
            aria-label={option.label}
            // Choosing the selected option again clears the style. Radix
            // never reports a deselect, so this listens for the click.
            title={
              option.id === value && !readOnly
                ? 'Click again to clear'
                : undefined
            }
            onClick={() => {
              if (option.id === value && !readOnly) {
                onChange(undefined);
              }
            }}
          >
            {control === 'toolbar' && option.icon && ICONS[option.icon] ? (
              <Tooltip content={option.label}>
                <span>{ICONS[option.icon]}</span>
              </Tooltip>
            ) : (
              optionLabel(option)
            )}
          </SegmentedControl.Item>
        ))}
      </SegmentedControl.Root>
    );
  } else {
    input = (
      <Select.Root
        size="1"
        value={value ?? ''}
        disabled={readOnly}
        onValueChange={pick}
        onOpenChange={(open) => !open && hover.end()}
      >
        <Select.Trigger
          aria-label={name}
          aria-describedby={definition.description ? descriptionId : undefined}
          className={isSet ? styles.select : styles.selectUnset}
          placeholder="Not set"
        />
        <Select.Content>
          {/* Set apart from the options, so it never reads as a "None"
              option the style itself defines. */}
          {isSet && (
            <>
              <Select.Item
                value={UNSET}
                className={styles.unsetItem}
                onFocus={() => hover.preview(undefined)}
              >
                (Unset)
              </Select.Item>
              <Select.Separator />
            </>
          )}
          {options.map((option) => (
            <Select.Item
              key={option.id}
              value={option.id}
              onFocus={() => hover.preview(option.id)}
            >
              {optionLabel(option)}
            </Select.Item>
          ))}
        </Select.Content>
      </Select.Root>
    );
  }

  const [menuOpen, setMenuOpen] = useState(false);
  const canReset = isSet && !readOnly;

  // As in Figma and Webflow, dragging a number's label sideways changes it.
  // A click without movement still opens the label's menu.
  const scrubbable = offersCustom && Boolean(definition.custom);
  const scrubbed = useRef(false);
  const startScrub = (event: ReactPointerEvent) => {
    if (!scrubbable || event.button !== 0 || event.altKey) return;
    const custom = definition.custom!;
    const startX = event.clientX;
    const start = value;
    let last = value;
    scrubbed.current = false;
    const pixelsPerStep = scrubPixelsPerStep(custom, value);
    const move = (moveEvent: PointerEvent) => {
      const dx = moveEvent.clientX - startX;
      if (!scrubbed.current && Math.abs(dx) < 3) return;
      scrubbed.current = true;
      document.body.style.cursor = 'ew-resize';
      // Shift moves ten steps at a time.
      const steps =
        Math.round(dx / pixelsPerStep) * (moveEvent.shiftKey ? 10 : 1);
      const next = stepCustom(start, custom, steps);
      if (next !== last) {
        last = next;
        onChange(next);
      }
    };
    const end = () => {
      window.removeEventListener('pointermove', move);
      document.body.style.cursor = '';
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end, { once: true });
  };
  // Nothing to show without a description or a reset: a plain label.
  const hasMenu = canReset || Boolean(definition.description);

  return (
    <div
      className={styles.row}
      data-testid={`canvas-style-${selectionKey}`}
      data-style-set={isSet ? 'true' : 'false'}
    >
      <Flex align="center" gap="1" minWidth="0">
        {/* As in Webflow: a set style's label is a chip, and the label opens
            its details and Reset. Option-click resets directly. */}
        {!hasMenu ? (
          <span className={isSet ? styles.labelSet : styles.labelStatic}>
            {definition.label}
          </span>
        ) : (
          <Popover.Root open={menuOpen} onOpenChange={setMenuOpen}>
            <Popover.Trigger>
              <button
                type="button"
                className={`${isSet ? styles.labelSet : styles.label}${scrubbable ? ` ${styles.scrub}` : ''}`}
                aria-describedby={
                  definition.description ? descriptionId : undefined
                }
                onPointerDown={startScrub}
                onClick={(event) => {
                  if (scrubbed.current) {
                    scrubbed.current = false;
                    event.preventDefault();
                    return;
                  }
                  if (event.altKey && canReset) {
                    event.preventDefault();
                    onChange(undefined);
                  }
                }}
              >
                {definition.label}
              </button>
            </Popover.Trigger>
            <Popover.Content
              size="1"
              side="bottom"
              align="start"
              className={styles.labelMenu}
            >
              {/* About the style first; the action, and what it does, below. */}
              {definition.description && (
                <Text as="p" size="1" className={styles.menuDescription}>
                  {definition.description}
                </Text>
              )}
              {canReset && (
                <button
                  type="button"
                  className={styles.menuItem}
                  onClick={() => {
                    onChange(undefined);
                    setMenuOpen(false);
                  }}
                >
                  <ResetIcon aria-hidden="true" className={styles.menuIcon} />
                  <span className={styles.menuItemText}>
                    <span>Reset</span>
                    <span className={styles.menuItemHint}>
                      Back to the component&apos;s own design
                    </span>
                  </span>
                  <span className={styles.menuShortcut}>⌥ click</span>
                </button>
              )}
            </Popover.Content>
          </Popover.Root>
        )}
        {decoration?.marker && (
          <Tooltip
            content={decoration.marker.tooltip ?? decoration.marker.label}
          >
            <Text size="1" color="amber" className={styles.marker}>
              {decoration.marker.label}
            </Text>
          </Tooltip>
        )}
      </Flex>
      <Flex align="center" gap="2" minWidth="0" className={styles.control}>
        {input}
        {decoration?.action && !readOnly && (
          <button
            type="button"
            className={styles.linkButton}
            onClick={decoration.action.onSelect}
          >
            {decoration.action.label}
          </button>
        )}
      </Flex>
      {definition.description && (
        <span id={descriptionId} hidden>
          {definition.description}
        </span>
      )}
    </div>
  );
};

export default StyleControl;
