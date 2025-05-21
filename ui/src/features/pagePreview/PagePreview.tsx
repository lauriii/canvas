import styles from './PagePreview.module.css';
import { usePostPreviewMutation } from '@/services/preview';
import { useAppSelector } from '@/app/hooks';
import {
  selectUpdatePreview,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { useCallback, useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { selectPreviewHtml } from '@/features/pagePreview/previewSlice';
import { viewportSizes } from '@/types/Preview';
import { useParams } from 'react-router';

const PagePreview = () => {
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const entity_form_fields = useAppSelector(selectPageData);
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const [postPreview] = usePostPreviewMutation();
  const { showBoundary } = useErrorBoundary();
  const [widthVal, setWidthVal] = useState('100%');
  const { width } = useParams();
  const [linkIntercepted, setLinkIntercepted] = useState('');

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        await postPreview({
          layout,
          model,
          entity_form_fields,
        }).unwrap();
      } catch (err) {
        showBoundary(err);
      }
    };
    if (updatePreview) {
      sendPreviewRequest().then(() => {});
    }
  }, [
    layout,
    model,
    postPreview,
    entity_form_fields,
    updatePreview,
    showBoundary,
  ]);

  useEffect(() => {
    if (width === 'full') {
      setWidthVal('100%');
    } else {
      viewportSizes.find((vs) => {
        if (width === vs.id) {
          setWidthVal(`${vs.width}px`);
          return true;
        }
      });
    }
  }, [width]);

  useEffect(() => {
    function handlePreviewLinkClick(event: MessageEvent) {
      if (event.data && event.data.xbPreviewClickedUrl) {
        setLinkIntercepted(event.data.xbPreviewClickedUrl);
      }
    }
    window.addEventListener('message', handlePreviewLinkClick);

    return () => {
      window.removeEventListener('message', handlePreviewLinkClick);
    };
  });

  const handleDialogOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      setLinkIntercepted('');
    }
  };

  const handleLinkOpenClick = useCallback(() => {
    window.open(linkIntercepted, '_blank');
  }, [linkIntercepted]);

  return (
    <>
      <div className={styles.PagePreviewContainer}>
        <div className={styles.controls}></div>
        <iframe
          title="Page preview"
          style={{ width: widthVal }}
          srcDoc={frameSrcDoc}
          className={styles.PagePreviewIframe}
        ></iframe>
      </div>
      <AlertDialog.Root
        open={!!linkIntercepted}
        defaultOpen={false}
        onOpenChange={handleDialogOpenChange}
      >
        <AlertDialog.Content maxWidth="450px">
          <AlertDialog.Title>Link clicked</AlertDialog.Title>
          <AlertDialog.Description size="2" mb="4">
            You clicked a link in the preview and we intercepted it so that you
            are not navigated away from this page.
          </AlertDialog.Description>

          <AlertDialog.Description size="2">
            The link goes to <strong>{linkIntercepted}</strong>
          </AlertDialog.Description>

          <Flex gap="3" mt="4" justify="end">
            <AlertDialog.Cancel>
              <Button variant="soft" color="gray">
                Done
              </Button>
            </AlertDialog.Cancel>
            <AlertDialog.Action>
              <Button
                variant="solid"
                color="blue"
                onClick={handleLinkOpenClick}
              >
                Open in new window
              </Button>
            </AlertDialog.Action>
          </Flex>
        </AlertDialog.Content>
      </AlertDialog.Root>
    </>
  );
};

export default PagePreview;
