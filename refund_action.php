<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/order_refund.php';
require_once dirname(__DIR__) . '/includes/stripe_refund_engine.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$guardFiles = [
    __DIR__ . '/admin_auth.php',
    __DIR__ . '/auth.php',
    dirname(__DIR__) . '/includes/admin_auth.php',
    dirname(__DIR__) . '/includes/auth_admin.php',
];
foreach ($guardFiles as $gf) {
    if (is_file($gf)) {
        require_once $gf;
    }
}

function bv_admin_refund_action_set_flash(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
}

function bv_admin_refund_action_redirect(string $default = '/admin/refunds.php'): void
{
    $returnUrl = isset($_POST['return_url']) ? (string)$_POST['return_url'] : '';
    if ($returnUrl === '') {
        $returnUrl = isset($_GET['return_url']) ? (string)$_GET['return_url'] : '';
    }
    if ($returnUrl === '' || strpos($returnUrl, '://') !== false || str_starts_with($returnUrl, '//')) {
        $returnUrl = $default;
    }
    header('Location: ' . $returnUrl);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bv_admin_refund_action_set_flash('error', 'Invalid request method.');
    bv_admin_refund_action_redirect();
}

if (isset($_SESSION['csrf_token'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        bv_admin_refund_action_set_flash('error', 'Invalid CSRF token.');
        bv_admin_refund_action_redirect();
    }
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$refundId = (int)($_POST['refund_id'] ?? 0);
if ($refundId <= 0 || $action === '') {
    bv_admin_refund_action_set_flash('error', 'Missing refund action data.');
    bv_admin_refund_action_redirect();
}

$actorUserId = bv_order_refund_current_user_id();
$actorRole = bv_order_refund_current_user_role();

try {
    if ($action === 'approve') {
        $approvedAmount = (float)($_POST['approved_amount'] ?? 0);
        if ($approvedAmount <= 0) {
            $refund = bv_order_refund_get_by_id($refundId);
            $approvedAmount = (float)($refund['requested_refund_amount'] ?? 0);
        }
        bv_order_refund_approve($refundId, $approvedAmount, $actorUserId, $actorRole, (string)($_POST['note'] ?? ''));
        bv_admin_refund_action_set_flash('success', 'Refund approved.');
    } elseif ($action === 'reject') {
        bv_order_refund_reject($refundId, (string)($_POST['reason'] ?? ''), $actorUserId, $actorRole);
        bv_admin_refund_action_set_flash('success', 'Refund rejected.');
    } elseif ($action === 'cancel') {
        bv_order_refund_cancel($refundId, (string)($_POST['reason'] ?? ''), $actorUserId, $actorRole);
        bv_admin_refund_action_set_flash('success', 'Refund cancelled.');
    } elseif ($action === 'processing') {
        bv_order_refund_mark_processing($refundId, $actorUserId, (string)($_POST['note'] ?? ''));
        bv_admin_refund_action_set_flash('success', 'Refund marked processing.');
    } elseif ($action === 'mark_failed') {
        bv_order_refund_mark_failed($refundId, (string)($_POST['reason'] ?? ''), [], $actorUserId);
        bv_admin_refund_action_set_flash('success', 'Refund marked failed.');
    } elseif ($action === 'mark_refunded_manual') {
        $amount = (float)($_POST['actual_amount'] ?? 0);
        bv_order_refund_mark_refunded($refundId, $amount, [], $actorUserId, (string)($_POST['note'] ?? 'Manual refund'));
        bv_admin_refund_action_set_flash('success', 'Refund marked refunded.');
    } elseif ($action === 'refund_stripe') {
        $refund = bv_order_refund_get_by_id($refundId);
        if (!$refund) {
            throw new RuntimeException('Refund not found.');
        }

        $status = strtolower((string)($refund['status'] ?? ''));
        if (!in_array($status, ['approved', 'processing', 'partially_refunded'], true)) {
            throw new RuntimeException('Refund is not in a Stripe-processable status.');
        }

        $approved = (float)($refund['approved_refund_amount'] ?? 0);
        $alreadyRefunded = (float)($refund['actual_refunded_amount'] ?? 0);
        if ($approved <= 0) {
            throw new RuntimeException('Approved refund amount must be greater than zero.');
        }

        $remainingRefundable = round($approved - $alreadyRefunded, 2);
        if ($remainingRefundable <= 0) {
            bv_admin_refund_action_set_flash('success', 'Refund already fully refunded.');
            bv_admin_refund_action_redirect();
        }

        $manualAmount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
        $amount = $manualAmount > 0 ? round($manualAmount, 2) : $remainingRefundable;
        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }
        if ($amount > $remainingRefundable) {
            throw new RuntimeException('Refund amount exceeds remaining refundable amount.');
        }

        if ($status !== 'processing') {
            bv_order_refund_mark_processing($refundId, $actorUserId, 'Stripe refund initiated by admin');
            $refund = bv_order_refund_get_by_id($refundId) ?? $refund;
        }

        $stripeResult = bv_stripe_refund_create([
            'refund_id' => $refundId,
            'payment_intent_id' => (string)($refund['payment_reference_snapshot'] ?? ''),
            'charge_id' => (string)($_POST['charge_id'] ?? ''),
            'amount' => $amount,
            'currency' => (string)($refund['currency'] ?? 'USD'),
            'reason' => (string)($_POST['reason'] ?? 'requested_by_customer'),
            'metadata' => [
                'refund_id' => (string)$refundId,
                'order_id' => (string)($refund['order_id'] ?? 0),
            ],
        ]);

        bv_order_refund_insert_transaction([
            'refund_id' => $refundId,
            'transaction_type' => 'provider_refund',
            'transaction_status' => (string)$stripeResult['transaction_status'],
            'provider' => 'stripe',
            'provider_refund_id' => (string)($stripeResult['provider_refund_id'] ?? ''),
            'provider_payment_intent_id' => (string)($stripeResult['provider_payment_intent_id'] ?? ''),
            'currency' => (string)($stripeResult['currency'] ?? $refund['currency'] ?? 'USD'),
            'amount' => (float)($stripeResult['amount'] ?? $amount),
            'raw_request_payload' => (string)($stripeResult['raw_request_payload'] ?? ''),
            'raw_response_payload' => (string)($stripeResult['raw_response_payload'] ?? ''),
            'failure_code' => (string)($stripeResult['failure_code'] ?? ''),
            'failure_message' => (string)($stripeResult['failure_message'] ?? ''),
            'created_by_user_id' => $actorUserId,
        ]);

        $normalized = (string)($stripeResult['transaction_status'] ?? 'pending');
        if ($normalized === 'succeeded') {
            bv_order_refund_mark_refunded($refundId, $amount, [], $actorUserId, 'Stripe refund succeeded');
            bv_admin_refund_action_set_flash('success', 'Stripe refund succeeded.');
        } elseif ($normalized === 'failed' || $normalized === 'cancelled') {
            bv_order_refund_mark_failed($refundId, (string)($stripeResult['failure_message'] ?? 'Stripe refund failed'), [], $actorUserId);
            bv_admin_refund_action_set_flash('error', 'Stripe refund failed.');
        } else {
            bv_admin_refund_action_set_flash('success', 'Stripe refund pending.');
        }
    } else {
        throw new RuntimeException('Unsupported action: ' . $action);
    }
} catch (Throwable $e) {
    bv_admin_refund_action_set_flash('error', $e->getMessage());
}

bv_admin_refund_action_redirect();
