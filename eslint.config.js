import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

/**
 * Lint rules for the officer portal and the browser extension.
 *
 * This exists because a green build proved twice to mean nothing: a component
 * that referenced an unimported Skeleton took a page down, and a query key
 * rebuilt on every render turned one open panel into 114 requests. Vite
 * bundles both without complaint, because bundling is not checking.
 *
 * The rules below are the ones that would have caught those two, plus the
 * small set that catch the same class of mistake. Style is left to Prettier's
 * absence and to review — this is a correctness net, not a taste enforcer.
 */
export default [
    {
        ignores: [
            'node_modules/**',
            'vendor/**',
            'public/build/**',
            'storage/**',
            'bootstrap/cache/**',
            'agent/**',
            'output/**',
            'tmp/**',
        ],
    },

    /* ------------------------------------------------- officer portal (React) */
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.browser,
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
        },
        plugins: {
            react,
            'react-hooks': reactHooks,
        },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            ...js.configs.recommended.rules,
            ...react.configs.flat.recommended.rules,
            ...react.configs.flat['jsx-runtime'].rules,

            // The one that matters most: a name used but never imported or
            // declared. This is exactly the Skeleton failure.
            'no-undef': 'error',

            // A name imported and never used is usually a leftover from a
            // refactor, and occasionally the wrong half of a rename.
            'no-unused-vars': ['warn', {
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
                caughtErrorsIgnorePattern: '^_',
            }],

            'react-hooks/rules-of-hooks': 'error',

            // The render-loop rule. An object or timestamp rebuilt inline and
            // passed as a dependency re-runs the effect forever; this is what
            // flags it before a user finds it.
            'react-hooks/exhaustive-deps': 'warn',

            // Prop types are not used in this codebase; the API shapes are the
            // contract and they are tested server-side.
            'react/prop-types': 'off',
        },
    },

    /* --------------------------------------------- browser extension (MV3) */
    {
        files: ['extension/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'script',
            globals: {
                ...globals.browser,
                ...globals.webextensions,
            },
        },
        rules: {
            ...js.configs.recommended.rules,
            'no-undef': 'error',
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],

            // A deliberately empty catch is how the extension ignores a
            // browser-internal or malformed URL; that is the behaviour, not
            // an oversight.
            'no-empty': ['error', { allowEmptyCatch: true }],
        },
    },

    /* ------------------------------------------------------- build configs */
    {
        files: ['*.config.js', 'vite.config.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.node,
        },
        rules: js.configs.recommended.rules,
    },
];
