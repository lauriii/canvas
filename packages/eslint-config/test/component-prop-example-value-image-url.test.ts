import { RuleTester } from 'eslint';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-prop-example-value-image-url.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

testRunner.run('component-prop-example-value-image-url rule', rule, {
  valid: [
    {
      name: 'should pass when image example src is a fully-qualified URL',
      code: `
        name: Hero
        machineName: hero
        props:
          properties:
            image:
              type: object
              $ref: json-schema-definitions://canvas.module/image
              title: Image
              examples:
                - src: https://example.com/hero.jpg
                  alt: Woman playing the violin
      `,
      filename: '/components/hero/component.yml',
    },
    {
      name: 'should pass when every array item image example src is a fully-qualified URL',
      code: `
        name: Gallery
        machineName: gallery
        props:
          properties:
            images:
              type: array
              items:
                type: object
                $ref: json-schema-definitions://canvas.module/image
              examples:
                - - src: https://example.com/cat.jpg
                    alt: Cat
                  - src: https://example.com/dog.jpg
                    alt: Dog
      `,
      filename: '/components/gallery/component.yml',
    },
    {
      name: 'should not apply to non-image props',
      code: `
        name: Hero
        machineName: hero
        props:
          properties:
            buttonLink:
              type: string
              format: uri-reference
              title: Button link
              examples:
                - /page/1
      `,
      filename: '/components/hero/component.yml',
    },
    {
      name: 'should not be applied to non-component yml files',
      code: `
        name: Hero
        machineName: hero
        props:
          properties:
            image:
              type: object
              $ref: json-schema-definitions://canvas.module/image
              title: Image
              examples:
                - src: ./hero.jpg
      `,
      filename: '/components/hero/hero.yml',
    },
  ],
  invalid: [
    {
      name: 'should fail when image example src is relative',
      code: `
        name: Hero
        machineName: hero
        props:
          properties:
            image:
              type: object
              $ref: json-schema-definitions://canvas.module/image
              title: Image
              examples:
                - src: ./hero.jps
                  alt: Woman playing the violin
      `,
      filename: '/components/hero/component.yml',
      errors: [
        {
          message:
            'Image prop "image" example src must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
          line: 11,
        },
      ],
    },
    {
      name: 'should fail when image example src is root-relative',
      code: `
        name: Hero
        machineName: hero
        props:
          properties:
            image:
              type: object
              $ref: json-schema-definitions://canvas.module/image
              title: Image
              examples:
                - src: /sites/default/files/hero.jpg
      `,
      filename: '/components/hero/component.yml',
      errors: [
        {
          message:
            'Image prop "image" example src must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
          line: 11,
        },
      ],
    },
    {
      name: 'should fail when an image array example contains a relative src',
      code: `
        name: Gallery
        machineName: gallery
        props:
          properties:
            images:
              type: array
              items:
                type: object
                $ref: json-schema-definitions://canvas.module/image
              examples:
                - - src: https://example.com/cat.jpg
                    alt: Cat
                  - src: gracie.jpg
                    alt: Dog
      `,
      filename: '/components/gallery/component.yml',
      errors: [
        {
          message:
            'Image prop "images" example src must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.',
          line: 14,
        },
      ],
    },
  ],
});
