import {
  defaultValue,
  localDefaultValue,
  localDefaultEndValue,
  datePartValueAsText,
} from './default-date.js';

export const edit = (cy) => {
  // Confirm we have the correct timezone in the test.
  expect(defaultValue.toISOString()).to.equal('2025-04-01T04:15:00.000Z');
  cy.findByRole('group', { name: 'XB Date Range (Datelist)' }).as(
    'dateRangeDateList',
  );
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Year')
    .eq(0)
    .parent()
    .find('select')
    .as('startDateYear');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Year')
    .eq(0)
    .parent()
    .findByRole('combobox')
    .as('startDateYearText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Year')
    .eq(1)
    .parent()
    .find('select')
    .as('endDateYear');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Year')
    .eq(1)
    .parent()
    .findByRole('combobox')
    .as('endDateYearText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Month')
    .eq(0)
    .parent()
    .find('select')
    .as('startDateMonth');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Month')
    .eq(0)
    .parent()
    .findByRole('combobox')
    .as('startDateMonthText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Month')
    .eq(1)
    .parent()
    .find('select')
    .as('endDateMonth');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Month')
    .eq(1)
    .parent()
    .findByRole('combobox')
    .as('endDateMonthText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Day')
    .eq(0)
    .parent()
    .find('select')
    .as('startDateDay');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Day')
    .eq(0)
    .parent()
    .findByRole('combobox')
    .as('startDateDayText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Day')
    .eq(1)
    .parent()
    .find('select')
    .as('endDateDay');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Day')
    .eq(1)
    .parent()
    .findByRole('combobox')
    .as('endDateDayText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Hour')
    .eq(0)
    .parent()
    .find('select')
    .as('startDateHour');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Hour')
    .eq(0)
    .parent()
    .findByRole('combobox')
    .as('startDateHourText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Hour')
    .eq(1)
    .parent()
    .find('select')
    .as('endDateHour');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Hour')
    .eq(1)
    .parent()
    .findByRole('combobox')
    .as('endDateHourText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Minute')
    .eq(0)
    .parent()
    .find('select')
    .as('startDateMinute');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Minute')
    .eq(0)
    .parent()
    .findByRole('combobox')
    .as('startDateMinuteText');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Minute')
    .eq(1)
    .parent()
    .find('select')
    .as('endDateMinute');
  cy.get('@dateRangeDateList')
    .findAllByLabelText('Minute')
    .eq(1)
    .parent()
    .findByRole('combobox')
    .as('endDateMinuteText');
  const defaultValues = {
    startDateYear: 2025,
    startDateMonth: localDefaultValue.format('M'),
    startDateDay: localDefaultValue.format('D'),
    startDateHour: localDefaultValue.format('H'),
    startDateMinute: localDefaultValue.format('m'),
    endDateYear: 2025,
    endDateMonth: localDefaultEndValue.format('M'),
    endDateDay: localDefaultEndValue.format('D'),
    endDateHour: localDefaultEndValue.format('H'),
    endDateMinute: localDefaultEndValue.format('m'),
  };
  Object.entries(defaultValues).forEach(([key, value]) => {
    cy.get(`@${key}`).should('have.value', value);
    cy.get(`@${key}Text`).should('have.text', datePartValueAsText(key, value));
  });
  // Check we can select the empty value without raising a 500 error.
  cy.get('@startDateMonth').select('Month', { force: true });
  cy.waitForElementContentInIframe(
    '[data-drupal-messages]',
    'A value must be selected for month',
  );
  // This date is after daylight savings time has finished in the
  // timezone core uses for tests (Australia/Sydney). This is by design
  // as we want to assert that the saved value reflects the new offset
  // of UTC+10.
  // @see bootstrap.php
  const newValues = {
    startDateYear: 2026,
    startDateMonth: 5,
    startDateDay: 2,
    startDateHour: 5,
    startDateMinute: 15,
    endDateYear: 2026,
    endDateMonth: 6,
    endDateDay: 2,
    endDateHour: 7,
    endDateMinute: 30,
  };
  Object.entries(newValues).forEach(([key, value]) => {
    // Radix renders these as a hidden element with a button to trigger, so
    // we have to use force.
    cy.get(`@${key}`).select(String(value), { force: true });
    cy.get(`@${key}Text`).should('have.text', datePartValueAsText(key, value));
  });
};
export const assertData = (response) => {
  expect(response.attributes.field_xbt_daterange_datelist.value).to.equal(
    '2026-05-02T05:15:00+10:00',
  );
  expect(response.attributes.field_xbt_daterange_datelist.end_value).to.equal(
    '2026-06-02T07:30:00+10:00',
  );
};
