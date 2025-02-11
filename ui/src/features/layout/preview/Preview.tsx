import type React from 'react';
import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  selectLayoutInitialized,
} from '@/features/layout/layoutModelSlice';
import {
  selectUpdateComponentLoadingState,
  usePostPreviewMutation,
} from '@/services/preview';
import Viewport from '@/features/layout/preview/Viewport';
import ComponentHtmlMapProvider from '@/features/layout/preview/DataToHtmlMapContext';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { selectPreviewHtml } from '@/features/pagePreview/previewSlice';
import { useParams } from 'react-router-dom';

interface PreviewProps {}

const previewSizes = {
  lg: {
    height: 768,
    width: 1024,
    name: 'Desktop',
  },
  sm: {
    height: 768,
    width: 400,
    name: 'Mobile',
  },
};
type PreviewSizeKey = keyof typeof previewSizes;

const Preview: React.FC<PreviewProps> = () => {
  const layout = useAppSelector(selectLayout);
  const initialized = useAppSelector(selectLayoutInitialized);
  const model = useAppSelector(selectModel);
  const { componentId: selectedComponent } = useParams();
  const selectedComponentId = selectedComponent || 'noop';
  const entity_form_fields = useAppSelector(selectPageData);
  const [postPreview, { isLoading: isFetching }] = usePostPreviewMutation();
  const isPatching = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponentId),
  );
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        await postPreview({ layout, model, entity_form_fields }).unwrap();
      } catch (err) {
        showBoundary(err);
      }
    };
    if (initialized) {
      sendPreviewRequest();
    }
  }, [
    layout,
    model,
    postPreview,
    entity_form_fields,
    initialized,
    showBoundary,
  ]);

  return (
    <>
      {Object.keys(previewSizes).map((size) => {
        const key = size as PreviewSizeKey;
        return (
          <ComponentHtmlMapProvider key={key}>
            <Viewport
              size={key}
              name={previewSizes[key].name}
              height={previewSizes[key].height}
              width={previewSizes[key].width}
              frameSrcDoc={frameSrcDoc}
              isFetching={isFetching || isPatching}
            />
          </ComponentHtmlMapProvider>
        );
      })}
    </>
  );
};
export default Preview;
