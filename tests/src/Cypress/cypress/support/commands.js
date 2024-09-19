import '@testing-library/cypress/add-commands';
import { realDnd } from './realDnd'

// This selector gets the preview iframe ensuring that it is initialized and that it is the currently active/swapped in element.
const initializedReadyPreviewIframeSelector = '[data-xb-preview="lg"][data-test-xb-content-initialized="true"][data-xb-swap-active="true"]'

const commandAsWebserver = (command) => {
  if (Cypress.env('testWebserverUser')) {
    return `sudo -u ${Cypress.env('testWebserverUser')} ${command}`;
  }
  return command;
};

Cypress.Commands.add(
  'drupalCreateUser',
  ({ name, password, permissions = [] }, callback) => {
    const roleName = Math.random()
      .toString(36)
      .replace(/[^\w\d]/g, '')
      .substring(2, 15);
    if (permissions.length) {
      cy.drupalCreateRole({ permissions, name: roleName });
    }
    cy.drupalLoginAsAdmin(() => {
      cy.drupalRelativeURL('/admin/people/create');
      cy.get('input[name="name"]').type(name);
      cy.get('input[name="pass[pass1]"]').type(password);
      cy.get('input[name="pass[pass2]"]').type(password);
      if (permissions.length) {
        cy.get(`input[name="roles[${roleName}]`).click();
      }
      cy.get('#user-register-form').submit();
      cy.get('[data-drupal-messages]').should(($message) => {
        expect($message.text()).to.contain(
          'Created a new user account',
          `User "${name}" was created successfully.`,
        );
      });
      if (typeof callback === 'function') {
        callback.call(this);
      }
    });
  },
);

Cypress.Commands.add(
  'drupalCreateRole',
  ({ permissions, name = null }, callback) => {
    const roleName = name || Math.random().toString(36).substring(2, 15);
    cy.drupalLoginAsAdmin(async () => {
      cy.drupalRelativeURL('/admin/people/roles/add');
      cy.get('input[name="label"]').type(roleName);
      Cypress.$('input[name="label"]').trigger('formUpdated');
      cy.get('.user-role-form .machine-name-value').should('be.visible');
      let theMachineName = '';
      cy.contains('.user-role-form .machine-name-value', /^[a-z0-9_]/, {
        timeout: 5000,
      })
        .invoke('text')
        .then((machineName) => {
          theMachineName = machineName;
          cy.get('form').submit('#user-role_form');

          cy.drupalRelativeURL('/admin/people/permissions');
          permissions.forEach((permission) => {
            cy.get(`input[name="${theMachineName}[${permission}]"]`).click();
          });
          cy.get('form').submit('#user-admin-permissions');
          cy.drupalRelativeURL('/admin/people/permissions');
          if (typeof callback === 'function') {
            callback.call(self, machineName);
          }
        });
    });
  },
);

Cypress.Commands.add(
  'drupalEnableTheme',
  (themeMachineName, adminTheme = false) => {
    cy.drupalLoginAsAdmin(() => {
      const path = adminTheme
        ? '/admin/theme/install_admin/'
        : '/admin/theme/install_default/';
      cy.drupalRelativeURL(`${path}${themeMachineName}`);
      cy.get('#theme-installed').should('exist');
    });
  },
);

Cypress.Commands.add('drupalXbInstall', () => {
  cy.task('log', `The setup file ${Cypress.env('setupFile')}`);
  cy.drupalInstall({
    setupFile: Cypress.env('setupFile'),
  });
});

Cypress.Commands.add(
  'drupalInstall',
  (
    {
      setupFile = '',
      installProfile = 'nightwatch_testing',
      langcode = '',
    } = {},
    callback,
  ) => {
    cy.clearCookies();
    try {
      setupFile = setupFile ? `--setup-file "${setupFile}"` : '';
      installProfile = `--install-profile "${installProfile}"`;
      const langcodeOption = langcode ? `--langcode "${langcode}"` : '';
      const dbOption = Cypress.env('dbUrl')
        ? `--db-url ${Cypress.env('dbUrl')}`
        : '';

      const installCommand = commandAsWebserver(
        `php ${Cypress.env('coreDir')}/scripts/test-site.php install ${setupFile} ${installProfile} ${langcodeOption} --base-url ${Cypress.env('baseUrl')} ${dbOption} --json`,
      );
      cy.exec(installCommand).then((install) => {
        const installData = JSON.parse(install.stdout);
        const url = new URL(Cypress.env('baseUrl'));
        Cypress.env('drupalDbPrefix', installData.db_prefix);
        Cypress.env('drupalSitePath', installData.site_path);
        Cypress.env('userAgent', installData.user_agent);
        Cypress.env('host', url.host);
        cy.visit('/', { failOnStatusCode: false }).then(() => {
          cy.drupalSession();
        });
      });
    } catch (error) {
      cy.task('log', `Failed Installing Drupal ${error}`);
    }
  },
);

