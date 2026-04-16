<?php
/**
 * Plugin Name: INTERWIRE Ticker
 * Description: World clock, weather & currency bar. Shortcode: [interwire_ticker]
 * Version:     1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {
    $style_path  = plugin_dir_path(__FILE__) . 'assets/style.css';
    $script_path = plugin_dir_path(__FILE__) . 'assets/script.js';

    wp_enqueue_style(
        'iw-style',
        plugin_dir_url(__FILE__) . 'assets/style.css',
        [],
        file_exists($style_path) ? filemtime($style_path) : '1.9.0'
    );

    wp_enqueue_script(
        'iw-script',
        plugin_dir_url(__FILE__) . 'assets/script.js',
        [],
        file_exists($script_path) ? filemtime($script_path) : '1.9.0',
        true
    );
});

function iw_cities() {
    return [
        [
            'label' => 'LONDON',
            'tz'    => 'Europe/London',
            'owm'   => 'London,GB',
            'cur'   => 'GBP',
            'sym'   => '£',
        ],
        [
            'label' => 'PARIS',
            'tz'    => 'Europe/Paris',
            'owm'   => 'Paris,FR',
            'cur'   => 'EUR',
            'sym'   => '€',
        ],
        [
            'label' => 'NEW YORK',
            'tz'    => 'America/New_York',
            'owm'   => 'New York,US',
            'cur'   => 'USD',
            'sym'   => '$',
        ],
        [
            'label' => 'HONG KONG',
            'tz'    => 'Asia/Hong_Kong',
            'owm'   => 'Hong Kong,HK',
            'cur'   => 'HKD',
            'sym'   => 'HK$',
        ],
        [
            'label' => 'MOSCOW',
            'tz'    => 'Europe/Moscow',
            'owm'   => 'Moscow,RU',
            'cur'   => 'RUB',
            'sym'   => '₽',
        ],
    ];
}

function iw_weather($city) {
    $key = get_option('iw_owm_key', '');
    if (!$key) {
        return null;
    }

    $cache_key = 'iw_w_' . md5($city);
    $cache = get_transient($cache_key);

    if ($cache !== false) {
        return $cache;
    }

    $url = add_query_arg([
        'q'     => $city,
        'appid' => $key,
        'units' => 'metric',
    ], 'https://api.openweathermap.org/data/2.5/weather');

    $res = wp_remote_get($url, ['timeout' => 10]);

    if (is_wp_error($res)) {
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);

    if (
        !is_array($body) ||
        !isset($body['main']['temp']) ||
        empty($body['weather'][0]['main'])
    ) {
        return null;
    }

    $map = [
        'Clear'        => 'CLEAR SKIES',
        'Clouds'       => 'OVERCAST',
        'Rain'         => 'RAIN',
        'Snow'         => 'SNOW',
        'Drizzle'      => 'DRIZZLE',
        'Thunderstorm' => 'STORM',
        'Mist'         => 'MIST',
        'Fog'          => 'FOG',
        'Haze'         => 'HAZE',
        'Smoke'        => 'SMOKE',
    ];

    $main_weather = $body['weather'][0]['main'];

    $data = [
        'temp' => round((float) $body['main']['temp']),
        'cond' => $map[$main_weather] ?? strtoupper($main_weather),
    ];

    set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);

    return $data;
}

function iw_rates() {
    $cache_key = 'iw_rates_main_v2';
    $cache = get_transient($cache_key);

    if ($cache !== false) {
        return $cache;
    }

    $fallback = [
        'USD' => 1.00,
        'GBP' => 0.79,
        'EUR' => 0.86,
        'HKD' => 7.83,
    ];

    $res = wp_remote_get(
        'https://api.frankfurter.app/latest?from=USD&to=GBP,EUR,HKD',
        ['timeout' => 10]
    );

    if (is_wp_error($res)) {
        return $fallback;
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);

    if (!is_array($body) || empty($body['rates']) || !is_array($body['rates'])) {
        return $fallback;
    }

    $rates = $body['rates'];

    $data = [
        'USD' => 1.00,
        'GBP' => isset($rates['GBP']) ? (float) $rates['GBP'] : $fallback['GBP'],
        'EUR' => isset($rates['EUR']) ? (float) $rates['EUR'] : $fallback['EUR'],
        'HKD' => isset($rates['HKD']) ? (float) $rates['HKD'] : $fallback['HKD'],
    ];

    set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);

    return $data;
}

function iw_rub_rate() {
    $cache_key = 'iw_rub_rate_v3';
    $cache = get_transient($cache_key);

    if ($cache !== false) {
        return $cache;
    }

    $fallback = 90.00;
    $api_key  = get_option('iw_rub_key', '');

    if ($api_key) {
        $url = 'https://v6.exchangerate-api.com/v6/' . rawurlencode($api_key) . '/latest/USD';
    } else {
        $url = 'https://open.er-api.com/v6/latest/USD';
    }

    $res = wp_remote_get($url, ['timeout' => 10]);

    if (is_wp_error($res)) {
        return $fallback;
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($api_key) {
        if (
            !is_array($body) ||
            empty($body['conversion_rates']['RUB'])
        ) {
            return $fallback;
        }

        $rate = (float) $body['conversion_rates']['RUB'];
    } else {
        if (
            !is_array($body) ||
            empty($body['rates']['RUB'])
        ) {
            return $fallback;
        }

        $rate = (float) $body['rates']['RUB'];
    }

    set_transient($cache_key, $rate, 30 * MINUTE_IN_SECONDS);

    return $rate;
}

function iw_format_currency($symbol, $rate) {
    if ($rate === null || $rate === '') {
        return '';
    }

    return $symbol . ' ' . number_format((float) $rate, 2);
}

add_shortcode('interwire_ticker', function () {
    if (is_admin()) {
        return '<div style="padding:12px;background:#f7f7f4;border:1px solid #ddd;font-family:Times New Roman, serif;">INTERWIRE Ticker Preview</div>';
    }

    $cities = iw_cities();
    $rates  = iw_rates();

    $rates['RUB'] = iw_rub_rate();

    ob_start();
    ?>
    <div class="iw-ticker-wrap">
        <table class="iw-ticker-table" role="presentation">
            <tr>
                <?php foreach ($cities as $c) : ?>
                    <?php
                    $weather = iw_weather($c['owm']);
                    $rate = $rates[$c['cur']] ?? null;
                    $currency_line = iw_format_currency($c['sym'], $rate);
                    ?>
                    <td>
                        <div><?php echo esc_html($c['label']); ?></div>
                        <div class="iw-time" data-tz="<?php echo esc_attr($c['tz']); ?>">--:--</div>

                        <?php if ($weather) : ?>
                            <div><?php echo esc_html($weather['temp'] . '°, ' . $weather['cond']); ?></div>
                        <?php else : ?>
                            <div>--°, --</div>
                        <?php endif; ?>

                        <?php if ($currency_line) : ?>
                            <div><?php echo esc_html($currency_line); ?></div>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        </table>
    </div>
    <?php

    return ob_get_clean();
});

add_action('admin_menu', function () {
    add_options_page(
        'INTERWIRE Ticker',
        'INTERWIRE Ticker',
        'manage_options',
        'iw-ticker',
        'iw_settings'
    );
});

function iw_settings() {
    if (
        isset($_POST['iw_nonce']) &&
        wp_verify_nonce($_POST['iw_nonce'], 'iw_save')
    ) {
        update_option('iw_owm_key', sanitize_text_field($_POST['iw_key'] ?? ''));
        update_option('iw_rub_key', sanitize_text_field($_POST['iw_rub_key'] ?? ''));

        echo '<div class="notice notice-success"><p>✓ Saved!</p></div>';
    }

    $key     = get_option('iw_owm_key', '');
    $rub_key = get_option('iw_rub_key', '');
    ?>
    <div class="wrap">
        <h1>INTERWIRE Ticker</h1>

        <form method="post">
            <?php wp_nonce_field('iw_save', 'iw_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">OpenWeatherMap API Key</th>
                    <td>
                        <input
                            type="text"
                            name="iw_key"
                            value="<?php echo esc_attr($key); ?>"
                            class="regular-text"
                            placeholder="Paste your key here"
                        >
                    </td>
                </tr>

                <tr>
                    <th scope="row">RUB Exchange API Key</th>
                    <td>
                        <input
                            type="text"
                            name="iw_rub_key"
                            value="<?php echo esc_attr($rub_key); ?>"
                            class="regular-text"
                            placeholder="Optional"
                        >
                        <p class="description">Used only for Moscow (RUB).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save'); ?>
        </form>

        <hr>
        <p><strong>Shortcode:</strong> <code>[interwire_ticker]</code></p>
    </div>
    <?php
}
