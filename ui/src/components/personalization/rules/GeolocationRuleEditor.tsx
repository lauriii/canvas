import { useId, useState } from 'react';
import { Flex, Text, TextField } from '@radix-ui/themes';

import type { GeolocationCondition } from '@/types/Personalization';

interface GeolocationRuleEditorProps {
  settings: GeolocationCondition;
  onChange: (settings: GeolocationCondition) => void;
}

const parseCodes = (value: string): string[] =>
  value
    .split(',')
    .map((code) => code.trim().toUpperCase())
    .filter(Boolean);

const GeolocationRuleEditor = ({
  settings,
  onChange,
}: GeolocationRuleEditorProps) => {
  const countriesId = useId();
  const regionsId = useId();
  // Keep the raw text locally so separators and trailing commas survive
  // while typing; only the parsed codes are propagated.
  const [countriesText, setCountriesText] = useState(() =>
    settings.countries.join(', '),
  );
  const [regionsText, setRegionsText] = useState(() =>
    (settings.regions ?? []).join(', '),
  );

  const invalidCountries = parseCodes(countriesText).filter(
    (code) => !/^[A-Z]{2}$/.test(code),
  );
  const invalidRegions = parseCodes(regionsText).filter(
    (code) => !/^[A-Z0-9]{1,3}$/.test(code),
  );

  return (
    <Flex direction="column" gap="2">
      <Flex direction="column" gap="1">
        <Text as="label" size="1" weight="medium" htmlFor={countriesId}>
          Countries
        </Text>
        <TextField.Root
          id={countriesId}
          size="1"
          value={countriesText}
          placeholder="US, CA"
          onChange={(e) => {
            setCountriesText(e.target.value);
            onChange({ ...settings, countries: parseCodes(e.target.value) });
          }}
        />
        <Text size="1" color={invalidCountries.length > 0 ? 'red' : 'gray'}>
          {invalidCountries.length > 0
            ? `Not a two-letter country code: ${invalidCountries.join(', ')}`
            : 'Two-letter country codes, separated by commas.'}
        </Text>
      </Flex>
      <Flex direction="column" gap="1">
        <Text as="label" size="1" weight="medium" htmlFor={regionsId}>
          Regions (optional)
        </Text>
        <TextField.Root
          id={regionsId}
          size="1"
          value={regionsText}
          placeholder="NY, ON"
          onChange={(e) => {
            setRegionsText(e.target.value);
            onChange({ ...settings, regions: parseCodes(e.target.value) });
          }}
        />
        <Text size="1" color={invalidRegions.length > 0 ? 'red' : 'gray'}>
          {invalidRegions.length > 0
            ? `Not a valid region code: ${invalidRegions.join(', ')}`
            : 'Region codes of 1-3 letters or digits, separated by commas.'}
        </Text>
      </Flex>
    </Flex>
  );
};

export default GeolocationRuleEditor;