Cypress.Commands.add('drupalInstallModule', (module, force, callback) => {
  cy.drupalLoginAsAdmin(() => {
    cy.drupalRelativeURL('/admin/modules');

    // Open any collapsed sections in the modules page.
    cy.get('[data-drupal-selector="system-modules"] > details > summary[aria-expanded="false"][aria-controls^="edit-modules"]')
      .then(($closedDetails) => {
        $closedDetails.each((index, closed) => {
          Cypress.$(closed).click();
        })
      })

    cy.get(`form.system-modules [name="modules[${module}][enable]"]`).check();
    cy.get('form.system-modules').submit();
    if (force) {
      cy.get('body').then(($body) => {
        if ($body.find('#system-modules-confirm-form')) {
          cy.get('#system-modules-confirm-form').submit();
        }
      });
    }
    cy.drupalRelativeURL('/admin/modules');
    cy.get(`form.system-modules [name="modules[${module}][enable]"]`).should(
      ($checkbox) => {
        expect($checkbox.is(':checked'), `The ${module} module is installed`).to
          .be.true;
        expect(
          $checkbox.is(':disabled'),
          `The ${module} install checkbox can not be unchecked`,
        ).to.be.true;
      },
    );
  });
});

Cypress.Commands.add('drupalLogAndEnd', ({ onlyOnError = true }, callback) => {
  console.log(
    'Not sure this is even needed as cypress logs differently but who knows',
  );
  if (typeof callback === 'function') {
    callback.call(this);
  }
});

Cypress.Commands.add('drupalLogin', (name, password) => {
  cy.drupalUserIsLoggedIn((sessionExists) => {
    // Log the current user out if necessary.
    if (sessionExists) {
      cy.drupalLogout();
    }
    cy.session(
      [name, password],
      () => {
        cy.drupalSession();
        cy.drupalRelativeURL('/user/login');
        cy.get('input[name="name"]').type(name);
        cy.get('input[name="pass"]').type(password);
        cy.get('#user-login-form').submit();
        cy.get('h1').contains(name);
      },
      {
        validate() {
          cy.request('/')
            .its('body')
            .then((body) => {
              // @todo👇Is there a better way to validate that someone is logged in.
              cy.expect(body).to.contain(name);
            });
        },
      },
    );
  });
});

Cypress.Commands.add('drupalLoginAsAdmin', (callback) => {
  cy.drupalUserIsLoggedIn((sessionExists) => {
    if (sessionExists) {
      cy.drupalLogout();
    }
    const execCommand = commandAsWebserver(
      `php ${Cypress.env('coreDir')}/scripts/test-site.php user-login 1 --site-path ${Cypress.env('drupalSitePath')}`,
    );
    cy.exec(execCommand).then((userLink) => {
      cy.drupalRelativeURL(userLink.stdout);
      cy.drupalUserIsLoggedIn((sessionExists) => {
        if (!sessionExists) {
          throw new Error('Logging in as an admin user failed.');
        }
      });
    });
    if (typeof callback === 'function') {
      callback.call(this);
    }
    cy.drupalLogout({ silent: true });
  });
});

Cypress.Commands.add('drupalLogout', ({ silent = false } = {}, callback) => {
  cy.getAllCookies().then((result) => {
    const stringResult = JSON.stringify(result);
    result.forEach((cookie) => {
      if (cookie.name.match(/^S?SESS/)) {
        cy.clearCookie(cookie.name);
      }
    });
  });

  cy.drupalUserIsLoggedIn((sessionExists) => {
    if (silent) {
      if (sessionExists || sessionExists !== false) {
        throw new Error('Logging out failed.');
      }
    } else {
      expect(sessionExists).to.be.false;
    }
  });

  if (typeof callback === 'function') {
    callback.call(this);
  }
});

Cypress.Commands.add('drupalRelativeURL', (pathname, callback) => {
  cy.visit(`${pathname}`, { failOnStatusCode: false });
  if (typeof callback === 'function') {
    callback.call(this);
  }
});

