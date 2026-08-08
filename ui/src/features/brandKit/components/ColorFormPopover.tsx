import { useEffect, useMemo, useReducer } from 'react';
import { v4 as uuidv4 } from 'uuid';
import { Cross2Icon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import {
  Box,
  Button,
  Flex,
  IconButton,
  Text,
  TextField,
} from '@radix-ui/themes';

import ColorPicker from '@/components/ColorPicker';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import ErrorCard from '@/components/error/ErrorCard';
import {
  CREATE_COLOR_CACHE_KEY,
  UPDATE_COLOR_CACHE_KEY,
} from '@/features/brandKit/constants';
import { validateCssVariableClientSide } from '@/features/validation/validation';
import {
  useCreateColorMutation,
  useUpdateColorMutation,
} from '@/services/brandKit';
import {
  useGetFoldersQuery,
  useUpdateFolderMutation,
} from '@/services/componentAndLayout';
import { getColorAlpha, getColorHex } from '@/utils/brandKitColor';
import { normalizeError } from '@/utils/rtkQuery-error';

import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor, BrandKitColorValue } from '@/types/CodeComponent';

import styles from './ColorFormPopover.module.css';

interface ColorFormPopoverProps {
  /** Values from a rejected add, so reopening does not lose them. */
  rejectedColor?: BrandKitColor;
  /** Reports a color that saved but could not be filed into its folder. */
  onFolderAssignmentError?: (message: string) => void;
  /** Reports a rejected add, so the form can be reopened on its values. */
  onCreateRejected?: (color: BrandKitColor) => void;
  operation: 'add' | 'edit';
  color?: BrandKitColor;
  folderId?: string;
  anchorRef: React.RefObject<Measurable>;
  align?: 'start' | 'center' | 'end';
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

// Default color value for new colors (red, sRGB)
const DEFAULT_COLOR_VALUE: BrandKitColorValue = {
  colorSpace: 'srgb',
  components: [1, 0, 0],
  alpha: null,
  hex: '#ff0000',
};

interface FormState {
  colorName: string;
  variableName: string;
  colorValue: BrandKitColorValue;
  variableNameTouched: boolean;
  originalColor: { value: BrandKitColorValue } | null;
  colorNameError: string;
  variableNameError: string;
  colorValueError: string;
  isColorValueValid: boolean;
}

type FormAction =
  | { type: 'INIT_ADD'; color?: BrandKitColor }
  | { type: 'INIT_EDIT'; color: BrandKitColor }
  | { type: 'SET_COLOR_NAME'; value: string }
  | { type: 'SET_VARIABLE_NAME'; value: string }
  | { type: 'SET_COLOR_VALUE'; value: BrandKitColorValue }
  | { type: 'SET_COLOR_VALIDITY'; isValid: boolean }
  | { type: 'SHOW_VALIDATION_ERRORS' };

const INITIAL_FORM_STATE: FormState = {
  colorName: '',
  variableName: '',
  colorValue: DEFAULT_COLOR_VALUE,
  variableNameTouched: false,
  originalColor: null,
  colorNameError: '',
  variableNameError: '',
  colorValueError: '',
  isColorValueValid: true,
};

function generateVariableName(colorName: string): string {
  return colorName
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/[^a-z0-9-]/g, '');
}

function formReducer(state: FormState, action: FormAction): FormState {
  switch (action.type) {
    case 'INIT_ADD':
      if (!action.color) {
        return { ...INITIAL_FORM_STATE };
      }
      // Reopening after a rejected add: keep what the author typed.
      return {
        ...INITIAL_FORM_STATE,
        colorName: action.color.name,
        variableName: action.color.cssVariable.startsWith('--')
          ? action.color.cssVariable.slice(2)
          : action.color.cssVariable,
        colorValue: action.color.value,
        variableNameTouched: true,
      };

    case 'INIT_EDIT':
      return {
        ...INITIAL_FORM_STATE,
        colorName: action.color.name,
        variableName: action.color.cssVariable.startsWith('--')
          ? action.color.cssVariable.slice(2)
          : action.color.cssVariable,
        colorValue: action.color.value,
        variableNameTouched: true,
        originalColor: { value: action.color.value },
      };

    case 'SET_COLOR_NAME': {
      const colorName = action.value;
      const colorNameError = colorName.trim() ? '' : 'Color name is required.';
      // If the user has manually edited the variable name, leave it alone.
      if (state.variableNameTouched) {
        return { ...state, colorName, colorNameError };
      }
      const generated = generateVariableName(colorName);
      return {
        ...state,
        colorName,
        colorNameError,
        variableName: generated || state.variableName,
        variableNameError: generated
          ? validateCssVariableClientSide(generated)
          : state.variableNameError,
      };
    }

    case 'SET_VARIABLE_NAME':
      return {
        ...state,
        variableName: action.value,
        variableNameTouched: true,
        variableNameError: validateCssVariableClientSide(action.value),
      };

    case 'SET_COLOR_VALUE':
      return { ...state, colorValue: action.value };

    case 'SET_COLOR_VALIDITY':
      return {
        ...state,
        isColorValueValid: action.isValid,
        colorValueError: action.isValid ? '' : 'Must be a valid color.',
      };

    case 'SHOW_VALIDATION_ERRORS':
      return {
        ...state,
        colorNameError: state.colorName.trim() ? '' : 'Color name is required.',
        variableNameError: validateCssVariableClientSide(state.variableName),
        colorValueError: state.isColorValueValid
          ? ''
          : 'Must be a valid color.',
      };

    default:
      return state;
  }
}

