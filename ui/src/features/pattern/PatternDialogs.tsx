import DeletePatternDialog from '@/features/pattern/DeletePatternDialog';
import RenamePatternDialog from '@/features/pattern/RenamePatternDialog';
import SavePatternDialog from '@/features/pattern/SavePatternDialog';

const PatternDialogs = () => {
  return (
    <>
      <SavePatternDialog />
      <RenamePatternDialog />
      <DeletePatternDialog />
    </>
  );
};

export default PatternDialogs;