Cypress.Commands.add('drupalUninstall', (callback) => {
  const prefix = Cypress.env('drupalDbPrefix');

  const dbOption = Cypress.env('dbUrl')
    ? `--db-url ${Cypress.env('dbUrl')}`
    : '';
  try {
    if (!prefix || !prefix.length) {
      throw new Error(
        'Missing database prefix parameter, unable to uninstall Drupal (the initial install was probably unsuccessful).',
      );
    }

    const tearDownCommand = commandAsWebserver(
      `php ${Cypress.env('coreDir')}/scripts/test-site.php tear-down ${prefix} ${dbOption}`,
    );
    cy.exec(tearDownCommand).then(() => {
      if (typeof callback === 'function') {
        callback.call(self);
      }
    });
  } catch (error) {
    throw new Error(error);
  }
});

Cypress.Commands.add('drupalUserIsLoggedIn', (callback) => {
  if (typeof callback === 'function') {
    cy.getCookies().then((cookies) => {
      const sessionExists = cookies.some((cookie) =>
        cookie.name.match(/^S?SESS/),
      );
      callback.call(this, sessionExists);
    });
  }
});

Cypress.Commands.add('drupalSession', () => {
  cy.visit('/', { failOnStatusCode: false }).then(() => {
    // With this cookie set, visits to the test site will be directed to a
    // version of the site running a test database.
    cy.setCookie(
      'SIMPLETEST_USER_AGENT',
      encodeURIComponent(Cypress.env('userAgent')),
      { domain: Cypress.env('host'), path: '/' },
    );
  });
});


/**
 * Ensures that the preview iframe is initialized and has content before continuing. Can be called
 * after performing an action that refreshes the iFrame to ensure subsequent actions wait for the new
 * content to have loaded.
 *
 * @param {string} selector
 *   The selector of the iframe to get.
 */
Cypress.Commands.add(
  'previewReady',
  (iframeSelector = initializedReadyPreviewIframeSelector) => {
    // Not logging these assertions to try and keep the command log a bit tidier
    cy.get('.previewsContainer', {log: false}).should('have.css', 'opacity', '1');
    cy.get(iframeSelector, {log: false, timeout: 10000}).as('iframe')
    cy.get(iframeSelector, {log: false}).its('0.contentDocument', {log: false})
    cy.log(`Preview '${iframeSelector}' initialized and has content document.`);
    return cy.get('@iframe')
  },
);

/**
 * Gets an iframe element once its content has loaded.
 *
 * @param {string} selector
 *   The selector of the iframe to get.
 *
 * @return
 *   The Cypress-wrapped iframe.
 */
Cypress.Commands.add('getIframe', (selector) => {
  return cy.get(selector).its('0.contentDocument').should('exist');
});

/**
 * Gets the body content of an iframe
 *
 * @param {string} selector
 *   The selector of the iframe to get
 *
 * @return {object}
 *  The Cypress-wrapped iframe body.
 */
Cypress.Commands.add('getIframeBody', (selector = initializedReadyPreviewIframeSelector) => {
  return cy
    .getIframe(selector)
    .its('body')
    .should('not.be.undefined')
    .then(cy.wrap);
});

/**
 * Waits for element matching a selector to be present in an iframe.
 *
 * @param {string} selector
 *   The selector of what to wait on in the iframe.
 * @param {string} iframeSelector
 *   The selector of the iframe to check inside. Defaults to the first preview.
 * @param {number|null} customTimeout
 *   Optional: If the time to wait for the element should differ from the
 *   Cypress retry default duration.
 */
Cypress.Commands.add(
  'waitForElementInIframe',
  (selector, iframeSelector = initializedReadyPreviewIframeSelector, customTimeout) => {
    cy.document().then((doc) => {
      cy.get(true, {
        timeout: customTimeout || Cypress.config('defaultCommandTimeout'),
      }).should(() => {
        const frameContent = doc
          .querySelector(iframeSelector)
          ?.contentWindow?.document?.body.querySelector(selector);
        expect(
          !!frameContent,
          `'${selector}' was found in iframe '${iframeSelector}'`,
        ).to.equal(true);
      });
    });
  },
);

Cypress.Commands.add(
  'waitForElementContentInIframe',
  (selector, textContent, iframeSelector = initializedReadyPreviewIframeSelector, customTimeout) => {
    cy.document().then((doc) => {
      cy.get(true, {
        timeout: customTimeout || Cypress.config('defaultCommandTimeout'),
      }).should(() => {
        const frameContent = doc
          .querySelector(iframeSelector)
          ?.contentWindow?.document?.body.querySelector(selector);
        expect(
          !!frameContent,
          `'${selector}' was found in iframe '${iframeSelector}'`,
        ).to.equal(true);
        expect(frameContent?.textContent?.includes(textContent), `${iframeSelector} in iframe includes text ${textContent}`).to.equal(true)
      });
    });
  },
);

