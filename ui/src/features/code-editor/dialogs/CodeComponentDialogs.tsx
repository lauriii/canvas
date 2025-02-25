import AddCodeComponentDialog from './AddCodeComponentDialog';
import RenameCodeComponentDialog from './RenameCodeComponentDialog';
import DeleteCodeComponentDialog from './DeleteCodeComponentDialog';
import AddToComponentsDialog from './AddToComponentsDialog';
import RemoveFromComponentsDialog from './RemoveFromComponentsDialog';
import ComponentInLayoutDialog from './ComponentInLayoutDialog';

const CodeComponentDialogs = () => {
  return (
    <>
      <AddCodeComponentDialog />
      <RenameCodeComponentDialog />
      <DeleteCodeComponentDialog />
      <AddToComponentsDialog />
      <RemoveFromComponentsDialog />
      <ComponentInLayoutDialog />
    </>
  );
};

export default CodeComponentDialogs;
