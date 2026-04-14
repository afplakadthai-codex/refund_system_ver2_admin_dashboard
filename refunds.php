<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$baseDir = dirname(__DIR__);
$rootDir = dirname($baseDir);

$guardFiles = [
    __DIR__ . '/_guard.php',
    __DIR__ . '/guard.php',
    __DIR__ . '/permission.php',
    __DIR__ . '/admin_auth.php',
    __DIR__ . '/auth.php',
    $baseDir . '/includes/admin_auth.php',
    $baseDir . '/includes/auth_admin.php',
    $baseDir . '/includes/auth.php',
    $rootDir . '/admin_auth.php',
];
foreach ($guardFiles as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$requiredFiles = [
    $baseDir . '/includes/order_refund.php',
    $rootDir . '/order_refund.php',
];
foreach ($requiredFiles as $requiredFile) {
    if (is_file($requiredFile)) {
        require_once $requiredFile;
        break;
    }
}

$queryFiles = [
    __DIR__ . '/includes/refund_dashboard_queries.php',
    $rootDir . '/public_html/admin/includes/refund_dashboard_queries.php',
];
foreach ($queryFiles as $queryFile) {
    if (is_file($queryFile)) {
        require_once $queryFile;
        break;
    }
}

if (!function_exists('bv_admin_refund_dashboard_summary') || !function_exists('bv_admin_refund_dashboard_rows')) {
    http_response_code(500);
    echo 'Refund dashboard dependencies are missing.';
    exit;
}

if (!function_exists('bv_admin_refunds_h')) {
    function bv_admin_refunds_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_admin_refunds_currency')) {
    function bv_admin_refunds_currency($amount, string $currency = 'USD'): string
    {
        $num = is_numeric($amount) ? (float)$amount : 0.0;
        return strtoupper($currency) . ' ' . number_format($num, 2);
    }
}

if (!function_exists('bv_admin_refunds_status_label')) {
    function bv_admin_refunds_status_label(string $status): string
    {
        $status = strtolower(trim($status));
        return ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('bv_admin_refunds_status_class')) {
    function bv_admin_refunds_status_class(string $status): string
    {
        $status = strtolower(trim($status));
        $map = [
            'pending_approval' => 'badge-pending',
            'approved' => 'badge-approved',
            'processing' => 'badge-processing',
            'partially_refunded' => 'badge-partial',
            'refunded' => 'badge-refunded',
            'failed' => 'badge-failed',
            'rejected' => 'badge-rejected',
            'cancelled' => 'badge-cancelled',
        ];
        return $map[$status] ?? 'badge-default';
    }
}

if (!function_exists('bv_admin_refunds_actions_for_status')) {
    function bv_admin_refunds_actions_for_status(string $status): array
    {
        $status = strtolower(trim($status));
        $map = [
            'pending_approval' => ['approve', 'reject', 'cancel'],
            'approved' => ['processing', 'refund_stripe', 'cancel'],
            'processing' => ['refund_stripe', 'mark_refunded_manual', 'mark_failed'],
            'partially_refunded' => ['refund_stripe', 'mark_refunded_manual', 'mark_failed'],
            'failed' => ['processing', 'cancel'],
            'refunded' => [],
            'rejected' => [],
            'cancelled' => [],
        ];

        return $map[$status] ?? [];
    }
}

if (!function_exists('bv_admin_refunds_action_label')) {
    function bv_admin_refunds_action_label(string $action): string
    {
        $map = [
            'approve' => 'Approve',
            'reject' => 'Reject',
            'processing' => 'Mark Processing',
            'refund_stripe' => 'Refund via Stripe',
            'mark_refunded_manual' => 'Mark Refunded Manual',
            'mark_failed' => 'Mark Failed',
            'cancel' => 'Cancel',
        ];
        return $map[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
}

if (!function_exists('bv_admin_refunds_action_class')) {
    function bv_admin_refunds_action_class(string $action): string
    {
        $map = [
            'approve' => 'btn-action btn-green',
            'reject' => 'btn-action btn-red',
            'processing' => 'btn-action btn-blue',
            'refund_stripe' => 'btn-action btn-purple',
            'mark_refunded_manual' => 'btn-action btn-teal',
            'mark_failed' => 'btn-action btn-orange',
            'cancel' => 'btn-action btn-gray',
        ];
        return $map[$action] ?? 'btn-action btn-gray';
    }
}

if (!function_exists('bv_admin_refunds_action_confirm')) {
    function bv_admin_refunds_action_confirm(string $action): string
    {
        $map = [
            'reject' => 'Reject this refund request? This will mark it as rejected.',
            'cancel' => 'Cancel this refund workflow? This action changes status to cancelled.',
            'mark_failed' => 'Mark this refund as failed? Use this only when refund processing failed.',
            'refund_stripe' => 'Trigger Stripe refund now for this record?',
            'mark_refunded_manual' => 'Mark this refund as manually refunded? Ensure external refund is complete.',
        ];
        return $map[$action] ?? '';
    }
}

if (!function_exists('bv_admin_refunds_safe_return_url')) {
    function bv_admin_refunds_safe_return_url(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/refunds.php');
        if ($uri === '' || strpos($uri, '://') !== false || str_starts_with($uri, '//') || $uri[0] !== '/') {
            return '/admin/refunds.php';
        }
        return $uri;
    }
}

$rawFilters = [
    'status' => $_GET['status'] ?? '',
    'refund_mode' => $_GET['refund_mode'] ?? '',
    'refund_source' => $_GET['refund_source'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'keyword' => $_GET['keyword'] ?? '',
];

$filters = bv_admin_refund_dashboard_normalize_filters($rawFilters);
$summary = bv_admin_refund_dashboard_summary($filters);
$rows = bv_admin_refund_dashboard_rows($filters);
$options = bv_admin_refund_dashboard_filter_options();

$currency = 'USD';
if (!empty($rows) && isset($rows[0]['currency']) && trim((string)$rows[0]['currency']) !== '') {
    $currency = strtoupper((string)$rows[0]['currency']);
}

$flashSuccess = isset($_SESSION['flash_success']) ? (string)$_SESSION['flash_success'] : '';
$flashError = isset($_SESSION['flash_error']) ? (string)$_SESSION['flash_error'] : '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrfToken = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
$returnUrl = bv_admin_refunds_safe_return_url();

$headerIncluded = false;
$headerFiles = [
    __DIR__ . '/includes/header.php',
    __DIR__ . '/header.php',
    __DIR__ . '/_head.php',
];
foreach ($headerFiles as $headerFile) {
    if (is_file($headerFile)) {
        require_once $headerFile;
        $headerIncluded = true;
        break;
    }
}

if (!$headerIncluded):
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Refund Dashboard</title>
</head>
<body class="bv-admin-refund-standalone">
<?php endif; ?>
<style>
    .bv-refund-page {
        max-width: 1440px;
        margin: 20px auto;
        padding: 0 16px 30px;
        font-family: Arial, sans-serif;
        color: #1f2937;
    }
    .bv-admin-refund-standalone {
        margin: 0;
        background: #f3f5f8;
    }
    .bv-refund-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
.bv-refund-title-wrap h1 {
    margin: 0 0 4px;
    font-size: 26px;
    color: #ffffff;
}
.bv-refund-title-wrap p {
    margin: 0;
    color: #cbd5e1;
    font-size: 14px;
}
    .bv-top-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .flash { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 14px; }
    .flash-success { background: #ecfdf3; border: 1px solid #86efac; color: #166534; }
    .flash-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
    .summary-grid { display: grid; grid-template-columns: repeat(5, minmax(170px, 1fr)); gap: 12px; margin-bottom: 14px; }
    .summary-card { background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; padding: 12px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04); }
    .summary-label { color: #6b7280; font-size: 12px; margin-bottom: 6px; }
    .summary-value { font-size: 22px; font-weight: 700; }
    .filter-card, .table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; }
    .filter-card { margin-bottom: 14px; }
    .filter-grid { display: grid; grid-template-columns: repeat(6, minmax(140px, 1fr)); gap: 10px; align-items: end; }
    .field label { display: block; font-size: 12px; color: #4b5563; margin-bottom: 5px; }
    .field input, .field select { width: 100%; padding: 8px; border-radius: 7px; border: 1px solid #d1d5db; box-sizing: border-box; }
    .filter-actions { display: flex; gap: 8px; }
    .btn { display: inline-block; border: none; padding: 8px 10px; border-radius: 7px; cursor: pointer; font-size: 13px; text-decoration: none; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-secondary { background: #e5e7eb; color: #111827; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 1300px; }
    th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 13px; }
    th { color: #6b7280; font-weight: 600; background: #f9fafb; }
    .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-approved { background: #dbeafe; color: #1d4ed8; }
    .badge-processing { background: #ede9fe; color: #5b21b6; }
    .badge-partial { background: #cffafe; color: #155e75; }
    .badge-refunded { background: #dcfce7; color: #166534; }
    .badge-failed { background: #fee2e2; color: #991b1b; }
    .badge-rejected { background: #fee2e2; color: #7f1d1d; }
    .badge-cancelled { background: #e5e7eb; color: #374151; }
    .badge-default { background: #e5e7eb; color: #111827; }
    .actions-wrap { display: flex; flex-wrap: wrap; gap: 6px; }
    .inline-form { margin: 0; }
    .btn-action { border: none; border-radius: 6px; padding: 6px 8px; font-size: 11px; cursor: pointer; color: #fff; white-space: nowrap; }
    .btn-green { background: #059669; }
    .btn-red { background: #dc2626; }
    .btn-blue { background: #2563eb; }
    .btn-purple { background: #7c3aed; }
    .btn-teal { background: #0d9488; }
    .btn-orange { background: #ea580c; }
    .btn-gray { background: #4b5563; }
    .empty-state { padding: 24px 10px; text-align: center; color: #6b7280; }
    @media (max-width: 1100px) {
        .summary-grid { grid-template-columns: repeat(3, minmax(140px, 1fr)); }
        .filter-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
    }
    @media (max-width: 680px) {
        .summary-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
        .filter-grid { grid-template-columns: 1fr; }
    }
</style>
<div class="bv-refund-page">
    <div class="bv-refund-head">
        <div class="bv-refund-title-wrap">
            <h1>Refund Dashboard</h1>
            <p>Operational insight and action center for refund workflow.</p>
        </div>
        <div class="bv-top-actions">
            <a class="btn btn-secondary" href="/admin/index.php">Back to Admin Dashboard</a>
            <a class="btn btn-secondary" href="<?php echo bv_admin_refunds_h($returnUrl); ?>">Refresh</a>
            <a class="btn btn-primary" href="/admin/refunds.php">Clear Filters</a>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash flash-success"><?php echo bv_admin_refunds_h($flashSuccess); ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash flash-error"><?php echo bv_admin_refunds_h($flashError); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card"><div class="summary-label">Pending Approval</div><div class="summary-value"><?php echo (int)($summary['pending_approval_count'] ?? 0); ?></div></div>
        <div class="summary-card"><div class="summary-label">Approved</div><div class="summary-value"><?php echo (int)($summary['approved_count'] ?? 0); ?></div></div>
        <div class="summary-card"><div class="summary-label">Processing</div><div class="summary-value"><?php echo (int)($summary['processing_count'] ?? 0); ?></div></div>
        <div class="summary-card"><div class="summary-label">Partially Refunded</div><div class="summary-value"><?php echo (int)($summary['partially_refunded_count'] ?? 0); ?></div></div>
        <div class="summary-card"><div class="summary-label">Refunded</div><div class="summary-value"><?php echo (int)($summary['refunded_count'] ?? 0); ?></div></div>
        <div class="summary-card"><div class="summary-label">Failed</div><div class="summary-value"><?php echo (int)($summary['failed_count'] ?? 0); ?></div></div>
        <div class="summary-card"><div class="summary-label">Requested Total</div><div class="summary-value"><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($summary['requested_total'] ?? 0, $currency)); ?></div></div>
        <div class="summary-card"><div class="summary-label">Approved Total</div><div class="summary-value"><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($summary['approved_total'] ?? 0, $currency)); ?></div></div>
        <div class="summary-card"><div class="summary-label">Refunded Total</div><div class="summary-value"><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($summary['refunded_total'] ?? 0, $currency)); ?></div></div>
        <div class="summary-card"><div class="summary-label">Remaining Total</div><div class="summary-value"><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($summary['remaining_total'] ?? 0, $currency)); ?></div></div>
    </div>

    <div class="filter-card">
        <form method="get" action="">
            <div class="filter-grid">
                <div class="field">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">All</option>
                        <?php foreach (($options['status'] ?? []) as $statusOption): ?>
                            <option value="<?php echo bv_admin_refunds_h($statusOption); ?>"<?php echo ($filters['status'] === $statusOption ? ' selected' : ''); ?>><?php echo bv_admin_refunds_h(bv_admin_refunds_status_label($statusOption)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="refund_mode">Refund Mode</label>
                    <select name="refund_mode" id="refund_mode">
                        <option value="">All</option>
                        <?php foreach (($options['refund_mode'] ?? []) as $modeOption): ?>
                            <option value="<?php echo bv_admin_refunds_h($modeOption); ?>"<?php echo ($filters['refund_mode'] === $modeOption ? ' selected' : ''); ?>><?php echo bv_admin_refunds_h(ucfirst($modeOption)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="refund_source">Refund Source</label>
                    <select name="refund_source" id="refund_source">
                        <option value="">All</option>
                        <?php foreach (($options['refund_source'] ?? []) as $sourceOption): ?>
                            <option value="<?php echo bv_admin_refunds_h($sourceOption); ?>"<?php echo ($filters['refund_source'] === $sourceOption ? ' selected' : ''); ?>><?php echo bv_admin_refunds_h(ucwords(str_replace('_', ' ', $sourceOption))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="date_from">Date From</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo bv_admin_refunds_h(substr((string)$filters['date_from'], 0, 10)); ?>">
                </div>
                <div class="field">
                    <label for="date_to">Date To</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo bv_admin_refunds_h(substr((string)$filters['date_to'], 0, 10)); ?>">
                </div>
                <div class="field">
                    <label for="keyword">Keyword</label>
                    <input type="text" name="keyword" id="keyword" value="<?php echo bv_admin_refunds_h($filters['keyword']); ?>" placeholder="Code, order, note, provider ref...">
                </div>
                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit">Apply Filters</button>
                    <a class="btn btn-secondary" href="/admin/refunds.php">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Refund Code</th>
                    <th>Order ID</th>
                    <th>Status</th>
                    <th>Refund Mode</th>
                    <th>Refund Source</th>
                    <th>Requested</th>
                    <th>Approved</th>
                    <th>Refunded</th>
                    <th>Remaining</th>
                    <th>Provider</th>
                    <th>Provider Refund ID</th>
                    <th>Requested At</th>
                    <th>Updated At</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="14"><div class="empty-state">No refund records found for the selected filters.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowCurrency = strtoupper((string)($row['currency'] ?? 'USD'));
                        $status = (string)($row['status'] ?? '');
                        $provider = trim((string)($row['latest_provider'] ?? ''));
                        $providerRefundId = trim((string)($row['latest_provider_refund_id'] ?? ''));
                        $actions = bv_admin_refunds_actions_for_status($status);
                        $remaining = isset($row['remaining_refund_amount']) ? (float)$row['remaining_refund_amount'] : 0.0;
                        ?>
                        <tr>
                            <td><?php echo bv_admin_refunds_h($row['refund_code'] ?? ''); ?></td>
                            <td>#<?php echo (int)($row['order_id'] ?? 0); ?></td>
                            <td><span class="badge <?php echo bv_admin_refunds_h(bv_admin_refunds_status_class($status)); ?>"><?php echo bv_admin_refunds_h(bv_admin_refunds_status_label($status)); ?></span></td>
                            <td><?php echo bv_admin_refunds_h(ucfirst((string)($row['refund_mode'] ?? ''))); ?></td>
                            <td><?php echo bv_admin_refunds_h(ucwords(str_replace('_', ' ', (string)($row['refund_source'] ?? '')))); ?></td>
                            <td><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($row['requested_refund_amount'] ?? 0, $rowCurrency)); ?></td>
                            <td><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($row['approved_refund_amount'] ?? 0, $rowCurrency)); ?></td>
                            <td><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($row['actual_refunded_amount'] ?? 0, $rowCurrency)); ?></td>
                            <td><?php echo bv_admin_refunds_h(bv_admin_refunds_currency($remaining, $rowCurrency)); ?></td>
                            <td><?php echo bv_admin_refunds_h($provider !== '' ? $provider : '-'); ?></td>
                            <td><?php echo bv_admin_refunds_h($providerRefundId !== '' ? $providerRefundId : '-'); ?></td>
                            <td><?php echo bv_admin_refunds_h((string)($row['requested_at'] ?? '')); ?></td>
                            <td><?php echo bv_admin_refunds_h((string)($row['updated_at'] ?? '')); ?></td>
                            <td>
                                <div class="actions-wrap">
                                    <?php if (empty($actions)): ?>
                                        <span style="color:#6b7280;">No actions</span>
                                    <?php else: ?>
                                        <?php foreach ($actions as $action): ?>
                                            <?php $confirmText = bv_admin_refunds_action_confirm($action); ?>
                                            <form class="inline-form" method="post" action="/admin/refund_action.php"<?php echo $confirmText !== '' ? ' onsubmit="return confirm(' . bv_admin_refunds_h(json_encode($confirmText, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) . ');"' : ''; ?>>
                                                <input type="hidden" name="refund_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                <input type="hidden" name="action" value="<?php echo bv_admin_refunds_h($action); ?>">
                                                <input type="hidden" name="return_url" value="<?php echo bv_admin_refunds_h($returnUrl); ?>">
                                                <?php if ($csrfToken !== ''): ?>
                                                    <input type="hidden" name="csrf_token" value="<?php echo bv_admin_refunds_h($csrfToken); ?>">
                                                <?php endif; ?>
                                                <?php if ($action === 'mark_refunded_manual'): ?>
                                                    <input type="hidden" name="actual_amount" value="<?php echo bv_admin_refunds_h(number_format($remaining, 2, '.', '')); ?>">
                                                <?php endif; ?>
                                                <?php if ($action === 'approve'): ?>
                                                    <input type="hidden" name="approved_amount" value="<?php echo bv_admin_refunds_h(number_format((float)($row['requested_refund_amount'] ?? 0), 2, '.', '')); ?>">
                                                <?php endif; ?>
                                                <?php if ($action === 'refund_stripe'): ?>
                                                    <input type="hidden" name="amount" value="<?php echo bv_admin_refunds_h(number_format($remaining, 2, '.', '')); ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="<?php echo bv_admin_refunds_h(bv_admin_refunds_action_class($action)); ?>"><?php echo bv_admin_refunds_h(bv_admin_refunds_action_label($action)); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$footerFiles = [
    __DIR__ . '/includes/footer.php',
    __DIR__ . '/footer.php',
];
foreach ($footerFiles as $footerFile) {
    if (is_file($footerFile)) {
        require_once $footerFile;
        break;
    }
}
?>
<?php if (!$headerIncluded): ?>
</body>
</html>
<?php endif; ?>
