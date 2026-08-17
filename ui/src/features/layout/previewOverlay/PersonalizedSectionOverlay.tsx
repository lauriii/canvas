import clsx from 'clsx';
import { unionCanvasRects } from '@drupal-canvas/preview-geometry';

import { useAppSelector } from '@/app/hooks';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  DEFAULT_VARIANT_ID,
  findSwitchNodes,
  getCaseVariantId,
  getPreviewedVariant,
  getSwitchCases,
  humanizeVariantId,
} from '@/features/layout/personalizationUtils';
import { usePreviewDom } from '@/features/layout/preview/PreviewDomContext';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import {
  selectEditorViewPortScale,
  selectPreviewedVariants,
} from '@/features/ui/uiSlice';

import type React from 'react';
import type { CanvasRect } from '@drupal-canvas/preview-geometry';

import styles from './PreviewOverlay.module.css';

interface PersonalizedSection {
  switchUuid: string;
  label: string;
  rect: CanvasRect;
}

/**
 * Marks the boundary of every personalized section whose previewed variant is
 * not the default: one rectangle per switch spanning all of the active case's
 * elements, with a tab naming the variant. The rectangle is drawn in the
 * overlay layer above the iframe, so unlike a marker inside the preview it is
 * never clipped by the content's own overflow or stacking contexts and never
 * affects the content's layout.
 */
const PersonalizedSectionOverlay: React.FC = () => {
  const componentsMap = usePreviewDom()?.componentsMap;
  const { geometryMap } = usePreviewGeometry();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);

  if (!componentsMap) {
    return null;
  }

  const sections: PersonalizedSection[] = findSwitchNodes(layout).flatMap(
    (switchNode) => {
      const activeVariantId = getPreviewedVariant(
        previewedVariants,
        switchNode.uuid,
      );
      if (activeVariantId === DEFAULT_VARIANT_ID) {
        return [];
      }
      const activeCase = getSwitchCases(switchNode).find(
        (caseNode) => getCaseVariantId(model, caseNode) === activeVariantId,
      );
      // A geometry entry confirms the case is rendered and measured. The
      // geometry map also ties this overlay to the preview observer: its
      // context updates on preview scrolls, resizes, and mutations are what
      // re-render this component with fresh element rectangles.
      if (!activeCase || !geometryMap.component[activeCase.uuid]) {
        return [];
      }
      // One rectangle spans every element of the case, measured in the same
      // iframe viewport coordinates as the other overlay rectangles.
      const rect = unionCanvasRects(
        (componentsMap[activeCase.uuid]?.elements ?? []).map((element) =>
          element.getBoundingClientRect(),
        ),
      );
      if (!rect) {
        return [];
      }
      return [
        {
          switchUuid: switchNode.uuid,
          label: humanizeVariantId(activeVariantId),
          rect,
        },
      ];
    },
  );

  return (
    <>
      {sections.map(({ switchUuid, label, rect }) => (
        <div
          key={switchUuid}
          className={clsx(
            'personalizedSectionOverlay',
            styles.personalizedSection,
          )}
          data-testid="canvas-personalized-section"
          style={{
            top: rect.top * editorViewPortScale,
            left: rect.left * editorViewPortScale,
            width: rect.width * editorViewPortScale,
            height: rect.height * editorViewPortScale,
          }}
        >
          <div
            className={styles.personalizedSectionLabel}
            data-testid="canvas-personalized-section-label"
          >
            {label}
          </div>
        </div>
      ))}
    </>
  );
};

export default PersonalizedSectionOverlay;
