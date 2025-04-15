import { a2p } from '@/local_packages/utils.js';
import TextArea from '@/components/form/components/TextArea';
import InputBehaviors from '@/components/form/inputBehaviors';
import { useRef, useEffect, useState } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

const { Drupal } = window as any;
const DrupalTextArea = ({
  attributes = {},
  wrapperAttributes = {},
}: {
  attributes?: Attributes;
  wrapperAttributes?: Attributes;
}) => {
  // This state exists solely to be a useEffect dependency so the effect is
  // triggered once an editor initializes.
  const [theEditorId, setTheEditorId] = useState<string | undefined>(undefined);
  const ref = useRef<HTMLTextAreaElement | null>(null);

  useEffect(() => {
    // Create the observer variable here so it can be accessed by clean up.
    let observer: boolean | MutationObserver = false;
    if (!ref?.current) {
      return;
    }
    setTimeout(() => {
      if (!ref?.current) {
        return;
      }

      /**
       * Conveys CKEditor5 changes to the inputBehaviors change callback.
       *
       * @param {string} editorId
       *   The editor instance id.
       */
      const integrateCKEditor5 = (editorId: string) => {
        const theEditor = Drupal.CKEditor5Instances.get(editorId);
        if (!theEditor) {
          console.log(
            `no editor found for ${editorId}`,
            Drupal.CKEditor5Instances,
          );
          return;
        }

        // Listen to changes made within the editor.
        theEditor.model.document.on('change:data', () => {
          if (!ref?.current) {
            return;
          }

          // Create a synthetic change
          const event = new Event('change');
          ref.current.value = theEditor.getData();
          Object.defineProperty(event, 'target', {
            writable: false,
            value: ref.current,
          });
          if (typeof attributes?.onChange === 'function') {
            attributes.onChange(event);
          }
        });
      };

      if (ref.current.hasAttribute('data-ckeditor5-id')) {
        const editorId = ref.current.getAttribute('data-ckeditor5-id');
        if (editorId) {
          integrateCKEditor5(editorId);
        }
      } else {
        // There are scenarios where this component has rendered before the
        // editor has initialized, so this observer is added to identify
        // post-render initializations.
        observer = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            if (
              mutation.type === 'attributes' &&
              mutation.attributeName === 'data-ckeditor5-id'
            ) {
              const newEditorId = (mutation.target as HTMLElement).getAttribute(
                'data-ckeditor5-id',
              );
              if (newEditorId) {
                // Set the editorID state to trigger a new render, which will
                // identify a CKEditor5 ready input and call
                // integrateCKEditor5 ().
                setTheEditorId(newEditorId);
              }
            }
          });
        });

        observer.observe(ref.current, {
          attributes: true,
          attributeFilter: ['data-ckeditor5-id'],
        });
      }
    });
    return () => {
      if (observer instanceof MutationObserver) {
        observer.disconnect();
      }
    };
  }, [attributes, theEditorId]);

  return (
    <div {...a2p(wrapperAttributes)}>
      <TextArea
        value={attributes.value?.toString() ?? ''}
        attributes={a2p(attributes, {}, { skipAttributes: ['value'] })}
        ref={ref}
      />
    </div>
  );
};

export default InputBehaviors(DrupalTextArea);
