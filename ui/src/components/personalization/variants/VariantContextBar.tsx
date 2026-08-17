import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import VariantAudience from '@/components/personalization/variants/VariantAudience';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  DEFAULT_VARIANT_ID,
  findSwitchNodes,
  getCaseSegmentIds,
  getCaseVariantId,
  getPreviewedVariant,
  getSwitchCases,
  getSwitchVariants,
  humanizeVariantId,
} from '@/features/layout/personalizationUtils';
import {
  selectPreviewedVariants,
  setPreviewedVariant,
} from '@/features/ui/uiSlice';

import styles from './VariantContextBar.module.css';

/**
 * Banner under the topbar that flags variant editing. It is visible only
 * while the previewed variant of any switch is not the default, so authors
 * cannot mistake variant content for the default page content.
 */
const VariantContextBar = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);

  const switches = findSwitchNodes(layout);
  const activeSwitches = switches.filter((switchNode) => {
    const variantId = getPreviewedVariant(previewedVariants, switchNode.uuid);
    // A stale preview entry (for example, after undoing a variant creation)
    // must not surface the bar for a variant that no longer exists.
    return (
      variantId !== DEFAULT_VARIANT_ID &&
      getSwitchVariants(model, switchNode.uuid).includes(variantId)
    );
  });

  if (activeSwitches.length === 0) {
    return null;
  }

  const handleBackToDefault = () => {
    switches.forEach((switchNode) => {
      if (
        getPreviewedVariant(previewedVariants, switchNode.uuid) !==
        DEFAULT_VARIANT_ID
      ) {
        dispatch(
          setPreviewedVariant({
            switchUuid: switchNode.uuid,
            variantId: DEFAULT_VARIANT_ID,
          }),
        );
      }
    });
  };

  const primarySwitch = activeSwitches[0];
  const primaryVariantId = getPreviewedVariant(
    previewedVariants,
    primarySwitch.uuid,
  );
  const primaryCase = getSwitchCases(primarySwitch).find(
    (caseNode) => getCaseVariantId(model, caseNode) === primaryVariantId,
  );

  return (
    <Flex
      className={styles.bar}
      align="center"
      justify="center"
      gap="3"
      px="4"
      py="2"
      data-testid="variant-context-bar"
    >
      <Text size="2" weight="medium">
        {activeSwitches.length === 1 ? (
          <>
            Editing variant: {humanizeVariantId(primaryVariantId)} —{' '}
            <VariantAudience
              isDefault={false}
              segmentIds={
                primaryCase ? getCaseSegmentIds(model, primaryCase) : []
              }
            />
          </>
        ) : (
          <>
            Editing variants:{' '}
            {activeSwitches
              .map((switchNode) =>
                humanizeVariantId(
                  getPreviewedVariant(previewedVariants, switchNode.uuid),
                ),
              )
              .join(', ')}
          </>
        )}
      </Text>
      <Button size="1" variant="surface" onClick={handleBackToDefault}>
        Back to default
      </Button>
    </Flex>
  );
};

export default VariantContextBar;
