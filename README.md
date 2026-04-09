# Survey Pages Shuffle

A REDCap External Module that randomizes the order of pages within a single
survey for each respondent.

---

## How REDCap Survey Pages Work

REDCap paginates a survey instrument whenever a field is assigned a
**Section Header** in the Online Designer. Each section header creates a new
page boundary — everything from one section header up to (but not including)
the next belongs to the same page.

```
Page 1 ─── Field A  (no section header, or first field)
            Field B
Page 2 ─── Field C  (has section header "Demographics")
            Field D
Page 3 ─── Field E  (has section header "Quality of Life")
```

> **Important:** The survey must have "Question Display Format → One section per
> page" enabled in Survey Settings for paging to be active.

---

## What This Module Does

For each configured instrument the module:

1. **Keeps the first and last pages fixed** — page 1 is always shown first,
   and the last page is always shown last. This ensures that introductory
   content (consent, instructions) and closing content (submit button,
   thank-you message) remain in their expected positions.
2. **Shuffles only the middle pages** — pages 2 through N−1 are randomly
   reordered for each respondent. This is ideal for randomizing the
   presentation order of question blocks while preserving intro/outro pages.
3. **Generates the shuffled order once** per respondent session, and stores it
   in the PHP session (and optionally in a REDCap field for auditing).
4. **Injects JavaScript** into every survey page that intercepts the **Next** and
   **Back** button clicks, rewriting REDCap's hidden `__page__` and
   `__page_hash__` fields so the respondent navigates through the shuffled
   order rather than the natural order.
5. **Updates the visible page counter** ("Page X of Y") to display the
   respondent's virtual (sequential) position, not the underlying real page
   number.

> **Minimum pages:** You need at least **4 pages** for shuffling to take
> effect. With 3 or fewer pages there is at most one middle page, so there is
> nothing to shuffle.

---

## Configuration

Navigate to **Project Setup → External Modules → Survey Pages Shuffle →
Configure**. Add one row per instrument you want to shuffle:

| Setting | Description |
|---|---|
| **Instrument** | The survey instrument to enable page shuffling for. |
| **Field to store the shuffled page order** | Optional. A text field where the module will record the shuffled order as a comma-separated list of real page numbers, e.g. `1,3,4,2,5`. This lets you replay a respondent's exact page sequence for auditing. |

> **Note:** The first and last pages are always kept in their original
> positions. Only pages 2 through N−1 are shuffled. This ensures REDCap's
> Submit button on the last page works correctly.

---

## Requirements

* REDCap ≥ 13.0.0
* PHP ≥ 7.4
* The target instrument must be enabled as a **survey** (not data-entry only).
* The survey must have at least **4 pages** (i.e. ≥ 3 section headers) for
  shuffling to take effect.
* "One section per page" must be enabled in Survey Settings.

---

## Technical Notes

### Server-side session
The shuffled order is stored in `$_SESSION` under the key
`spc_{record}_{instrument}_{event_id}` (unique per respondent response). Using the recordId, instrument, event ID — rather
than the record ID — allows the session to be created on **page 1**, before
REDCap assigns the record ID (which only happens after the first form
submission). The session is reused for all subsequent pages of the same
response. It also tracks a **visited set** of real page numbers so the module
knows which middle pages have already been seen.

### Hook 1 — `redcap_every_page_before_render`
Fires before REDCap processes the posted page number. It reads the
`__sps_target__` field set by the JS and rewrites `$_POST['__page__']` to the
sentinel value `99999` (which does not appear in `$pageFields`). This causes
REDCap's `setPageNum()` to leave `$_GET['__page__']` (already set to the real
target) unchanged, and bypasses the data-save field-filter so **all** posted
fields are saved regardless of which real page they belong to.

### Hook 2 — `redcap_survey_page_top`
Fires after REDCap determines the current page and saves any posted data.
It manages the visited set, saves the shuffled order to the optional field,
and injects inline JavaScript:

* Reads the virtual-to-real page mapping, precomputed page hashes, and the
  set of remaining unvisited middle pages (all injected by PHP).
* Overrides `window.dataEntrySubmit` to intercept the named submit buttons
  (`submit-btn-saverecord` = Next/Submit, `submit-btn-saveprevpage` = Back).
* Before the form posts, it sets a hidden `__sps_target__` field to the
  desired real page number. It also rewrites `input[name="__page__"]` and
  `input[name="__page_hash__"]` as a fallback in case Hook 1 is inactive.
* **Redirects to unvisited middle pages** if the respondent would otherwise
  jump straight to the last page — they are automatically routed to the first
  remaining unvisited middle page instead.

### Page counter
The visible "Page X of Y" element (`surveypagenum` / `pagecounter`) is
updated to show the virtual position so respondents always see a clean
`1 … N` sequence.

### First & last page pinning
Page 1 is always virtual position 1 and the last real page is always the
last virtual position. This means REDCap's native "Submit" button on the
final page works without any special handling. The "Previous Page" button on
page 1 and the submit flow on the last page are never intercepted.

### Branching logic
All REDCap branching logic continues to work normally because the underlying
field names and their positions in the instrument are unchanged. Only the
*navigation order* across pages is shuffled.

