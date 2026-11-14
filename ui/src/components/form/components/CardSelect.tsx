import { useEffect, useId, useRef, useState } from 'react';
import {
  ChevronDownIcon,
  Cross2Icon,
  ImageIcon,
  MagnifyingGlassIcon,
} from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';

import { useLabelId } from '@/components/form/components/reactSelectParts';
import Select from '@/components/form/components/Select';
import { noOptionsMessage } from '@/components/form/components/treeOptionLabel';
import { useFieldContext } from '@/components/form/contexts/FieldContext';

import type { KeyboardEvent } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './CardSelect.module.css';

type DrupalOption = {
  value: string;
  label: string;
  selected: boolean;
  type: string;
};

// The cards in a row. The arrow keys up and down move by a row.
const COLUMNS = 3;

/**
 * Whether a select offers its options as cards.
 *
 * The component schema asks for it with `x-canvas-options-display: cards`, next
 * to `x-canvas-options`.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceInterface::DISPLAY_KEY
 */
export const showsCards = (attributes: Attributes): boolean =>
  attributes['data-canvas-options-display'] === 'cards' &&
  !('multiple' in attributes);

/**
 * The image URLs of a select's options, by option value.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceWithImagesInterface
 */
const optionImages = (attributes: Attributes): Record<string, string> => {
  try {
    return JSON.parse(`${attributes['data-canvas-option-images'] ?? '{}'}`);
  } catch {
    // Not JSON: no images.
    return {};
  }
};

/**
 * The image of an option, or a placeholder. The label next to it names the
 * option, so the image is decorative.
 */
const Thumbnail = ({ src }: { src?: string }) => (
  <span className={styles.thumbnail} aria-hidden>
    {src ? <img src={src} alt="" loading="lazy" /> : <ImageIcon />}
  </span>
);

/**
 * Renders a single-value select as a button that opens a grid of cards.
 *
 * Each card shows the image and the label of an option. A search box filters
 * the cards by label. The <select> stays in the DOM, hidden, and remains the
 * form element. Choosing a card sets the select's value, which fires the same
 * change event as picking an option from the dropdown.
 *
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
 */
