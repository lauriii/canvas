/* cspell:ignore Ronk mander mando bination mentary */

describe('Prop types editing', () => {
  const textFieldIterations = {
    'String: Required': {
      valuePre: 'Hello, required world!',
      valuePost: 'Hello, required world! Goodbye shack',
      typeThis: ' Goodbye shack',
      iframeSelector: '#test-required-string',
      labelText: 'String',
    },
    String: {
      valuePre: 'Hello, world!',
      valuePost: 'Hello, world! My name is Ronk',
      typeThis: ' My name is Ronk',
      iframeSelector: '#test-string',
      labelText: 'String — single line',
    },
    'String: Multiline': {
      valuePre: 'Hello,\nmultiline\nworld!',
      valuePost: 'Hello,\nmultiline\nworld! yay',
      typeThis: ' yay',
      iframeSelector: '#test-string-multiline',
      labelText: 'String — multi-line',
    },

    'String: Format email': {
      valuePre: 'hello@example.com',
      valuePost: 'hello@example.commander',
      typeThis: 'mander',
      iframeSelector: '#test-string-format-email',
      labelText: 'String, format=email',
    },
    'String: Format idn email': {
      valuePre: 'hello@idn.example.com',
      valuePost: 'hello@idn.example.commando',
      typeThis: 'mando',
      iframeSelector: '#test-string-format-idn-email',
      labelText: 'String, format=idn-email',
    },
    'String: Format uri': {
      valuePre: 'https://uri.example.com',
      valuePost: 'https://uri.example.combination',
      typeThis: 'bination',
      iframeSelector: '#test-string-format-uri',
      labelText: 'String, format=uri',
    },
    'String: Format iri': {
      valuePre: 'https://iri.example.com',
      valuePost: 'https://iri.example.commentary',
      typeThis: 'mentary',
      iframeSelector: '#test-string-format-iri',
      labelText: 'String, format=iri',
    },
  };
  before(() => {
    cy.drupalXbInstall();
    cy.drupalInstallModule('sdc_test_all_props');
    cy.drupalLogin('xbUser', 'xbUser');
  });
  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
    cy.get('.primaryPanelContent').findByText('Two Column').click();
    cy.findByLabelText('Column Width').should('exist');
    cy.findAllByLabelText('Add section')
      .first()
      .click({ scrollBehavior: 'center' });
    cy.get('[data-testid="xb-primary-panel--library"]').should(
      'have.attr',
      'data-state',
      'on',
    );
    cy.get('.primaryPanelContent').findByText('All props').click();
    cy.openLayersPanel();
    cy.clickComponentInLayersView('All props');
    cy.findByLabelText('String — single line').should('exist');
  });

  afterEach(() => {
    cy.drupalRelativeURL('');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Boolean', () => {
    cy.waitForElementContentInIframe('#test-bool code', 'true');
    cy.waitForElementContentNotInIframe('#test-bool code', 'false');
    cy.get('.ToggleContainer')
      .should('exist')
      .then(($toggleContainer) => {
        const $buttons = $toggleContainer.find('button');
        expect($buttons).to.have.length(2);
        expect($buttons.get(0)).to.have.text('TrueTrue');
        expect($buttons.get(0)).attr('aria-checked', 'true');
        expect($buttons.get(0)).attr('data-state', 'on');

        expect($buttons.get(1)).to.have.text('FalseFalse');
        expect($buttons.get(1)).attr('aria-checked', 'false');
        expect($buttons.get(1)).attr('data-state', 'off');
      });

    cy.get('.ToggleContainer button').last().click();

    cy.get('.ToggleContainer')
      .should('exist')
      .then(($toggleContainer) => {
        const $buttons = $toggleContainer.find('button');
        expect($buttons).to.have.length(2);
        expect($buttons.get(0)).to.have.text('TrueTrue');
        expect($buttons.get(0)).attr('aria-checked', 'false');
        expect($buttons.get(0)).attr('data-state', 'off');

        expect($buttons.get(1)).to.have.text('FalseFalse');
        expect($buttons.get(1)).attr('aria-checked', 'true');
        expect($buttons.get(1)).attr('data-state', 'on');
      });

    cy.waitForElementContentInIframe('#test-bool code', 'false');
    cy.waitForElementContentNotInIframe('#test-bool code', 'true');
  });

  it('Single textfields - valid input', () => {
    Object.entries(textFieldIterations).forEach(([testName, testData]) => {
      cy.log(`Test ${testName}`);
      cy.findByLabelText(testData.labelText).should(
        'have.value',
        testData.valuePre,
      );
      cy.waitForElementContentInIframe(
        testData.iframeSelector,
        testData.valuePre,
      );
      cy.findByLabelText(testData.labelText).type(testData.typeThis);
      cy.waitForElementContentInIframe(
        testData.iframeSelector,
        testData.valuePost,
      );
    });
  });

  it('Enum (select)', () => {
    cy.findByLabelText('String - Enum').should('have.value', 'foo');
    cy.waitForElementContentInIframe('#test-string-enum', 'foo');
    cy.findByLabelText('String - Enum').select(0);
    cy.findByLabelText('String - Enum').should('have.value', '_none');
    cy.waitForElementContentNotInIframe('#test-string-enum', 'foo');
    cy.testInIframe('#test-string-enum code', (enumPreview) => {
      expect(enumPreview.textContent).to.eq('');
    });
    cy.findByLabelText('String - Enum').select(2);
    cy.findByLabelText('String - Enum').should('have.value', 'bar');
    cy.waitForElementContentInIframe('#test-string-enum', 'bar');
  });

  it('Date + Time widget', () => {
    // @todo these tests confirm that the date+time inputs can be changed and the
    // preview updates in response. It is not yet confirmed if the values found
    // in the form and preview are *correct*. This may require time zone/locale
    // adjustments - do not interpret the presence of this test as evidence that
    // time zone offsets are working as they should.
    const dateSelector =
      '[name$="[test_string_format_date_time][0][value][date]"]';
    const timeSelector =
      '[name$="[test_string_format_date_time][0][value][time]"]';

    cy.get(dateSelector).should('have.value', '2016-09-17');

    cy.get(timeSelector).should('have.value', '06:20:39');
    cy.waitForElementContentInIframe(
      '#test-string-format-date-time',
      '2016-09-16T20:20:39+00:00',
    );

    cy.get(dateSelector).clear();
    cy.get(dateSelector).type('2017-06-28');
    cy.get(timeSelector).clear();
    cy.get(timeSelector).type('07:21:35');
    cy.get(dateSelector).should('have.value', '2017-06-28');

    cy.get(timeSelector).should('have.value', '07:21:35');
    cy.waitForElementContentInIframe(
      '#test-string-format-date-time',
      '2017-06-28T07:21:35.000Z',
    );
  });
  it('Individual date and time inputs', () => {
    // @todo The time prop isn't appearing in the form so this is just date
    // for now.
    // @todo these tests confirm that the date+time inputs can be changed and the
    // preview updates in response. It is not yet confirmed if the values found
    // in the form and preview are *correct*. This may require time zone/locale
    // adjustments - do not interpret the presence of this test as evidence that
    // time zone offsets are working as they should.

    const dateSelector = '[name$="[test_string_format_date][0][value][date]"]';
    cy.get(dateSelector).should('have.value', '2018-11-12');
    cy.waitForElementContentInIframe('#test-string-format-date', '2018-11-13');
    cy.get(dateSelector).clear();
    cy.get(dateSelector).type('2017-06-28');
    cy.waitForElementContentInIframe('#test-string-format-date', '2017-06-28');
  });
  it('Integer', () => {
    cy.findByLabelText('Integer').should('have.value', -42);
    cy.waitForElementContentInIframe('#test-integer', '-42');
    cy.findByLabelText('Integer').clear();
    cy.findByLabelText('Integer').type(12);
    cy.findByLabelText('Integer').should('have.value', 12);
    cy.waitForElementContentInIframe('#test-integer', '12');

    cy.findByLabelText('Integer, minimum=0').should('have.value', 42);
    cy.waitForElementContentInIframe('#test-integer-range-minimum', '42');
    cy.findByLabelText('Integer, minimum=0').clear();
    cy.findByLabelText('Integer, minimum=0').type(55);
    cy.findByLabelText('Integer, minimum=0').should('have.value', 55);
    cy.waitForElementContentInIframe('#test-integer-range-minimum', '55');

    cy.findByLabelText(
      'Integer, minimum=-2147483648, maximum=2147483648',
    ).should('have.value', 1730718000);
    cy.waitForElementContentInIframe(
      '#test-integer-range-minimum-maximum-timestamps',
      '1730718000',
    );
    cy.findByLabelText(
      'Integer, minimum=-2147483648, maximum=2147483648',
    ).clear();
    cy.findByLabelText('Integer, minimum=-2147483648, maximum=2147483648').type(
      543211,
    );
    cy.findByLabelText(
      'Integer, minimum=-2147483648, maximum=2147483648',
    ).should('have.value', 543211);
    cy.waitForElementContentInIframe(
      '#test-integer-range-minimum-maximum-timestamps',
      '543211',
    );
  });
});
