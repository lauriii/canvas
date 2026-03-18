import { useEffect, useRef, useState } from 'react';

import { getDrupal } from '@/utils/drupal-globals';

// This component largely exists to handle AJAX replacements of the Media
// Library fieldset in a manner that ensures all Media Library items are
// positioned in the React component tree to allow sorting.
const DrupalMediaLibraryFieldset = ({
  children,
}: {
  children: React.ReactNode;
}) => {
  const Drupal = getDrupal();
  const wrapRef = useRef<HTMLDivElement>(null);
  const [ajaxAdditions, setAjaxAdditions] = useState<any>(null);
  useEffect(() => {
    if (!wrapRef.current) {
      return;
    }
    const fieldsetWrapper = wrapRef.current;
    const handleAddition = (e: Event) => {
      const customEvent = e as CustomEvent;
      // Convert HTML string to DOM element
      const parser = new DOMParser();
      const doc = parser.parseFromString(
        `<div data-wrap>${customEvent.detail.data}</div>`,
        'text/html',
      );
      const element = doc.body.firstElementChild;
      if (element) {
        Drupal.HyperscriptifyUpdateStore([
          ...element.querySelectorAll('*[attributes]'),
        ]);
        setAjaxAdditions(Drupal.Hyperscriptify(element));
      }
    };
    fieldsetWrapper.addEventListener(
      'canvas:updateMediaWidget',
      handleAddition as EventListener,
    );

    return () => {
      if (fieldsetWrapper) {
        fieldsetWrapper.removeEventListener(
          'canvas:updateMediaWidget',
          handleAddition as EventListener,
        );
      }
    };
  }, [Drupal]);

  return (
    <div
      data-canvas-ml-ajax-target
      ref={wrapRef}
      style={{ display: 'contents' }}
    >
      {ajaxAdditions || children}
    </div>
  );
};

export default DrupalMediaLibraryFieldset;
