import { Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import {
  Button,
  Flex,
  IconButton,
  Select,
  Text,
  TextField,
} from '@radix-ui/themes';

import { UTM_KEYS } from '@/features/personalization/rules';

import type {
  UtmParameter,
  UtmParameterMatching,
  UtmParametersCondition,
} from '@/types/Personalization';

// Sentinel Select value for parameter names outside the standard utm_* keys.
const CUSTOM_KEY = '_custom';

interface UtmParametersRuleEditorProps {
  settings: UtmParametersCondition;
  onChange: (settings: UtmParametersCondition) => void;
}

const UtmParametersRuleEditor = ({
  settings,
  onChange,
}: UtmParametersRuleEditorProps) => {
  const updateParameter = (index: number, changes: Partial<UtmParameter>) => {
    onChange({
      ...settings,
      parameters: settings.parameters.map((parameter, i) =>
        i === index ? { ...parameter, ...changes } : parameter,
      ),
    });
  };

  const removeParameter = (index: number) => {
    onChange({
      ...settings,
      parameters: settings.parameters.filter((_, i) => i !== index),
    });
  };

  const addParameter = () => {
    onChange({
      ...settings,
      parameters: [
        ...settings.parameters,
        { key: 'utm_source', value: '', matching: 'exact' },
      ],
    });
  };

  return (
    <Flex direction="column" gap="2" align="start">
      <Select.Root
        size="1"
        value={settings.all ? 'all' : 'any'}
        onValueChange={(value) =>
          onChange({ ...settings, all: value === 'all' })
        }
      >
        <Select.Trigger aria-label="How parameters combine" />
        <Select.Content>
          <Select.Item value="all">All parameters must match</Select.Item>
          <Select.Item value="any">Any parameter can match</Select.Item>
        </Select.Content>
      </Select.Root>
      {settings.parameters.map((parameter, index) => {
        const isStandardKey = (UTM_KEYS as readonly string[]).includes(
          parameter.key,
        );
        return (
          // The rows have no stable identity, so the index is the best key.
          <Flex key={index} gap="2" wrap="wrap" align="center">
            <Select.Root
              size="1"
              value={isStandardKey ? parameter.key : CUSTOM_KEY}
              onValueChange={(key) =>
                updateParameter(index, {
                  key: key === CUSTOM_KEY ? '' : key,
                })
              }
            >
              <Select.Trigger aria-label={`Parameter ${index + 1} name`} />
              <Select.Content>
                {UTM_KEYS.map((key) => (
                  <Select.Item key={key} value={key}>
                    {key}
                  </Select.Item>
                ))}
                <Select.Item value={CUSTOM_KEY}>Custom parameter</Select.Item>
              </Select.Content>
            </Select.Root>
            {!isStandardKey && (
              <TextField.Root
                size="1"
                value={parameter.key}
                placeholder="Parameter name"
                aria-label={`Parameter ${index + 1} custom name`}
                onChange={(e) =>
                  updateParameter(index, { key: e.target.value })
                }
              />
            )}
            <Select.Root
              size="1"
              value={parameter.matching}
              onValueChange={(matching) =>
                updateParameter(index, {
                  matching: matching as UtmParameterMatching,
                })
              }
            >
              <Select.Trigger aria-label={`Parameter ${index + 1} matching`} />
              <Select.Content>
                <Select.Item value="exact">Equals</Select.Item>
                <Select.Item value="starts_with">Starts with</Select.Item>
              </Select.Content>
            </Select.Root>
            <TextField.Root
              size="1"
              value={parameter.value}
              placeholder="Value"
              aria-label={`Parameter ${index + 1} value`}
              onChange={(e) =>
                updateParameter(index, { value: e.target.value })
              }
            />
            <IconButton
              size="1"
              variant="ghost"
              color="gray"
              aria-label={`Remove parameter ${index + 1}`}
              onClick={() => removeParameter(index)}
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        );
      })}
      {settings.parameters.length === 0 && (
        <Text size="1" color="gray">
          Add at least one UTM parameter to match.
        </Text>
      )}
      <Button size="1" variant="outline" onClick={addParameter}>
        <PlusIcon />
        Add parameter
      </Button>
    </Flex>
  );
};

export default UtmParametersRuleEditor;
