<?php
/**
 * Survey Pages Shuffle — REDCap External Module
 *
 * Randomizes page order within a single multi-page survey.
 * Pages are defined by Section Headers in the Online Designer.
 *
 * First page and last page are ALWAYS kept in place (page 1 stays first,
 * page N stays last).  Only pages 2 … N-1 are shuffled.  This guarantees
 * that REDCap's native Submit button on the last page works normally.
 *
 * @author Ihab Zeedia <ihab.zeedia@stanford.edu>
 */

namespace Stanford\SurveyPagesShuffle;

use ExternalModules\AbstractExternalModule;
use REDCap;

class SurveyPagesShuffle extends AbstractExternalModule
{
    /* ---------- helpers ---------- */

    private function skey(string $hash): string
    {
        return "survey_pages_shuffle_{$hash}";
    }

    /**
     * Look up total page count, form name and project id from the survey hash.
     */
    private function countPagesFromDB(string $surveyHash): array
    {
        $sql = "SELECT s.project_id, s.form_name, s.question_by_section
                FROM redcap_surveys s
                JOIN redcap_surveys_participants p ON s.survey_id = p.survey_id
                WHERE p.hash = '" . db_escape($surveyHash) . "'
                  AND p.event_id IS NOT NULL
                LIMIT 1";
        $q = db_query($sql);
        if (!$q || db_num_rows($q) === 0) return [0, null, null];
        $row = db_fetch_assoc($q);

        $pid  = (int)$row['project_id'];
        $form = $row['form_name'];
        if (!(int)$row['question_by_section']) return [1, $form, $pid];

        $pk = db_result(db_query(
            "SELECT field_name FROM redcap_metadata WHERE project_id = $pid ORDER BY field_order LIMIT 1"
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

    private function isFormConfigured(string $form): bool
    {
        return in_array($form, $this->getProjectSetting('instrument') ?? [], true);
    }

    private function getOrderField(string $form): ?string
    {
        $instruments = $this->getProjectSetting('instrument')       ?? [];
        $oF          = $this->getProjectSetting('page-order-field') ?? [];
        foreach ($instruments as $i => $inst) {
            if ($inst === $form) return $oF[$i] ?? null;
        }
        return null;
    }

    /**
     * Build the shuffled page order.
     * Page 1 is always first, page N is always last.
     * Only pages 2 … N-1 are shuffled.
     * Needs at least 4 pages to actually shuffle anything.
     */
    private function makeOrder(int $total): array
    {
        if ($total <= 3) return range(1, $total);
        $mid = range(2, $total - 1);
        shuffle($mid);
        return array_merge([1], $mid, [$total]);
    }

    /**
     * Return two maps:
     *   $v2r  virtual-position → real-page
     *   $r2v  real-page → virtual-position
     */
    private function maps(array $order): array
    {
        $v2r = $r2v = [];
        foreach ($order as $vi => $rp) {
            $v2r[$vi + 1]  = (int)$rp;
            $r2v[(int)$rp] = $vi + 1;
        }
        return [$v2r, $r2v];
    }

    /* ================================================================ */
    /*  HOOK — redcap_survey_page_top                                   */
    /* ================================================================ */
    public function redcap_survey_page_top(
        $project_id, $record, $instrument, $event_id,
        $group_id, $survey_hash, $response_id, $repeat_instance
    ) {
        try {
            $this->handleSurveyPageTop(
                (int)$project_id, $record, (string)$instrument,
                (int)$event_id, (string)$survey_hash
            );
        } catch (\Throwable $e) {
            error_log("SPS ERROR: " . $e->getMessage());
        }
    }

    private function handleSurveyPageTop(
        int $project_id, $record, string $instrument,
        int $event_id, string $survey_hash
    ): void {
        if (empty($survey_hash)) return;

        $sk = $this->skey($survey_hash);

        /* --- Initialise the shuffle on first visit --- */
        if (!isset($_SESSION[$sk])) {
            [$totalPages, $formName, $pid] = $this->countPagesFromDB($survey_hash);
            if ($totalPages <= 3 || !$formName) return;
            if (!$this->isFormConfigured($formName)) return;

            $order = $this->makeOrder($totalPages);
            $_SESSION[$sk] = [
                'order'       => $order,
                'total_pages' => $totalPages,
                'form_name'   => $formName,
            ];
        }

        $order = $_SESSION[$sk]['order'];
        $total = $_SESSION[$sk]['total_pages'];
        [$v2r, $r2v] = $this->maps($order);

        $curReal    = max(1, (int)($_GET['__page__'] ?? 1));
        $curVirtual = $r2v[$curReal] ?? 1;

        /* Save order to field once */
        if (!isset($_SESSION[$sk]['saved']) && !empty($record)) {
            $of = $this->getOrderField($instrument);
            if ($of) {
                REDCap::saveData($project_id, 'array',
                    [$record => [$event_id => [$of => implode(',', $order)]]],
                    'overwrite');
            }
            $_SESSION[$sk]['saved'] = true;
        }

        /*
         * Precompute page hashes for every possible __page__ value we might
         * POST.  The range of "fake" page values is 0 … total+1 because
         * REDCap does ±1 on the posted value to get the target page.
         */
        $pageHashes = [];
        for ($p = 0; $p <= $total + 1; $p++) {
            $pageHashes[$p] = \Survey::getPageNumHash($p);
        }

        $v2rJson    = json_encode($v2r);
        $r2vJson    = json_encode($r2v);
        $hashesJson = json_encode($pageHashes);
        ?>
        <script>
        $(function(){
            var v2r    = <?=$v2rJson?>,
                r2v    = <?=$r2vJson?>,
                hashes = <?=$hashesJson?>,
                total  = <?=(int)$total?>,
                curR   = <?=(int)$curReal?>,
                curV   = <?=(int)$curVirtual?>;

            /* --- Fix page counter display --- */
            var el = document.getElementById('surveypagenum') || document.getElementById('pagecounter');
            if (el) el.innerHTML = el.innerHTML.replace(/\d+(\s*(?:of|\/)\s*)\d+/i, curV + '$1' + total);

            /* --- Intercept form submission to rewrite __page__ --- */
            var form = document.getElementById('form');
            if (!form) return;

            var pageField = form.querySelector('input[name="__page__"]');
            var hashField = form.querySelector('input[name="__page_hash__"]');
            if (!pageField || !hashField) return;

            /* Override dataEntrySubmit to rewrite page before REDCap processes */
            var origSubmit = window.dataEntrySubmit;
            window.dataEntrySubmit = function(ob) {
                var action = (ob && ob.name) ? ob.name : '';

                if (action === 'submit-btn-saveprevpage') {
                    /* Going back: REDCap will do posted_page - 1 to get target */
                    var prevV = curV - 1;
                    if (prevV >= 1) {
                        var targetR = v2r[String(prevV)];
                        /* Need: posted - 1 = targetR  →  posted = targetR + 1 */
                        var newPage = targetR + 1;
                        pageField.value = newPage;
                        hashField.value = hashes[String(newPage)] || '';
                    }
                    return origSubmit(ob);
                }

                if (action === 'submit-btn-saverecord') {
                    var nextV = curV + 1;
                    if (nextV <= total) {
                        var targetR = v2r[String(nextV)];
                        /* Need: posted + 1 = targetR  →  posted = targetR - 1 */
                        var newPage = targetR - 1;
                        pageField.value = newPage;
                        hashField.value = hashes[String(newPage)] || '';
                    }
                    /* If nextV > total → last page, normal submit, no rewrite */
                    return origSubmit(ob);
                }

                /* Any other button (save & return later, etc.) — pass through */
                return origSubmit(ob);
            };
        });
        </script>
        <?php
    }
}
