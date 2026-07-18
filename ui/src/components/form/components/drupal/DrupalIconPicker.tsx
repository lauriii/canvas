import { useMemo, useState } from 'react';
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
const DrupalIconPicker = ({
  attributes = {},
}: {
  attributes?: Attributes;
  children?: React.ReactNode;
}) => {
  const [value, setValue] = useState<string>(
    (attributes?.value ?? '').toString(),
  );
  const [isOpen, setIsOpen] = useState(false);
  const fieldContext = useFieldContext();
  const { data: packs } = useGetIconPacksQuery();

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

  const selectedIcon = useMemo(() => {
    if (!value) {
      return null;
    }
    for (const pack of allowedPacks) {
      const icon = pack.icons.find((icon) => icon.id === value);
      if (icon) {
        return icon;
      }
    }
    return null;
  }, [allowedPacks, value]);

  const updateValue = (newValue: string) => {
    fieldContext?.triggerChange(newValue);
    setValue(newValue);
  };

  return (
    <Popover.Root open={isOpen} onOpenChange={setIsOpen} modal={false}>
      <Popover.Trigger>
        <button
          type="button"
          className={styles.control}
          aria-label={
            selectedIcon ? `Icon: ${selectedIcon.label}` : 'Choose icon'
          }
        >
          {value ? (
            <>
              <span className={styles.iconChip}>
                {selectedIcon && <IconPreview icon={selectedIcon} size={16} />}
              </span>
              <Text
                size="2"
                className={clsx(styles.label, {
                  [styles.labelBroken]: !selectedIcon,
                })}
                title={selectedIcon ? undefined : `Missing icon: ${value}`}
                truncate
              >
                {selectedIcon ? selectedIcon.label : value}
              </Text>
              <IconButton
                aria-label="Clear icon"
                variant="ghost"
                color="gray"
                size="1"
                className={styles.clear}
                onClick={(e) => {
                  e.stopPropagation();
                  updateValue('');
                }}
              >
                <Cross2Icon width="15" height="15" />
              </IconButton>
            </>
          ) : (
            <>
              <Text size="2" className={styles.placeholder} truncate>
                Choose icon
              </Text>
              <ChevronDownIcon className={styles.chevron} aria-hidden="true" />
            </>
          )}
        </button>
      </Popover.Trigger>
      <Popover.Content
        side="left"
        align="start"
        sideOffset={8}
        className={styles.popover}
        // Inline style so it wins over the Radix theme's default padding.
        style={{ padding: 0 }}
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
