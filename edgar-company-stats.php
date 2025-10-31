<?php
/**
 * Plugin Name: EDGAR Company Stats
 * Description: Display public company stats using the SEC EDGAR API. Example: [edgar_stats symbol="IBM"]
 * Version: 1.0
 * Author: Your Name
 * License: GPL2
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const EDGAR_COMPANY_STATS_USER_AGENT = 'WordPress Plugin - buildingbettersoftware.io blake@blakehowe.com';

$GLOBALS['edgar_company_stats_last_error'] = '';

/**
 * Persist a readable error message for display/logging.
 */
function edgar_company_stats_set_error(string $message): void
{
    $GLOBALS['edgar_company_stats_last_error'] = $message;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EDGAR Company Stats] ' . $message);
    }
}

/**
 * Retrieve the last plugin error message.
 */
function edgar_company_stats_get_last_error(): string
{
    return isset($GLOBALS['edgar_company_stats_last_error'])
        ? (string) $GLOBALS['edgar_company_stats_last_error']
        : '';
}

/**
 * Reset the stored error message.
 */
function edgar_company_stats_clear_error(): void
{
    $GLOBALS['edgar_company_stats_last_error'] = '';
}

/**
 * Select the most recent entry from an array of fact entries.
 *
 * @param array<int, array<string, mixed>> $entries Fact entries from the SEC response.
 */
function edgar_company_stats_select_latest(array $entries): ?array
{
    $valid_entries = array_filter(
        $entries,
        static function ($entry): bool {
            return is_array($entry) && isset($entry['val']);
        }
    );

    if (empty($valid_entries)) {
        return null;
    }

    usort(
        $valid_entries,
        static function (array $a, array $b): int {
            $a_end = $a['end'] ?? '';
            $b_end = $b['end'] ?? '';

            return strcmp((string) $b_end, (string) $a_end);
        }
    );

    return $valid_entries[0] ?? null;
}

/**
 * Extract a metric (latest value) for a given concept and unit from the facts payload.
 *
 * @param array<string, mixed> $facts Facts payload returned by the SEC.
 */
function edgar_company_stats_extract_metric(array $facts, string $concept, string $unit = 'USD'): ?array
{
    if (! isset($facts['us-gaap'][$concept]['units'][$unit])) {
        return null;
    }

    $entries = $facts['us-gaap'][$concept]['units'][$unit];

    if (! is_array($entries)) {
        return null;
    }

    $latest = edgar_company_stats_select_latest($entries);

    if (! $latest) {
        return null;
    }

    $value = isset($latest['val']) ? (float) $latest['val'] : null;

    if ($value === null) {
        return null;
    }

    $date = $latest['end'] ?? ($latest['fy'] ?? '');

    return [
        'value' => $value,
        'unit'  => $unit,
        'date'  => (string) $date,
    ];
}

/**
 * Format a numeric value as USD currency with thousands separators.
 */
function edgar_company_stats_format_currency(float $value): string
{
    $decimals = $value !== 0.0 && abs($value) < 1 ? 2 : 0;

    return number_format($value, $decimals, '.', ',');
}

/**
 * Format an EDGAR date string (YYYY-MM-DD) into a human readable format.
 */
function edgar_company_stats_format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return 'N/A';
    }

    try {
        $date_time = new DateTime($date);

        return $date_time->format('M j, Y');
    } catch (Exception $exception) {
        return $date;
    }
}

/**
 * Build a printable address string from the SEC address payload.
 *
 * @param array<string, mixed> $address Address information from the SEC.
 */
function edgar_company_stats_format_address(array $address): string
{
    $parts = [];

    foreach (['street1', 'street2', 'city', 'stateOrCountry', 'zipCode', 'country'] as $key) {
        if (! empty($address[$key])) {
            $parts[] = (string) $address[$key];
        }
    }

    return implode(', ', $parts);
}

add_shortcode('edgar_stats', 'edgar_company_stats_render_shortcode');

/**
 * Render the EDGAR company stats shortcode output.
 */
