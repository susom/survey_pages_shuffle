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

> **Important:** The survey must have **"Question Display Format → One section
> per page"** enabled in Survey Settings for paging to be active.

---

## What This Module Does

For each configured instrument the module:

1. **Pins page 1 and page N** — the first page is always shown first and the
   last page is always shown last. See [Known Limitations](#known-limitations)
   for why this is necessary.
2. **Shuffles only the middle pages** — pages 2 through N−1 are randomly
   reordered once per respondent session.
3. **Tracks visited pages** server-side via a PHP session visited-set. The
   module knows which middle pages have already been seen and automatically
   routes the respondent to any remaining unvisited page before allowing them
   to reach the last page.
4. **Stores the shuffled order** in PHP `$_SESSION` (and optionally in a
   REDCap text field for auditing/reproducibility).
5. **Updates the visible page counter** ("Page X of Y") so it always shows the
   respondent's sequential virtual position (1 … N), not the underlying real
   page number.

> **Minimum pages:** You need at least **4 pages** (i.e. ≥ 3 section headers)
> for shuffling to take effect. With 3 or fewer pages there is at most one
> middle page and there is nothing to shuffle.

---

## Configuration

Navigate to **Project Setup → External Modules → Survey Pages Shuffle →
Configure**. Add one row per instrument you want to shuffle:

| Setting | Description |
|---|---|
| **Instrument** | The survey instrument to enable page shuffling for. |
| **Field to store the shuffled page order** | *Optional.* A text field where the module records the shuffled order as a comma-separated list of real page numbers (e.g. `1,3,4,2,5`). Useful for auditing or replaying a respondent's exact sequence. |
| **Inject progress bar** | *Optional.* When enabled, the module injects a visual progress bar at the top of each survey page. The progress bar shows the percentage complete based on the virtual page position, ensuring it moves smoothly from 0% to 100%. See [Progress Bar](#progress-bar) for details. |

---

## Progress Bar

When the **Inject progress bar** option is enabled, the module displays a styled progress bar at the top of each survey page:

```
┌────────────────────────────────────────────────────────────────┐
│  ████████████████████████████████░░░░░░░░░░░░  67%             │
└────────────────────────────────────────────────────────────────┘
```

### Features

- **Accurate progress:** The bar reflects the respondent's position in the shuffled sequence (virtual position), not the original page order.
- **Smooth progression:** Progress moves continuously from 0% (page 1) to 100% (last page).
- **Consistent styling:** Uses a neutral gray color scheme that works with most survey themes.
- **Responsive:** The bar is centered and scales appropriately on different screen sizes.

### Formula

```
percentage = ((virtualPosition - 1) / (totalPages - 1)) × 100
```

| Virtual Position | Total Pages | Percentage |
|---|---|---|
| 1 | 5 | 0% |
| 2 | 5 | 25% |
| 3 | 5 | 50% |
| 4 | 5 | 75% |
| 5 | 5 | 100% |

### Customization

The progress bar uses inline styles for maximum compatibility. If you need to customize the appearance (colors, size, etc.), you can add custom CSS in REDCap's survey settings or via the **Survey Theme** feature. Target the `#sps-progress-bar` element:

```css
#sps-progress-bar table {
    background-color: #d0d0d0 !important;  /* Empty portion */
}
#sps-progress-bar td:first-child {
    background-color: #4CAF50 !important;  /* Filled portion */
}
```

---

## Requirements

* REDCap ≥ 13.0.0
* PHP ≥ 7.4
* EM Framework version ≥ 16 (`permissions` attribute is not required)
* The target instrument must be enabled as a **survey** (not data-entry only).
* The survey must have at least **4 pages** (i.e. ≥ 3 section headers).
* **"One section per page"** must be enabled in Survey Settings.

---

## Known Limitations

### First and last pages are always fixed
Page 1 is always the first page shown and page N is always the last page
shown. **The first and last pages cannot be shuffled.** This is a deliberate
design constraint, not a temporary limitation:

* **Page 1 (first):** REDCap has not yet created the record when the respondent
  loads the survey for the first time. The record ID only becomes available
  after the first form submission. Pinning page 1 avoids session-key and
  data-save edge cases that arise before the record exists.
* **Page N (last):** REDCap renders its native **Submit** button only on the
  final (highest-numbered) real page. If the last real page were shuffled to
  appear in the middle, respondents would hit Submit prematurely before filling
  all pages. Pinning page N ensures the Submit button appears exactly once, at
  the correct end of the survey.

**Practical effect:** only pages 2 through N−1 are randomised. You need at
least 4 pages for any randomisation to occur.

### Full end-to-end page shuffling is not supported
Due to the constraints above, shuffling the first or last page is not possible
without modifying REDCap core survey logic.

---

## Technical Notes

### Server-side session key
The shuffled order is stored in `$_SESSION` under the key
`spc_{instrument}_{event_id}`. Because PHP's `$_SESSION` is already scoped to
the respondent's browser session, no record ID or public survey hash is needed
in the key — `instrument + event_id` uniquely identifies the survey within a
session and is stable on page 1 before REDCap assigns the record ID.

> **Note:** Earlier versions used the public survey hash (`?s=…`) as part of
> the session key. This was incorrect — the same hash is shared across all
> respondents of a public survey link and would cause order collisions between
> concurrent respondents. The current key uses only `instrument + event_id`,
> which is per-session safe.

### Visited-set tracking
The session stores a **visited set** of real page numbers that have already
been rendered. On every page load the current real page is added to the set.
Before the respondent is allowed to proceed to the last page, the JS checks
the server-supplied `remaining` array. If any middle pages are unvisited the
respondent is automatically redirected to the first unvisited middle page.
This guarantees complete coverage without a mutable stack (earlier stack-based
approaches were prone to infinite-loop bugs when the browser back button was
used or when the same page was visited more than once).

### Hook 1 — `redcap_every_page_before_render`
Fires **before** REDCap processes the posted page number. It reads the
`__sps_target__` hidden field posted by the client-side JS and:

* Sets `$_GET['__page__']` to the desired real target page.
* Sets `$_POST['__page__']` to the sentinel value `99999` (which does not
  appear in REDCap's `$pageFields` array). This causes REDCap's `setPageNum()`
  to leave `$_GET['__page__']` unchanged and bypasses the data-save
  field-filter so **all posted fields are saved** regardless of which real page
  they belong to.

### Hook 2 — `redcap_survey_page_top`
Fires **after** REDCap determines the current page and saves any posted data.
Responsibilities:

* Initialises the session (builds the shuffled order) on the first visit.
* Marks the current real page as visited.
* Defers saving the order to the optional REDCap field until the record ID is
  available (i.e. after page 1 has been submitted).
* Injects inline JavaScript that intercepts `window.dataEntrySubmit` to:
  * Route **Next** clicks to the correct next virtual page, or to the first
    unvisited middle page if the natural next would skip unvisited pages or
    jump to the last page prematurely.
  * Route **Back** clicks to the previous virtual page.
  * Post `__sps_target__` (the desired real page) to the server via Hook 1.
  * Provide a `__page__` / `__page_hash__` fallback in case Hook 1 is inactive.
* Updates the "Page X of Y" counter to show the virtual position.

### Branching logic
All REDCap branching logic continues to work normally because the underlying
field names and their positions in the instrument are unchanged. Only the
*navigation order* across pages is shuffled.

---

## Version History

| Version | Notes |
|---|---|
| 0.0.0 | Initial release. Middle-page shuffling with server-side visited-set tracking. First and last pages are pinned. Added optional progress bar fix for section headers. |
