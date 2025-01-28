import type { Dispatch, SetStateAction, Ref } from 'react';
import { useCallback } from 'react';
import { useEffect, useImperativeHandle, useRef, useState } from 'react';
import { forwardRef } from 'react';
import styles from '@/features/layout/preview/Preview.module.css';
import clsx from 'clsx';
import type { ViewPortSize } from '@/features/layout/preview/Viewport';
import { useAppSelector } from '@/app/hooks';
import { selectDragging } from '@/features/ui/uiSlice';

interface IFrameSwapperProps {
  srcDocument: string;
  size: ViewPortSize;
  setIsReloading: Dispatch<SetStateAction<boolean>>;
  interactive: boolean;
}

const IFrameSwapper = forwardRef<HTMLIFrameElement, IFrameSwapperProps>(
  (
    { size, srcDocument, setIsReloading, interactive },
    ref: Ref<HTMLIFrameElement>,
  ) => {
    const iFrameRefs = useRef<(HTMLIFrameElement | null)[]>([]);
    const whichActiveRef = useRef(0);
    const [whichActive, setWhichActive] = useState(0);
    const { isDragging } = useAppSelector(selectDragging);

    useImperativeHandle(ref, () => {
      if (!iFrameRefs.current[0] || !iFrameRefs.current[1]) {
        throw new Error(
          'Error passing iFrame ref to parent: One of the iframe refs is null',
        );
      }
      return iFrameRefs.current[whichActive] as HTMLIFrameElement;
    });

    const getIFrames = useCallback(() => {
      const activeIFrame = iFrameRefs.current[whichActiveRef.current];
      const inactiveIFrame = iFrameRefs.current[whichActiveRef.current ? 0 : 1];

      if (!activeIFrame || !inactiveIFrame) {
        throw new Error(
          'Error initializing iFrameSwapper. One of the iframe refs is null',
        );
      }
      return { activeIFrame, inactiveIFrame };
    }, []);

    const swapIFrames = useCallback(() => {
      setWhichActive((current) => (current ? 0 : 1));
      setTimeout(() => {
        setIsReloading(false);
      }, 0);
    }, [setIsReloading]);

    useEffect(() => {
      whichActiveRef.current = whichActive;
    }, [whichActive]);

    useEffect(() => {
      // Important to change a state ensure parent re-renders (and re-calls hooks) once the iframe is swapped.
      setIsReloading(true);
      const { activeIFrame, inactiveIFrame } = getIFrames();

      // Initialize active iframe if not already initialized
      if (activeIFrame && !activeIFrame.srcdoc) {
        activeIFrame.srcdoc = srcDocument;
      }

      // Immediately set the currently active iframe to not initialized
      if (activeIFrame) {
        activeIFrame.dataset.testXbContentInitialized = 'false';
      }

      // Set up load event listener and update content for inactive iframe. Once loaded, it will be swapped in.
      if (inactiveIFrame) {
        inactiveIFrame.removeEventListener('load', swapIFrames);
        inactiveIFrame.addEventListener('load', swapIFrames);
        inactiveIFrame.srcdoc = srcDocument;
      }

      return () => {
        inactiveIFrame?.removeEventListener('load', swapIFrames);
        activeIFrame?.removeEventListener('load', swapIFrames);
      };
    }, [srcDocument, setIsReloading, swapIFrames, getIFrames]);

    const commonIFrameProps = {
      className: clsx(styles.preview, {
        [styles.interactable]: isDragging || interactive,
      }),
      'data-xb-preview': size,
      'data-test-xb-content-initialized': 'false',
    };

    return (
      <>
        <iframe
          // Set the tab index to 0 when the iframe is interactive, -1 when it is not.
          tabIndex={!interactive || whichActive === 1 ? -1 : 0}
          ref={(el) => (iFrameRefs.current[0] = el)}
          data-xb-swap-active={whichActive === 0 ? 'true' : 'false'}
          title={whichActive === 0 ? 'Preview' : 'Inactive preview'}
          data-xb-iframe="A"
          scrolling="no"
          {...commonIFrameProps}
        ></iframe>
        <iframe
          tabIndex={!interactive || whichActive === 0 ? -1 : 0}
          ref={(el) => (iFrameRefs.current[1] = el)}
          data-xb-swap-active={whichActive === 1 ? 'true' : 'false'}
          title={whichActive === 1 ? 'Preview' : 'Inactive preview'}
          data-xb-iframe="B"
          scrolling="no"
          {...commonIFrameProps}
        ></iframe>
      </>
    );
  },
);
export default IFrameSwapper;
