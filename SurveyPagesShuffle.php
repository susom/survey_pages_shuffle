<?php
/**
 * Survey Pages Shuffle - REDCap External Module
 *
 * Randomizes the order of pages within a single survey for each respondent.
 * Survey pages are defined by Section Headers in REDCap's Online Designer.
 *
 * Strategy:
 *   - On redcap_survey_page_top, compute the list of "real" REDCap pages
 *     (groups of fields delimited by section headers) for the configured instrument.
 *   - On the very first page visit (real page 1, no prior shuffle in session),
 *     generate a shuffled order and store it in $_SESSION.
 *   - On every page, inject JavaScript that intercepts the Next/Back buttons
 *     to rewrite the hidden __page__ input to the correct shuffled real page
 *     before form submission, and updates the visible page counter.
 *   - Optionally saves the shuffled page order to a REDCap text field.
 *
 * @author  Ihab Zeedia <ihab.zeedia@stanford.edu>
 */

namespace Stanford\SurveyPagesShuffle;

use ExternalModules\AbstractExternalModule;
use REDCap;

class SurveyPagesShuffle extends AbstractExternalModule
{
    public function __construct()
    {
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Build a real-page → field-list map for an instrument.
    // Page number increments whenever a field carries a non-empty
    // element_preceding_header (= section header) and is not the very first field.
    // -------------------------------------------------------------------------
    private function buildPageMap(string $instrument): array
    {
        global $Proj, $table_pk;

        if (empty($Proj->forms[$instrument])) {
            return [];
        }

        $pageMap = [];
        $page    = 1;
        $isFirst = true;

        foreach (array_keys($Proj->forms[$instrument]['fields']) as $fieldName) {
            if ($fieldName === $table_pk || $fieldName === $instrument . '_complete') {
                continue;
            }
            if (!$isFirst && !empty($Proj->metadata[$fieldName]['element_preceding_header'])) {
                $page++;
            }
            $pageMap[$page][] = $fieldName;
            $isFirst          = false;
        }

        return $pageMap;
    }

    // -------------------------------------------------------------------------
    // Return the config block for a given instrument (or null if not configured)
    // -------------------------------------------------------------------------
    private function getInstrumentConfig(string $instrument): ?array
    {
        $instruments  = $this->getProjectSetting('instrument');
        $exclFirst    = $this->getProjectSetting('exclude-first-page');
        $exclLast     = $this->getProjectSetting('exclude-last-page');
        $orderFields  = $this->getProjectSetting('page-order-field');

        if (empty($instruments)) {
            return null;
        }

        foreach ((array)$instruments as $i => $inst) {
            if ($inst === $instrument) {
                return [
                    'instrument'    => $inst,
                    'exclude_first' => !empty($exclFirst[$i]),
                    'exclude_last'  => !empty($exclLast[$i]),
                    'order_field'   => $orderFields[$i] ?? null,
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Session key unique to this project + survey response
    // -------------------------------------------------------------------------
    private function sessionKey(int $projectId, string $surveyHash): string
    {
        return "sps_{$projectId}_{$surveyHash}";
    }

    // -------------------------------------------------------------------------
    // Generate a shuffled order array (1-based real page numbers).
    // e.g. for totalPages=4, excludeFirst=true, excludeLast=false
    //      might return [1, 3, 2, 4]
    // -------------------------------------------------------------------------
    private function generateShuffleOrder(
        int  $totalPages,
        bool $excludeFirst,
        bool $excludeLast
    ): array {
        if ($totalPages <= 1) {
            return [1];
        }

        $start  = $excludeFirst ? 2 : 1;
        $end    = $excludeLast  ? ($totalPages - 1) : $totalPages;

        $middle = range($start, $end);
        shuffle($middle);

        $order = [];
        if ($excludeFirst) {
            $order[] = 1;
        }
        foreach ($middle as $p) {
            $order[] = $p;
        }
        if ($excludeLast) {
            $order[] = $totalPages;
        }

        return $order;
    }

    // -------------------------------------------------------------------------
    // redcap_survey_page_top
    // Fires near the top of every rendered survey page (after HTML <head> is
    // emitted but before form fields are printed).
    // -------------------------------------------------------------------------
    public function redcap_survey_page_top(
        $project_id,
        $record,
        $instrument,
        $event_id,
        $group_id,
        $survey_hash,
        $response_id,
        $repeat_instance
    ) {
        $config = $this->getInstrumentConfig((string)$instrument);
        if (empty($config)) {
            return;
        }

        global $Proj;

        // Build the real page map
        $pageMap    = $this->buildPageMap((string)$instrument);
        $totalPages = count($pageMap);

        if ($totalPages <= 1) {
            return; // nothing to shuffle
        }

        // Current real page (REDCap sets this GET param before calling the hook)
        $currentRealPage = max(1, (int)($_GET['__page__'] ?? 1));

        // ---- Session management ----
        $sessionKey = $this->sessionKey((int)$project_id, (string)$survey_hash);

        if (!isset($_SESSION[$sessionKey])) {
            // Generate shuffle on first page load of this response
            $order = $this->generateShuffleOrder(
                $totalPages,
                (bool)$config['exclude_first'],
                (bool)$config['exclude_last']
            );

            $_SESSION[$sessionKey] = [
                'order'       => $order,
                'total_pages' => $totalPages,
            ];

            // Persist to a REDCap field if configured
            if (!empty($config['order_field']) && !empty($record)) {
                REDCap::saveData(
                    (int)$project_id,
                    'array',
                    [$record => [$event_id => [$config['order_field'] => implode(',', $order)]]],
                    'overwrite'
                );
            }
        }

        $order = $_SESSION[$sessionKey]['order']; // e.g. [3, 1, 2, 4]

        // ---- Build virtual ↔ real maps ----
        $virtualToReal = []; // virtual page (1-based) => real page
        $realToVirtual = []; // real page => virtual page (1-based)
        foreach ($order as $vIdx => $realPage) {
            $vPage                   = $vIdx + 1;
            $virtualToReal[$vPage]   = (int)$realPage;
            $realToVirtual[(int)$realPage] = $vPage;
        }

        $currentVirtualPage = $realToVirtual[$currentRealPage] ?? 1;

        // The real page we should navigate TO on Next / Back
        $nextVP       = $currentVirtualPage + 1;
        $prevVP       = $currentVirtualPage - 1;
        $nextRealPage = isset($virtualToReal[$nextVP])
            ? $virtualToReal[$nextVP]
            : ($totalPages + 1); // signals survey completion to REDCap
        $prevRealPage = ($prevVP >= 1 && isset($virtualToReal[$prevVP]))
            ? $virtualToReal[$prevVP]
            : 1;

        // ---- Pre-compute page hashes for every real page ----
        // REDCap verifies __page_hash__ == Survey::getPageNumHash(__page__) on POST.
        // Because the hash uses server-side salts we cannot recompute it in JS,
        // so we compute all hashes here and pass them to the client.
        $pageHashes = [];
        for ($p = 1; $p <= ($totalPages + 1); $p++) {
            $pageHashes[$p] = \Survey::getPageNumHash($p);
        }

        // ---- Inject JS ----
        $jsData = [
            'totalPages'         => $totalPages,
            'currentRealPage'    => $currentRealPage,
            'currentVirtualPage' => $currentVirtualPage,
            'nextRealPage'       => $nextRealPage,
            'prevRealPage'       => $prevRealPage,
            'order'              => $order,
            'virtualToReal'      => $virtualToReal,
            'realToVirtual'      => $realToVirtual,
            'pageHashes'         => $pageHashes,
        ];

        echo $this->initializeJavascriptModuleObject();
        ?>
        <script>
        (function () {
            var SPS = <?= $this->getJavascriptModuleObjectName() ?>;
            SPS.shuffleData = <?= json_encode($jsData, JSON_UNESCAPED_UNICODE) ?>;
        })();
        </script>
        <?php
        echo '<script src="' . $this->getUrl('assets/shuffle.js', true) . '"></script>';
    }

    // -------------------------------------------------------------------------
    // AJAX handler
    // -------------------------------------------------------------------------
    public function redcap_module_ajax(
        $action, $payload, $project_id, $record, $instrument, $event_id,
        $repeat_instance, $survey_hash, $response_id, $survey_queue_hash,
        $page, $page_full, $user_id, $group_id
    ) {
        switch ($action) {
            case 'getShuffleOrder':
                $key  = $this->sessionKey((int)$project_id, (string)$survey_hash);
                $data = $_SESSION[$key] ?? null;
                return ['success' => !empty($data), 'data' => $data];

            default:
                throw new \Exception("Action '$action' is not defined.");
        }
    }

}
