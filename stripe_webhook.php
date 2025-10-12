<?php
// Receive the checkout.session.completed event from Stripe and fulfill the order
require_once 'stripe-php/init.php';
require_once 'secrets.php';

$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
if (!$db) die("Connection failed: " . mysqli_connect_error());

if (!defined('STRIPE_API_KEY') || !defined('STRIPE_WEBHOOK_SECRET')) {
    http_response_code(500);
    error_log("Stripe API key or webhook secret not defined");
    exit();
}

\Stripe\Stripe::setApiKey(STRIPE_API_KEY);
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

if (!$payload) {
    http_response_code(400);
    error_log("Payload empty");
    exit();
}

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    error_log("Invalid payload");
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    error_log("Invalid signature");
    exit();
}

// Handle the checkout.session.completed event
if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    $metadata = $session->metadata;
    if (empty($metadata)) {
        http_response_code(400);
        error_log("No metadata in session");
        exit();
    }
    $metadata = json_decode(json_encode($metadata)); // Convert to object
    if (empty($metadata->order_ids)) {
        http_response_code(400);
        error_log("No order_ids in metadata");
        exit();
    }
    $order_ids = explode(',', $metadata->order_ids);
    foreach ($order_ids as $order_id) {
        error_log($order_id);
        $order_id = intval($order_id);
        $status_query = mysqli_query($db, "SELECT status FROM orders WHERE id=$order_id;");
        $status_result = mysqli_fetch_array($status_query)['status'] ?? null;
        if (!$status_result) {
            error_log("Order ID $order_id not found");
            continue;
        }
        if ($status_result === 'unpaid') mysqli_query($db, "UPDATE orders SET status='pending' WHERE id=$order_id;");
        else if ($status_result === 'unpaid preorder') mysqli_query($db, "UPDATE orders SET status='preorder' WHERE id=$order_id;");
        else error_log("Order ID $order_id has unexpected status $status_result");
    }
}
http_response_code(200);
