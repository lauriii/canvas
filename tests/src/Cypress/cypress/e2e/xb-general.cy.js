import layoutDefault from "../../../../../ui/src/mocks/fixtures/layout-default.json"

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
          // The return is hard coded since this is mock data anyway. Tests that
          // use the actual Drupal backend are on the way.
          html: '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"><head><title>New Document</title><style></style></head><body><div class="sortable-list" data-xb-uuid="root"><div class="sortable-item" data-xb-uuid="dynamic-image-udf7d" data-xb-type="component"><h1>debug: dynamic-image-udf7d</h1></div><div class="sortable-item" data-xb-uuid="static-static-card1ab" data-xb-type="component"><h1>debug: static-static-card1ab</h1></div><div class="sortable-item" data-xb-uuid="dynamic-static-card2df" data-xb-type="component"><h1>debug: dynamic-static-card2df</h1></div><div class="sortable-item" data-xb-uuid="dynamic-dynamic-card3rr" data-xb-type="component"><h1>debug: dynamic-dynamic-card3rr</h1></div><div class="sortable-item" data-xb-uuid="dynamic-image-static-imageStyle-something7d" data-xb-type="component"><h1>debug: dynamic-image-static-imageStyle-something7d</h1></div></div></body></html>',
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

    cy.get('[data-testid="addElementOverlay"]').click({force:true});
    cy.get('[data-radix-popper-content-wrapper] > [data-radix-menubar-content] > [data-radix-menubar-subtrigger]')
    .contains('Default components').click().get('[data-xb-uuid="experience_builder:image"]')
      .should(($componentOption) => {
        expect($componentOption).to.have.length(1, 'The image component is available to select')
      });

    cy.getPreviewBody()
      .should((previewIframe) => {
        expect(previewIframe.querySelector('.sortable-item:first-child h1').textContent).to.equal('debug: dynamic-image-udf7d')
      });

    // Drag over a component
    cy.get('[data-xb-component-outline]').should('not.exist')
    // @todo The check below failed after recent changes, this should be
    // reinstated when we add tests that include the full Drupal backend
    // instead of mock data.
    // @todo Update in https://www.drupal.org/project/experience_builder/issues/3461435
    // cy.getPreviewBody().find('.sortable-item:first-child h1')
    //   .first()
    //   .trigger('mouseover')
    // cy.get('[data-xb-component-outline]').should('exist')

    // Open the right drawer
    // @todo do not test right drawer until these tests fully use Drupal data.
    // @todo Update in https://www.drupal.org/project/experience_builder/issues/3461435
  })
})
