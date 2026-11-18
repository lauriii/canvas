// @vitest-environment jsdom
import '@angular/compiler';

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { Component, Input } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  BrowserTestingModule,
  platformBrowserTesting,
} from '@angular/platform-browser/testing';

import type { CanvasComponentTree as CanvasComponentTreeDocument } from '@drupal-canvas/json-render';
import type * as CanvasRenderer from '../src/index';

const root = `${process.cwd()}/`;
let renderer: typeof CanvasRenderer;
let Card: typeof CardFixture;

class CardFixture {
  title = '';
}

beforeAll(async () => {
  // Reuse CI's build output instead of deleting files used by parallel tests.
  if (
    !existsSync(resolve(root, 'dist/package.json')) ||
    !existsSync(
      resolve(root, 'dist/fesm2022/drupal-canvas-json-render-angular.mjs'),
    )
  ) {
    execFileSync('npm', ['run', 'build'], { cwd: root, stdio: 'pipe' });
  }
  // Exercise the published entry point through Angular's JIT linker.
  const manifest = JSON.parse(
    readFileSync(resolve(root, 'dist/package.json'), 'utf8'),
  );
  const entryPoint = resolve(root, 'dist', manifest.exports['.'].default);
  renderer = await import(/* @vite-ignore */ entryPoint);
  Input()(CardFixture.prototype, 'title');
  Component({
    selector: 'test-card',
    imports: [renderer.CanvasSlot],
    template: `<article><h2>{{ title }}</h2><canvas-slot /><aside><canvas-slot name="sidebar" /></aside><footer><canvas-slot name="empty" /></footer></article>`,
  })(CardFixture);
  Card = CardFixture;
  TestBed.initTestEnvironment(BrowserTestingModule, platformBrowserTesting());
}, 30_000);

afterEach(() => TestBed.resetTestingModule());

const tree: CanvasComponentTreeDocument = {
  root: 'card-one',
  elements: {
    'card-one': {
      type: 'js.card',
      props: { title: 'Title' },
      children: ['main'],
      slots: { sidebar: ['side'], empty: ['placeholder'] },
    },
    main: {
      type: 'drupal-markup',
      props: { markup: '<p>Main content</p>' },
      children: [],
    },
    side: {
      type: 'drupal-markup',
      props: { markup: '<p>Side content</p>' },
      children: [],
    },
    placeholder: {
      type: 'canvas-slot-placeholder',
      props: {},
      children: [],
    },
  },
};

function render(document: CanvasComponentTreeDocument | null, preview = false) {
  TestBed.configureTestingModule({ imports: [renderer.CanvasComponentTree] });
  const fixture = TestBed.createComponent(renderer.CanvasComponentTree);
  fixture.componentRef.setInput('tree', document);
  fixture.componentRef.setInput('components', {
    card: Card,
    'canvas-marker': Card,
    'canvas-preview-content-region': Card,
    'canvas-slot-placeholder': Card,
    'drupal-markup': Card,
  });
  fixture.componentRef.setInput('preview', preview);
  fixture.detectChanges();
  return fixture;
}

