Cypress.Commands.add('drupalCreateUser', (
  { name, password, permissions = [] },
  callback,
) => {
  const roleName = Math.random()
    .toString(36)
    .replace(/[^\w\d]/g, '')
    .substring(2, 15);
  if (permissions.length) {
    cy.drupalCreateRole({ permissions, name: roleName })
  }
  cy.drupalLoginAsAdmin(() => {
    cy.drupalRelativeURL('/admin/people/create')
    cy.get('input[name="name"]').type(name)
    cy.get('input[name="pass[pass1]"]').type(password)
    cy.get('input[name="pass[pass2]"]').type(password)
    if (permissions.length) {
      cy.get(`input[name="roles[${roleName}]`).click()
    }
    cy.get('#user-register-form').submit();
    cy.get('[data-drupal-messages]').should($message => {
      expect($message.text()).to.contain(
        'Created a new user account',
        `User "${name}" was created successfully.`,
      )
    })
    if (typeof callback === 'function') {
      callback.call(this);
    }
  })
})

Cypress.Commands.add('drupalCreateRole', (
  { permissions, name = null },
  callback,
) => {
  const roleName = name || Math.random().toString(36).substring(2, 15);
  cy.drupalLoginAsAdmin(async () => {
    cy.drupalRelativeURL('/admin/people/roles/add');
    cy.get('input[name="label"]').type(roleName);
    Cypress.$('input[name="label"]').trigger('formUpdated');
    cy.get('.user-role-form .machine-name-value').should('be.visible');
    let theMachineName = ''
    cy.contains('.user-role-form .machine-name-value', /^[a-z0-9_]/, { timeout: 5000 })
      .invoke('text')
      .then(machineName => {
        theMachineName = machineName
        cy.get('form').submit('#user-role_form');

        cy.drupalRelativeURL('/admin/people/permissions');
        permissions.forEach(permission => {
          cy.get(`input[name="${theMachineName}[${permission}]"]`).click();
        })
        cy.get('form').submit('#user-admin-permissions');
        cy.drupalRelativeURL('/admin/people/permissions');
        if (typeof callback === 'function') {
          callback.call(self, machineName);
        }
      })
  })
})

Cypress.Commands.add('drupalEnableTheme', (
  themeMachineName,
  adminTheme = false,
) => {
  cy.drupalLoginAsAdmin(() => {
    const path = adminTheme
      ? '/admin/theme/install_admin/'
      : '/admin/theme/install_default/';
    cy.drupalRelativeURL(`${path}${themeMachineName}`);
    cy.get('#theme-installed').should('exist');
  });
})

Cypress.Commands.add('drupalInstall', (
  { setupFile = '', installProfile = 'nightwatch_testing', langcode = '' } = {},
  callback,
) => {
  cy.clearCookies();

  try {
    setupFile = setupFile ? `--setup-file "${setupFile}"` : '';
    installProfile = `--install-profile "${installProfile}"`;
    const langcodeOption = langcode ? `--langcode "${langcode}"` : '';
    const dbOption =
      Cypress.env('dbUrl')
        ? `--db-url ${Cypress.env('dbUrl')}`
        : '';
    const installCommand = `php ../../../../../../core/scripts/test-site.php install ${setupFile} ${installProfile} ${langcodeOption} --base-url ${Cypress.env('baseUrl')} ${dbOption} --json`;
    cy.exec(installCommand).then(install => {
      const installData = JSON.parse(install.stdout);
      const url = new URL(Cypress.env('baseUrl'));
      Cypress.env('drupalDbPrefix', installData.db_prefix);
      Cypress.env('drupalSitePath', installData.site_path);
      Cypress.env('userAgent', installData.user_agent)
      Cypress.env('host', url.host)
    });
  } catch (error) {
    cy.log('failed', error)
  }
})

Cypress.Commands.add('drupalInstallModule', (module, force, callback) => {
  console.log('in DRUPAL install cmodule');
  cy.drupalLoginAsAdmin(() => {
    cy.drupalRelativeURL('/admin/modules');
    cy.get(`form.system-modules [name="modules[${module}][enable]"]`).check();
    cy.get('form.system-modules').submit();
    if (force) {
      cy.get('body').then(($body) => {
        if ($body.find('#system-modules-confirm-form')) {
          cy.get('#system-modules-confirm-form').submit()
        }
      })
    }
    cy.drupalRelativeURL('/admin/modules');

    cy.get(`form.system-modules [name="modules[${module}][enable]"]`).should(($checkbox) => {
      console.log(`The ${module} module is installed`)
      expect($checkbox.is(':checked'), `The ${module} module is installed`).to.be.true;
      expect($checkbox.is(':disabled'), `The ${module} install checkbox can not be unchecked`).to.be.true;

    })
  })
})

Cypress.Commands.add('drupalLogAndEnd', ({ onlyOnError = true }, callback) => {
  console.log('not sure this is even needed as cypress logs differently but who knows')
  if (typeof callback === 'function') {
    callback.call(this);
  }
})


Cypress.Commands.add('drupalLogin', (name, password) => {
  cy.drupalUserIsLoggedIn((sessionExists) => {
    // Log the current user out if necessary.
    if (sessionExists) {
      cy.drupalLogout();
    }

    cy.drupalRelativeURL('/user/login');
    cy.get('input[name="name"]').type(name);
    cy.get('input[name="pass"]').type(password);
    cy.get('#user-login-form').submit()
  })
})

Cypress.Commands.add('drupalLoginAsAdmin', (callback) => {
  cy.drupalUserIsLoggedIn((sessionExists) => {
    if (sessionExists) {
      cy.drupalLogout();
    }
    const execCommand = `php ../../../../../../core/scripts/test-site.php user-login 1 --site-path ${Cypress.env('drupalSitePath')}`;
    cy.exec(execCommand).then((userLink)=> {
      cy.drupalRelativeURL(userLink.stdout)
      cy.drupalUserIsLoggedIn((sessionExists) => {
        if (!sessionExists) {
          throw new Error('Logging in as an admin user failed.');
        }
      });
    })
    if (typeof callback === 'function') {
      callback.call(this);
    }
    cy.drupalLogout({ silent: true });
  })
})

Cypress.Commands.add('drupalLogout', ({ silent = false } = {}, callback) => {
  cy.drupalRelativeURL('/user/logout');

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
})

Cypress.Commands.add('drupalRelativeURL', (pathname, callback) => {
  cy.visit(`${Cypress.env('baseUrl')}${pathname}`);
  if (typeof callback === 'function') {
    callback.call(this);
  }
})

Cypress.Commands.add('drupalUninstall', (callback) => {
  const prefix = Cypress.env('drupalDbPrefix');

    const dbOption =
    Cypress.env('dbUrl').length > 0  ? `--db-url ${Cypress.env('dbUrl')}` : '';
    try {
      if (!prefix || !prefix.length) {
        throw new Error('Missing database prefix parameter, unable to uninstall Drupal (the initial install was probably unsuccessful).');
      }

      const tearDownCommand = `php ../../../../../../core/scripts/test-site.php tear-down ${prefix} ${dbOption}`;
      cy.exec(tearDownCommand).then(() => {
        if (typeof callback === 'function') {
          callback.call(self);
        }
      })
    } catch (error) {
      throw new Error(error);
    }

})

Cypress.Commands.add('drupalUserIsLoggedIn', (callback) => {
  if (typeof callback === 'function') {
    cy.getCookies().then((cookies) => {
      const sessionExists = cookies.some((cookie) => cookie.name.match(/^S?SESS/))
      callback.call(this, sessionExists);
    })
  }
})


