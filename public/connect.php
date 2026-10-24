<?php
/*
 * Where WHOOP, Fitbit, Withings and Oura send people back after they agree to connect.
 * Register this exact address (shown on the Admin page) with each service.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
if (get('error') !== '') {
    // Only for the connection this person started (its state), so another site can't cancel or fake one.
    if (empty($_SESSION['connect']['state']) || !hash_equals((string) $_SESSION['connect']['state'], get('state'))) {
        redirect('connections.php');
    }
    unset($_SESSION['connect']);
    flash('error', get('error') === 'access_denied' ? 'Connecting was cancelled.' : 'The service said: ' . mb_substr(get('error_description') ?: get('error'), 0, 200));
    redirect('connections.php');
}
try {
    $provider = connector_finish((int) $user['id'], get('code'), get('state'));
    $conn = q('SELECT * FROM connections WHERE user_id = ? AND provider = ?', [$user['id'], $provider])->fetch();
    try {
        $r = sync_connection($conn, 30);
        flash('success', CONNECTORS[$provider]['name'] . ' connected. ' . ($r['added'] ? $r['added'] . ' readings from the last 30 days were added.' : 'New readings will appear within the hour.'));
    } catch (Throwable $e) {
        flash('success', CONNECTORS[$provider]['name'] . ' connected, but the first sync failed: ' . $e->getMessage() . ' It will try again within the hour.');
    }
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
}
redirect('connections.php');
