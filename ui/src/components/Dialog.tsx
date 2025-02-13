import { Button, Dialog as ThemedDialog, Flex, Text } from '@radix-ui/themes';
import ErrorCard from '@/components/error/ErrorCard';
import styles from './Dialog.module.css';
import DraggableDialogWrapper from '@/components/DraggableDialogWrapper';
import type React from 'react';

export interface DialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  modal?: boolean;
  description?: React.ReactNode;
  children?: React.ReactNode;
  error?: {
    title: string;
    message: string;
    resetButtonText?: string;
    onReset?: () => void;
  };
  footer?: {
    cancelText?: string;
    confirmText?: string;
    onConfirm?: () => void;
    isConfirmDisabled?: boolean;
    isConfirmLoading?: boolean;
    isDanger?: boolean;
  };
}

const DialogWrap = ({ open, handleOpenChange, children, description }: any) => (
  <ThemedDialog.Root open={open} onOpenChange={handleOpenChange}>
    <ThemedDialog.Content
      width="287px"
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
  modal = true,
  footer = {
    cancelText: 'Cancel',
    confirmText: 'Confirm',
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
    >
      <ThemedDialog.Title className={styles.title}>
        <Text size="1" weight="bold">
          {title}
        </Text>
      </ThemedDialog.Title>

      {description && (
        <ThemedDialog.Description size="2" mb="4">
          {description}
        </ThemedDialog.Description>
      )}

      <Flex direction="column" gap="2">
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

        <Flex gap="2" justify="end">
          <ThemedDialog.Close>
            <Button variant="outline" size="1">
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
      </Flex>
    </Wrapper>
  );
};

const DialogFieldLabel = ({ children }: { children: React.ReactNode }) => {
  return (
    <Text as="label" size="1" weight="bold" className={styles.fieldLabel}>
      {children}
    </Text>
  );
};

export { DialogFieldLabel };
export default Dialog;