const ColorFormPopover = ({
  operation,
  color,
  rejectedColor,
  onFolderAssignmentError,
  onCreateRejected,
  folderId,
  anchorRef,
  align = 'start',
  open,
  onOpenChange,
}: ColorFormPopoverProps) => {
  const [
    createColor,
    { isLoading: isCreating, isError: isCreateError, error: createError },
  ] = useCreateColorMutation({ fixedCacheKey: CREATE_COLOR_CACHE_KEY });
  // Only the trigger is taken from this mutation. Its state is shared with
  // every other form through the fixed cache key, so reading `isLoading` or
  // `error` here would make one form's in-flight edit spin another form's Save
  // button and surface a past rejection in an unrelated form. An edit closes
  // its form immediately, so the colors section owns reporting it.
  const [updateColor] = useUpdateColorMutation({
    fixedCacheKey: UPDATE_COLOR_CACHE_KEY,
  });
  const [updateFolder] = useUpdateFolderMutation();
  const { data: foldersData } = useGetFoldersQuery();

  // Form state managed via reducer
  const [formState, updateForm] = useReducer(formReducer, INITIAL_FORM_STATE);
  const {
    colorName,
    variableName,
    colorValue,
    originalColor,
    colorNameError,
    variableNameError,
    colorValueError,
    isColorValueValid,
  } = formState;

  // Neither mutation is reset when this popover closes. Both use a shared cache
  // key, and closing is exactly when their failures still need to be reachable:
  // an edit is reported by the colors section, and a rejected add reopens this
  // form on the values that were entered. The section clears the add state when
  // a new color is started.

  // Initialize form when opening
  useEffect(() => {
    if (open) {
      if (operation === 'edit' && color) {
        updateForm({ type: 'INIT_EDIT', color });
      } else {
        updateForm({ type: 'INIT_ADD', color: rejectedColor });
      }
    }
  }, [open, operation, color, rejectedColor]);

  const handleVariableNameChange = (value: string) => {
    updateForm({ type: 'SET_VARIABLE_NAME', value });
  };

  const handleColorNameChange = (value: string) => {
    updateForm({ type: 'SET_COLOR_NAME', value });
  };

  const handleColorPickerChange = (newValue: BrandKitColorValue) => {
    updateForm({ type: 'SET_COLOR_VALUE', value: newValue });
  };

  const handleColorValidityChange = (isValid: boolean) => {
    updateForm({ type: 'SET_COLOR_VALIDITY', isValid });
  };

  const handleSave = async () => {
    // Only creation can be double-submitted: an edit closes this popover
    // immediately, and `isUpdating` now covers edits started elsewhere too.
    if (isCreating) return;

    updateForm({ type: 'SHOW_VALIDATION_ERRORS' });

    const hasColorNameError = !colorName.trim();
    const hasVariableNameError = !!validateCssVariableClientSide(variableName);
    const hasColorValueError = !isColorValueValid;

    if (hasColorNameError || hasVariableNameError || hasColorValueError) {
      return;
    }

    const cssVariable = `--${variableName.startsWith('--') ? variableName.slice(2) : variableName}`;

    try {
      if (operation === 'add') {
        // Mint the id here so the row can be rendered under its final
        // identifier before the response arrives.
        const newColor: BrandKitColor = {
          id: uuidv4(),
          name: colorName,
          cssVariable,
          value: colorValue,
          weight: 0,
        };
        // The row appears immediately, so there is nothing to wait for. A
        // rejection reopens this form on these values.
        onOpenChange(false);
        try {
          await createColor(newColor).unwrap();
        } catch (createErr) {
          console.error('Failed to create color:', createErr);
          onCreateRejected?.(newColor);
          return;
        }

        if (folderId && foldersData?.folders) {
          const folder = foldersData.folders[folderId];
          if (folder) {
            try {
              await updateFolder({
                id: folderId,
                changes: {
                  name: folder.name,
                  weight: folder.weight,
                  items: [...folder.items, newColor.id],
                },
              }).unwrap();
            } catch (folderErr) {
              console.error('Failed to add color to folder:', folderErr);
              // This form has closed, so the colors section reports it.
              onFolderAssignmentError?.(
                'The color was created but could not be added to the folder. You can move it manually.',
              );
            }
          }
        }
        return;
      } else if (operation === 'edit' && color) {
        // The edit is applied to the cache before the request is sent, so there
        // is nothing here to wait for. Close now rather than holding the form
        // open on a spinner; a rejection is reported by the colors section,
        // which outlives this popover.
        onOpenChange(false);
        updateColor({ id: color.id, changes: { value: colorValue } });
        return;
      }

      onOpenChange(false);
    } catch (err) {
      console.error('Failed to save color:', err);
    }
  };

  const title =
    operation === 'add' ? 'Add color' : (color?.name ?? 'Edit color');
  const confirmText = operation === 'add' ? 'Add' : 'Save';

  const isConfirmDisabled = useMemo(() => {
    // Block save if color value is invalid (for both add and edit operations).
    if (!isColorValueValid) {
      return true;
    }
    if (operation === 'add') {
      return (
        !colorName.trim() ||
        !variableName.trim() ||
        !!colorNameError ||
        !!variableNameError
      );
    }
    return false;
  }, [
    colorName,
    variableName,
    colorNameError,
    variableNameError,
    isColorValueValid,
    operation,
  ]);

  // The create state is shared through a fixed cache key, so only the add form
  // may render it. An edit form reading it would surface an unrelated past
  // failure.
  const error =
    operation === 'add' && isCreateError && createError
      ? {
          title: 'Failed to create color',
          message: normalizeError(createError).message,
        }
      : null;

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
          align={align}
          sideOffset={4}
          className={styles.popoverContent}
          data-testid="canvas-color-form-popover"
          onOpenAutoFocus={(e) => {
            e.preventDefault();
          }}
          onFocusOutside={(e) => {
            e.preventDefault();
          }}
          onInteractOutside={(e) => {
            // When the DropdownMenu that triggered this popover closes, Radix
            // briefly moves focus to the menu content element before removing
            // it from the DOM. Ignore that event so the popover stays open.
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
            py="3"
            data-testid="canvas-color-form-header"
          >
            <Text size="2" weight="bold" data-testid="color-form-title">
              {title}
            </Text>
            <Popover.Close asChild>
              <IconButton
                variant="ghost"
                size="1"
                aria-label="Close"
                data-testid="color-form-close-button"
              >
                <Cross2Icon />
              </IconButton>
            </Popover.Close>
          </Flex>

          <form
            onSubmit={(e) => {
              e.preventDefault();
              handleSave();
            }}
            className={styles.form}
            data-testid="color-form"
          >
            <Flex direction="column" gap="3">
              {operation === 'add' && (
                <>
                  <Flex direction="column" gap="1" px="3">
                    <label htmlFor="colorName" className={styles.fieldLabel}>
                      Color name
                    </label>
                    <TextField.Root
                      id="colorName"
                      value={colorName}
                      onChange={(e) => handleColorNameChange(e.target.value)}
                      placeholder="Enter a name"
                      size="1"
                      data-testid="canvas-color-name-input"
                    />
                    {colorNameError && (
                      <Text size="1" color="red" data-testid="color-name-error">
                        {colorNameError}
                      </Text>
                    )}
                  </Flex>

                  <Flex direction="column" gap="1" px="3">
                    <label htmlFor="variableName" className={styles.fieldLabel}>
                      Variable name
                    </label>
                    <TextField.Root
                      id="variableName"
                      value={variableName}
                      onChange={(e) => handleVariableNameChange(e.target.value)}
                      placeholder="e.g., color-primary"
                      size="1"
                      data-testid="canvas-color-variable-input"
                    >
                      <TextField.Slot side="left">--</TextField.Slot>
                    </TextField.Root>
                    {variableNameError && (
                      <Text
                        size="1"
                        color="red"
                        data-testid="color-variable-error"
                      >
                        {variableNameError}
                      </Text>
                    )}
                  </Flex>

                  <ErrorBoundary
                    title="Color picker unavailable"
                    variant="card"
                  >
                    <ColorPicker
                      value={colorValue}
                      onChange={handleColorPickerChange}
                      onValidityChange={handleColorValidityChange}
                      data-testid="color-picker"
                    />
                  </ErrorBoundary>
                  {colorValueError && (
                    <Box px="3">
                      <Text
                        size="1"
                        color="red"
                        data-testid="color-value-error"
                      >
                        {colorValueError}
                      </Text>
                    </Box>
                  )}

                  {folderId && (
                    <input type="hidden" name="folder" value={folderId} />
                  )}
                </>
              )}

              {operation === 'edit' && (
                <>
                  {/* Current and Preview color display */}
                  <Flex
                    gap="3"
                    px="3"
                    className={styles.previewRow}
                    data-testid="color-preview-row"
                  >
                    <Flex
                      direction="column"
                      gap="1"
                      className={styles.previewColumn}
                      data-testid="color-current-preview"
                    >
                      <Text size="1" className={styles.previewLabel}>
                        Current
                      </Text>
                      <div
                        className={styles.previewSwatch}
                        style={{
                          backgroundColor: originalColor
                            ? `${getColorHex(originalColor.value)}${Math.round(
                                getColorAlpha(originalColor.value) * 255,
                              )
                                .toString(16)
                                .padStart(2, '0')}`
                            : 'transparent',
                        }}
                        aria-label="Current color"
                        data-testid="canvas-color-current-swatch"
                      />
                      <Text
                        size="1"
                        className={styles.previewHex}
                        data-testid="color-current-hex"
                      >
                        {originalColor?.value?.hex?.toUpperCase() ??
                          getColorHex(
                            originalColor?.value ?? DEFAULT_COLOR_VALUE,
                          ).toUpperCase()}
                      </Text>
                    </Flex>
                    <Flex
                      direction="column"
                      gap="1"
                      className={styles.previewColumn}
                      data-testid="color-new-preview"
                    >
                      <Text size="1" className={styles.previewLabel}>
                        Preview
                      </Text>
                      <div
                        className={styles.previewSwatch}
                        style={{
                          backgroundColor: `${getColorHex(colorValue)}${Math.round(
                            getColorAlpha(colorValue) * 255,
                          )
                            .toString(16)
                            .padStart(2, '0')}`,
                        }}
                        aria-label="Preview color"
                        data-testid="canvas-color-preview-swatch"
                      />
                      <Text
                        size="1"
                        className={styles.previewHex}
                        data-testid="color-preview-hex"
                      >
                        {getColorHex(colorValue).toUpperCase()}
                      </Text>
                    </Flex>
                  </Flex>

                  <ErrorBoundary
                    title="Color picker unavailable"
                    variant="card"
                  >
                    <ColorPicker
                      value={colorValue}
                      onChange={handleColorPickerChange}
                      onValidityChange={handleColorValidityChange}
                      data-testid="color-picker"
                    />
                  </ErrorBoundary>
                  {colorValueError && (
                    <Box px="3">
                      <Text
                        size="1"
                        color="red"
                        data-testid="color-value-error"
                      >
                        {colorValueError}
                      </Text>
                    </Box>
                  )}

                  <Box px="3">
                    <div
                      className={styles.infoBox}
                      data-testid="canvas-color-edit-info"
                    >
                      <svg
                        className={styles.infoIcon}
                        xmlns="http://www.w3.org/2000/svg"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                      >
                        <path
                          d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"
                          fill="currentColor"
                        />
                      </svg>
                      <Text size="1" className={styles.infoText}>
                        Changing this color will affect 0 components across your
                        design.
                      </Text>
                    </div>
                  </Box>
                </>
              )}
            </Flex>

            {error && (
              <Box px="3" mt="3" data-testid="color-error-card">
                <ErrorCard title={error.title} error={error.message} />
              </Box>
            )}

            {/* Footer with action buttons */}
            <Flex
              gap="2"
              justify="end"
              px="3"
              pb="3"
              pt="3"
              className={styles.footer}
            >
              <Popover.Close asChild>
                <Button
                  variant="outline"
                  size="1"
                  data-testid="canvas-color-cancel-button"
                >
                  Cancel
                </Button>
              </Popover.Close>
              <Button
                type="submit"
                disabled={isConfirmDisabled}
                loading={isCreating}
                size="1"
                color="blue"
                data-testid="canvas-color-save-button"
              >
                {confirmText}
              </Button>
            </Flex>
          </form>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
};

export default ColorFormPopover;
