import { Cross2Icon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Flex,
  IconButton,
  Text,
  Dialog as ThemedDialog,
} from '@radix-ui/themes';

import DraggableDialogWrapper from '@/components/DraggableDialogWrapper';
import ErrorCard from '@/components/error/ErrorCard';

import type React from 'react';

import styles from './Dialog.module.css';

export interface DialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string | React.ReactNode;
  modal?: boolean;
  description?: React.ReactNode;
  children?: React.ReactNode;
  error?: {
    title: string;
    message: string | React.ReactNode;
    resetButtonText?: string;
    onReset?: () => void;
  };
  headerClose?: boolean;
  width?: string;
  footer?: {
    cancelText?: string;
    confirmText?: string;
    onConfirm?: () => void;
    isConfirmDisabled?: boolean;
    hidden?: boolean;
    isConfirmLoading?: boolean;
    isDanger?: boolean;
    onCancel?: () => void;
  };
}

const DialogWrap = ({
  open,
  handleOpenChange,
  children,
  description,
  width,
}: any) => (
  <ThemedDialog.Root open={open} onOpenChange={handleOpenChange}>
    <ThemedDialog.Content
      width={width ?? '287px'}
      maxWidth={width}
      className={styles.dialogContent}
      {...(!description && { 'aria-describedby': undefined })}
    >
      {children}
    </ThemedDialog.Content>
  </ThemedDialog.Root>
);

const DraggableDialogWrap = ({
  handleOpenChange,
  open,
  description,
  children,
}: any) => (
  <DraggableDialogWrapper
    open={open}
    onOpenChange={handleOpenChange}
    description={description}
  >
    {children}
  </DraggableDialogWrapper>
);

const Dialog = ({
  open,
  onOpenChange,
  title,
  description,
  children,
  error,
  headerClose,
  modal = true,
  width,
  footer = {
    cancelText: Drupal.t('Cancel'),
    confirmText: Drupal.t('Confirm'),
  },
}: DialogProps) => {
  const handleOpenChange = (isOpen: boolean) => {
    onOpenChange(isOpen);
  };

  const Wrapper = modal ? DialogWrap : DraggableDialogWrap;

  return (
    <Wrapper
      open={open}
      handleOpenChange={handleOpenChange}
      description={description}
      width={width}
    >
      {headerClose && (
        <Box className={styles.headerCloseButton}>
          <ThemedDialog.Close>
            <IconButton
              variant="ghost"
              size="1"
              aria-label={Drupal.t('Close')}
              onClick={(e) => handleOpenChange(false)}
            >
              <span className="visually-hidden">{Drupal.t('Close')}</span>
              <Cross2Icon color="black" />
            </IconButton>
          </ThemedDialog.Close>
        </Box>
      )}
      <ThemedDialog.Title className={styles.title}>
        <Text size="2" weight="bold">
          {title}
        </Text>
      </ThemedDialog.Title>

      <Box className={styles.dialogScrollableInner}>
        {description && (
          <ThemedDialog.Description size="2" mb="4">
            {description}
          </ThemedDialog.Description>
        )}

        <Flex direction="column" gap="1">
          {children}
        </Flex>

        {error && (
          <ErrorCard
            title={error.title}
            error={error.message}
            resetButtonText={error.resetButtonText}
            resetErrorBoundary={error.onReset}
          />
        )}
      </Box>

      {footer.hidden ? null : (
        <Flex gap="2" justify="end" mt="3">
          <ThemedDialog.Close>
            <Button
              variant="outline"
              size="1"
              onClick={() => {
                if (footer?.onCancel) {
                  footer.onCancel();
                } else {
                  handleOpenChange(false);
                }
              }}
            >
              {footer.cancelText}
            </Button>
          </ThemedDialog.Close>
          {footer.onConfirm && (
            <Button
              onClick={footer.onConfirm}
              disabled={footer.isConfirmDisabled}
              loading={footer.isConfirmLoading}
              size="1"
              color={footer.isDanger ? 'red' : 'blue'}
            >
              {footer.confirmText}
            </Button>
          )}
        </Flex>
      )}
    </Wrapper>
  );
};

const DialogFieldLabel = ({
  children,
  htmlFor,
}: {
  children: React.ReactNode;
  htmlFor: string;
}) => {
  return (
    <Text
      as="label"
      size="1"
      weight="bold"
      className={styles.fieldLabel}
      htmlFor={htmlFor}
    >
      {children}
    </Text>
  );
};

export { DialogFieldLabel };
export default Dialog;
