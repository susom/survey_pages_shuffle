<?php
/**
 * Survey Pages Shuffle — REDCap External Module
 *
 * Randomizes page order within a single multi-page survey.
 * Pages 1 and N are pinned. Only pages 2…N-1 are shuffled.
 *
 * Navigation uses a VISITED SET + position pointer instead of a mutable stack,
 * making it impossible to get stuck in a loop.
 *
 * Session key: spc_{instrument}_{event_id}
 * $_SESSION is already scoped per PHP session (per respondent browser session),
 * so no record ID or hash is needed in the key — instrument+event_id is enough
 * to distinguish concurrent surveys. This also means the key is stable on
 * page 1 before REDCap assigns the record ID.
 *
 * @author Ihab Zeedia <ihab.zeedia@stanford.edu>
 */

namespace Stanford\SurveyPagesShuffle;

use ExternalModules\AbstractExternalModule;
use REDCap;

class SurveyPagesShuffle extends AbstractExternalModule
{
    // ── Session key ────────────────────────────────────────────────────────

    private function skey(string $instrument, int $eventId): string
    {
        // $_SESSION is per PHP session (per respondent), so instrument+event_id
        // is sufficient to be unique within a session and is available on page 1
        // before REDCap assigns the record ID.
        return "spc_{$instrument}_{$eventId}";
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Returns [$pageCount, $formName, $projectId] from the survey hash. */
    private function countPages(string $surveyHash): array
    {
        $q = db_query(
            "SELECT s.project_id, s.form_name, s.question_by_section
             FROM redcap_surveys s
             JOIN redcap_surveys_participants p ON s.survey_id = p.survey_id
             WHERE p.hash = '" . db_escape($surveyHash) . "'
             LIMIT 1"
        );
        if (!$q || db_num_rows($q) === 0) return [0, null, null];
        $row = db_fetch_assoc($q);

        $pid  = (int)$row['project_id'];
        $form = $row['form_name'];
        if (!(int)$row['question_by_section']) return [1, $form, $pid];

        $pk = db_result(db_query(
            "SELECT field_name FROM redcap_metadata
             WHERE project_id = $pid ORDER BY field_order LIMIT 1"
        ), 0);

        $q2 = db_query(
            "SELECT field_name, element_preceding_header FROM redcap_metadata
             WHERE project_id = $pid AND form_name = '" . db_escape($form) . "'
             ORDER BY field_order"
        );
        $pages = 1;
        $first = true;
        while ($r = db_fetch_assoc($q2)) {
            if ($r['field_name'] === $pk || $r['field_name'] === "{$form}_complete") continue;
            if (!$first && $r['element_preceding_header'] !== null && $r['element_preceding_header'] !== '') {
                $pages++;
            }
            $first = false;
        }
        return [$pages, $form, $pid];
    }

    private function isConfigured(string $form): bool
    {
        return in_array($form, $this->getProjectSetting('instrument') ?? [], true);
    }

    private function orderField(string $form): ?string
    {
        $instruments = $this->getProjectSetting('instrument')       ?? [];
        $fields      = $this->getProjectSetting('page-order-field') ?? [];
        foreach ($instruments as $i => $inst) {
            if ($inst === $form) return $fields[$i] ?? null;
        }
        return null;
    }

    private function fixProgressHeader(string $form): bool
    {
        $instruments = $this->getProjectSetting('instrument')          ?? [];
        $flags       = $this->getProjectSetting('fix-progress-header') ?? [];
        foreach ($instruments as $i => $inst) {
            if ($inst === $form) return !empty($flags[$i]);
        }
        return false;
    }

    /**
     * Build shuffled order. Page 1 always first, page N always last.
     * Returns e.g. [1, 4, 2, 3, 5] for a 5-page survey.
     */
    private function buildOrder(int $total): array
    {
        if ($total <= 3) return range(1, $total);
        $mid = range(2, $total - 1);
        shuffle($mid);
        return array_merge([1], $mid, [$total]);
    }

    // ── HOOK 1 — redcap_every_page_before_render ──────────────────────────
    //
    // Fires BEFORE initPageNumCheck() and setPageNum().
    // Reads __sps_target__ posted by our JS and:
    //   • Sets $_GET['__page__'] = target  (page REDCap will render)
    //   • Sets $_POST['__page__'] = 99999  (sentinel not in $pageFields)
    //       → setPageNum() skips its ±1 block
    //       → data-save field-filter skips stripping  → ALL fields saved

    public function redcap_every_page_before_render($project_id)
    {
        try {
            if (empty($project_id))                               return;
            if (!defined('PAGE') || PAGE !== 'surveys/index.php') return;
            if (empty($_GET['s']))                                 return;
            if ($_SERVER['REQUEST_METHOD'] !== 'POST')            return;
            if (!isset($_POST['__sps_target__']))                 return;
            if (!class_exists('Survey'))                          return;

            $target = (int)$_POST['__sps_target__'];
            if ($target < 1) return;

            $_GET['__page__']       = $target;
            $_POST['__page__']      = '99999';
            $_POST['__page_hash__'] = \Survey::getPageNumHash(99999);
            unset($_POST['__sps_target__']);

        } catch (\Throwable $e) {
            error_log("[SPS] before_render: " . $e->getMessage());
        }
    }

    // ── HOOK 2 — redcap_survey_page_top ───────────────────────────────────
    //
    // Fires AFTER page determination + data save.
    // Manages the visited-set and injects JS.

    public function redcap_survey_page_top(
        $project_id, $record, $instrument, $event_id,
        $group_id, $survey_hash, $response_id, $repeat_instance
    ) {
        try {
            $this->onPageTop(
                (int)$project_id, $record, (string)$instrument,
                (int)$event_id, (string)$survey_hash
            );
        } catch (\Throwable $e) {
            error_log("[SPS] page_top: " . $e->getMessage());
        }
    }

    private function onPageTop(
        int $pid, $record, string $instrument,
        int $eventId, string $hash
    ): void {
        if (empty($hash)) return;

        $sk = $this->skey($instrument, $eventId);

        // Current real page REDCap is rendering.
        // Must be read before the session checks below.
        $curReal = max(1, (int)($_GET['__page__'] ?? 1));

        // ── Detect new survey attempt ──────────────────────────────────────
        //
        // Every new attempt begins with a GET request to page 1:
        //   • The participant clicked the survey link afresh, OR
        //   • Their data was cleared and they were sent the link again.
        //
        // Back-navigation to page 1 always arrives as a POST (the survey
        // form submits). REDCap resume-to-a-middle-page arrives as a GET
        // but with curReal > 1. So the triple condition below is specific
        // to "brand-new page-1 visit on an existing session" and nothing
        // else — no response_id comparison needed.
        //
        // NOTE: response_id is intentionally NOT used here. REDCap creates a
        // new response row on every page POST within the same attempt, so the
        // hook parameter changes mid-survey (e.g. 5 → 6) and cannot reliably
        // distinguish a new attempt from normal navigation.
        //
        if (
            isset($_SESSION[$sk])
            && $curReal === 1
            && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        ) {
            unset($_SESSION[$sk]);
        }

        // ── Initialise session (first visit, or after reset above) ─────────
        if (!isset($_SESSION[$sk])) {
            [$total, $form] = $this->countPages($hash);
            if ($total <= 3 || !$form)      return;
            if (!$this->isConfigured($form)) return;

            $order = $this->buildOrder($total);
            $_SESSION[$sk] = [
                'order'   => $order,   // [real_page, ...] in shuffled sequence
                'visited' => [],       // set of real pages already shown
                'total'   => $total,
                'form'    => $form,
            ];
        }

        $data    = &$_SESSION[$sk];
        $order   = $data['order'];   // e.g. [1, 4, 2, 3, 5]
        $total   = $data['total'];
        $visited = &$data['visited'];

        // Mark current page as visited
        if (!in_array($curReal, $visited, true)) {
            $visited[] = $curReal;
        }

        // Virtual position = 1-based index of curReal in the order array.
        // array_search must be checked for false BEFORE casting to int,
        // because (int)false === 0 which would corrupt the position.
        $foundAt    = array_search($curReal, $order, true);
        $curVirtual = ($foundAt !== false) ? (int)$foundAt + 1 : 1;

        // Pages still unvisited — used by JS to redirect to an unvisited middle
        // page if the respondent tries to jump straight to the last page.
        // Exclude the last real page itself (always unvisited until the very end).
        $lastRealPage = $order[$total - 1];   // always = $total (pinned last)
        $remaining    = array_values(array_diff($order, $visited, [$lastRealPage]));

        // Save order to optional field on every page once the record exists.
        // The record is empty on page 1 (before REDCap creates it), so the
        // write is naturally skipped there and succeeds from page 2 onward.
        //
        // We intentionally do NOT use a one-shot 'saved' flag here. The flag
        // would persist in the PHP session across a close-and-return, leaving
        // the field empty/stale if REDCap cleared it (admin reset, re-attempt,
        // or a page-save overwrite). Since the order is constant within a
        // session, writing it on every page is safe and idempotent.
        if (!empty($record)) {
            $of = $this->orderField($instrument);
            if ($of) {
                REDCap::saveData($pid, 'array',
                    [$record => [$eventId => [$of => implode(',', $order)]]],
                    'overwrite');
            }
        }

        // Precompute page hashes the JS needs (real pages + sentinel 99999)
        $hashes = [];
        for ($p = 0; $p <= $total + 1; $p++) {
            $hashes[$p] = \Survey::getPageNumHash($p);
        }
        $hashes[99999] = \Survey::getPageNumHash(99999);

        // Build virtual→real map for JS navigation
        $v2r = [];
        foreach ($order as $vi => $rp) {
            $v2r[$vi + 1] = (int)$rp;
        }

        $v2rJson       = json_encode($v2r);
        $hashesJson    = json_encode($hashes);
        $remainingJson = json_encode($remaining);
        $fixProgress   = $this->fixProgressHeader($instrument) ? 'true' : 'false';
        ?>
        <script>
        $(function () {
            var v2r       = <?= $v2rJson ?>,
                hashes    = <?= $hashesJson ?>,
                remaining = <?= $remainingJson ?>,
                total     = <?= (int)$total ?>,
                curR      = <?= (int)$curReal ?>,
                curV      = <?= (int)$curVirtual ?>,
                fixProgress = <?= $fixProgress ?>;

            console.log('[SPS] real=' + curR + ' virtual=' + curV + '/' + total
                + '  order='     + JSON.stringify(v2r)
                + '  remaining=' + JSON.stringify(remaining));

            // Fix page counter display
            var pgEl = document.getElementById('surveypagenum')
                     || document.getElementById('pagecounter');
            if (pgEl) {
                pgEl.innerHTML = pgEl.innerHTML
                    .replace(/\d+(\s*(?:of|\/)\s*)\d+/i, curV + '$1' + total);
            }

            // Inject progress bar if enabled
            if (fixProgress) {
                // Calculate the correct percentage based on virtual position
                // Page 1 = 0%, last page = 100%
                var pct = Math.round(((curV - 1) / (total - 1)) * 100);
                var filledWidth = pct;
                var emptyWidth = 100 - pct;

                // Build the progress bar HTML
                var progressHtml =
                    '<div id="sps-progress-bar" style="width: 100%; max-width: 400px; margin: 10px auto 15px auto;">' +
                        '<table style="background-color: #e0e0e0; border-radius: 15px; border-collapse: separate; width: 100%;" border="0" cellspacing="0" cellpadding="3">' +
                            '<tbody><tr>';

                if (pct > 0) {
                    progressHtml +=
                        '<td style="background-color: #7f7776; height: 25px; border-radius: 12px; text-align: center; color: white; font-weight: bold; font-size: 14px; min-width: 50px;" width="' + filledWidth + '%">' + pct + '%</td>';
                }
                if (pct < 100) {
                    progressHtml +=
                        '<td style="background-color: transparent;" width="' + emptyWidth + '%">&nbsp;</td>';
                }

                progressHtml += '</tr></tbody></table></div>';

                // Find the best place to inject the progress bar
                // Try survey title area first, then form container
                var surveyTitle = document.getElementById('surveytitlelogo')
                               || document.getElementById('surveytitle')
                               || document.querySelector('.surveyTitle');
                var formContainer = document.getElementById('questiontable')
                                 || document.getElementById('form');

                var targetEl = surveyTitle || formContainer;
                if (targetEl) {
                    // Insert after the survey title, or at the beginning of the form
                    var progressDiv = document.createElement('div');
                    progressDiv.innerHTML = progressHtml;

                    if (surveyTitle && surveyTitle.parentNode) {
                        surveyTitle.parentNode.insertBefore(progressDiv.firstChild, surveyTitle.nextSibling);
                    } else if (formContainer) {
                        formContainer.insertBefore(progressDiv.firstChild, formContainer.firstChild);
                    }

                    console.log('[SPS] Injected progress bar: ' + pct + '% (page ' + curV + '/' + total + ')');
                }
            }

            var form = document.getElementById('form');
            if (!form) return;

            // Hidden field carrying the desired target page to the server
            var spsField = document.createElement('input');
            spsField.type  = 'hidden';
            spsField.name  = '__sps_target__';
            spsField.value = '';
            form.appendChild(spsField);

            var pageField = form.querySelector('input[name="__page__"]');
            var hashField = form.querySelector('input[name="__page_hash__"]');

            var _orig = window.dataEntrySubmit;
            window.dataEntrySubmit = function (ob) {
                var action  = (ob && ob.name) ? ob.name : '';
                var targetR = 0;

                if (action === 'submit-btn-saveprevpage') {
                    // Back: previous virtual page
                    if (curV > 1) {
                        targetR = v2r[String(curV - 1)];
                    }

                } else if (action === 'submit-btn-saverecord' && curV < total) {
                    // Next: next virtual page
                    var nextV = curV + 1;
                    var nextR = v2r[String(nextV)];

                    // If unvisited middle pages remain AND the next natural page
                    // is not one of them (it is either the last page or an
                    // already-visited middle page), redirect to the first
                    // unvisited middle page instead.  This prevents both
                    // re-visiting already-seen pages after back-navigation AND
                    // skipping straight to the last page.
                    if (remaining.length > 0 && remaining.indexOf(nextR) === -1) {
                        console.log('[SPS] Next page (real=' + nextR + ') already visited or is last; redirecting to first unvisited real page ' + remaining[0] + '.');
                        targetR = remaining[0];
                    } else {
                        targetR = nextR;
                    }
                }

                if (targetR > 0) {
                    spsField.value = String(targetR);
                    // Set __page__ to the target page with its real hash.
                    // Hook 1 (redcap_every_page_before_render) will replace this
                    // with the 99999 sentinel server-side, which bypasses
                    // setPageNum()'s ±1 and the data-save field filter.
                    // If Hook 1 is not active, REDCap will still navigate to
                    // targetR via normal ±1 math (targetR-1 for next, targetR+1
                    // for prev), so we post targetR∓1 as a safe fallback.
                    if (pageField && hashField) {
                        var postPage;
                        if (action === 'submit-btn-saveprevpage') {
                            postPage = targetR + 1;  // REDCap does posted-1 for prev
                        } else {
                            postPage = targetR - 1;  // REDCap does posted+1 for next
                        }
                        pageField.value = String(postPage);
                        hashField.value = hashes[String(postPage)] || '';
                    }
                    console.log('[SPS] → navigating to real page ' + targetR);
                }

                return _orig(ob);
            };
        });
        </script>
        <?php
    }
}