const CardSelect = ({
  attributes = {},
  options = [],
}: {
  attributes?: Attributes;
  options?: DrupalOption[];
}) => {
  const wrapperRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const fieldContext = useFieldContext();
  const labelId = useLabelId(attributes.id);
  const triggerId = useId();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');

  const current = `${
    attributes.value ?? options.find((option) => option.selected)?.value ?? ''
  }`;
  // Shows the choice before the form state catches up.
  const [selected, setSelected] = useState(current);
  useEffect(() => setSelected(current), [current]);

  const images = optionImages(attributes);
  // Optional selects offer "None": clearing the field picks it.
  const noneOption = options.find((option) => option.value === '_none');
  const items = options.filter(
    (option) => option.type !== 'optgroup' && option !== noneOption,
  );
  const chosen = items.find((option) => `${option.value}` === selected);
  const matches = items.filter((option) =>
    option.label.toLowerCase().includes(search.trim().toLowerCase()),
  );
  // One card is in the tab order: the arrow keys reach the others.
  const tabbable = matches.includes(chosen as DrupalOption)
    ? chosen
    : matches[0];

  const getSelect = () => wrapperRef.current?.querySelector('select');

  const choose = (value: string) => {
    setSelected(value);
    setOpen(false);
    const select = getSelect();
    if (select) {
      // Select intercepts the native setter to dispatch a change event.
      // @see interceptNativeSetter()
      select.value = value;
    }
  };

  const moveFocus = (event: KeyboardEvent<HTMLDivElement>) => {
    const step = {
      ArrowRight: 1,
      ArrowLeft: -1,
      ArrowDown: COLUMNS,
      ArrowUp: -COLUMNS,
    }[event.key];
    // The editor deletes the selected component on Backspace or Delete while
    // a button has focus: these keys go back to the search box.
    if (event.key === 'Backspace' || event.key === 'Delete') {
      event.stopPropagation();
      event.currentTarget
        .closest(`.${styles.popover}`)
        ?.querySelector<HTMLElement>('input')
        ?.focus();
      return;
    }
    if (!step) {
      return;
    }
    // Keep the arrow keys from scrolling the grid.
    event.preventDefault();
    const cards = [
      ...event.currentTarget.querySelectorAll<HTMLElement>('[role="option"]'),
    ];
    cards[cards.indexOf(event.target as HTMLElement) + step]?.focus();
  };

  return (
    <div
      ref={wrapperRef}
      className={styles.root}
      data-canvas-card-select
      onBlur={(event) => {
        // The hidden select never receives focus, so run its blur handling
        // (touched state, validation) when focus leaves the picker.
        if (!event.currentTarget.contains(event.relatedTarget)) {
          fieldContext?.triggerBlur(getSelect() ?? undefined);
        }
      }}
    >
      <Popover.Root
        open={open}
        onOpenChange={(isOpen) => {
          setOpen(isOpen);
          setSearch('');
        }}
      >
        <div
          className={styles.control}
          // Forward the invalid-field outline of the hidden select.
          data-invalid-prop-value={attributes['data-invalid-prop-value']}
        >
          <Popover.Trigger asChild>
            <button
              ref={triggerRef}
              type="button"
              id={triggerId}
              className={styles.trigger}
              disabled={!!attributes.disabled}
              // The Drupal label points at the hidden select. Name the button
              // with it, followed by the choice.
              aria-labelledby={labelId && `${labelId} ${triggerId}`}
              aria-describedby={
                attributes['aria-describedby'] as string | undefined
              }
            >
              {chosen ? (
                <>
                  <Thumbnail src={images[chosen.value]} />
                  <span className={styles.value} title={chosen.label}>
                    {chosen.label}
                  </span>
                </>
              ) : (
                <span className={styles.placeholder}>Choose…</span>
              )}
              <ChevronDownIcon className={styles.indicator} aria-hidden />
            </button>
          </Popover.Trigger>
          {chosen && noneOption && !attributes.disabled && (
            <button
              type="button"
              className={styles.clear}
              aria-label={`Clear ${chosen.label}`}
              onClick={() => {
                choose(noneOption.value);
                // This button goes away with the choice.
                triggerRef.current?.focus();
              }}
            >
              <Cross2Icon width={12} height={12} aria-hidden />
            </button>
          )}
        </div>
        {/* Not in a portal, like the popover of a list item: focus stays within
            the picker, and a popover around the picker stays open.
            @see ui/src/components/form/components/drupal/DrupalInputMultivalueForm.tsx */}
        <Popover.Content
          side="left"
          align="start"
          sideOffset={21}
          collisionPadding={8}
          className={styles.popover}
          aria-labelledby={labelId}
        >
          <div className={styles.search}>
            <input
              type="text"
              autoComplete="off"
              placeholder="Search"
              aria-label="Search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onKeyDown={(event) => {
                // Enter would submit the form that the picker is in.
                if (event.key === 'Enter') {
                  event.preventDefault();
                }
                if (event.key === 'ArrowDown') {
                  event.preventDefault();
                  event.currentTarget
                    .closest(`.${styles.popover}`)
                    ?.querySelector<HTMLElement>('[role="option"]')
                    ?.focus();
                }
              }}
            />
            <MagnifyingGlassIcon aria-hidden />
          </div>
          {matches.length ? (
            <div
              role="listbox"
              aria-labelledby={labelId}
              className={styles.grid}
              style={{
                gridTemplateColumns: `repeat(${COLUMNS}, minmax(0, 1fr))`,
              }}
              onKeyDown={moveFocus}
            >
              {matches.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  role="option"
                  aria-selected={option === chosen}
                  tabIndex={option === tabbable ? 0 : -1}
                  className={styles.card}
                  onClick={() => choose(`${option.value}`)}
                >
                  <Thumbnail src={images[option.value]} />
                  {/* The tooltip shows a label that is cut off. */}
                  <span className={styles.cardLabel} title={option.label}>
                    {option.label}
                  </span>
                </button>
              ))}
            </div>
          ) : (
            <p className={styles.empty} role="status">
              {noOptionsMessage({ inputValue: search.trim() })}
            </p>
          )}
        </Popover.Content>
      </Popover.Root>
      <div hidden>
        <Select attributes={attributes} options={options} />
      </div>
    </div>
  );
};

export default CardSelect;
