import type React from 'react';
import { useRef } from 'react';
import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  selectUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  selectUpdateComponentLoadingState,
  usePostPreviewMutation,
} from '@/services/preview';
import Viewport from '@/features/layout/preview/Viewport';
import ComponentHtmlMapProvider from '@/features/layout/preview/DataToHtmlMapContext';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { selectPreviewHtml } from '@/features/pagePreview/previewSlice';
import { contentApi } from '@/services/content';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import { getXbSettings } from '@/utils/drupal-globals';

interface PreviewProps {}

const xbSettings = getXbSettings();
const labelFormKey = `${xbSettings.entityTypeKeys.label}[0][value]`;

const Preview: React.FC<PreviewProps> = () => {
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const selectedComponentId = selectedComponent || 'noop';
  const entity_form_fields = useAppSelector(selectPageData);
  const [postPreview, { isLoading: isFetching }] = usePostPreviewMutation({
    fixedCacheKey: 'canvasPreview',
  });
  const isPatching = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponentId),
  );
  const dispatch = useAppDispatch();
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const { showBoundary } = useErrorBoundary();
  const previousEntityFormTitle = useRef(entity_form_fields[labelFormKey]);
  // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
  const previousEntityFormAlias = useRef(entity_form_fields['path[0][alias]']);

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        await postPreview({ layout, model, entity_form_fields }).unwrap();
      } catch (err) {
        showBoundary(err);
      }
    };
    if (updatePreview) {
      // Specifically when updating the Title or Alias, the page list used in the navigator must be re-fetched so that
      // it can display those updated values.
      let invalidatePageList = false;
      if (
        entity_form_fields[labelFormKey] !== previousEntityFormTitle.current ||
        // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
        entity_form_fields['path[0][alias]'] !== previousEntityFormAlias.current
      ) {
        invalidatePageList = true;
        previousEntityFormTitle.current = entity_form_fields[labelFormKey];
        // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
        previousEntityFormAlias.current = entity_form_fields['path[0][alias]'];
      }

      sendPreviewRequest().then(() => {
        if (invalidatePageList) {
          dispatch(
            contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
          );
        }
      });
    }
  }, [
    layout,
    model,
    postPreview,
    entity_form_fields,
    updatePreview,
    showBoundary,
    dispatch,
  ]);

  return (
    <ComponentHtmlMapProvider>
      <Viewport
        frameSrcDoc={frameSrcDoc}
        isFetching={isFetching || isPatching}
      />
    </ComponentHtmlMapProvider>
  );
};
export default Preview;
