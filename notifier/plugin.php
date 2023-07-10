<?php
/*
Plugin Name: Notifier
Plugin URI: https://github.com/SosthenG/yourls-notifier
Description: Sends a notification to Discord when a short is created
Version: 1.2
Author: SosthenG
Author URI: https://github.com/SosthenG
*/

// No direct call
if (!defined('YOURLS_ABSPATH')) {
    die();
}

function notifier_discord(string $webhook, string $message, array $fields = [], ?DateTime $date = null)
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

function notifier_post_add_new_link($args)
{
    $discord_webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($discord_webhook)) {
        return;
    }

    $data = $args[3];

    if ($data['status'] === 'success') {
        notifier_discord($discord_webhook, $data['message'], [
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

function notifier_redirect_shorturl($args)
{
    $discord_webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($discord_webhook)) {
        return;
    }

    try {
        $table = YOURLS_DB_TABLE_URL;
        $clicks = yourls_get_db()->fetchValue("SELECT `clicks` FROM `$table` WHERE `keyword` = :keyword", [
            'keyword' => $args[1],
        ]);
        $clicks = (int)$clicks + 1;
    } catch (Exception $e) {
        $clicks = 'Unknown';
    }

    notifier_discord($discord_webhook, 'Short "' . $args[1] . '" has just been used!', [
        [
            'name' => 'Short',
            'value' => $args[1]
        ],
        [
            'name' => 'Redirected to',
            'value' => $args[0]
        ],
        [
            'name' => 'Clicks',
            'value' => $clicks
        ],
    ]);

}

yourls_add_action('plugins_loaded', 'notifier_loaded');
function notifier_loaded()
{
    yourls_register_plugin_page('notifier_settings', 'Notifier', 'notifier_register_settings_page');

    $events = notifier_get_events_subscriptions();
    foreach ($events as $event => $enabled) {
        if ($enabled) {
            yourls_add_action($event, 'notifier_' . $event);
        }
    }
}


function notifier_get_events_subscriptions() {
    $events = [
        'post_add_new_link' => true,
        'redirect_shorturl' => false,
    ];
    $db_events = yourls_get_option('notifier_events_subscriptions');
    foreach ($db_events as $event => $status) {
        if (array_key_exists($event, $events)) {
            $events[$event] = $status;
        }
    }

    return $events;
}

function notifier_register_settings_page()
{
    $events = notifier_get_events_subscriptions();
    $events_descriptions = [
        'post_add_new_link' => 'When a new link is shortened',
        'redirect_shorturl' => 'When a short URL is accessed',
    ];

    if (isset($_POST['discord_webhook'])) {
        yourls_verify_nonce('notifier_settings');

        $discord_webhook = $_POST['discord_webhook'];
        if (!filter_var($discord_webhook, FILTER_VALIDATE_URL)) {
            echo 'Invalid discord webhook url.';
        } else {
            yourls_update_option('notifier_discord_webhook', $discord_webhook);
        }

        $posted_events = [];
        if (!empty($_POST['events'])) {
            $posted_events = $_POST['events'];
        }
        foreach ($events as $event => $enabled) {
            if (array_key_exists($event, $posted_events)) {
                $events[$event] = true;
            } else {
                $events[$event] = false;
            }
        }

        yourls_update_option('notifier_events_subscriptions', $events);
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
                <input type="url" name="discord_webhook" value="$discord_webhook" placeholder="https://discord.com/api/webhooks/" size="100" />
            </p>
            <fieldset>
                <legend>Events subscriptions</legend>
HTML;

    foreach ($events as $event => $enabled) {
        echo '<input type="checkbox" id="' . $event . '" name="events[' . $event . ']" ' . ($enabled ? 'checked' : '') . '><label for="' . $event . '">' . $events_descriptions[$event] . '</label><br>';
    }

    echo <<<HTML
            </fieldset>
            <p><input type="submit" value="Save" class="button" /></p>
            </form>
        </main>
HTML;
}
