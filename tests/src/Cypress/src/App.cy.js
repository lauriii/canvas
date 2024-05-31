import App from './App';

describe('LoginForm', () => {
  it('should redirect to welcome screen when credentials are correct', () => {
    cy.mount(<App />);
    cy.contains('Username').find('input').type('testUser');
    cy.contains('Password').find('input').type('testPassword');
    cy.intercept('POST', '/auth', {
      statusCode: 200,
      body: {
        message: 'Authenticated',
      },
    });
    cy.get('button').contains('Login').as('loginButton').click();
    cy.contains('Welcome testUser!');
  });

  it('should show error message when credentials are incorrect', () => {
    cy.mount(<App />);
    cy.contains('Username').find('input').type('badUser');
    cy.contains('Password').find('input').type('badPassword');
    cy.intercept('POST', '/auth', {
      statusCode: 401,
      body: {
        message: 'Bad username or password',
      },
    });
    cy.get('button').contains('Login').as('loginButton').click();
    cy.contains('Bad username or password');
  });
});
