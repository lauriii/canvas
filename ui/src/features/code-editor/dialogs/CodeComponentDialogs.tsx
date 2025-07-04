import AddCodeComponentDialog from './AddCodeComponentDialog';
import RenameCodeComponentDialog from './RenameCodeComponentDialog';
import DeleteCodeComponentDialog from './DeleteCodeComponentDialog';
import AddToComponentsDialog from './AddToComponentsDialog';
import RemoveFromComponentsDialog from './RemoveFromComponentsDialog';
import ComponentInLayoutDialog from './ComponentInLayoutDialog';
import PermissionCheck from '@/components/PermissionCheck';

const CodeComponentDialogs = () => {
  return (
    <>
      <PermissionCheck hasPermission="codeComponents">
        <AddCodeComponentDialog />
      </PermissionCheck>
      <RenameCodeComponentDialog />
      <DeleteCodeComponentDialog />
      <AddToComponentsDialog />
      <RemoveFromComponentsDialog />
      <ComponentInLayoutDialog />
    </>
  );
};

export default CodeComponentDialogs;
