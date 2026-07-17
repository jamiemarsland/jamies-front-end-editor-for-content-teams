# GitHub release notes — v0.7.1

Paste into the GitHub release for tag `v0.7.1`. Covers everything since the last
tagged release, since 0.6 and 0.7 were never tagged on GitHub.

---

## Front-end editing for links, images and buttons

This release expands front-end editing well beyond text. Your content team can
now click almost anything on the live page and change it in place — no wp-admin,
block markup preserved.

**New**

- **Inline link editing.** Select words while editing and a link bubble appears:
  add a link (with optional open-in-new-tab). Click an existing link to edit or
  remove it. Links no longer navigate away mid-edit.
- **Inline image editing.** Click an image to get a small toolbar: Replace (via
  the media library), Alt text, or Remove — all on the front end.
- **Button editing.** Click a button to change its text, its link, and whether
  it opens in a new tab.

**Reliability**

- Edits are now matched to blocks by content fingerprint rather than a fixed
  position, so removing or replacing an image no longer causes a later save to
  report a false "changed by someone else" conflict. Block fingerprints refresh
  after each save so you can keep editing without reloading.

**Docs**

- Updated the plugin description to reflect text, link, button and image editing.

**Notes**

- Image and button changes save immediately on confirm (the toast is the "saved"
  cue); text edits still stage behind an explicit Save.

---

### To create the tag

```
git tag v0.7.1
git push origin v0.7.1
```

Then create the release on GitHub against that tag and paste the notes above.
