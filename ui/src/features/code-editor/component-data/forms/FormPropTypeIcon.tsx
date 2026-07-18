import { useMemo, useState } from 'react';
import { ChevronDownIcon } from '@radix-ui/react-icons';
import { Box, Button, Checkbox, Flex, Popover, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import IconPickerContent from '@/components/icons/IconPickerContent';
import IconPreview from '@/components/icons/IconPreview';
import {
  buildIconScopePattern,
  parseIconScopePattern,
} from '@/components/icons/iconScope';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { dispatchUpdateProp } from '@/features/code-editor/utils/arrayPropUtils';
import { useGetIconPacksQuery } from '@/services/icons';

import type { CodeComponentProp } from '@/types/CodeComponent';

/**
 * Form for the `icon` prop type in the code editor.
 *
 * Lets the component author scope the prop to one or more installed icon
 * packs (written into the prop schema as a generated `pattern`), and pick an
 * example icon from the allowed packs.
 */
export default function FormPropTypeIcon({
  id,
  example,
  pattern,
  isDisabled = false,
}: Pick<CodeComponentProp, 'id'> & {
  example: string;
  pattern?: string;
  isDisabled?: boolean;
}) {
  const dispatch = useAppDispatch();
  const { data: packs = [] } = useGetIconPacksQuery();
  const [isExamplePickerOpen, setIsExamplePickerOpen] = useState(false);

  // NULL means all installed packs are allowed.
  const selectedPackIds = parseIconScopePattern(pattern);
  const allowedPacks = useMemo(
    () =>
      selectedPackIds === null
        ? packs
        : packs.filter((pack) => selectedPackIds.includes(pack.id)),
    [packs, selectedPackIds],
  );

  const exampleIcon = useMemo(() => {
    if (!example) {
      return null;
    }
    for (const pack of allowedPacks) {
      const icon = pack.icons.find((icon) => icon.id === example);
      if (icon) {
        return icon;
      }
    }
    return null;
  }, [allowedPacks, example]);

  const togglePack = (packId: string, checked: boolean) => {
    const current =
      selectedPackIds === null ? packs.map((pack) => pack.id) : selectedPackIds;
    const next = checked
      ? [...current, packId]
      : current.filter((id) => id !== packId);
    // At least one pack must remain allowed.
    if (next.length === 0) {
      return;
    }
    const allSelected =
      next.length === packs.length &&
      packs.every((pack) => next.includes(pack.id));
    const updates: Partial<CodeComponentProp> = {
      pattern: allSelected ? undefined : buildIconScopePattern(next.sort()),
    };
    // Clear the example if it no longer matches the narrowed scope.
    if (example && !allSelected) {
      const examplePackId = example.split(':')[0];
      if (!next.includes(examplePackId)) {
        updates.example = '';
      }
    }
    dispatchUpdateProp(dispatch, id, updates);
  };

  const scopeSummary =
    selectedPackIds === null
      ? 'All icon packs'
      : allowedPacks.map((pack) => pack.label).join(', ') ||
        selectedPackIds.join(', ');

  return (
    <>
      <Box mt="3">
        <FormElement>
          <Label htmlFor={`prop-icon-packs-${id}`}>Icon packs</Label>
          <Popover.Root>
            <Popover.Trigger>
              <Button
                id={`prop-icon-packs-${id}`}
                variant="surface"
                color="gray"
                size="1"
                disabled={isDisabled || packs.length === 0}
                style={{ width: '100%', justifyContent: 'space-between' }}
              >
                <Text truncate>
                  {packs.length === 0
                    ? 'No icon packs installed'
                    : scopeSummary}
                </Text>
                <ChevronDownIcon />
              </Button>
            </Popover.Trigger>
            <Popover.Content
              side="bottom"
              align="start"
              style={{ width: 'var(--radix-popover-trigger-width)' }}
            >
              <Flex direction="column" gap="2">
                {packs.map((pack) => {
                  const isChecked =
                    selectedPackIds === null ||
                    selectedPackIds.includes(pack.id);
                  return (
                    <Text as="label" size="1" key={pack.id}>
                      <Flex gap="2" align="center">
                        <Checkbox
                          size="1"
                          checked={isChecked}
                          onCheckedChange={(checked) =>
                            togglePack(pack.id, checked === true)
                          }
                        />
                        {pack.label}
                        <Text color="gray">({pack.iconCount})</Text>
                      </Flex>
                    </Text>
                  );
                })}
              </Flex>
            </Popover.Content>
          </Popover.Root>
        </FormElement>
      </Box>
      <Box mt="3">
        <FormElement>
          <Label htmlFor={`prop-icon-example-${id}`}>Example icon</Label>
          <Popover.Root
            open={isExamplePickerOpen}
            onOpenChange={setIsExamplePickerOpen}
          >
            <Popover.Trigger>
              <Button
                id={`prop-icon-example-${id}`}
                variant="surface"
                color="gray"
                size="1"
                disabled={isDisabled || packs.length === 0}
                style={{ width: '100%', justifyContent: 'space-between' }}
              >
                <Flex align="center" gap="2" minWidth="0">
                  {exampleIcon && <IconPreview icon={exampleIcon} size={16} />}
                  <Text truncate>
                    {exampleIcon
                      ? exampleIcon.label
                      : example || 'Choose example icon'}
                  </Text>
                </Flex>
                <ChevronDownIcon />
              </Button>
            </Popover.Trigger>
            <Popover.Content side="bottom" align="start" style={{ padding: 0 }}>
              <IconPickerContent
                packs={allowedPacks}
                selectedId={example}
                onSelect={(icon) => {
                  dispatchUpdateProp(dispatch, id, { example: icon.id });
                  setIsExamplePickerOpen(false);
                }}
                onClose={() => setIsExamplePickerOpen(false)}
              />
            </Popover.Content>
          </Popover.Root>
        </FormElement>
      </Box>
    </>
  );
}
