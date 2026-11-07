import { forwardRef, useRef } from 'react';
import { CKEditor } from '@ckeditor/ckeditor5-react';

import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { a2p } from '@/local_packages/utils.js';
import { processConfig, selectPlugins } from '@/utils/ckeditor5';

import type { Editor } from '@ckeditor/ckeditor5-core';
import type { Attributes } from '@/types/DrupalAttribute';
import type { CKEditor5ConfigObject } from '@/utils/ckeditor5';

interface EditorInstance extends Editor {
  getData: () => string;
}

interface DrupalFormattedTextAreaProps {
  attributes?: Attributes;
  format: {
    editorSettings: {
      toolbar: any[];
      plugins: string[];
      config: CKEditor5ConfigObject;
      language: Record<string, any>;
    };
  };
}

const DrupalFormattedTextArea = forwardRef<
  HTMLTextAreaElement,
  DrupalFormattedTextAreaProps
>(function DrupalFormattedTextArea({ attributes = {}, format }, ref) {
  const editorRef = useRef<EditorInstance | null>(null);
  const dataRef = useRef<string | null>(null);
  const fieldContext = useFieldContext();

  const { toolbar, plugins, config, language } = format.editorSettings;
  const extraPlugins = selectPlugins(plugins);
  const pluginConfig = processConfig(config) || {};
  const editorConfig = {
    extraPlugins,
    toolbar,
    ...pluginConfig,
    language: { ...(pluginConfig.language || {}), ...language },
    initialData: (dataRef?.current || attributes.value?.toString()) ?? '',
  };
  const { editorClassic } = window.CKEditor5;
  const { ClassicEditor } = editorClassic;

  return (
    <>
      <CKEditor
        editor={ClassicEditor}
        config={editorConfig}
        onReady={(editor) => {
          editorRef.current = editor as EditorInstance;

          // If the rows attribute is present, let that inform the editor's
          // minimum height
          if (attributes.rows && Number(attributes.rows)) {
            const editable = editor.ui.view.editable.element;
            if (editable) {
              const editorElement = editable.closest('.ck-editor');
              if (editorElement instanceof HTMLElement) {
                editorElement.style.setProperty(
                  '--ck-min-height',
                  `${Number(attributes.rows) * 20}px`,
                );
              }
            }
          }
        }}
        onChange={function () {
          if (!editorRef.current) {
            return;
          }
          // Get the editor contents and update the textarea that is synced with
          // Redux.
          const data = editorRef.current.getData();
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
