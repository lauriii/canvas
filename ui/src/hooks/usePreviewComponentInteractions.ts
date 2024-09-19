import { useCallback, useEffect, useRef, useState } from 'react';
import {
  setHoveredComponent,
  setIsContextMenuOpen,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
import type { ViewPortSize } from '@/features/layout/preview/Viewport';

/**
 * This hook initializes the event listeners to allow for clicking, right-clicking, and hovering on components in the
 * preview iFrame.
 */
function useIframeKeyHandlers(
  iframe: HTMLIFrameElement | null,
  size: ViewPortSize,
) {
  const iframeDocumentRef = useRef<Document | null>(null);
  const dispatch = useAppDispatch();
  const sizeRef = useRef(size);
  const [mouseEventPosition, setMouseEventPosition] = useState<{
    pageX: number;
    pageY: number;
  }>({ pageX: 0, pageY: 0 });

  useEffect(() => {
    sizeRef.current = size;
  }, [size]);

  const handleMouseOver = useCallback(
    (event: MouseEvent) => {
      event.stopPropagation();
      if (event.currentTarget) {
        const target = event.currentTarget as HTMLElement;
        if (!target.dataset.xbUuid) {
          return;
        }
        dispatch(setHoveredComponent(target.dataset.xbUuid));
        dispatch(setIsContextMenuOpen(undefined));
      }
    },
    [dispatch],
  );

  const handleRightClick = useCallback(
    (event: MouseEvent) => {
      event.stopPropagation();
      event.preventDefault();
      if (event.currentTarget) {
        const target = event.currentTarget as HTMLElement;
        if (!target.dataset.xbUuid) {
          return;
        }
        setMouseEventPosition({ pageX: event.pageX, pageY: event.pageY });
        dispatch(setHoveredComponent(target.dataset.xbUuid));
        dispatch(setIsContextMenuOpen(sizeRef?.current));
      }
    },
    [dispatch],
  );

  const handleClick = useCallback(
    (event: MouseEvent) => {
      // In safari middle mouse click fires the click event with button === 1
      if (event.button === 1) {
        return;
      }
      event.stopPropagation();
      if (event.currentTarget) {
        const target = event.currentTarget as HTMLElement;
        if (!target.dataset.xbUuid) {
          return;
        }
        dispatch(setSelectedComponent(target.dataset.xbUuid));
      }
    },
    [dispatch],
  );

  const init = useCallback(() => {
    if (!iframe) {
      return;
    }

    iframeDocumentRef.current = iframe.contentDocument;

    if (!iframeDocumentRef.current) {
      return;
    }
    const sortableLists =
      iframeDocumentRef.current.querySelectorAll<HTMLElement>(
        '.xb--sortable-list',
      );

    sortableLists.forEach((sortableList) => {
      const draggableItems =
        sortableList.querySelectorAll<HTMLElement>('.xb--sortable-item');
      draggableItems.forEach((item) => {
        item.addEventListener('mouseover', handleMouseOver);
        item.addEventListener('contextmenu', handleRightClick);
        item.addEventListener('click', handleClick);
      });
    });
  }, [iframe, handleMouseOver, handleRightClick, handleClick]);

  useEffect(() => {
    if (iframe) {
      init();
    }

    return () => {
      if (!iframe || !iframeDocumentRef.current) {
        return;
      }

      const sortableLists =
        iframeDocumentRef.current.querySelectorAll<HTMLElement>(
          '.xb--sortable-list',
        );

      sortableLists.forEach((sortableList) => {
        const draggableItems =
          sortableList.querySelectorAll<HTMLElement>('.xb--sortable-item');
        draggableItems.forEach((item) => {
          item.removeEventListener('mouseover', handleMouseOver);
          item.removeEventListener('contextmenu', handleRightClick);
          item.removeEventListener('click', handleClick);
        });
      });
    };
  }, [iframe, init, handleMouseOver, handleRightClick, handleClick]);

  return mouseEventPosition;
}

export default useIframeKeyHandlers;
