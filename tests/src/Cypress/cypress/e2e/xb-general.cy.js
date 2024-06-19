import layoutDefault from "../../../../../ui/src/mocks/fixtures/layout-default.json"
import mockPreviewDocument from "../../../../../ui/src/mocks/preview.ts";

const fakeComponentsBody = [
  {
    "id": "experience_builder:image",
    "name": "Image",
    "metadata": {
      "path": "modules\/custom\/experience_builder\/components\/image",
      "documentation": "No documentation found. Add a README.md in your component directory.",
      "status": "stable",
      "machineName": "image",
      "name": "Image",
      "group": "All Components",
      "schema": {
        "type": "object",
        "required": [
          "image"
        ],
        "properties": {
          "image": {
            "title": "Image",
            "$ref": "json-schema-definitions:\/\/experience_builder.module\/image",
            "type": [
              "object"
            ]
          }
        },
        "additionalProperties": false
      },
      "description": "- Description not available -",
      "mandatorySchemas": true,
      "slots": []
    }
  },
  {
    "id": "sdc_test:my-cta",
    "name": "Call to Action",
    "metadata": {
      "path": "core\/modules\/system\/tests\/modules\/sdc_test\/components\/my-cta",
      "documentation": "No documentation found. Add a README.md in your component directory.",
      "status": "stable",
      "machineName": "my-cta",
      "name": "Call to Action",
      "group": "All Components",
      "schema": {
        "type": "object",
        "required": [
          "text"
        ],
        "properties": {
          "text": {
            "type": [
              "string",
              "object"
            ],
            "title": "Title",
            "description": "The title for the cta",
            "examples": [
              "Press",
              "Submit now"
            ]
          },
          "href": {
            "type": [
              "string",
              "object"
            ],
            "title": "URL",
            "format": "uri"
          },
          "target": {
            "type": [
              "string",
              "object"
            ],
            "title": "Target",
            "enum": [
              "",
              "_blank"
            ]
          },
          "attributes": {
            "type": [
              "Drupal\\Core\\Template\\Attribute",
              "object"
            ],
            "name": "Attributes"
          }
        },
        "additionalProperties": false
      },
      "description": "Call to action link.",
      "mandatorySchemas": true,
      "slots": []
    }
  }
];

describe('General Experience Builder', {testIsolation: false}, () => {
  before( () => {
    cy.drupalXbInstall()

    // Intercept the MSW endpoints because they're not cooperating with Cypress
    // and once there is real data that's what the e2e tests should use anyway.
    cy.intercept('*api/layout/*', {
      statusCode: 200,
      body: layoutDefault,
    })
    cy.intercept('*api/preview', (req) => {
      const {layout, model} = req.body;
      req.reply({
        statusCode: 200,
        body: {
          html: mockPreviewDocument(layout, model),
        },
      })
    })

    // @todo this intercept should not be needed since /xb-components is an
    // already working endpoint. However
    cy.intercept('*xb-components', {
      statusCode: 200,
      body: fakeComponentsBody,
    })
  });

  after(() => {
    cy.drupalUninstall()
  })

  beforeEach(() => {
    cy.drupalSession();
  });

  it('Can access XB UI', () => {
    cy.drupalLogin('xbUser', 'xbUser')

    cy.drupalRelativeURL('xb')

    cy.wait(2000)

    cy.get('[vaul-drawer-direction="left"] [data-xb-uuid="experience_builder:image"]')
      .should(($componentOption) => {
        expect($componentOption).to.have.length(1, 'The image component is available to select')
      });

    cy.getPreviewBody()
      .should((previewIframe) => {
        expect(previewIframe.querySelector('.sortable-item:first-child h1').textContent).to.equal('Component 1 (no slots)')
      });


    // Drag over a component
    cy.get('[data-xb-component-outline]').should('not.exist')
    cy.getPreviewBody().find('.sortable-item:first-child h1')
      .first()
      .trigger('mouseover')
    cy.get('[data-xb-component-outline]').should('exist')

    // Open the right drawer
    cy.get('[vaul-drawer-direction="right"]').should('not.exist')
    cy.getPreviewBody().find('.sortable-item:nth-child(2)')
      .first()
      .trigger('click')
    cy.get('[vaul-drawer-direction="right"]').should('exist')

  })
})
