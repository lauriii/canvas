import type React from 'react';

import { useEffect, useState } from 'react';
import { useAppSelector } from '@/app/hooks';

import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { usePostPreviewMutation } from '@/services/preview';
import Viewport from '@/features/layout/preview/Viewport';

interface PreviewProps {}

const Preview: React.FC<PreviewProps> = () => {
  const layout = useAppSelector(selectLayout);

  const model = useAppSelector(selectModel);
  const [frameSrcDoc, setFrameSrcDoc] = useState('');
  const [postPreview, { isLoading }] = usePostPreviewMutation();

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        const result = await postPreview({ layout, model }).unwrap();
        // Handle the successful response here
        setFrameSrcDoc(result.html);
      } catch (err) {
        // Handle the error here
        console.error(err); // Do something with the error
      }
    };
    if (layout && model) {
      sendPreviewRequest().then(() => {});
    }
  }, [layout, model, postPreview]);

  return (
    <>
      <Viewport
        previewId="lg"
        height={768}
        width={1024}
        frameSrcDoc={frameSrcDoc}
        isLoading={isLoading}
      />
      <Viewport
        previewId="sm"
        height={768}
        width={400}
        frameSrcDoc={frameSrcDoc}
        isLoading={isLoading}
      />
    </>
  );
};
export default Preview;
