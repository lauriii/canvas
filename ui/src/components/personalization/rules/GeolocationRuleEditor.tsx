import { useId, useState } from 'react';
import { Cross2Icon } from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Flex,
  IconButton,
  Text,
  TextField,
} from '@radix-ui/themes';

import type { GeolocationCondition } from '@/types/Personalization';

// Officially assigned ISO 3166-1 alpha-2 codes. Intl.supportedValuesOf()
// does not expose region codes, so the list is inlined; country names come
// from Intl.DisplayNames.
// prettier-ignore
const ISO_COUNTRY_CODES = [
  'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT',
  'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI',
  'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY',
  'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
  'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM',
  'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK',
  'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL',
  'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
  'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR',
  'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN',
  'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS',
  'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
  'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW',
  'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP',
  'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM',
  'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
  'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM',
  'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF',
  'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW',
  'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
  'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
];

const regionDisplayNames = new Intl.DisplayNames(['en'], { type: 'region' });

const countryName = (code: string): string => {
  try {
    return regionDisplayNames.of(code) ?? code;
  } catch {
    // Stored data may hold malformed codes; show them as-is.
    return code;
  }
};

const COUNTRY_OPTIONS = ISO_COUNTRY_CODES.map((code) => ({
  code,
  name: countryName(code),
})).sort((a, b) => a.name.localeCompare(b.name));

const MAX_SUGGESTIONS = 8;

const parseCodes = (value: string): string[] =>
  value
    .split(',')
    .map((code) => code.trim().toUpperCase())
    .filter(Boolean);

interface GeolocationRuleEditorProps {
  settings: GeolocationCondition;
  onChange: (settings: GeolocationCondition) => void;
}

const GeolocationRuleEditor = ({
  settings,
  onChange,
}: GeolocationRuleEditorProps) => {
  const countriesId = useId();
  const regionsId = useId();
  const [countryQuery, setCountryQuery] = useState('');
  // Keep the raw region text locally so separators and trailing commas
  // survive while typing; only the parsed codes are propagated.
  const [regionsText, setRegionsText] = useState(() =>
    (settings.regions ?? []).join(', '),
  );

  const query = countryQuery.trim().toLowerCase();
  const suggestions = query
    ? COUNTRY_OPTIONS.filter(
        ({ code, name }) =>
          !settings.countries.includes(code) &&
          (name.toLowerCase().includes(query) || code.toLowerCase() === query),
      ).slice(0, MAX_SUGGESTIONS)
    : [];

  const addCountry = (code: string) => {
    onChange({ ...settings, countries: [...settings.countries, code] });
    setCountryQuery('');
  };

  const removeCountry = (code: string) => {
    onChange({
      ...settings,
      countries: settings.countries.filter((country) => country !== code),
    });
  };

  const invalidRegions = parseCodes(regionsText).filter(
    (code) => !/^[A-Z0-9]{1,3}$/.test(code),
  );

  return (
    <Flex direction="column" gap="2">
      <Flex direction="column" gap="1">
        <Text as="label" size="1" weight="medium" htmlFor={countriesId}>
          Countries
        </Text>
        {settings.countries.length > 0 && (
          <Flex gap="1" wrap="wrap">
            {settings.countries.map((code) => (
              <Badge key={code} size="1" variant="soft">
                {countryName(code)} ({code})
                <IconButton
                  size="1"
                  variant="ghost"
                  color="gray"
                  aria-label={`Remove ${countryName(code)}`}
                  onClick={() => removeCountry(code)}
                >
                  <Cross2Icon width="10" height="10" />
                </IconButton>
              </Badge>
            ))}
          </Flex>
        )}
        <TextField.Root
          id={countriesId}
          size="1"
          value={countryQuery}
          placeholder="Type a country name"
          autoComplete="off"
          role="combobox"
          aria-expanded={suggestions.length > 0}
          onChange={(e) => setCountryQuery(e.target.value)}
        />
        {suggestions.length > 0 && (
          <Flex
            direction="column"
            align="start"
            role="listbox"
            aria-label="Country suggestions"
          >
            {suggestions.map(({ code, name }) => (
              <Button
                key={code}
                type="button"
                size="1"
                variant="ghost"
                color="gray"
                role="option"
                aria-selected={false}
                onClick={() => addCountry(code)}
              >
                {name} ({code})
              </Button>
            ))}
          </Flex>
        )}
        <Text
          size="1"
          color={query && suggestions.length === 0 ? 'red' : 'gray'}
        >
          {query && suggestions.length === 0
            ? 'No matching countries.'
            : 'Type a country name and select it from the suggestions.'}
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
            : 'Region codes depend on the geolocation provider. Enter codes of 1-3 letters or digits, separated by commas, for example NY for New York.'}
        </Text>
      </Flex>
    </Flex>
  );
};

export default GeolocationRuleEditor;
