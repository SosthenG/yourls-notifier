<?php
/*
Plugin Name: Notifier
Plugin URI: https://github.com/SosthenG/yourls-notifier
Description: Sends a notification to Discord when a short is created
Version: 1.0
Author: SosthenG
Author URI: https://github.com/SosthenG
*/

// No direct call
if (!defined('YOURLS_ABSPATH')) {
    die();
}

function notify_discord(string $webhook, string $message, array $fields = [], ?DateTime $date = null)
{
    if ($date === null) {
        $date = new \DateTime();
    }
    $content = [
        'content' => null,
        'embeds' => [
            [
                'title' => $message,
                'fields' => $fields,
                'timestamp' => $date->format('c'),
            ],
        ],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhook);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!empty($response)) {
        trigger_error('Notifier failed to notify Discord!');
    }
}

yourls_add_filter('add_new_link', 'notify_new_link');
function notify_new_link($data)
{
    $discord_webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($discord_webhook)) {
        return;
    }

    if ($data['status'] === 'success') {
        notify_discord($discord_webhook, $data['message'], [
            [
                'name' => 'Url',
                'value' => $data['url']['url'],
                'inline' => true,
            ],
            [
                'name' => 'Short',
                'value' => $data['shorturl'],
                'inline' => true,
            ],
            [
                'name' => 'IP',
                'value' => $data['url']['ip'],
                'inline' => true,
            ],
        ], new DateTime($data['url']['date']));
    }
}

yourls_add_action('plugins_loaded', 'notifier_loaded');
function notifier_loaded()
{
    yourls_register_plugin_page('notifier_settings', 'Notifier', 'notifier_register_settings_page');
}

function notifier_register_settings_page()
{
    if (isset($_POST['discord_webhook'])) {
        yourls_verify_nonce('notifier_settings');

        $discord_webhook = $_POST['discord_webhook'];
        if (!filter_var($discord_webhook, FILTER_VALIDATE_URL)) {
            echo 'Invalid discord webhook url.';
        } else {
            yourls_update_option('notifier_discord_webhook', $discord_webhook);
        }
    }

    if (!isset($discord_webhook)) {
        $discord_webhook = yourls_get_option('notifier_discord_webhook', '');
    }
    $nonce = yourls_create_nonce('notifier_settings');

    echo <<<HTML
        <main>
            <h2>Notifier</h2>
            <form method="post">
            <input type="hidden" name="nonce" value="$nonce" />
            <p>
                <label>Discord webhook url</label>
                <input type="url" name="discord_webhook" value="$discord_webhook" placeholder="https://discord.com/api/webhooks/" />
            </p>
            <p><input type="submit" value="Save" class="button" /></p>
            </form>
        </main>
HTML;
}
