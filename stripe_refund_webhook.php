<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/order_refund.php';
require_once __DIR__ . '/includes/stripe_refund_engine.php';

function bv_stripe_refund_webhook_log(string $message, array $context = []): void
{
    bv_stripe_refund_log('webhook_' . $message, $context);
}

function bv_stripe_refund_webhook_event_seen(string $eventId): bool
{
    static $cache = null;
    $file = __DIR__ . '/logs/stripe_refund_webhook_seen.json';
    if ($cache === null) {
        $cache = [];
        if (is_file($file)) {
            $json = @file_get_contents($file);
            $arr = is_string($json) ? json_decode($json, true) : null;
            if (is_array($arr)) {
                $cache = $arr;
            }
        }
    }
    if (isset($cache[$eventId])) {
        return true;
    }
    $cache[$eventId] = time();
    if (count($cache) > 5000) {
        $cache = array_slice($cache, -3000, null, true);
    }
    @file_put_contents($file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return false;
}



function bv_stripe_refund_webhook_txn_already_succeeded_before(int $refundId, string $providerRefundId): bool
{
    if ($refundId <= 0 || $providerRefundId === '') {
        return false;
    }

    $row = bv_order_refund_query_one(
        'SELECT transaction_status
         FROM order_refund_transactions
         WHERE refund_id = :refund_id
           AND provider_refund_id = :provider_refund_id
         ORDER BY id DESC
         LIMIT 1',
        [
            'refund_id' => $refundId,
            'provider_refund_id' => $providerRefundId,
        ]
    );

    if (!$row) {
        return false;
    }

    return strtolower((string)($row['transaction_status'] ?? '')) === 'succeeded';
}

function bv_stripe_refund_webhook_signature_valid(string $payload, string $signatureHeader, string $secret): bool
{
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $item) {
        $kv = explode('=', trim($item), 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }

    $timestamp = isset($parts['t'][0]) ? (string)$parts['t'][0] : '';
    $signatures = $parts['v1'] ?? [];
    if ($timestamp === '' || $signatures === []) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

http_response_code(200);
header('Content-Type: application/json');

$payload = (string)file_get_contents('php://input');
$signatureHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

try {
    $config = bv_stripe_refund_config();

    if (!empty($config['webhook_secret'])) {
        if (!bv_stripe_refund_webhook_signature_valid($payload, $signatureHeader, (string)$config['webhook_secret'])) {
            bv_stripe_refund_webhook_log('signature_invalid');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
            exit;
        }
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
        throw new RuntimeException('Invalid JSON payload');
    }

    $eventId = (string)($event['id'] ?? '');
    if ($eventId !== '' && bv_stripe_refund_webhook_event_seen($eventId)) {
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }

    $eventType = (string)($event['type'] ?? '');
    $supported = ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'];
    if (!in_array($eventType, $supported, true)) {
        echo json_encode(['ok' => true, 'ignored' => true]);
        exit;
    }

    $object = $event['data']['object'] ?? [];
    if (!is_array($object)) {
        throw new RuntimeException('Invalid event object');
    }

    $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
    $providerRefundId = (string)($object['id'] ?? '');
    $paymentIntentId = (string)($object['payment_intent'] ?? '');

    $refund = null;
    if (!empty($metadata['refund_id']) && is_numeric($metadata['refund_id'])) {
        $refund = bv_order_refund_get_by_id((int)$metadata['refund_id']);
    }
    if (!$refund && $providerRefundId !== '') {
        $refund = bv_order_refund_query_one(
            'SELECT r.* FROM order_refunds r INNER JOIN order_refund_transactions t ON t.refund_id = r.id WHERE t.provider_refund_id = :provider_refund_id ORDER BY t.id DESC LIMIT 1',
            ['provider_refund_id' => $providerRefundId]
        );
    }
    if (!$refund && $paymentIntentId !== '') {
        $refund = bv_order_refund_query_one(
            'SELECT r.* FROM order_refunds r LEFT JOIN order_refund_transactions t ON t.refund_id = r.id WHERE t.provider_payment_intent_id = :pi OR r.payment_reference_snapshot = :pi ORDER BY r.id DESC LIMIT 1',
            ['pi' => $paymentIntentId]
        );
    }

    if (!$refund) {
        bv_stripe_refund_webhook_log('refund_not_found', ['event_id' => $eventId, 'type' => $eventType]);
        echo json_encode(['ok' => true, 'not_mapped' => true]);
        exit;
    }

    $refundId = (int)$refund['id'];

    $statusRaw = (string)($object['status'] ?? 'pending');
    if ($eventType === 'refund.failed') {
        $statusRaw = 'failed';
    }
    $transactionStatus = bv_stripe_refund_normalize_status($statusRaw);

    $amountMinor = (int)($object['amount'] ?? 0);
    $currency = strtoupper((string)($object['currency'] ?? ($refund['currency'] ?? 'USD')));
    $zeroDecimalCurrencies = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
    $actualAmount = in_array($currency, $zeroDecimalCurrencies, true)
        ? (float)$amountMinor
        : round(((float)$amountMinor / 100), 2);

    $rawObject = json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $existingSucceededBefore = bv_stripe_refund_webhook_txn_already_succeeded_before($refundId, $providerRefundId);

    $latest = $providerRefundId !== '' ? bv_order_refund_query_one(
        'SELECT * FROM order_refund_transactions WHERE provider_refund_id = :provider_refund_id AND refund_id = :refund_id ORDER BY id DESC LIMIT 1',
        ['provider_refund_id' => $providerRefundId, 'refund_id' => $refundId]
    ) : null;

    if (!$latest) {
        bv_order_refund_insert_transaction([
            'refund_id' => $refundId,
            'transaction_type' => 'provider_refund',
            'transaction_status' => $transactionStatus,
            'provider' => 'stripe',
            'provider_refund_id' => $providerRefundId,
            'provider_payment_intent_id' => $paymentIntentId,
            'currency' => $currency,
            'amount' => $actualAmount,
            'raw_request_payload' => '',
            'raw_response_payload' => $rawObject,
            'failure_code' => (string)($object['failure_reason'] ?? ''),
            'failure_message' => (string)($object['failure_balance_transaction'] ?? ''),
            'created_by_user_id' => 0,
        ]);
    } else {
        bv_order_refund_execute(
            'UPDATE order_refund_transactions
             SET transaction_status = :transaction_status,
                 raw_response_payload = :raw_response_payload,
                 failure_code = :failure_code,
                 failure_message = :failure_message
             WHERE id = :id',
            [
                'transaction_status' => $transactionStatus,
                'raw_response_payload' => $rawObject,
                'failure_code' => (string)($object['failure_reason'] ?? ''),
                'failure_message' => (string)($object['failure_balance_transaction'] ?? ''),
                'id' => (int)$latest['id'],
            ]
        );
    }

    if ($transactionStatus === 'succeeded') {
        if ($providerRefundId === '') {
            bv_stripe_refund_webhook_log('skip_header_apply_missing_provider_refund_id', ['refund_id' => $refundId]);
        } elseif (!$existingSucceededBefore && strtolower((string)($refund['status'] ?? '')) !== 'refunded') {
            bv_order_refund_mark_refunded($refundId, $actualAmount, [], 0, 'Stripe webhook confirmed refund');
        }
    } elseif ($transactionStatus === 'failed' || $transactionStatus === 'cancelled') {
        bv_order_refund_mark_failed($refundId, 'Stripe webhook reported failure', [], 0);
    } else {
        $status = strtolower((string)($refund['status'] ?? ''));
        if ($status === 'approved') {
            bv_order_refund_mark_processing($refundId, 0, 'Stripe webhook pending');
        }
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    bv_stripe_refund_webhook_log('exception', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
