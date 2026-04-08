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

1. **Computes the real page map** — groups of fields between section headers.
2. **Generates a shuffled page order** the first time a respondent lands on the
   survey, and stores it in the PHP session (and optionally in a REDCap field).
3. **Injects JavaScript** into every survey page that intercepts the **Next** and
   **Back** button clicks, rewriting REDCap's hidden `__page__` field to the
   correct *real* page number so the respondent navigates through the shuffled
   order rather than the natural order.
4. **Updates the visible page counter** ("Page X of Y") to display the
   respondent's virtual (sequential) position, not the underlying real page number.

---

## Configuration

Navigate to **Project Setup → External Modules → Survey Pages Shuffle →
Configure**. Add one row per instrument you want to shuffle:

| Setting | Description |
|---|---|
| **Instrument** | The survey instrument to enable page shuffling for. |
| **Exclude first page from shuffle** | When checked, page 1 always appears first. Useful when page 1 contains consent or instructions. |
| **Exclude last page from shuffle** | When checked, the last page always appears last. Useful for a closing/thank-you page. |
| **Field to store the shuffled page order** | Optional. A text field on the same instrument (or any form) where the module will record the shuffled order as a comma-separated list of real page numbers, e.g. `3,1,4,2`. This lets you replay a respondent's exact page sequence for auditing. |

---

## Requirements

* REDCap ≥ 13.0.0
* PHP ≥ 7.4
* The target instrument must be enabled as a **survey** (not data-entry only).
* The survey must have at least **two pages** (i.e. ≥ 1 section header).
* "One section per page" must be enabled in Survey Settings.

---

## Technical Notes

### Server-side session
The shuffled order is stored in `$_SESSION` under the key
`sps_{project_id}_{survey_hash}`. It is created on the first page load and
reused for all subsequent pages of the same response.

### JavaScript interception
`shuffle.js` is loaded on every survey page for a configured instrument. It:

* Reads `SPS.shuffleData` (injected by PHP) to know the current page mapping.
* Hooks into the form's submit event and the named submit buttons
  (`submit-btn-saverecord` = Next, `submit-btn-saveprevpage` = Back).
* Before the form posts, it overwrites `input[name="__page__"]` with the
  correct *real* page number for the intended direction of travel.

### Page counter
The visible "Page X of Y" element (id `pagecounter`) is updated to show the
virtual position so respondents always see a clean `1 … N` sequence.

### Branching logic
All REDCap branching logic continues to work normally because the underlying
field names and their positions in the instrument are unchanged. Only the
*navigation order* across pages is shuffled.

---

## Author

Ihab Zeedia — Stanford Health Care
<ihab.zeedia@stanford.edu>
