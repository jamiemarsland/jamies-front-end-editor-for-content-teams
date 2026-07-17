# Release runbook

How to cut a new release of Jamie's Front-End Editor for Content Teams to
GitHub and the WordPress.org plugin directory. This mirrors the steps used for
the 0.7 / 0.7.1 releases.

## 0. One-time setup (already done on this machine)

- `npx pressship login` — opens a browser and saves your WordPress.org session.
  You only need to redo this if `release` later says you're not logged in.
- The plugin is already approved and live, so new versions use `release`
  (not `submit`).

## 1. Bump the version

Edit both to the same number:

- `jamies-front-end-editor-for-content-teams.php` header: `Version:`
- `readme.txt`: `Stable tag:`

Add a matching `= x.y.z =` block at the top of the `== Changelog ==` section.

> Important: the `Stable tag` must match a tag that exists in SVN after release.
> `pressship release` creates that tag for you, so bump the tag and release in the
> same sitting — don't leave `Stable tag` pointing at a version you haven't
> released.

## 2. Pre-flight

```
cd "/Users/jamesmarsland/Desktop/jamies front end editor for content teams/jamies-front-end-editor-for-content-teams"
npx pressship verify
```

Runs readme validation + Plugin Check. Fix anything it flags before releasing.

## 3. Commit + push source to GitHub

```
git add -A
git commit -m "x.y.z: short summary"
git push
```

## 4. Release to WordPress.org

```
npx pressship release
```

- Do NOT pass a `.` path argument — on this shell the `.` gets mangled into
  `.~` and pressship then can't find the plugin. Running with no path uses the
  current directory, which is what you want.
- It will prompt "Plugin directory (…)" pre-filled with the correct path — press
  Enter to accept.
- Review the list of trunk/tag changes, confirm the "Commit these SVN changes?"
  prompt, and it commits. Success looks like `Committed revision NNNNNNN.`

## 5. After release

- The directory page can take a few minutes to show the new version.
- Optionally tag the GitHub release to match (see the release notes file).

## Gotchas seen so far

- `.` → `.~`: always run `pressship demo` / `pressship release` with no path arg.
- Playground `pressship demo` copies the plugin in at start, so a browser reload
  won't pick up PHP changes — restart the demo (`Ctrl+C`, then
  `npx pressship demo --reset`) to test new server-side code.
