import { useEffect } from 'react';

import { useAppDispatch } from '@/app/hooks';
import CanvasColorPickerField from '@/components/form/components/drupal/CanvasColorPickerField';
import { useSafeFormContext } from '@/components/form/contexts/FormContext';
import { dispatchFieldValue } from '@/components/form/react-hook-form/utils';
import { ALL_FOLDERS_ID } from '@/utils/colorConstants';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalColorPicker.module.css';

const DrupalColorPicker = ({
  attributes = {},
}: {
  attributes?: Attributes;
}) => {
  const dispatch = useAppDispatch();
  const formContext = useSafeFormContext();
  const mode = String(attributes['data-canvas-color-picker'] || 'kit-only');
  const value = String(
    attributes['data-canvas-color-value'] || attributes.value || '',
  );
  const fieldName = String(attributes.name || '');

  // On mount, push the real stored value into the formState slice, as there's
  // no actual <input> element to do this automatically.
  useEffect(() => {
    if (!formContext?.formId || !fieldName || !value) return;
    setTimeout(() => {
      dispatchFieldValue(dispatch, formContext.formId as any, fieldName, value);
    });
  }, [dispatch, formContext?.formId, fieldName, value]);

  // Parse the allowed folder IDs from the data attribute (JSON-encoded array or empty).
  // ALL_FOLDERS_ID sentinel means "no filter – show all".
  let allowedFolderIds: string[] | undefined;
  const foldersAttr = attributes['data-canvas-color-folders'];
  if (foldersAttr) {
    try {
      const parsed = JSON.parse(String(foldersAttr));
      if (
        Array.isArray(parsed) &&
        parsed.length > 0 &&
        !parsed.includes(ALL_FOLDERS_ID)
      ) {
        allowedFolderIds = parsed.map(String);
      }
    } catch {
      // ignore malformed attribute
    }
  }

  return (
    <div
      className={styles.wrap}
      data-field-name={attributes.name}
      data-prop-name={attributes['data-canvas-color-label']}
    >
      <CanvasColorPickerField
        value={value}
        mode={mode as 'kit-only' | 'kit-and-free'}
        allowedFolderIds={allowedFolderIds}
        attributes={attributes}
      />
    </div>
  );
};

export default DrupalColorPicker;
