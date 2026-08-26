import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { ChevronDownIcon, Cross2Icon } from '@radix-ui/react-icons';
import { IconButton, Popover, Text } from '@radix-ui/themes';

import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import IconPickerContent from '@/components/icons/IconPickerContent';
import IconPreview from '@/components/icons/IconPreview';
import { useGetIconPacksQuery } from '@/services/icons';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from '@/components/form/components/drupal/DrupalIconPicker.module.css';

/**
 * Icon prop widget: closed control plus the icon picker popover.
 *
 * The Drupal `canvas_icon` field widget renders as a plain text input holding
 * the full icon id (`pack_id:icon_id`); this component replaces it in the
 * Canvas editor. The packs the prop is scoped to arrive in the
 * `data-canvas-icon-packs` attribute (space-separated pack ids; absent means
 * all installed packs).
 *
 * @see \Drupal\canvas\Plugin\Field\FieldWidget\IconWidget
 */
export const DrupalIconPicker = ({
  attributes = {},
}: {
  attributes?: Attributes;
  children?: React.ReactNode;
}) => {
  const externalValue = (attributes?.value ?? '').toString();
  const [value, setValue] = useState<string>(externalValue);
  const [isOpen, setIsOpen] = useState(false);
  const fieldContext = useFieldContext();
  // The catalog only changes on deploy or CLI push, but a push can happen
  // mid-session: refetch when the cached catalog is over a minute old.
  const { data: packs } = useGetIconPacksQuery(undefined, {
    refetchOnMountOrArgChange: 60,
  });

  // The form can re-render this widget with a different incoming value
  // (undo/redo, server-side form rebuilds); follow it.
  useEffect(() => {
    setValue(externalValue);
  }, [externalValue]);

  const allowedPacks = useMemo(() => {
    const scope = (attributes?.['data-canvas-icon-packs'] ?? '')
      .toString()
      .split(' ')
      .filter(Boolean);
    if (!packs) {
      return [];
    }
    return scope.length
      ? packs.filter((pack) => scope.includes(pack.id))
      : packs;
  }, [attributes, packs]);

  // Look the stored value up across every pack, not just the allowed ones,
  // matching resolveIconValue(): a value whose pack fell out of the prop's
  // scope still renders, so the control should still show it.
  const selectedIcon = useMemo(() => {
    if (!value) {
      return null;
    }
    for (const pack of packs ?? []) {
      const icon = pack.icons.find((icon) => icon.id === value);
      if (icon) {
        return icon;
      }
    }
    return null;
  }, [packs, value]);

  const updateValue = (newValue: string) => {
    fieldContext?.triggerChange(newValue);
    setValue(newValue);
  };

  return (
    <Popover.Root open={isOpen} onOpenChange={setIsOpen} modal={false}>
      {/* The clear button is a sibling of the trigger, not a child: nesting
          interactive elements inside a button is invalid markup. */}
      <div className={styles.control}>
        <Popover.Trigger>
          <button
            type="button"
            className={styles.trigger}
            aria-label={
              // A set but unresolvable value still announces as an icon (by
              // its raw id), matching what the control visibly shows.
              value
                ? `Icon: ${selectedIcon ? selectedIcon.label : value}`
                : 'Choose icon'
            }
          >
            {value ? (
              <>
                <span className={styles.iconChip}>
                  {selectedIcon && (
                    <IconPreview icon={selectedIcon} size={16} />
                  )}
                </span>
                <Text
                  size="2"
                  className={clsx(styles.label, {
                    [styles.labelBroken]: !selectedIcon,
                  })}
                  title={
                    selectedIcon ? undefined : `Icon not available: ${value}`
                  }
                  truncate
                >
                  {selectedIcon ? selectedIcon.label : value}
                </Text>
              </>
            ) : (
              <>
                <Text size="2" className={styles.placeholder} truncate>
                  Choose icon
                </Text>
                <ChevronDownIcon
                  className={styles.chevron}
                  aria-hidden="true"
                />
              </>
            )}
          </button>
        </Popover.Trigger>
        {value && (
          <IconButton
            aria-label="Clear icon"
            variant="ghost"
            color="gray"
            size="1"
            className={styles.clear}
            onClick={() => updateValue('')}
          >
            <Cross2Icon width="15" height="15" />
          </IconButton>
        )}
      </div>
      <Popover.Content
        side="left"
        align="start"
        sideOffset={8}
        className={styles.popover}
        // Inline style so it wins over the Radix theme's default padding.
        style={{ padding: 0 }}
        // The picker focuses its own search field on open; without this,
        // Radix would focus the first tabbable element instead.
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <IconPickerContent
          packs={allowedPacks}
          selectedId={value}
          onSelect={(icon) => {
            updateValue(icon.id);
            setIsOpen(false);
          }}
          onClose={() => setIsOpen(false)}
        />
      </Popover.Content>
    </Popover.Root>
  );
};

export default withRHF(DrupalIconPicker);
