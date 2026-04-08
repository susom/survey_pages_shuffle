/**
 * Survey Pages Shuffle — shuffle.js
 *
 * Intercepts REDCap's survey Next / Back button form submissions and rewrites
 * the hidden __page__ input to the correct *real* page number as determined
 * by the server-side shuffled order.
 *
 * Also updates the visible "Page X of Y" counter so respondents see a
 * sequential virtual page number rather than the underlying real page number.
 */
(function () {
    'use strict';

    // The JSMO object is initialised by PHP before this file loads.
    // We locate it via its known property name pattern set in SurveyPagesShuffle.php.
    var SPS;

    // Poll until the module object is ready (it is set synchronously before
    // this <script> tag, but guard against any timing edge-cases).
    function getModuleObject() {
        // ExternalModules JSMO objects are stored on window under a generated name.
        // Our PHP code sets:   SPS.shuffleData = { ... }
        // We find it by scanning window for objects that have shuffleData set by us.
        if (SPS) return SPS;
        for (var key in window) {
            if (
                window.hasOwnProperty(key) &&
                typeof window[key] === 'object' &&
                window[key] !== null &&
                window[key].shuffleData
            ) {
                SPS = window[key];
                return SPS;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Core initialisation — called once the DOM is ready
    // -------------------------------------------------------------------------
    function init() {
        SPS = getModuleObject();
        if (!SPS || !SPS.shuffleData) {
            // Module object or data not yet available — nothing to do
            return;
        }

        var d = SPS.shuffleData;

        // Nothing to do if there is only one page
        if (d.totalPages <= 1) return;

        // -----------------------------------------------------------------
        // 1. Update the visible "Page N of M" counter
        // -----------------------------------------------------------------
        updatePageCounter(d.currentVirtualPage, d.totalPages);

        // -----------------------------------------------------------------
        // 2. Rewrite __page__ hidden input before the form is submitted.
        //
        // REDCap renders one <form> per survey page.  The hidden field
        // named "__page__" carries the page number that was *just completed*
        // to the server, which then redirects to __page__ + 1 (or - 1 for Back).
        //
        // We intercept the submit buttons and adjust __page__ so that REDCap
        // loads the correct *real* page next, in accordance with our shuffle.
        // -----------------------------------------------------------------
        interceptButtons(d);
    }

    // -------------------------------------------------------------------------
    // Replace the displayed page counter with the virtual page number
    // -------------------------------------------------------------------------
    function updatePageCounter(virtualPage, totalPages) {
        // REDCap renders the page counter inside an element with id="pagecounter"
        // or as plain text inside a <span class="pagecounter">.
        // Different REDCap versions use slightly different markup — we handle both.
        var counter = document.getElementById('pagecounter');
        if (!counter) {
            // Try the span approach used in newer skins
            var spans = document.querySelectorAll('span.pagecounter, div#pagecounter, div.pagecounter');
            if (spans.length) counter = spans[0];
        }
        if (counter) {
            // Replace just the numbers, preserving surrounding text like "Page X of Y"
            var text = counter.textContent || counter.innerText || '';
            // Match patterns like "1 of 5", "Page 1 of 5", "1 / 5" etc.
            var updated = text.replace(
                /(\d+)(\s*(?:of|\/)\s*)(\d+)/i,
                virtualPage + '$2' + totalPages
            );
            if (updated !== text) {
                counter.textContent = updated;
            } else {
                // Fallback: just overwrite
                counter.textContent = 'Page ' + virtualPage + ' of ' + totalPages;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Intercept submit buttons to adjust __page__ before posting
    //
    // REDCap buttons call  dataEntrySubmit(this)  in their onclick handler which
    // triggers a jQuery form.submit().  We use mousedown (fires before click /
    // submit) to ensure __page__ and __page_hash__ are updated in time.
    // We also bind the form's submit event as a safety fallback.
    // -------------------------------------------------------------------------
    function interceptButtons(d) {
        var $form = $('form[id^="form"]').first();
        if (!$form.length) {
            $form = $('form').first();
        }

        // Safety-net: catch any form submission not covered by button intercepts
        $form.on('submit', function () {
            // Only apply if we haven't already set the value via a button intercept
            // (direction is unknown here, default to 'next')
            if (!$form.data('sps-intercepted')) {
                adjustPageField(d, 'next');
            }
            $form.removeData('sps-intercepted');
        });

        // Next / Submit button  (mousedown fires before jQuery UI button click)
        $(document).on('mousedown', '[name="submit-btn-saverecord"]', function () {
            adjustPageField(d, 'next');
            $form.data('sps-intercepted', true);
        });

        // Back button
        $(document).on('mousedown', '[name="submit-btn-saveprevpage"]', function () {
            adjustPageField(d, 'back');
            $form.data('sps-intercepted', true);
        });

        // Keyboard (Enter key) support — treat as Next
        $(document).on('keydown', '[name="submit-btn-saverecord"]', function (e) {
            if (e.which === 13 || e.which === 32) {
                adjustPageField(d, 'next');
                $form.data('sps-intercepted', true);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Rewrite __page__ and __page_hash__ before the form posts
    //
    // REDCap's Survey::initPageNumCheck() verifies that the posted __page_hash__
    // matches Survey::getPageNumHash(__page__).  If it doesn't, __page__ is reset
    // to 0 (= page 1).  Our PHP hook pre-computes the correct hash for every real
    // page number and passes them in d.pageHashes so we can set both fields here.
    // -------------------------------------------------------------------------
    function adjustPageField(d, direction) {
        var targetRealPage;

        if (direction === 'back') {
            targetRealPage = d.prevRealPage;
        } else {
            // 'next' or ambiguous (form submit) — default to next
            targetRealPage = d.nextRealPage;
        }

        // Update hidden __page__ field
        var $pageInput = $('input[name="__page__"]');
        if ($pageInput.length) {
            $pageInput.val(targetRealPage);
        }

        // Update __page_hash__ to match the new target page number.
        // d.pageHashes is a map of  real_page_number → hash  precomputed by PHP.
        var $hashInput = $('input[name="__page_hash__"]');
        if ($hashInput.length && d.pageHashes && d.pageHashes[targetRealPage]) {
            $hashInput.val(d.pageHashes[targetRealPage]);
        }
    }

    // -------------------------------------------------------------------------
    // Bootstrap — wait for DOM + jQuery
    // -------------------------------------------------------------------------
    if (typeof $ !== 'undefined') {
        $(document).ready(init);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof $ !== 'undefined') {
                init();
            }
        });
    }

})();