Cypress.Commands.add(
  'waitForElementContentNotInIframe',
  (selector, textContent, iframeSelector = initializedReadyPreviewIframeSelector, customTimeout) => {
    cy.document().then((doc) => {
      cy.get(true, {
        timeout: customTimeout || Cypress.config('defaultCommandTimeout'),
      }).should(() => {
        const frameContent = doc
          .querySelector(iframeSelector)
          ?.contentWindow?.document?.body.querySelector(selector);
        expect(
          !!frameContent,
          `'${selector}' was found in iframe '${iframeSelector}'`,
        ).to.equal(true);
        expect(!!(!!frameContent && !frameContent.textContent.includes(textContent)), `${iframeSelector} in iframe should no longer include text ${textContent}, but there is ${frameContent?.textContent}`).to.equal(true)
      });
    });
  },
);

/**
 * Gets element(s) matching a selector within an iframe and sends to a callback.
 * @example
 * ```javascript
 * cy.testInIframe('#some-id', (result) => {
 *   expect(result.text).to.equal('Hello World')
 * });
 * ```
 *
 * @param {string} selector
 *   The selector of what to query in the iframe.
 * @param {function} callback
 *   User supplied callback that receives the `selector` result as the argument.
 * @param {string} iframeSelector
 *   The selector of the iframe. Defaults to the first preview iframe.
 */
Cypress.Commands.add(
  'testInIframe',
  (selector, callback, iframeSelector = initializedReadyPreviewIframeSelector) => {
    cy.getIframeBody(iframeSelector).should((previewIframe) => {
      const queryResult = previewIframe.querySelectorAll(selector);
      let callbackArg = queryResult;
      if (queryResult.length === 1) {
        callbackArg = queryResult[0];
      } else if (queryResult.length === 0) {
        callbackArg = null;
      }

      callback(callbackArg, previewIframe);
    });
  },
);

Cypress.Commands.add('clickAddMenu', () => {
  cy.get('.TopbarRoot').find('#add-menu-button').click();
});

/**
 * Sets the value of input[type="range"] that is controlled by React in a way that ensures that React is notified of the change.
 * using .val(101).trigger('change') or .trigger('input') does not seem to work. https://github.com/cypress-io/cypress/issues/1570
 * @example
 * ```javascript
 *    cy.findByLabelText('Canvas zoom level').setRangeValue('101');
 * ```
*/
Cypress.Commands.add(
  'setRangeValue',
  { prevSubject: 'element' },
  (subject, value) => {
    const range = subject[0];
    const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
      window.HTMLInputElement.prototype,
      'value',
    ).set;
    nativeInputValueSetter.call(range, value);
    const event = new Event('input', { bubbles: true });
    range.dispatchEvent(event);
    return cy.wrap(subject); // Ensure the command is chainable
  },
);

// Simulates the user using the mousewheel while holding the Control key
Cypress.Commands.add(
  'triggerMouseWheelWithCtrl',
  { prevSubject: 'element' },
  (subject, deltaY) => {
    const event = new WheelEvent('wheel', {
      deltaY: deltaY,
      ctrlKey: true,
      bubbles: true,
      cancelable: true,
      view: window,
    });
    subject[0].dispatchEvent(event);
    return cy.wrap(subject); // Ensure the command is chainable
  },
);

/**
 * Loads the XB page and waits to ensure initial backend requests have been returned and that the preview
 * iFrame is initialized and ready to be interacted with.
 *
 * @param {string} url
 *   The url you want to visit - defaults to /xb/node/1.
*/
Cypress.Commands.add('loadURLandWaitForXBLoaded', (url = 'xb/node/1') => {
  cy.drupalRelativeURL(url);

  cy.previewReady();
});

// Helper function used by the realDnd command.
Cypress.Commands.add("realDndRaw", realDnd);

/**
 * Drag and drop an element.
 *
 *  @param {string} subject
 *  The selector of the item to drag.
 *  @param {string} destination
 *  The selector of where to drop the item.
 *  @param {object} opts
 *  Options for the drag and drop.
 *
 *  @see https://github.com/dmtrKovalenko/cypress-real-events/pull/17 */
Cypress.Commands.add(
  "realDnd",
  { prevSubject: true },
  (subject, destination, opts) => {
    if (typeof destination === "string") {
      cy.get(destination).then((el) => {
        cy.realDndRaw(subject, el, opts);
      });
    } else {
      cy.realDndRaw(subject, destination, opts);
    }
  }
);
