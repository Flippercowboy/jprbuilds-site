<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Solis Cloud API credentials ──
define('SOLIS_KEY_ID',     '1300386381676683008');
define('SOLIS_KEY_SECRET', '6d8bf199d09149bea5339f219347b372');
define('SOLIS_API_URL',    'https://www.soliscloud.com:13333');

// ── Solcast credentials ──
define('SOLCAST_API_KEY',   '7Y7ZvkmpmPi3byFQC5LYZQ6pErY8MY6e');
define('SOLCAST_RESOURCE',  '5b50-cdad-7f7c-abc1');

// ── Cache directory ──
define('CACHE_DIR', '/tmp/solar_cache/');
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);

// ─────────────────────────────────────────
//  ROUTING
// ─────────────────────────────────────────
$action = $_GET['action'] ?? 'states';

switch ($action) {
    case 'states':   echo json_encode(getSolisStates());     break;
    case 'forecast': echo json_encode(getSolcastForecast()); break;
    case 'monthly':  echo json_encode(getSolisMonthly());    break;
    default:         echo json_encode(['error' => 'Unknown action']); break;
}

// ─────────────────────────────────────────
//  SOLIS API HELPER
// ─────────────────────────────────────────
function solisRequest($path, $body) {
    $bodyStr     = json_encode($body);
    $md5         = base64_encode(md5($bodyStr, true));
    $date        = gmdate('D, d M Y H:i:s \G\M\T');
    $contentType = 'application/json';
    $stringToSign = "POST\n$md5\n$contentType\n$date\n$path";
    $hmac        = base64_encode(hash_hmac('sha1', $stringToSign, SOLIS_KEY_SECRET, true));
    $auth        = 'API ' . SOLIS_KEY_ID . ':' . $hmac;

    $ch = curl_init(SOLIS_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $bodyStr,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: $contentType",
            "Content-MD5: $md5",
            "Date: $date",
            "Authorization: $auth",
        ],
    ]);
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($result, true);
}

