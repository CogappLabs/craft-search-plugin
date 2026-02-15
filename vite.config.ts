import { resolve } from 'node:path';
import { defineConfig } from 'vite';

const assetsDir = resolve(__dirname, 'src/web/assets');

/** Map CSS filenames to their asset bundle dist directories. */
const cssToBundle: Record<string, string> = {
  'search-document-field.css': 'searchdocumentfield',
  'field-mappings.css': 'fieldmappings',
};

export default defineConfig({
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
