import { useEffect } from 'react';

import { useAppSelector } from '@/app/hooks';
import { buildColorStyles } from '@/features/brandKit/colorCss';
import { selectBrandKit } from '@/features/code-editor/codeEditorSlice';

import type { BrandKit } from '@/types/CodeComponent';

const STYLE_ID = 'canvas-brand-kit-colors';

/**
 * Mirrors Brand kit colors into the page preview as CSS custom properties.
 *
 * The preview receives the Brand kit's colors as a server-rendered stylesheet
 * attached by `LibraryHooks::libraryInfoBuild()`, which it only re-requests when
 * the whole editor reloads. Without this the preview keeps rendering the
 * previous palette after a color is changed, however long the editor stays
 * open. Injecting the current colors keeps it in step with the edit.
 *
 * The injected block is appended to the preview's head, so it comes after the
 * server stylesheet and wins on equal specificity, and it is replaced by server
 * truth on the next full load.
 */
export const usePreviewBrandKitColors = () => {
  const colors = useAppSelector((state) =>
    selectBrandKit<BrandKit['colors']>(state, 'colors'),
  );

  useEffect(() => {
    const css = buildColorStyles(colors ?? []);
    const applyTo = (frame: HTMLIFrameElement) => {
      const previewDocument = frame.contentDocument;
      if (!previewDocument?.head) {
        return;
      }
      const existing = previewDocument.getElementById(STYLE_ID);
      const style = existing ?? previewDocument.createElement('style');
      style.id = STYLE_ID;
      style.textContent = css;
      // Re-append even when it already exists, so the block stays last in the
      // head if the preview added stylesheets after it.
      previewDocument.head.append(style);
    };

    const frames = Array.from(
      document.querySelectorAll<HTMLIFrameElement>(
        'iframe[data-canvas-preview]',
      ),
    );
    // Swapping in a new srcdoc replaces the document and drops the injected
    // block, so it has to be re-applied whenever a preview finishes loading.
    const handlers = frames.map((frame) => {
      const handler = () => applyTo(frame);
      frame.addEventListener('load', handler);
      applyTo(frame);
      return { frame, handler };
    });

    return () => {
      handlers.forEach(({ frame, handler }) =>
        frame.removeEventListener('load', handler),
      );
    };
  }, [colors]);
};
