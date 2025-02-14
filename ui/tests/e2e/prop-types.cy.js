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
    cy.openLibraryPanel();
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
    cy.findByLabelText('Bool')
      .assertToggleState(true)
      .toggleToggle()
      .assertToggleState(false);

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

  it('Enum (select) - string', () => {
    cy.findByLabelText('String - Enum')
      .parent()
      .find('select')
      .as('select')
      .should('have.value', 'foo');
    cy.waitForElementContentInIframe('#test-string-enum', 'foo');
    cy.get('@select').select(0, { force: true });
    cy.get('@select').should('have.value', '_none');
    cy.waitForElementContentNotInIframe('#test-string-enum', 'foo');
    cy.testInIframe('#test-string-enum code', (enumPreview) => {
      expect(enumPreview.textContent).to.eq('');
    });
    cy.get('@select').select(2, { force: true });
    cy.get('@select').should('have.value', 'bar');
    cy.waitForElementContentInIframe('#test-string-enum', 'bar');
  });

  it('Enum (select) - integer', () => {
    cy.findByLabelText('Integer - Enum')
      .parent()
      .find('select')
      .as('select')
      .should('have.value', '1');
    cy.waitForElementContentInIframe('#test-integer-enum', '1');
    cy.get('@select').select(0, { force: true });
    cy.get('@select').should('have.value', '_none');
    cy.waitForElementContentNotInIframe('#test-integer-enum', '1');
    cy.testInIframe('#test-integer-enum code', (enumPreview) => {
      expect(enumPreview.textContent).to.eq('');
    });
    cy.get('@select').select(2, { force: true });
    cy.get('@select').should('have.value', '2');
    cy.waitForElementContentInIframe('#test-integer-enum', '2');
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

    cy.get(dateSelector).focus();
    cy.realType('628{uparrow}');

    cy.get(timeSelector).focus();
    cy.realType('72135');

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
    cy.findByLabelText(
      'Integer, minimum=-2147483648, maximum=2147483648',
    ).clear();
    cy.findByLabelText('Integer, minimum=-2147483648, maximum=2147483648').type(
      2147483648,
    );
    cy.waitForElementContentInIframe(
      '#test-integer-range-minimum-maximum-timestamps',
      '2147483648',
    );
    cy.findByLabelText('Integer, minimum=-2147483648, maximum=2147483648').type(
      '{uparrow}',
    );
    cy.findByLabelText(
      'Integer, minimum=-2147483648, maximum=2147483648',
    ).should('have.value', '2147483648');
    cy.waitForElementContentInIframe(
      '#test-integer-range-minimum-maximum-timestamps',
      '2147483648',
    );
  });

  it('url field', () => {
    // not sure if this is THE test yet, but it resembles it.
    const previewSelector = '#test-string-format-uri code';
    cy.waitForElementContentInIframe(
      previewSelector,
      'https://uri.example.com',
    );

    cy.findByLabelText('String, format=uri')
      .as('theInput')
      .should('have.value', 'https://uri.example.com');
    cy.get('@theInput').clear();
    cy.get('@theInput')
      .should('have.value', '')
      .then(($el) => $el[0].checkValidity())
      .should('be.true');

    cy.waitForElementContentNotInIframe(
      previewSelector,
      'https://uri.example.com',
    );

    cy.testInIframe(previewSelector, (uriPreview) => {
      expect(uriPreview.textContent.trim()).to.equal('');
    });

    cy.get('@theInput').type('start');
    cy.get('@theInput').should('have.value', 'start');

    cy.testInIframe(previewSelector, (uriPreview) => {
      expect(uriPreview.textContent.trim()).to.equal('');
    });

    cy.get('button').first().focus();
    cy.get('@theInput').should('have.attr', 'data-invalid-prop-value');
    cy.get('[data-prop-message]')
      .should('have.length', 1)
      .should('have.text', '❌ data must match format "uri"');
  });

  it('idn-email', () => {
    const previewSelector = '#test-string-format-idn-email code';
    const initialValue = 'hello@idn.example.com';
    cy.waitForElementContentInIframe(previewSelector, initialValue);

    cy.findByLabelText('String, format=idn-email')
      .as('theInput')
      .should('have.value', initialValue);
    cy.get('@theInput').clear();
    cy.get('@theInput')
      .should('have.value', '')
      .then(($el) => $el[0].checkValidity())
      .should('be.true');

    cy.waitForElementContentNotInIframe(previewSelector, initialValue);

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    cy.get('@theInput').type('not-email');
    cy.get('@theInput').should('have.value', 'not-email');

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    cy.get('button').first().focus();
    cy.get('@theInput').should('have.attr', 'data-invalid-prop-value');
    cy.get('[data-prop-message]')
      .should('have.length', 1)
      .should('have.text', '❌ data must match format "idn-email"');
  });

  it('String, format=email', () => {
    const previewSelector = '#test-string-format-email code';
    const initialValue = 'hello@example.com';
    cy.waitForElementContentInIframe(previewSelector, initialValue);

    cy.findByLabelText('String, format=email')
      .as('theInput')
      .should('have.value', initialValue);
    cy.get('@theInput').clear();
    cy.get('@theInput')
      .should('have.value', '')
      .then(($el) => $el[0].checkValidity())
      .should('be.true');

    cy.waitForElementContentNotInIframe(previewSelector, initialValue);

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    cy.get('@theInput').type('not-email');
    cy.get('@theInput').should('have.value', 'not-email');

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    cy.get('button').first().focus();
    cy.get('@theInput').should('have.attr', 'data-invalid-prop-value');
    cy.get('[data-prop-message]')
      .should('have.length', 1)
      .should('have.text', '❌ data must match format "email"');
  });

  it('String, format=uri-reference', () => {
    const previewSelector = '#test-string-format-uri-reference code';
    const initialValue = '/example-uri';
    cy.waitForElementContentInIframe(previewSelector, initialValue);

    cy.findByLabelText('String, format=uri-reference')
      .as('theInput')
      .should('have.value', initialValue);
    cy.get('@theInput').clear();
    cy.get('@theInput')
      .should('have.value', '')
      .then(($el) => $el[0].checkValidity())
      .should('be.true');

    cy.waitForElementContentNotInIframe(previewSelector, initialValue);

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    cy.get('@theInput').focus();
    cy.realType('not');
    cy.get('@theInput').should('have.value', 'not');

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    // @todo HTML5 validation works IRL but not in these tests.
    // Fortunately we are still confirming values in an invalid format does not
    // result in errors.

    cy.get('@theInput').clear();
    cy.get('@theInput').focus();
    cy.realType('/whatever');

    cy.waitForElementContentInIframe(previewSelector, '/whatever');
  });

  it('String, format=iri-reference', () => {
    const previewSelector = '#test-string-format-iri-reference code';
    const initialValue = '/example-iri';
    cy.waitForElementContentInIframe(previewSelector, initialValue);

    cy.findByLabelText('String, format=iri-reference')
      .as('theInput')
      .should('have.value', initialValue);
    cy.findByLabelText('String, format=iri-reference').clear();
    cy.findByLabelText('String, format=iri-reference')
      .should('have.value', '')
      .then(($el) => $el[0].checkValidity())
      .should('be.true');

    cy.waitForElementContentNotInIframe(previewSelector, initialValue);

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    cy.get('@theInput').focus();
    cy.realType('not');
    cy.get('@theInput').should('have.value', 'not');

    cy.testInIframe(previewSelector, (preview) => {
      expect(preview.textContent.trim()).to.equal('');
    });

    // @todo HTML5 validation works IRL but not in these tests.
    // Fortunately we are still confirming values in an invalid format does not
    // result in errors.

    cy.get('@theInput').clear();
    cy.get('@theInput').focus();
    cy.realType('/whatever');

    cy.waitForElementContentInIframe(previewSelector, '/whatever');
  });
  it('can enter number into a text field', () => {
    const iframeSelector = '#test-string';
    const labelText = 'String — single line';
    const valuePre = 'Hello, world!';
    const valuePost = '1999';
    cy.waitForElementContentInIframe(iframeSelector, valuePre);
    cy.findByLabelText(labelText).should('have.value', valuePre);
    cy.findByLabelText(labelText).clear();
    cy.findByLabelText(labelText).type(valuePost);
    cy.waitForElementContentNotInIframe(iframeSelector, valuePre);
    cy.waitForElementContentInIframe(iframeSelector, valuePost);
  });
  it('can enter just a space into a text field', () => {
    const iframeSelector = '#test-string';
    const labelText = 'String — single line';
    const valuePre = 'Hello, world!';
    const valuePost = ' ';
    cy.waitForElementContentInIframe(iframeSelector, valuePre);
    cy.findByLabelText(labelText).should('have.value', valuePre);
    cy.findByLabelText(labelText).clear();
    cy.findByLabelText(labelText).type(valuePost);
    cy.waitForElementContentNotInIframe(iframeSelector, valuePre);
    cy.waitForElementContentInIframe(iframeSelector, valuePost);
  });
});
