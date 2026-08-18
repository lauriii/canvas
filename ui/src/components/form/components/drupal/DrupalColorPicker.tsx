import CanvasColorPickerField from '@/components/form/components/drupal/CanvasColorPickerField';
import { ALL_FOLDERS_ID } from '@/utils/colorConstants';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalColorPicker.module.css';

const DrupalColorPicker = ({
  attributes = {},
}: {
  attributes?: Attributes;
}) => {
  const mode = String(attributes['data-canvas-color-picker'] || 'kit-only');
  const value = String(
    attributes['data-canvas-color-value'] || attributes.value || '',
  );

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
