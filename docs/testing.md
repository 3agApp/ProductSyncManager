# Testing

Two suites, covering different things.

## Server side

```bash
php artisan test
```

Pest feature tests over routes, policies, validation, the sync actions and
the models. Fast, run them constantly.

## Browser

```bash
npm run test:e2e          # once
npm run test:e2e:ui       # with Playwright's UI
```

Playwright drives a real Chrome against a real server. This is the layer
that catches what the server side cannot see: a component that throws when
a control is clicked, taking the page down with it.

Every test watches the console and fails on any error, and the page-level
tests assert the error boundary did not catch anything. That combination is
what turns a blank page from something a person has to notice into a failing
test.

### How it runs

`e2e/serve.sh` gives the run its own SQLite file at `database/e2e.sqlite`,
rebuilds and seeds it, and serves the app on port 8011. **The database used
for development is never touched.** Playwright starts and stops that server
itself, so there is nothing to run first.

Signing in happens once in `e2e/auth.setup.ts`; the session is saved and
reused, so the other tests start already logged in as the seeded
`test@example.com`.

The app marks elements with `data-test`, so `page.getByTestId()` is wired to
that attribute rather than Playwright's default `data-testid`.

### A note on assets

Laravel serves JavaScript from the Vite dev server whenever `public/hot`
exists, and from `public/build` otherwise. CI has no dev server running, so
it always tests the built assets. Locally either works -- but if `public/hot`
is present and the Vite dev server is **not** running, every page will be
blank. That is worth knowing before blaming the app.

### Writing more

- `e2e/smoke.spec.ts` -- every page renders, with no console errors.
  Add a line here whenever a page is added.
- `e2e/shops.spec.ts`, `e2e/catalog.spec.ts` -- interactions worth
  protecting: selects inside dialogs, filtering, sorting, bulk actions, the
  category tree, the product form.
- `e2e/helpers.ts` -- `watchForErrors()` and `chooseFromSelect()`, which
  handles opening a shadcn select and picking from it.
