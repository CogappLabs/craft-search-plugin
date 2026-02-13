import { resolve } from 'node:path';
import { defineConfig } from 'vite';

const assetsDir = resolve(__dirname, 'src/web/assets');

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
        'searchpage/dist/search-page': resolve(assetsDir, 'searchpage/src/search-page.ts'),
        'searchdocumentfield/dist/search-document-field': resolve(
          assetsDir,
          'searchdocumentfield/src/search-document-field.ts',
        ),
        'indexstructure/dist/index-structure': resolve(
          assetsDir,
          'indexstructure/src/index-structure.ts',
        ),
      },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: (assetInfo) => {
          // Route CSS files alongside their JS entry
          const name = assetInfo.name ?? '';
          if (name === 'search-document-field.css') {
            return 'searchdocumentfield/dist/[name][extname]';
          }
          if (name === 'field-mappings.css') {
            return 'fieldmappings/dist/[name][extname]';
          }
          return '[name][extname]';
        },
      },
    },
  },
});