// ─────────────────────────────────────────
//  GET LIVE SOLIS STATES
// ─────────────────────────────────────────
function getSolisStates() {
    $cacheFile = CACHE_DIR . 'solis_states.json';
    $cacheTTL  = 300; // 5 minutes

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    // Get station list first
    $stations = solisRequest('/v1/api/userStationList', [
        'pageNo' => 1, 'pageSize' => 10
    ]);

    if (empty($stations['data']['page']['records'])) {
        return ['error' => 'No stations found', 'raw' => $stations];
    }

    $stationId = $stations['data']['page']['records'][0]['id'];

    // Get inverter list for this station
    $inverters = solisRequest('/v1/api/inverterList', [
        'pageNo' => 1, 'pageSize' => 10, 'stationId' => $stationId
    ]);

    if (empty($inverters['data']['page']['records'])) {
        return ['error' => 'No inverters found'];
    }

    $inv        = $inverters['data']['page']['records'][0];
    $inverterId = $inv['id'];
    $inverterSn = $inv['sn'];

    // Get detailed inverter data
    $detail = solisRequest('/v1/api/inverterDetail', [
        'id' => $inverterId, 'sn' => $inverterSn
    ]);

    if (empty($detail['data'])) {
        return ['error' => 'No inverter detail found'];
    }

    $d  = $detail['data'];
    $kw = 1000;

    $states = [
        'sensor.solis_ac_output_total_power'          => ['state' => ($d['pac'] ?? 0) * $kw,                                                          'unit' => 'W'],
        'sensor.solis_energy_today'                   => ['state' => $d['eToday'] ?? 0,                                                               'unit' => 'kWh'],
        'sensor.solis_energy_this_month'              => ['state' => $d['eMonth'] ?? 0,                                                               'unit' => 'kWh'],
        'sensor.solis_energy_this_year'               => ['state' => $d['eYear'] ?? 0,                                                                'unit' => 'kWh'],
        'sensor.solis_energy_total'                   => ['state' => $d['eTotal'] ?? 0,                                                               'unit' => 'kWh'],
        'sensor.solis_battery_power'                  => ['state' => ($d['batteryPower'] ?? 0) * $kw,                                                 'unit' => 'W'],
        'sensor.solis_remaining_battery_capacity'     => ['state' => $d['battery_capacity_soc'] ?? $d['batteryCapacitySoc'] ?? 0,                    'unit' => '%'],
        'sensor.solis_battery_voltage'                => ['state' => $d['battery_voltage'] ?? $d['batteryVoltage'] ?? 0,                             'unit' => 'V'],
        'sensor.solis_battery_current'                => ['state' => $d['bsttery_current'] ?? $d['batteryCurrent'] ?? 0,                             'unit' => 'A'],
        'sensor.solis_battery_state_of_health'        => ['state' => $d['battery_health_soh'] ?? 0,                                                  'unit' => '%'],
        'sensor.solis_power_grid_total_power'         => ['state' => ($d['psum'] ?? 0) * $kw,                                                        'unit' => 'W'],
        'sensor.solis_total_consumption_power'        => ['state' => ($d['total_load_power'] ?? $d['familyLoadPower'] ?? 0) * $kw,                   'unit' => 'W'],
        'sensor.solis_plant_total_consumption_power'  => ['state' => ($d['total_load_power'] ?? $d['totalLoadPower'] ?? 0) * $kw,                    'unit' => 'W'],
        'sensor.solis_temperature'                    => ['state' => $d['inverterTemperature'] ?? 0,                                                  'unit' => '°C'],
        'sensor.solis_ac_voltage_r'                   => ['state' => $d['uAc1'] ?? $d['u_ac1'] ?? 0,                                                 'unit' => 'V'],
        'sensor.solis_ac_current_r'                   => ['state' => $d['iAc1'] ?? $d['i_ac1'] ?? 0,                                                 'unit' => 'A'],
        'sensor.solis_ac_frequency'                   => ['state' => $d['fAc'] ?? $d['f_ac'] ?? 0,                                                   'unit' => 'Hz'],
        'sensor.solis_daily_on_grid_energy'           => ['state' => $d['gridSellTodayEnergy'] ?? 0,                                                 'unit' => 'kWh'],
        'sensor.solis_daily_grid_energy_purchased'    => ['state' => $d['gridPurchasedTodayEnergy'] ?? 0,                                            'unit' => 'kWh'],
        'sensor.solis_daily_energy_charged'           => ['state' => $d['battery_today_charge_energy'] ?? $d['batteryTodayChargeEnergy'] ?? 0,       'unit' => 'kWh'],
        'sensor.solis_daily_energy_discharged'        => ['state' => $d['battery_today_discharge_energy'] ?? $d['batteryTodayDischargeEnergy'] ?? 0, 'unit' => 'kWh'],
        'sensor.solis_monthly_on_grid_energy'         => ['state' => $d['gridSellMonthEnergy'] ?? 0,                                                 'unit' => 'kWh'],
        'sensor.solis_monthly_grid_energy_purchased'  => ['state' => $d['gridPurchasedMonthEnergy'] ?? 0,                                            'unit' => 'kWh'],
        'sensor.solis_monthly_energy_charged'         => ['state' => $d['batteryMonthChargeEnergy'] ?? 0,                                            'unit' => 'kWh'],
        'sensor.solis_monthly_energy_discharged'      => ['state' => $d['batteryMonthDischargeEnergy'] ?? 0,                                         'unit' => 'kWh'],
        'sensor.solis_yearly_on_grid_energy'          => ['state' => $d['gridSellYearEnergy'] ?? 0,                                                  'unit' => 'kWh'],
        'sensor.solis_yearly_grid_energy_purchased'   => ['state' => ($d['gridPurchasedYearEnergy'] ?? 0) * $kw,                                     'unit' => 'kWh'],
        'sensor.solis_total_on_grid_energy'           => ['state' => ($d['gridSellTotalEnergy'] ?? 0) * $kw,                                         'unit' => 'kWh'],
        'sensor.solis_total_energy_purchased'         => ['state' => ($d['gridPurchasedTotalEnergy'] ?? 0) * $kw,                                    'unit' => 'kWh'],
        'sensor.solis_total_energy_charged'           => ['state' => $d['battery_total_charge_energy'] ?? $d['batteryTotalChargeEnergy'] ?? 0,       'unit' => 'kWh'],
        'sensor.solis_total_energy_discharged'        => ['state' => $d['battery_total_discharge_energy'] ?? $d['batteryTotalDischargeEnergy'] ?? 0, 'unit' => 'kWh'],
        'sensor.solis_dc_voltage_pv1'                 => ['state' => $d['uPv1'] ?? $d['u_pv1'] ?? 0,                                                'unit' => 'V'],
        'sensor.solis_dc_current_pv1'                 => ['state' => $d['iPv1'] ?? $d['i_pv1'] ?? 0,                                                'unit' => 'A'],
        'sensor.solis_dc_power_pv1'                   => ['state' => $d['pow1'] ?? 0,                                                                'unit' => 'W'],
        'sensor.solis_dc_voltage_pv2'                 => ['state' => $d['uPv2'] ?? $d['u_pv2'] ?? 0,                                                'unit' => 'V'],
        'sensor.solis_dc_current_pv2'                 => ['state' => $d['iPv2'] ?? $d['i_pv2'] ?? 0,                                                'unit' => 'A'],
        'sensor.solis_dc_power_pv2'                   => ['state' => $d['pow2'] ?? 0,                                                                'unit' => 'W'],
        'sensor.best_solar_month'                     => ['state' => 0,                                                                               'unit' => 'kWh'],
    ];

    $haFormat = [];
    foreach ($states as $entityId => $info) {
        $haFormat[] = [
            'entity_id'  => $entityId,
            'state'      => (string)$info['state'],
            'attributes' => ['unit_of_measurement' => $info['unit']],
        ];
    }

    file_put_contents($cacheFile, json_encode($haFormat));
    return $haFormat;
}