function edgar_company_stats_render_shortcode($atts): string
{
    $atts = shortcode_atts(
        [
            'symbol' => 'IBM',
        ],
        $atts,
        'edgar_stats'
    );

    $symbol = strtoupper(sanitize_text_field((string) $atts['symbol']));

    if ($symbol === '') {
        return '<div>Invalid symbol provided.</div>';
    }

    $cik = edgar_get_cik($symbol);

    if (! $cik) {
        $error_message = edgar_company_stats_get_last_error();
        $message       = $error_message !== ''
            ? $error_message
            : 'Unable to find company CIK for symbol ' . $symbol . '.';

        return '<div class="edgar-stats-error">' . esc_html($message) . '</div>';
    }

    edgar_company_stats_clear_error();
    $facts = edgar_get_facts($cik);
    $facts_error = '';

    if (! $facts) {
        $facts_error = edgar_company_stats_get_last_error();
        edgar_company_stats_clear_error();
    }

    $profile = edgar_get_company_profile($cik);
    $profile_error = '';

    if (! $profile) {
        $profile_error = edgar_company_stats_get_last_error();
        edgar_company_stats_clear_error();
    }

    if (! $facts && ! $profile) {
        $message = $facts_error !== ''
            ? $facts_error
            : ($profile_error !== '' ? $profile_error : 'Unable to retrieve company information for ' . $symbol . '.');

        return '<div class="edgar-stats-error">' . esc_html($message) . '</div>';
    }

    $display_name = $facts['name'] ?? ($profile['name'] ?? $symbol);
    $metrics      = $facts['metrics'] ?? [];
    $recent_filing_date = $facts['recent_filing'] ?? '';

    $tickers = [];
    if (! empty($profile['tickers'])) {
        $tickers = $profile['tickers'];
    }

    if (! in_array($symbol, $tickers, true)) {
        array_unshift($tickers, $symbol);
        $tickers = array_unique($tickers);
    }

    $exchanges = $profile['exchanges'] ?? [];

    $overview_items = [
        'CIK'                     => $cik,
        'Primary Tickers'         => ! empty($tickers) ? implode(', ', $tickers) : '',
        'Exchanges'               => ! empty($exchanges) ? implode(', ', $exchanges) : '',
        'Entity Type'             => $profile['entity_type'] ?? '',
        'Category'                => $profile['category'] ?? '',
        'SIC'                     => $profile['sic'] ?? '',
        'SIC Description'         => $profile['sic_description'] ?? '',
        'State of Incorporation'  => $profile['state_of_incorporation'] ?? '',
        'State Description'       => $profile['state_description'] ?? '',
        'Fiscal Year End'         => $profile['fiscal_year_end'] ?? '',
        'EIN'                     => $profile['ein'] ?? '',
        'LEI'                     => $profile['lei'] ?? '',
        'Phone'                   => $profile['phone'] ?? '',
        'Website'                 => $profile['website'] ?? '',
        'Investor Relations Site' => $profile['investor_website'] ?? '',
        'Most Recent Filing'      => $recent_filing_date,
    ];

    $business_address = isset($profile['addresses']['business']) && is_array($profile['addresses']['business'])
        ? edgar_company_stats_format_address($profile['addresses']['business'])
        : '';

    $mailing_address = isset($profile['addresses']['mailing']) && is_array($profile['addresses']['mailing'])
        ? edgar_company_stats_format_address($profile['addresses']['mailing'])
        : '';

    $recent_filings = $profile['recent_filings'] ?? [];

    ob_start();
    ?>
    <div class="edgar-stats">
        <h3><?php echo esc_html($display_name); ?> (<?php echo esc_html($symbol); ?>)</h3>

        <div class="edgar-stats-section">
            <h4>Company Overview</h4>
            <ul class="edgar-stats-list">
                <?php foreach ($overview_items as $label => $value) :
                    if ($value === '' || $value === 'N/A') {
                        continue;
                    }

                    $is_url = in_array($label, ['Website', 'Investor Relations Site'], true) && filter_var($value, FILTER_VALIDATE_URL);
                    ?>
                    <li>
                        <strong><?php echo esc_html($label); ?>:</strong>
                        <?php if ($is_url) : ?>
                            <a href="<?php echo esc_url($value); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($value); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($value); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($profile && $profile_error !== '') : ?>
                <p class="edgar-stats-warning">Profile note: <?php echo esc_html($profile_error); ?></p>
            <?php endif; ?>
        </div>

        <?php if (! empty($business_address) || ! empty($mailing_address)) : ?>
            <div class="edgar-stats-addresses">
                <?php if (! empty($business_address)) : ?>
                    <div class="edgar-stats-section">
                        <h4>Business Address</h4>
                        <p><?php echo esc_html($business_address); ?></p>
                    </div>
                <?php endif; ?>
                <?php if (! empty($mailing_address)) : ?>
                    <div class="edgar-stats-section">
                        <h4>Mailing Address</h4>
                        <p><?php echo esc_html($mailing_address); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (! empty($metrics)) : ?>
            <div class="edgar-stats-section">
                <h4>Key Financial Metrics</h4>
                <table class="edgar-stats-table">
                    <thead>
                        <tr>
                            <th scope="col">Metric</th>
                            <th scope="col">Value</th>
                            <th scope="col">As of</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metrics as $label => $metric) :
                            $formatted_value = $metric['value'];
                            if ($metric['format'] === 'currency') {
                                $formatted_value = '$' . edgar_company_stats_format_currency((float) $metric['value']);
                            } elseif ($metric['format'] === 'number') {
                                $formatted_value = edgar_company_stats_format_currency((float) $metric['value']);
                            } elseif ($metric['format'] === 'decimal') {
                                $formatted_value = number_format((float) $metric['value'], 2, '.', ',');
                            }

                            $metric_date = edgar_company_stats_format_date($metric['date']);
                            ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td><?php echo esc_html($formatted_value); ?></td>
                                <td><?php echo esc_html($metric_date); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($facts_error !== '') : ?>
            <p class="edgar-stats-warning">Financial data note: <?php echo esc_html($facts_error); ?></p>
        <?php endif; ?>

        <?php if (! empty($recent_filings)) : ?>
            <div class="edgar-stats-section">
                <h4>Recent Filings</h4>
                <table class="edgar-stats-table">
                    <thead>
                        <tr>
                            <th scope="col">Form</th>
                            <th scope="col">Filing Date</th>
                            <th scope="col">Report Date</th>
                            <th scope="col">Accession</th>
                            <th scope="col">Document</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_filings as $filing) :
                            $filing_date  = edgar_company_stats_format_date($filing['filing_date']);
                            $report_date  = edgar_company_stats_format_date($filing['report_date']);
                            $document_url = $filing['document_url'];
                            ?>
                            <tr>
                                <td><?php echo esc_html($filing['form']); ?></td>
                                <td><?php echo esc_html($filing_date); ?></td>
                                <td><?php echo esc_html($report_date); ?></td>
                                <td><?php echo esc_html($filing['accession']); ?></td>
                                <td>
                                    <?php if ($document_url !== '') : ?>
                                        <a href="<?php echo esc_url($document_url); ?>" target="_blank" rel="noopener noreferrer">View Document</a>
                                    <?php else : ?>
                                        <?php echo esc_html($filing['primary_document']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($profile && ! empty($profile['description'])) : ?>
            <div class="edgar-stats-section">
                <h4>Company Description</h4>
                <p><?php echo esc_html($profile['description']); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Retrieve the CIK for a given ticker symbol.
 */
function edgar_get_cik(string $symbol)
{
    $url      = 'https://www.sec.gov/files/company_tickers.json';
    $response = wp_remote_get(
        $url,
        [
            'headers' => [
                'User-Agent' => EDGAR_COMPANY_STATS_USER_AGENT,
                'Accept'     => 'application/json',
            ],
            'timeout' => 10,
        ]
    );

    if (is_wp_error($response)) {
        edgar_company_stats_set_error('Error retrieving ticker list: ' . $response->get_error_message());

        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if (200 !== $status_code) {
        edgar_company_stats_set_error(
            sprintf('Ticker lookup failed with status code %d. Ensure the User-Agent header is accepted by the SEC.', $status_code)
        );

        return false;
    }

    $body = wp_remote_retrieve_body($response);

    $data = json_decode($body, true);

    if (! is_array($data)) {
        edgar_company_stats_set_error('Unexpected response structure from SEC ticker list.');

        return false;
    }

    foreach ($data as $company) {
        if (! is_array($company) || ! isset($company['ticker'], $company['cik_str'])) {
            continue;
        }

        if (strtoupper((string) $company['ticker']) === strtoupper($symbol)) {
            $cik = str_pad((string) $company['cik_str'], 10, '0', STR_PAD_LEFT);

            return $cik;
        }
    }

    edgar_company_stats_set_error('Symbol ' . $symbol . ' not found in SEC ticker dataset.');

    return false;
}

/**
 * Retrieve key fact data for the given CIK.
 */
function edgar_get_facts(string $cik)
{
    $cik = preg_replace('/[^0-9]/', '', $cik);

    if ($cik === '') {
        edgar_company_stats_set_error('CIK passed to facts lookup is invalid.');

        return false;
    }

    $url      = 'https://data.sec.gov/api/xbrl/companyfacts/CIK' . $cik . '.json';
    $response = wp_remote_get(
        $url,
        [
            'headers' => [
                'User-Agent' => EDGAR_COMPANY_STATS_USER_AGENT,
                'Accept'     => 'application/json',
            ],
            'timeout' => 10,
        ]
    );

    if (is_wp_error($response)) {
        edgar_company_stats_set_error('Error retrieving company facts: ' . $response->get_error_message());

        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if (200 !== $status_code) {
        edgar_company_stats_set_error(
            sprintf('Company facts request failed with status code %d. SEC may be blocking the request.', $status_code)
        );

        return false;
    }

    $body = wp_remote_retrieve_body($response);

    $data = json_decode($body, true);

    if (! is_array($data) || ! isset($data['entityName'])) {
        edgar_company_stats_set_error('Company facts response missing entity name.');

        return false;
    }

    $facts_payload = $data['facts'] ?? [];

    if (! is_array($facts_payload) || empty($facts_payload)) {
        edgar_company_stats_set_error('Company facts payload missing in SEC response.');

        return false;
    }

    $metric_definitions = [
        [
            'label'   => 'Total Assets',
            'concept' => 'Assets',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Total Liabilities',
            'concept' => 'Liabilities',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Current Assets',
            'concept' => 'AssetsCurrent',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Current Liabilities',
            'concept' => 'LiabilitiesCurrent',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Revenue',
            'concept' => 'Revenues',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Net Income',
            'concept' => 'NetIncomeLoss',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Operating Cash Flow',
            'concept' => 'NetCashProvidedByUsedInOperatingActivities',
            'unit'    => 'USD',
            'format'  => 'currency',
        ],
        [
            'label'   => 'Diluted EPS',
            'concept' => 'EarningsPerShareDiluted',
            'unit'    => 'USD/shares',
            'format'  => 'decimal',
        ],
        [
            'label'   => 'Shares Outstanding',
            'concept' => 'CommonStockSharesOutstanding',
            'unit'    => 'shares',
            'format'  => 'number',
        ],
    ];

    $metrics = [];
    $recent_filing = 'N/A';

    foreach ($metric_definitions as $definition) {
        $metric = edgar_company_stats_extract_metric($facts_payload, $definition['concept'], $definition['unit']);

        if (! $metric) {
            continue;
        }

        if ($recent_filing === 'N/A' && $metric['date'] !== '') {
            $recent_filing = $metric['date'];
        }

        $metrics[$definition['label']] = array_merge(
            $metric,
            [
                'format' => $definition['format'],
            ]
        );
    }

    if (empty($metrics)) {
        edgar_company_stats_set_error('No financial metrics were found in the SEC facts dataset for this company.');
    }

    return [
        'name'          => (string) $data['entityName'],
        'metrics'       => $metrics,
        'recent_filing' => (string) $recent_filing,
    ];
}

/**
 * Retrieve company profile and filing data from the SEC submissions endpoint.
 */
function edgar_get_company_profile(string $cik)
{
    $cik = preg_replace('/[^0-9]/', '', $cik);

    if ($cik === '') {
        edgar_company_stats_set_error('CIK passed to company profile lookup is invalid.');

        return false;
    }

    $url      = 'https://data.sec.gov/submissions/CIK' . $cik . '.json';
    $response = wp_remote_get(
        $url,
        [
            'headers' => [
                'User-Agent' => EDGAR_COMPANY_STATS_USER_AGENT,
                'Accept'     => 'application/json',
            ],
            'timeout' => 10,
        ]
    );

    if (is_wp_error($response)) {
        edgar_company_stats_set_error('Error retrieving company profile: ' . $response->get_error_message());

        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if (200 !== $status_code) {
        edgar_company_stats_set_error(
            sprintf('Company profile request failed with status code %d.', $status_code)
        );

        return false;
    }

    $body = wp_remote_retrieve_body($response);

    $data = json_decode($body, true);

    if (! is_array($data)) {
        edgar_company_stats_set_error('Company profile response is not valid JSON.');

        return false;
    }

    $addresses = [
        'business' => [],
        'mailing'  => [],
    ];

    if (isset($data['addresses']) && is_array($data['addresses'])) {
        $addresses['business'] = is_array($data['addresses']['business'] ?? null)
            ? $data['addresses']['business']
            : [];
        $addresses['mailing'] = is_array($data['addresses']['mailing'] ?? null)
            ? $data['addresses']['mailing']
            : [];
    }

    $tickers = isset($data['tickers']) && is_array($data['tickers'])
        ? array_values(array_filter($data['tickers']))
        : [];

    $exchanges = isset($data['exchanges']) && is_array($data['exchanges'])
        ? array_values(array_filter($data['exchanges']))
        : [];

    $recent_filings = [];
    $filings        = $data['filings']['recent'] ?? [];

    if (is_array($filings) && ! empty($filings)) {
        $accessions       = is_array($filings['accessionNumber'] ?? null) ? $filings['accessionNumber'] : [];
        $forms            = is_array($filings['form'] ?? null) ? $filings['form'] : [];
        $filing_dates     = is_array($filings['filingDate'] ?? null) ? $filings['filingDate'] : [];
        $report_dates     = is_array($filings['reportDate'] ?? null) ? $filings['reportDate'] : [];
        $acceptance_dates = is_array($filings['acceptanceDateTime'] ?? null) ? $filings['acceptanceDateTime'] : [];
        $primary_docs     = is_array($filings['primaryDocument'] ?? null) ? $filings['primaryDocument'] : [];
        $doc_descriptions = is_array($filings['primaryDocDescription'] ?? null) ? $filings['primaryDocDescription'] : [];
        $items            = is_array($filings['items'] ?? null) ? $filings['items'] : [];
        $acts             = is_array($filings['act'] ?? null) ? $filings['act'] : [];
        $file_numbers     = is_array($filings['fileNumber'] ?? null) ? $filings['fileNumber'] : [];
        $film_numbers     = is_array($filings['filmNumber'] ?? null) ? $filings['filmNumber'] : [];
        $sizes            = is_array($filings['size'] ?? null) ? $filings['size'] : [];

        $limit = min(10, count($accessions));
        $cik_no_leading = ltrim($cik, '0');

        for ($index = 0; $index < $limit; $index++) {
            $accession        = (string) ($accessions[$index] ?? '');
            $primary_document = (string) ($primary_docs[$index] ?? '');

            $clean_accession = $accession !== ''
                ? str_replace('-', '', $accession)
                : '';

            $document_url = '';

            if ($clean_accession !== '' && $primary_document !== '') {
                $document_url = sprintf(
                    'https://www.sec.gov/Archives/edgar/data/%s/%s/%s',
                    $cik_no_leading !== '' ? $cik_no_leading : $cik,
                    $clean_accession,
                    $primary_document
                );
            }

            $recent_filings[] = [
                'form'              => (string) ($forms[$index] ?? ''),
                'filing_date'       => (string) ($filing_dates[$index] ?? ''),
                'report_date'       => (string) ($report_dates[$index] ?? ''),
                'acceptance_date'   => (string) ($acceptance_dates[$index] ?? ''),
                'accession'         => $accession,
                'primary_document'  => $primary_document,
                'document_url'      => $document_url,
                'document_description' => (string) ($doc_descriptions[$index] ?? ''),
                'items'             => (string) ($items[$index] ?? ''),
                'act'               => (string) ($acts[$index] ?? ''),
                'file_number'       => (string) ($file_numbers[$index] ?? ''),
                'film_number'       => (string) ($film_numbers[$index] ?? ''),
                'size'              => (string) ($sizes[$index] ?? ''),
            ];
        }
    }

    return [
        'name'                     => (string) ($data['name'] ?? ''),
        'description'              => (string) ($data['description'] ?? ''),
        'entity_type'              => (string) ($data['entityType'] ?? ''),
        'category'                 => (string) ($data['category'] ?? ''),
        'sic'                      => (string) ($data['sic'] ?? ''),
        'sic_description'          => (string) ($data['sicDescription'] ?? ''),
        'state_of_incorporation'   => (string) ($data['stateOfIncorporation'] ?? ''),
        'state_description'        => (string) ($data['stateOfIncorporationDescription'] ?? ''),
        'fiscal_year_end'          => (string) ($data['fiscalYearEnd'] ?? ''),
        'ein'                      => (string) ($data['ein'] ?? ''),
        'lei'                      => (string) ($data['lei'] ?? ''),
        'phone'                    => (string) ($data['phone'] ?? ''),
        'website'                  => (string) ($data['website'] ?? ''),
        'investor_website'         => (string) ($data['investorWebsite'] ?? ''),
        'tickers'                  => $tickers,
        'exchanges'                => $exchanges,
        'addresses'                => $addresses,
        'recent_filings'           => $recent_filings,
    ];
}

add_action('wp_head', 'edgar_company_stats_inline_styles');

/**
 * Output simple inline styling for the shortcode output.
 */
function edgar_company_stats_inline_styles(): void
{
    echo '<style>
        .edgar-stats { border:1px solid #ccc; padding:24px; max-width:900px; font-family:sans-serif; background:#fff; margin:40px auto; box-shadow:0 6px 16px rgba(0,0,0,0.08); line-height:1.5; }
        .edgar-stats h3 { margin-top:0; color:#003366; }
        .edgar-stats h4 { margin-bottom:8px; color:#1a3b5d; }
        .edgar-stats-section { margin-bottom:24px; }
        .edgar-stats-list { list-style:none; padding:0; margin:0; }
        .edgar-stats-list li { margin:6px 0; }
        .edgar-stats-addresses { display:flex; flex-wrap:wrap; gap:24px; }
        .edgar-stats-addresses .edgar-stats-section { flex:1 1 260px; }
        .edgar-stats-table { width:100%; border-collapse:collapse; margin-top:12px; }
        .edgar-stats-table th, .edgar-stats-table td { border:1px solid #e0e0e0; padding:8px 10px; text-align:left; }
        .edgar-stats-table th { background:#f3f6fa; font-weight:600; }
        .edgar-stats-warning { margin-top:12px; padding:10px 12px; background:#fff4cd; border:1px solid #f7e39c; color:#4a3b00; border-radius:4px; }
        .edgar-stats a { color:#0b5ca5; text-decoration:none; }
        .edgar-stats a:hover { text-decoration:underline; }
    </style>';
}


