import { RuleTester } from 'eslint';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-content-entity-reference-props.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

const contentEntityReferenceRef =
  'json-schema-definitions://canvas.module/content-entity-reference';

testRunner.run('component-content-entity-reference-props rule', rule, {
  valid: [
    {
      name: 'should pass for optional CER props with matching expressions',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
              x-allowed-bundle: article
        dataDependencies:
          entityFields:
            article:
              - ℹ︎␜entity:node:article␝title␞␟value
              - ℹ︎␜entity:node:article␝path␞␟alias
      `,
      filename: '/components/article-card/component.yml',
    },
    {
      name: 'should not be applied to non-component yml files',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
      `,
      filename: '/components/article-card/article-card.yml',
    },
  ],
  invalid: [
    {
      name: 'should reject required CER props and examples',
      code: `
        name: Article card
        machineName: articleCard
        required:
          - article
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
              x-allowed-bundle: article
              examples:
                - Example
        dataDependencies:
          entityFields:
            article:
              - ℹ︎␜entity:node:article␝title␞␟value
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'Prop "article" is required, but content-entity-reference props must be optional.',
          line: 5,
        },
        {
          message:
            'Prop "article" is a content-entity-reference prop and must not have examples.',
          line: 13,
        },
      ],
    },
    {
      name: 'should require CER props and entityFields keys to match',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
              x-allowed-bundle: article
            title:
              title: Title
              type: string
        dataDependencies:
          entityFields:
            title:
              - ℹ︎␜entity:node:article␝title␞␟value
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'Prop "article" is a content-entity-reference prop but is missing dataDependencies.entityFields.article.',
          line: 6,
        },
        {
          message:
            'dataDependencies.entityFields.title references a prop that is not a content-entity-reference prop.',
          line: 16,
        },
      ],
    },
    {
      name: 'should require non-empty expression arrays',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
              x-allowed-bundle: article
        dataDependencies:
          entityFields:
            article: []
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'There must be >=1 entity field expression for content-entity-reference prop "article".',
          line: 13,
        },
      ],
    },
    {
      name: 'should require expression strings',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
              x-allowed-bundle: article
        dataDependencies:
          entityFields:
            article:
              - ℹ︎␜entity:node:article␝title␞␟value
              - ℹ︎␜entity:node:page␝title␞␟value
              - false
              - not-an-expression
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'dataDependencies.entityFields.article contains a non-string expression.',
          line: 16,
        },
      ],
    },
    {
      name: 'should require projected target metadata',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
        dataDependencies:
          entityFields:
            article:
              - ℹ︎␜entity:node:article␝title␞␟value
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'Prop "article" is a content-entity-reference prop but is missing x-allowed-entity-type-id.',
          line: 6,
        },
        {
          message:
            'Prop "article" is a content-entity-reference prop but is missing x-allowed-bundle.',
          line: 6,
        },
      ],
    },
    {
      name: 'should require projected bundle metadata',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
        dataDependencies:
          entityFields:
            article:
              - ℹ︎␜entity:node:article␝title␞␟value
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'Prop "article" is a content-entity-reference prop but is missing x-allowed-bundle.',
          line: 6,
        },
      ],
    },
    {
      name: 'should require entityFields when CER props exist',
      code: `
        name: Article card
        machineName: articleCard
        props:
          properties:
            article:
              title: Article
              $ref: ${contentEntityReferenceRef}
              x-allowed-entity-type-id: node
              x-allowed-bundle: article
      `,
      filename: '/components/article-card/component.yml',
      errors: [
        {
          message:
            'Prop "article" is a content-entity-reference prop but is missing dataDependencies.entityFields.article.',
          line: 6,
        },
      ],
    },
  ],
});