// ─────────────────────────────────────────
//  GET SOLCAST FORECAST
// ─────────────────────────────────────────
function getSolcastForecast() {
    $cacheFile = CACHE_DIR . 'solcast_forecast.json';
    $cacheTTL  = 3600;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $url = 'https://api.solcast.com.au/rooftop_sites/' . SOLCAST_RESOURCE . '/forecasts?format=json&hours=168';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . SOLCAST_API_KEY],
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($result, true);
    file_put_contents($cacheFile, json_encode($data));
    return $data;
}

// ─────────────────────────────────────────
//  GET SOLIS MONTHLY HISTORY
// ─────────────────────────────────────────
function getSolisMonthly() {
    $cacheFile = CACHE_DIR . 'solis_monthly.json';
    $cacheTTL  = 3600;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    // Get inverter details
    $inverters = solisRequest('/v1/api/inverterList', ['pageNo' => 1, 'pageSize' => 10]);
    if (empty($inverters['data']['page']['records'])) return ['error' => 'No inverters'];
    $inv        = $inverters['data']['page']['records'][0];
    $inverterId = $inv['id'];
    $inverterSn = $inv['sn'];

    // Fetch last 12 months of daily data and aggregate into monthly totals
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $ts    = strtotime("-$i months");
        $month = date('Y-m', $ts);
        $label = date('M y', $ts);

        $res  = solisRequest('/v1/api/inverterMonth', [
            'id'    => $inverterId,
            'sn'    => $inverterSn,
            'month' => $month,
        ]);

        $days   = $res['data'] ?? [];
        $totals = [
            'energy'                => 0,
            'gridSellEnergy'        => 0,
            'gridPurchasedEnergy'   => 0,
            'batteryChargeEnergy'   => 0,
            'batteryDischargeEnergy'=> 0,
        ];

        foreach ($days as $day) {
            $totals['energy']                 += $day['energy'] ?? 0;
            $totals['gridSellEnergy']         += $day['gridSellEnergy'] ?? 0;
            $totals['gridPurchasedEnergy']    += $day['gridPurchasedEnergy'] ?? 0;
            $totals['batteryChargeEnergy']    += $day['batteryChargeEnergy'] ?? 0;
            $totals['batteryDischargeEnergy'] += $day['batteryDischargeEnergy'] ?? 0;
        }

        $months[] = [
            'label' => $label,
            'data'  => $totals,
        ];
    }

    file_put_contents($cacheFile, json_encode($months));
    return $months;
}
