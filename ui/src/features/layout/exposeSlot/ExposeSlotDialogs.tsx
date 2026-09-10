import DeleteComponentWithExposedSlotsDialog from '@/features/layout/exposeSlot/DeleteComponentWithExposedSlotsDialog';
import ExposeSlotDialog from '@/features/layout/exposeSlot/ExposeSlotDialog';
import RemoveExposedSlotDialog from '@/features/layout/exposeSlot/RemoveExposedSlotDialog';

/**
 * Mounts the template-editor exposed-slot dialogs once, centrally. The slot and
 * component context menus open them via dialog slice state.
 */
const ExposeSlotDialogs = () => {
  return (
    <>
      <ExposeSlotDialog />
      <RemoveExposedSlotDialog />
      <DeleteComponentWithExposedSlotsDialog />
    </>
  );
};

export default ExposeSlotDialogs;
