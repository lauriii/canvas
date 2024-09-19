import type React from 'react';

import { useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppSelector } from '@/app/hooks';

import {
  selectLayout,
  selectModel,
  selectInitialized,
} from '@/features/layout/layoutModelSlice';
import { usePostPreviewMutation } from '@/services/preview';
import Viewport from '@/features/layout/preview/Viewport';

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
  const initialized = useAppSelector(selectInitialized);
  const model = useAppSelector(selectModel);
  const [frameSrcDoc, setFrameSrcDoc] = useState('');
  const [postPreview, { isLoading: isFetching }] = usePostPreviewMutation();
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        const result = await postPreview({ layout, model }).unwrap();
        // Handle the successful response here
        setFrameSrcDoc(result.html);
      } catch (err) {
        showBoundary(err);
      }
    };
    if (initialized === true) {
      sendPreviewRequest().then(() => {});
    }
  }, [layout, model, postPreview, initialized, showBoundary]);

  return (
    <>
      {Object.keys(previewSizes).map((size) => {
        const key = size as PreviewSizeKey;
        return (
          <Viewport
            key={key}
            size={key}
            name={previewSizes[key].name}
            height={previewSizes[key].height}
            width={previewSizes[key].width}
            frameSrcDoc={frameSrcDoc}
            isFetching={isFetching}
          />
        );
      })}
    </>
  );
};
export default Preview;
