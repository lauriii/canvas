import Dialog from '@/components/Dialog';

import type { HeadlessFrontend } from './types';

interface RemoveFrontendDialogProps {
  frontend: HeadlessFrontend | null;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  isRemoving: boolean;
}

const RemoveFrontendDialog = ({
  frontend,
  onOpenChange,
  onConfirm,
  isRemoving,
}: RemoveFrontendDialogProps) => (
  <Dialog
    open={frontend !== null}
    onOpenChange={onOpenChange}
    title="Remove frontend"
    description={
      <>
        You are about to remove <b>{frontend?.url}</b> from your headless
        frontends. You can add it again at any time.
      </>
    }
    footer={{
      cancelText: 'Cancel',
      confirmText: 'Remove',
      onConfirm,
      isConfirmDisabled: isRemoving,
      isConfirmLoading: isRemoving,
      isDanger: true,
    }}
  />
);

export default RemoveFrontendDialog;
