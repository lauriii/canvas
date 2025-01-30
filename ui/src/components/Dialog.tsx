import { Button, Dialog as RadixDialog, Flex, Text } from '@radix-ui/themes';
import ErrorCard from '@/components/error/ErrorCard';
import styles from './Dialog.module.css';
import type { ReactNode } from 'react';

export interface DialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: ReactNode;
  children?: ReactNode;
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

const Dialog = ({
  open,
  onOpenChange,
  title,
  description,
  children,
  error,
  footer = {
    cancelText: 'Cancel',
    confirmText: 'Confirm',
  },
}: DialogProps) => {
  const handleOpenChange = (isOpen: boolean) => {
    onOpenChange(isOpen);
  };

  return (
    <RadixDialog.Root open={open} onOpenChange={handleOpenChange}>
      <RadixDialog.Content
        width="287px"
        className={styles.dialogContent}
        // aria-describedby={undefined} is needed when there is no description.
        // @see https://www.radix-ui.com/primitives/docs/components/dialog#description
        {...(!description && { 'aria-describedby': undefined })}
      >
        <RadixDialog.Title className={styles.title}>
          <Text size="1" weight="bold">
            {title}
          </Text>
        </RadixDialog.Title>

        {description && (
          <RadixDialog.Description size="2" mb="4">
            {description}
          </RadixDialog.Description>
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
            <RadixDialog.Close>
              <Button variant="outline" size="1">
                {footer.cancelText}
              </Button>
            </RadixDialog.Close>
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
      </RadixDialog.Content>
    </RadixDialog.Root>
  );
};

const DialogFieldLabel = ({ children }: { children: ReactNode }) => {
  return (
    <Text as="label" size="1" weight="bold" className={styles.fieldLabel}>
      {children}
    </Text>
  );
};

export { DialogFieldLabel };
export default Dialog;
