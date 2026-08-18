import { useEffect, useMemo } from 'react';
import { Box, DropdownMenu, Grid, Select } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import CanvasColorPickerField from '@/components/form/components/drupal/CanvasColorPickerField';
import { useBrandKitColors } from '@/features/brandKit/hooks/useBrandKitColors';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { useGetFoldersQuery } from '@/services/componentAndLayout';
import { ALL_FOLDERS_ID } from '@/utils/colorConstants';

const FALLBACK_DEFAULT_COLOR = '#ff0000ff';

const COLOR_PICKER_MODES = [
  { value: 'kit-only', label: 'Brand Kit' },
  { value: 'kit-and-free', label: 'Freeform' },
] as const;

interface FormPropTypeColorProps {
  id: string;
  example?: string | string[] | boolean;
  required?: boolean;
  'x-canvas-color-picker'?: 'kit-only' | 'kit-and-free';
  'x-canvas-color-folders'?: string[];
}

export default function FormPropTypeColor({
  id,
  example,
  required = false,
  'x-canvas-color-picker': colorPickerMode = 'kit-only',
  'x-canvas-color-folders': colorFolders = [],
}: FormPropTypeColorProps) {
  const dispatch = useAppDispatch();
  const { colors: brandKitColors } = useBrandKitColors();
  const { data: foldersData } = useGetFoldersQuery();
  const currentExample = typeof example === 'string' ? example : '';

  // Build list of color folders for the folder selector
  const availableColorFolders = useMemo(() => {
    if (!foldersData?.folders) return [];
    return Object.values(foldersData.folders)
      .filter((folder: any) => folder.type === 'color')
      .sort((a: any, b: any) => {
        const aWeight = a.weight ?? 0;
        const bWeight = b.weight ?? 0;
        if (aWeight !== bWeight) return aWeight - bWeight;
        return (a.name ?? '').localeCompare(b.name ?? '');
      });
  }, [foldersData]);

  // "All" is active when folders array is empty or contains the ALL_FOLDERS_ID sentinel
  const isAllFoldersSelected =
    colorFolders.length === 0 || colorFolders.includes(ALL_FOLDERS_ID);

  // Label to display in the folder dropdown trigger
  const folderDropdownLabel = useMemo(() => {
    if (isAllFoldersSelected) return 'All';
    const selected = colorFolders.filter((id) => id !== ALL_FOLDERS_ID);
    if (selected.length === 1) {
      const folder = availableColorFolders.find(
        (f: any) => f.id === selected[0],
      );
      return folder ? folder.name : '1 folder';
    }
    return `${selected.length} folders`;
  }, [isAllFoldersSelected, colorFolders, availableColorFolders]);

  // Auto-set default color for kit-only/kit-and-free modes when:
  // - Example is empty, OR
  // - Example is a canvas-color:<uuid> that no longer exists in the brand kit
  useEffect(() => {
    if (!brandKitColors) return;

    const isEmpty = !currentExample;
    const isKitRef = currentExample.startsWith('canvas-color:');
    const uuid = isKitRef ? currentExample.slice('canvas-color:'.length) : null;
    const colorExists = uuid && brandKitColors?.find((c) => c.id === uuid);
    if (isEmpty || (isKitRef && !colorExists)) {
      if (brandKitColors && brandKitColors.length > 0) {
        dispatch(
          updateProp({
            id,
            updates: { example: `canvas-color:${brandKitColors[0].id}` },
          }),
        );
      } else if (isEmpty) {
        dispatch(
          updateProp({ id, updates: { example: FALLBACK_DEFAULT_COLOR } }),
        );
      }
    }
  }, [colorPickerMode, currentExample, brandKitColors, id, dispatch]);

  const handleModeChange = (value: string) => {
    dispatch(
      updateProp({
        id,
        updates: {
          'x-canvas-color-picker': value as 'kit-only' | 'kit-and-free',
        },
      }),
    );
  };

  const handleFolderToggle = (folderId: string, checked: boolean) => {
    let updated: string[];
    if (folderId === ALL_FOLDERS_ID) {
      // Selecting "All" clears specific selections
      updated = checked ? [ALL_FOLDERS_ID] : [ALL_FOLDERS_ID];
    } else {
      const withoutAll = (colorFolders ?? []).filter(
        (f) => f !== ALL_FOLDERS_ID,
      );
      if (checked) {
        updated = [...withoutAll, folderId];
      } else {
        updated = withoutAll.filter((f) => f !== folderId);
        // If nothing left, revert to "All"
        if (updated.length === 0) updated = [ALL_FOLDERS_ID];
      }
    }
    dispatch(
      updateProp({ id, updates: { 'x-canvas-color-folders': updated } }),
    );
  };

  const handleExampleChange = (value: string) => {
    dispatch(updateProp({ id, updates: { example: value } }));
  };

  const showFolderSelector =
    availableColorFolders.length > 0 && colorPickerMode === 'kit-only';

  return (
    <Box>
      {/* Color Source and Color Folder side by side */}
      <Grid columns={showFolderSelector ? '2' : '1'} gap="2">
        <FormElement>
          <Label htmlFor={`prop-color-mode-${id}`}>Color Source</Label>
          <Select.Root
            size="1"
            value={colorPickerMode}
            onValueChange={handleModeChange}
          >
            <Select.Trigger
              id={`prop-color-mode-${id}`}
              variant="surface"
              style={{ width: '100%' }}
            />
            <Select.Content>
              {COLOR_PICKER_MODES.map((mode) => (
                <Select.Item key={mode.value} value={mode.value}>
                  {mode.label}
                </Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
        </FormElement>

        {showFolderSelector && (
          <FormElement>
            <Label>Color Folder</Label>
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <button
                  type="button"
                  className="rt-reset rt-SelectTrigger rt-r-size-1 rt-variant-surface"
                  style={{ width: '100%' }}
                >
                  <span className="rt-SelectTriggerInner">
                    <span style={{ pointerEvents: 'none' }}>
                      {folderDropdownLabel}
                    </span>
                  </span>
                  <svg
                    width="9"
                    height="9"
                    viewBox="0 0 9 9"
                    fill="currentColor"
                    xmlns="http://www.w3.org/2000/svg"
                    className="rt-SelectIcon"
                    aria-hidden="true"
                  >
                    <path d="M0.135232 3.15803C0.324102 2.95657 0.640521 2.94637 0.841971 3.13523L4.5 6.56464L8.158 3.13523C8.3595 2.94637 8.6759 2.95657 8.8648 3.15803C9.0536 3.35949 9.0434 3.67591 8.842 3.86477L4.84197 7.6148C4.64964 7.7951 4.35036 7.7951 4.15803 7.6148L0.158031 3.86477C-0.0434285 3.67591 -0.0536285 3.35949 0.135232 3.15803Z" />
                  </svg>
                </button>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content>
                <DropdownMenu.CheckboxItem
                  checked={isAllFoldersSelected}
                  onCheckedChange={(checked) =>
                    handleFolderToggle(ALL_FOLDERS_ID, checked === true)
                  }
                >
                  All
                </DropdownMenu.CheckboxItem>
                <DropdownMenu.Separator />
                {availableColorFolders.map((folder: any) => (
                  <DropdownMenu.CheckboxItem
                    key={folder.id}
                    checked={
                      !isAllFoldersSelected &&
                      (colorFolders?.includes(folder.id) ?? false)
                    }
                    onCheckedChange={(checked) =>
                      handleFolderToggle(folder.id, checked === true)
                    }
                  >
                    {folder.name}
                  </DropdownMenu.CheckboxItem>
                ))}
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          </FormElement>
        )}
      </Grid>

      {(colorPickerMode === 'kit-only' ||
        colorPickerMode === 'kit-and-free') && (
        <Box mt="2">
          <FormElement>
            <Label htmlFor={id}>
              {colorPickerMode === 'kit-only'
                ? 'Default Brand Kit color'
                : 'Default color'}
            </Label>
            <CanvasColorPickerField
              mode={colorPickerMode}
              value={typeof example === 'string' ? example : ''}
              onChange={handleExampleChange}
              allowedFolderIds={
                colorPickerMode === 'kit-and-free'
                  ? [ALL_FOLDERS_ID]
                  : colorFolders
              }
              attributes={{ id }}
            />
          </FormElement>
        </Box>
      )}
    </Box>
  );
}
