import type React from 'react';
import { useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  selectLayoutInitialized,
} from '@/features/layout/layoutModelSlice';
import { usePostPreviewMutation } from '@/services/preview';
import Viewport from '@/features/layout/preview/Viewport';
import ComponentHtmlMapProvider from '@/features/layout/preview/DataToHtmlMapContext';
import { selectPageData } from '@/features/pageData/pageDataSlice';

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
  const entity_form_fields = useAppSelector(selectPageData);
  const [frameSrcDoc, setFrameSrcDoc] = useState('');
  const [postPreview, { isLoading: isFetching }] = usePostPreviewMutation();
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        const result = await postPreview({
          layout,
          model,
          entity_form_fields,
        }).unwrap();
        // Handle the successful response here
        setFrameSrcDoc(result.html);
      } catch (err) {
        showBoundary(err);
      }
    };
    if (initialized) {
      sendPreviewRequest().then(() => {});
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
              isFetching={isFetching}
            />
          </ComponentHtmlMapProvider>
        );
      })}
    </>
  );
};
export default Preview;
