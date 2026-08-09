import { afterEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import { getCanvasSettings } from '@/utils/drupal-globals';

import GeolocationRuleEditor from './GeolocationRuleEditor';

import type { GeolocationCondition } from '@/types/Personalization';

const settings: GeolocationCondition = {
  id: 'geolocation',
  negate: false,
  countries: [],
  regions: [],
};

const renderEditor = () =>
  render(
    <Theme>
      <GeolocationRuleEditor settings={settings} onChange={vi.fn()} />
    </Theme>,
  );

describe('GeolocationRuleEditor help text', () => {
  afterEach(() => {
    delete getCanvasSettings().personalizationSettings;
  });

  it('names the configured request headers', () => {
    getCanvasSettings().personalizationSettings = {
      countryHeader: 'X-Geo-Country',
      regionHeader: 'X-Geo-Region',
    };
    renderEditor();

    expect(
      screen.getByText(
        /Matched against the X-Geo-Country request header set by your CDN or reverse proxy\./,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /Region codes are defined by whatever sets the X-Geo-Region header — for United States states this is typically the two-letter state code \(CO, MA\)\./,
      ),
    ).toBeInTheDocument();
  });

  it('falls back to generic wording when the header settings are absent', () => {
    renderEditor();

    expect(
      screen.getByText(
        /Matched against a request header set by your CDN or reverse proxy\./,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /Region codes are defined by whatever sets the region header/,
      ),
    ).toBeInTheDocument();
  });
});
