import { forwardRef, useRef } from 'react';

import CKEditorHost from '@/components/form/components/CKEditorHost';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { a2p } from '@/local_packages/utils.js';

import type { FormatType } from '@drupal-canvas/types';
import type { Attributes } from '@/types/DrupalAttribute';

interface DrupalFormattedTextAreaProps {
  attributes?: Attributes;
  format: {
    editorSettings: NonNullable<FormatType['editorSettings']>;
  };
}

/**
 * The escape-hatch (server-built form) CKEditor 5 integration: mounts the
 * shared CKEditor host and syncs its output into the hidden textarea that
 * Drupal's form pipeline (and Redux) reads.
 *
 * @see ui/src/components/form/components/CKEditorHost.tsx
 */
const DrupalFormattedTextArea = forwardRef<
  HTMLTextAreaElement,
  DrupalFormattedTextAreaProps
>(function DrupalFormattedTextArea({ attributes = {}, format }, ref) {
  const dataRef = useRef<string | null>(null);
  const fieldContext = useFieldContext();

  return (
    <>
      <CKEditorHost
        editorSettings={format.editorSettings}
        initialValue={(dataRef?.current || attributes.value?.toString()) ?? ''}
        minRows={
          attributes.rows && Number(attributes.rows)
            ? Number(attributes.rows)
            : undefined
        }
        onChange={(data) => {
          // Get the editor contents and update the textarea that is synced
          // with Redux.
          dataRef.current = data;
          const textareaElement = ref && 'current' in ref ? ref.current : null;
          if (textareaElement) {
            textareaElement.value = data;
            textareaElement.innerHTML = data;
            fieldContext?.triggerChange(data, textareaElement);
          }
        }}
      />
      {/* This is a hidden textarea that is synced with Redux. */}
      <textarea
        {...a2p(attributes, {}, { skipAttributes: ['value', 'onChange'] })}
        ref={ref}
        style={{ display: 'none' }}
        defaultValue={attributes.value?.toString() ?? ''}
      ></textarea>
    </>
  );
});

export default DrupalFormattedTextArea;
