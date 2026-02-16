import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { Plugin } from 'vite';
import { defineConfig } from 'vite';

const assetsDir = resolve(__dirname, 'src/web/assets');

/** Map CSS filenames to their asset bundle dist directories. */
const cssToBundle: Record<string, string> = {
  'search-document-field.css': 'searchdocumentfield',
  'field-mappings.css': 'fieldmappings',
  'index-structure.css': 'indexstructure',
  'search-page.css': 'searchpage',
};

/** Copy built entries to locations outside the assets directory. */
const copyTargets: Record<string, string> = {
  'histogram/dist/histogram.js': resolve(__dirname, 'src/templates/stubs/sprig/js/histogram.js'),
};

function copyEntries(): Plugin {
  return {
    name: 'copy-entries',
    writeBundle() {
      for (const [src, dest] of Object.entries(copyTargets)) {
        const srcPath = resolve(assetsDir, src);
        mkdirSync(dirname(dest), { recursive: true });
        copyFileSync(srcPath, dest);
      }
    },
  };
}

export default defineConfig({
  plugins: [copyEntries()],
  build: {
    outDir: assetsDir,
    emptyOutDir: false,
    sourcemap: false,
    rollupOptions: {
      input: {
        'indexedit/dist/index-edit': resolve(assetsDir, 'indexedit/src/index-edit.ts'),
        'indexlist/dist/index-list': resolve(assetsDir, 'indexlist/src/index-list.ts'),
        'fieldmappings/dist/field-mappings': resolve(
          assetsDir,
          'fieldmappings/src/field-mappings.ts',
        ),
        'searchdocumentfield/dist/search-document-field': resolve(
          assetsDir,
          'searchdocumentfield/src/search-document-field.ts',
        ),
        'indexstructure/dist/index-structure': resolve(
          assetsDir,
          'indexstructure/src/index-structure.ts',
        ),
        'searchpage/dist/search-page': resolve(assetsDir, 'searchpage/src/search-page.ts'),
        'histogram/dist/histogram': resolve(assetsDir, 'histogram/src/histogram.ts'),
      },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: (assetInfo) => {
          const name = assetInfo.name ?? '';
          const bundle = cssToBundle[name];
          if (bundle) {
            return `${bundle}/dist/[name][extname]`;
          }
          return '[name][extname]';
        },
      },
    },
  },
});
