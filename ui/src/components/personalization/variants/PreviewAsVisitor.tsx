import { useId, useState } from 'react';
import { PersonIcon } from '@radix-ui/react-icons';
import { Button, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  findSwitchNodes,
  getCaseSegmentIds,
  getSwitchCases,
  humanizeVariantId,
} from '@/features/layout/personalizationUtils';
import {
  collectSimulationInputs,
  resolveVisitorVariants,
} from '@/features/layout/simulateVisitor';
import { capitalize, DAYS_OF_WEEK } from '@/features/personalization/rules';
import { setPreviewedVariant } from '@/features/ui/uiSlice';
import { useGetSegmentsQuery } from '@/services/personalization';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type { SimulatedVisitor } from '@/features/layout/simulateVisitor';
import type { DayOfWeek } from '@/types/Personalization';

// Select values for the unconstrained choices. Country codes are two
// letters and day values are lowercase day names, so these cannot collide.
const ANYWHERE_VALUE = 'anywhere';
const ANY_DAY_VALUE = 'any';

const regionDisplayNames = new Intl.DisplayNames(['en'], { type: 'region' });

const countryName = (code: string): string => {
  try {
    return regionDisplayNames.of(code) ?? code;
  } catch {
    // Stored data may hold malformed codes; show them as-is.
    return code;
  }
};

interface PreviewAsVisitorProps {
  // Names a switch for the outcome line when the layout has several.
  getSwitchLabel: (switchNode: ComponentNode) => string;
}

/**
 * Audience simulation: the author describes a visitor (query parameters,
 * country, day) and sees which variant each switch would serve, without
 * having to know which segment matches. The offered inputs are derived from
 * the rules the page's audiences actually use.
 */
const PreviewAsVisitor = ({ getSwitchLabel }: PreviewAsVisitorProps) => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const { data: segments } = useGetSegmentsQuery();
  const baseId = useId();
  const [isOpen, setOpen] = useState(false);
  const [queryValues, setQueryValues] = useState<Record<string, string>>({});
  const [country, setCountry] = useState(ANYWHERE_VALUE);
  const [day, setDay] = useState(ANY_DAY_VALUE);

  const switches = findSwitchNodes(layout);
  const referencedSegmentIds = switches.flatMap((switchNode) =>
    getSwitchCases(switchNode).flatMap((caseNode) =>
      getCaseSegmentIds(model, caseNode),
    ),
  );
  const inputs = collectSimulationInputs(segments, referencedSegmentIds);
  const hasInputs =
    inputs.queryParameters.length > 0 ||
    inputs.countries.length > 0 ||
    inputs.days;

  // An empty text input simulates the parameter being absent from the URL;
  // the input cannot distinguish absent from empty, and absent is the
  // useful reading while typing.
  const query: Record<string, string> = {};
  for (const parameter of inputs.queryParameters) {
    const value = queryValues[parameter] ?? '';
    if (value !== '') {
      query[parameter] = value;
    }
  }
  const visitor: SimulatedVisitor = {
    query,
    country: country === ANYWHERE_VALUE ? undefined : country,
    day: day === ANY_DAY_VALUE ? undefined : (day as DayOfWeek),
  };
  const outcome = resolveVisitorVariants(layout, model, segments, visitor);

  const outcomeText =
    switches.length === 1
      ? humanizeVariantId(outcome[switches[0].uuid])
      : switches
          .map(
            (switchNode) =>
              `${getSwitchLabel(switchNode)}: ${humanizeVariantId(outcome[switchNode.uuid])}`,
          )
          .join(' · ');

  const handleShowInPreview = () => {
    for (const switchNode of switches) {
      dispatch(
        setPreviewedVariant({
          switchUuid: switchNode.uuid,
          variantId: outcome[switchNode.uuid],
        }),
      );
    }
  };

  return (
    <Flex direction="column" gap="2" data-testid="preview-as-visitor">
      <Button
        variant="ghost"
        color="gray"
        size="1"
        onClick={() => setOpen(!isOpen)}
      >
        <PersonIcon />
        Preview as visitor
      </Button>
      {isOpen &&
        (!hasInputs ? (
          <Text size="1" color="gray">
            The audiences on this page do not use visitor conditions yet.
          </Text>
        ) : (
          <Flex direction="column" gap="2">
            {inputs.queryParameters.map((parameter) => (
              <Flex key={parameter} direction="column" gap="1">
                <Text
                  as="label"
                  size="1"
                  weight="medium"
                  htmlFor={`${baseId}-${parameter}`}
                >
                  {parameter}
                </Text>
                <TextField.Root
                  id={`${baseId}-${parameter}`}
                  size="1"
                  value={queryValues[parameter] ?? ''}
                  onChange={(e) =>
                    setQueryValues({
                      ...queryValues,
                      [parameter]: e.target.value,
                    })
                  }
                />
              </Flex>
            ))}
            {inputs.countries.length > 0 && (
              <Flex direction="column" gap="1">
                <Text size="1" weight="medium">
                  Country
                </Text>
                <Select.Root
                  size="1"
                  value={country}
                  onValueChange={setCountry}
                >
                  <Select.Trigger aria-label="Country" />
                  <Select.Content>
                    {inputs.countries.map((code) => (
                      <Select.Item key={code} value={code}>
                        {countryName(code)} ({code})
                      </Select.Item>
                    ))}
                    <Select.Item value={ANYWHERE_VALUE}>
                      Anywhere else
                    </Select.Item>
                  </Select.Content>
                </Select.Root>
              </Flex>
            )}
            {inputs.days && (
              <Flex direction="column" gap="1">
                <Text size="1" weight="medium">
                  Day
                </Text>
                <Select.Root size="1" value={day} onValueChange={setDay}>
                  <Select.Trigger aria-label="Day" />
                  <Select.Content>
                    {DAYS_OF_WEEK.map((dayOption) => (
                      <Select.Item key={dayOption} value={dayOption}>
                        {capitalize(dayOption)}
                      </Select.Item>
                    ))}
                    <Select.Item value={ANY_DAY_VALUE}>Any day</Select.Item>
                  </Select.Content>
                </Select.Root>
              </Flex>
            )}
            <Text size="1" data-testid="visitor-outcome">
              This visitor sees: {outcomeText}
            </Text>
            <Button variant="soft" size="1" onClick={handleShowInPreview}>
              Show this in the preview
            </Button>
          </Flex>
        ))}
    </Flex>
  );
};

export default PreviewAsVisitor;