describe('built output', () => {
  it('ships partial declarations for consumer Angular linking', () => {
    const output = readFileSync(
      resolve(root, 'dist/fesm2022/drupal-canvas-json-render-angular.mjs'),
      'utf8',
    );
    expect(output).toContain('ɵɵngDeclareComponent');
    expect(output).not.toContain('ɵɵdefineComponent');
    const manifest = JSON.parse(
      readFileSync(resolve(root, 'dist/package.json'), 'utf8'),
    );
    expect(existsSync(resolve(root, 'dist', manifest.exports['.'].types))).toBe(
      true,
    );
    expect(manifest.dependencies).not.toHaveProperty('@drupal-canvas/headless');
    expect(manifest.peerDependencies).not.toHaveProperty('@angular/router');
  });

  it('renders canonical component types, default children, and named slot references', () => {
    const fixture = render(tree);
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('h2')?.textContent).toBe('Title');
    expect(element.querySelector('article > canvas-slot')?.textContent).toBe(
      'Main content',
    );
    expect(element.querySelector('aside')?.textContent).toBe('Side content');
    expect(element.querySelector('footer')?.textContent).toBe('');
    expect(element.querySelector('[data-canvas-marker]')).toBeNull();
    expect(element.querySelector('.canvas--slot-empty-placeholder')).toBeNull();
  });

  it('renders component, region, and declared empty slot boundaries only in preview', () => {
    const fixture = render(tree, true);
    const element = fixture.nativeElement as HTMLElement;
    expect(
      element.querySelectorAll('[data-canvas-type="region"]'),
    ).toHaveLength(2);
    expect(
      element.querySelectorAll(
        '[data-canvas-type="component"][data-canvas-uuid="card-one"]',
      ),
    ).toHaveLength(2);
    expect(
      element.querySelectorAll(
        '[data-canvas-type="slot"][data-canvas-slot-name="empty"]',
      ),
    ).toHaveLength(2);
    expect(
      element.querySelector('.canvas--slot-empty-placeholder'),
    ).not.toBeNull();
    expect(element.querySelector('canvas-slot-placeholder')).toBeNull();

    fixture.componentRef.setInput('preview', false);
    fixture.detectChanges();
    expect(element.querySelector('[data-canvas-marker]')).toBeNull();
    expect(element.querySelector('.canvas--slot-empty-placeholder')).toBeNull();
  });

  it('renders intrinsic markers and trusted markup in a preview content region', () => {
    const fixture = render(
      {
        root: 'region',
        elements: {
          region: {
            type: 'canvas-preview-content-region',
            props: {},
            children: ['marker-one'],
          },
          'marker-one': {
            type: 'canvas-marker',
            props: { markerId: 'page_content' },
            children: ['markup'],
          },
          markup: {
            type: 'drupal-markup',
            props: { markup: '<p>Page content</p>' },
            children: [],
          },
        },
      },
      true,
    );
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toBe('Page content');
    expect(
      element.querySelectorAll('[data-canvas-type="region"]'),
    ).toHaveLength(2);
    expect(
      element.querySelectorAll(
        '[data-canvas-type="component"][data-canvas-uuid="marker-one"]',
      ),
    ).toHaveLength(2);
    expect(
      element.querySelector('.canvas--region-empty-placeholder'),
    ).toBeNull();
  });

  it('preserves component views across sibling reorders using UUID keys', () => {
    const document = (uuids: string[]): CanvasComponentTreeDocument => ({
      root: 'el-0',
      elements: {
        'el-0': {
          type: 'renderless-container',
          props: {},
          children: uuids,
        },
        ...Object.fromEntries(
          uuids.map((uuid) => [
            uuid,
            {
              type: 'js.card',
              props: { title: uuid },
              children: [],
            },
          ]),
        ),
      },
    });
    const fixture = render(document(['first', 'second']));
    const element = fixture.nativeElement as HTMLElement;
    const before = [...element.querySelectorAll('test-card')];
    // State owned by the application component's view must stay with its UUID.
    before[0].setAttribute('data-local-state', 'retained');
    fixture.componentRef.setInput('tree', document(['second', 'first']));
    fixture.detectChanges();
    const after = [...element.querySelectorAll('test-card')];
    expect(after[0]).toBe(before[1]);
    expect(after[1]).toBe(before[0]);
    expect(after[1].getAttribute('data-local-state')).toBe('retained');
    expect(after[1].querySelector('h2')?.textContent).toBe('first');
  });

  it('renders an empty preview region for an empty document', () => {
    const fixture = render({ root: '', elements: {} }, true);
    expect(
      fixture.nativeElement.querySelectorAll('[data-canvas-type="region"]'),
    ).toHaveLength(2);
    expect(
      fixture.nativeElement.querySelector('.canvas--region-empty-placeholder'),
    ).not.toBeNull();
  });
});
