<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$adminRoot = __DIR__;
$root = dirname(__DIR__);

$guardCandidates = [
    $adminRoot . '/_guard.php',
    $adminRoot . '/guard.php',
    $adminRoot . '/permission.php',
    $root . '/includes/auth.php',
    $root . '/includes/auth_bootstrap.php',
];

foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$configCandidates = [
    $root . '/config/db.php',
    $root . '/includes/db.php',
    $root . '/db.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

$orderCancelHelper = $root . '/includes/order_cancel.php';
if (is_file($orderCancelHelper)) {
    require_once $orderCancelHelper;
}

if (!function_exists('bv_admin_order_cancellations_h')) {
    function bv_admin_order_cancellations_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_admin_order_cancellations_db')) {
    function bv_admin_order_cancellations_db(): PDO
    {
        $candidates = [
            $GLOBALS['pdo'] ?? null,
            $GLOBALS['PDO'] ?? null,
            $GLOBALS['db'] ?? null,
            $GLOBALS['conn'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof PDO) {
                return $candidate;
            }
        }

        throw new RuntimeException('PDO connection not found.');
    }
}

if (!function_exists('bv_admin_order_cancellations_table_exists')) {
    function bv_admin_order_cancellations_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bv_admin_order_cancellations_columns')) {
    function bv_admin_order_cancellations_columns(PDO $pdo, string $table): array
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['Field'])) {
                    $columns[(string) $row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('bv_admin_order_cancellations_has_col')) {
    function bv_admin_order_cancellations_has_col(PDO $pdo, string $table, string $column): bool
    {
        $cols = bv_admin_order_cancellations_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('bv_admin_order_cancellations_build_url')) {
    function bv_admin_order_cancellations_build_url(string $path, array $params = []): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }

        if (!$clean) {
            return $path;
        }

        return $path . (strpos($path, '?') === false ? '?' : '&') . http_build_query($clean);
    }
}

if (!function_exists('bv_admin_order_cancellations_current_user_id')) {
    function bv_admin_order_cancellations_current_user_id(): int
    {
        $candidates = [
            $_SESSION['admin']['id'] ?? null,
            $_SESSION['user']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
            $_SESSION['member']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['admin_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }
}

if (!function_exists('bv_admin_order_cancellations_current_role')) {
    function bv_admin_order_cancellations_current_role(): string
    {
        $candidates = [
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['user']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
            $_SESSION['member']['role'] ?? null,
            $_SESSION['role'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $role = strtolower(trim((string) $candidate));
            if ($role !== '') {
                if (in_array($role, ['super_admin', 'superadmin', 'owner'], true)) {
                    return 'admin';
                }
                return $role;
            }
        }

        return 'guest';
    }
}

if (!function_exists('bv_admin_order_cancellations_is_admin')) {
    function bv_admin_order_cancellations_is_admin(): bool
    {
        $role = bv_admin_order_cancellations_current_role();
        if (in_array($role, ['admin', 'superadmin', 'super_admin', 'owner'], true)) {
            return true;
        }

        if (!empty($_SESSION['admin'])) {
            return true;
        }

        return false;
    }
}

if (!function_exists('bv_admin_order_cancellations_money')) {
    function bv_admin_order_cancellations_money($amount, ?string $currency = null): string
    {
        $currency = strtoupper(trim((string) ($currency ?: 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }

        if ($amount === null || $amount === '') {
            return $currency . ' 0.00';
        }

        if (function_exists('money') && is_numeric($amount)) {
            return (string) money((float) $amount, $currency);
        }

        return $currency . ' ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('bv_admin_order_cancellations_status_badge')) {
    function bv_admin_order_cancellations_status_badge(string $status): array
    {
        $status = strtolower(trim($status));

        $map = [
            'requested' => ['Requested', '#92400e', '#fef3c7'],
            'approved' => ['Approved', '#0369a1', '#e0f2fe'],
            'rejected' => ['Rejected', '#b91c1c', '#fee2e2'],
            'completed' => ['Completed', '#166534', '#dcfce7'],
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return [ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown'), '#374151', '#e5e7eb'];
    }
}

if (!function_exists('bv_admin_order_cancellations_refund_badge')) {
    function bv_admin_order_cancellations_refund_badge(string $status): array
    {
        $status = strtolower(trim($status));

        $map = [
            'not_required' => ['Not Required', '#334155', '#e2e8f0'],
            'pending' => ['Pending', '#92400e', '#fef3c7'],
            'ready' => ['Ready', '#1d4ed8', '#dbeafe'],
            'processing' => ['Processing', '#7c3aed', '#ede9fe'],
            'refunded' => ['Refunded', '#166534', '#dcfce7'],
            'failed' => ['Failed', '#b91c1c', '#fee2e2'],
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return [ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown'), '#374151', '#e5e7eb'];
    }
}

if (!function_exists('bv_admin_order_cancellations_order_status_badge')) {
    function bv_admin_order_cancellations_order_status_badge(string $status): array
    {
        $status = strtolower(trim($status));

        $map = [
            'pending' => ['Pending', '#9a6700', '#fff8c5'],
            'pending_payment' => ['Pending Payment', '#9a6700', '#fff8c5'],
            'reserved' => ['Reserved', '#7c3aed', '#ede9fe'],
            'paid' => ['Paid', '#166534', '#dcfce7'],
            'paid-awaiting-verify' => ['Awaiting Verify', '#0f766e', '#ccfbf1'],
            'processing' => ['Processing', '#1d4ed8', '#dbeafe'],
            'confirmed' => ['Confirmed', '#0369a1', '#e0f2fe'],
            'packing' => ['Packing', '#4338ca', '#e0e7ff'],
            'shipped' => ['Shipped', '#334155', '#e2e8f0'],
            'completed' => ['Completed', '#065f46', '#d1fae5'],
            'cancelled' => ['Cancelled', '#991b1b', '#fee2e2'],
            'refunded' => ['Refunded', '#be123c', '#ffe4e6'],
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return [ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown'), '#374151', '#e5e7eb'];
    }
}

if (!function_exists('bv_admin_order_cancellations_reason_label')) {
    function bv_admin_order_cancellations_reason_label(string $code): string
    {
        $code = strtolower(trim($code));

        $map = [
            'changed_mind' => 'Changed Mind',
            'wrong_item' => 'Wrong Item',
            'duplicate_order' => 'Duplicate Order',
            'payment_issue' => 'Payment Issue',
            'shipping_issue' => 'Shipping Issue',
            'seller_delay' => 'Seller Delay',
            'mistake_order' => 'Mistake Order',
            'other' => 'Other',
        ];

        return $map[$code] ?? ($code !== '' ? ucwords(str_replace('_', ' ', $code)) : '-');
    }
}

if (!function_exists('bv_admin_order_cancellations_admin_url')) {
    function bv_admin_order_cancellations_admin_url(): string
    {
        $candidates = [
            '/admin/index.php',
            '/admin/dashboard.php',
            '/index.php',
        ];

        foreach ($candidates as $candidate) {
            $full = dirname(__DIR__) . $candidate;
            if (is_file($full)) {
                return $candidate;
            }
        }

        return '/admin/index.php';
    }
}

if (!function_exists('bv_admin_order_cancellations_action_url')) {
    function bv_admin_order_cancellations_action_url(): string
    {
        $candidates = [
            '/admin/order_cancel_action.php',
            '/admin/order_cancellation_action.php',
        ];

        foreach ($candidates as $candidate) {
            $full = dirname(__DIR__) . $candidate;
            if (is_file($full)) {
                return $candidate;
            }
        }

        return '/admin/order_cancel_action.php';
    }
}

if (!function_exists('bv_admin_order_cancellations_csrf_token')) {
    function bv_admin_order_cancellations_csrf_token(string $scope = 'admin_order_cancel_actions'): string
    {
        if (empty($_SESSION['_csrf_admin_order_cancel'][$scope]) || !is_string($_SESSION['_csrf_admin_order_cancel'][$scope])) {
            $_SESSION['_csrf_admin_order_cancel'][$scope] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_admin_order_cancel'][$scope];
    }
}

if (!function_exists('bv_admin_order_cancellations_fetch')) {
    function bv_admin_order_cancellations_fetch(PDO $pdo, array $params, int $limit, int $offset): array
    {
        $where = [];
        $bind = [];

        $status = strtolower(trim((string) ($params['status'] ?? '')));
        $refundStatus = strtolower(trim((string) ($params['refund_status'] ?? '')));
        $cancelSource = strtolower(trim((string) ($params['cancel_source'] ?? '')));
        $search = trim((string) ($params['q'] ?? ''));

        if ($status !== '' && $status !== 'all') {
            $where[] = 'oc.status = :status';
            $bind[':status'] = $status;
        }

        if ($refundStatus !== '' && $refundStatus !== 'all') {
            $where[] = 'oc.refund_status = :refund_status';
            $bind[':refund_status'] = $refundStatus;
        }

        if ($cancelSource !== '' && $cancelSource !== 'all') {
            $where[] = 'oc.cancel_source = :cancel_source';
            $bind[':cancel_source'] = $cancelSource;
        }

        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $searchParts = [
                'oc.order_code_snapshot LIKE :search',
                'CAST(oc.order_id AS CHAR) LIKE :search',
                'oc.cancel_reason_text LIKE :search',
                'oc.admin_note LIKE :search',
                'oc.refund_reference LIKE :search',
            ];

            if (bv_admin_order_cancellations_table_exists($pdo, 'orders')) {
                if (bv_admin_order_cancellations_has_col($pdo, 'orders', 'buyer_name')) {
                    $searchParts[] = 'o.buyer_name LIKE :search';
                }
                if (bv_admin_order_cancellations_has_col($pdo, 'orders', 'buyer_email')) {
                    $searchParts[] = 'o.buyer_email LIKE :search';
                }
            }

            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            $bind[':search'] = $searchLike;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $select = [
            'oc.*',
            'o.status AS live_order_status',
            'o.payment_status AS live_payment_status',
            'o.order_source AS live_order_source',
            'o.user_id AS order_user_id',
            'o.buyer_name',
            'o.buyer_email',
            'o.buyer_phone',
            'o.ship_name',
            'o.ship_email',
            'o.ship_phone',
        ];

        $countSql = "
            SELECT COUNT(*)
            FROM order_cancellations oc
            LEFT JOIN orders o ON o.id = oc.order_id
            {$whereSql}
        ";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $totalRows = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT " . implode(', ', $select) . "
            FROM order_cancellations oc
            LEFT JOIN orders o ON o.id = oc.order_id
            {$whereSql}
            ORDER BY oc.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total_rows' => $totalRows,
        ];
    }
}

if (!function_exists('bv_admin_order_cancellations_summary')) {
    function bv_admin_order_cancellations_summary(PDO $pdo): array
    {
        $summary = [
            'all' => 0,
            'requested' => 0,
            'approved' => 0,
            'rejected' => 0,
            'completed' => 0,
            'refund_ready' => 0,
        ];

        try {
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) AS total_all,
                    SUM(CASE WHEN status = 'requested' THEN 1 ELSE 0 END) AS total_requested,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS total_approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS total_rejected,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
                    SUM(CASE WHEN refund_status IN ('ready','pending','processing') THEN 1 ELSE 0 END) AS total_refund_ready
                FROM order_cancellations
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $summary['all'] = (int) ($row['total_all'] ?? 0);
            $summary['requested'] = (int) ($row['total_requested'] ?? 0);
            $summary['approved'] = (int) ($row['total_approved'] ?? 0);
            $summary['rejected'] = (int) ($row['total_rejected'] ?? 0);
            $summary['completed'] = (int) ($row['total_completed'] ?? 0);
            $summary['refund_ready'] = (int) ($row['total_refund_ready'] ?? 0);
        } catch (Throwable $e) {
            // ignore
        }

        return $summary;
    }
}

if (!function_exists('bv_admin_order_cancellations_fetch_items_map')) {
    function bv_admin_order_cancellations_fetch_items_map(PDO $pdo, array $cancellationIds): array
    {
        $map = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $cancellationIds), static function ($id) {
            return $id > 0;
        })));

        if (!$ids) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM order_cancellation_items
                WHERE cancellation_id IN ({$placeholders})
                ORDER BY cancellation_id ASC, id ASC
            ");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $cid = (int) ($row['cancellation_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                if (!isset($map[$cid])) {
                    $map[$cid] = [];
                }
                $map[$cid][] = $row;
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $map;
    }
}

if (!bv_admin_order_cancellations_is_admin()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

try {
    $pdo = bv_admin_order_cancellations_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection not available.';
    exit;
}

if (!bv_admin_order_cancellations_table_exists($pdo, 'order_cancellations')) {
    http_response_code(500);
    echo 'order_cancellations table not found.';
    exit;
}

if (!bv_admin_order_cancellations_table_exists($pdo, 'order_cancellation_items')) {
    http_response_code(500);
    echo 'order_cancellation_items table not found.';
    exit;
}

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$refundStatusFilter = strtolower(trim((string) ($_GET['refund_status'] ?? 'all')));
$cancelSourceFilter = strtolower(trim((string) ($_GET['cancel_source'] ?? 'all')));
$search = trim((string) ($_GET['q'] ?? ''));
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

$allowedStatus = ['all', 'requested', 'approved', 'rejected', 'completed'];
$allowedRefundStatus = ['all', 'not_required', 'pending', 'ready', 'processing', 'refunded', 'failed'];
$allowedCancelSource = ['all', 'buyer', 'seller', 'admin', 'system'];

if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}
if (!in_array($refundStatusFilter, $allowedRefundStatus, true)) {
    $refundStatusFilter = 'all';
}
if (!in_array($cancelSourceFilter, $allowedCancelSource, true)) {
    $cancelSourceFilter = 'all';
}

$result = bv_admin_order_cancellations_fetch($pdo, [
    'status' => $statusFilter,
    'refund_status' => $refundStatusFilter,
    'cancel_source' => $cancelSourceFilter,
    'q' => $search,
], $perPage, $offset);

$rows = $result['rows'];
$totalRows = (int) ($result['total_rows'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$cancellationIds = [];
foreach ($rows as $row) {
    $cancellationIds[] = (int) ($row['id'] ?? 0);
}
$itemsMap = bv_admin_order_cancellations_fetch_items_map($pdo, $cancellationIds);

$summary = bv_admin_order_cancellations_summary($pdo);
$actionUrl = bv_admin_order_cancellations_action_url();
$dashboardUrl = bv_admin_order_cancellations_admin_url();
$csrfToken = bv_admin_order_cancellations_csrf_token('admin_order_cancel_actions');

$flash = $_SESSION['admin_order_cancel_flash'] ?? null;
unset($_SESSION['admin_order_cancel_flash']);

$flashStatus = is_array($flash) ? (string) ($flash['status'] ?? '') : '';
$flashMessage = is_array($flash) ? (string) ($flash['message'] ?? '') : '';

$pageTitle = 'Order Cancellations | Admin | Bettavaro';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bv_admin_order_cancellations_h($pageTitle); ?></title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root{
            --bg:#07130e;
            --bg-2:#0b1b14;
            --panel:#ffffff;
            --ink:#0f172a;
            --muted:#64748b;
            --line:#dbe2ea;
            --gold:#d4b06a;
            --green:#166534;
            --green-soft:#dcfce7;
            --amber:#9a6700;
            --amber-soft:#fff8c5;
            --red:#991b1b;
            --red-soft:#fee2e2;
            --blue:#1d4ed8;
            --blue-soft:#dbeafe;
            --shadow:0 24px 70px rgba(0,0,0,.24);
            --radius:22px;
            --radius-sm:14px;
            --max:1360px;
        }
        *{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body{
            font-family:Inter,"Segoe UI",Arial,sans-serif;
            color:#eef4ef;
            background:
                radial-gradient(circle at top, #123021 0%, #08140f 42%, #040b08 100%);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit}
        .wrap{max-width:var(--max);margin:0 auto;padding:28px 16px 80px}
        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }
        .crumbs{
            color:#d5dfd7;
            font-size:14px;
        }
        .crumbs a{color:var(--gold)}
        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .btn,
        .btn-outline,
        .btn-soft,
        .btn-danger,
        .btn-success,
        .btn-warning{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:42px;
            padding:0 14px;
            border-radius:999px;
            font-weight:800;
            font-size:13px;
            border:1px solid transparent;
            transition:.18s ease;
            cursor:pointer;
            text-decoration:none;
        }
        .btn{
            color:#0b140f;
            background:linear-gradient(180deg,#f1dab0 0%, #d4b06a 100%);
            box-shadow:0 10px 24px rgba(212,176,106,.22);
        }
        .btn:hover{transform:translateY(-1px)}
        .btn-outline{
            color:#eef4ef;
            border-color:rgba(255,255,255,.18);
            background:rgba(255,255,255,.04);
        }
        .btn-outline:hover{background:rgba(255,255,255,.08)}
        .btn-soft{
            color:#0f172a;
            border-color:#cbd5e1;
            background:#ffffff;
            box-shadow:0 4px 12px rgba(15,23,42,.08);
        }
        .btn-soft:hover{
            background:#f8fafc;
            border-color:#94a3b8;
        }
        .btn-danger{
            color:#fff;
            background:linear-gradient(180deg,#dc2626 0%, #b91c1c 100%);
            box-shadow:0 8px 18px rgba(185,28,28,.18);
        }
        .btn-success{
            color:#fff;
            background:linear-gradient(180deg,#16a34a 0%, #15803d 100%);
            box-shadow:0 8px 18px rgba(21,128,61,.18);
        }
        .btn-warning{
            color:#0f172a;
            background:linear-gradient(180deg,#fde68a 0%, #fbbf24 100%);
            box-shadow:0 8px 18px rgba(251,191,36,.18);
        }
        .hero{
            background:linear-gradient(135deg, rgba(255,255,255,.10), rgba(255,255,255,.05));
            border:1px solid rgba(255,255,255,.12);
            border-radius:28px;
            padding:26px;
            box-shadow:var(--shadow);
            backdrop-filter:blur(10px);
            margin-bottom:20px;
        }
        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:#efd59c;
            text-transform:uppercase;
            letter-spacing:.12em;
            font-size:12px;
            font-weight:900;
            margin-bottom:10px;
        }
        .eyebrow:before{
            content:"";
            width:18px;
            height:2px;
            border-radius:999px;
            background:linear-gradient(90deg, transparent, #efd59c);
            display:inline-block;
        }
        .hero h1{
            margin:0 0 8px;
            font-size:clamp(34px,5vw,54px);
            line-height:1.04;
            letter-spacing:-.03em;
        }
        .hero p{
            margin:0;
            max-width:860px;
            color:#d3ddd6;
            line-height:1.75;
            font-size:15px;
        }
        .summary-grid{
            display:grid;
            grid-template-columns:repeat(6,minmax(0,1fr));
            gap:14px;
            margin:20px 0 24px;
        }
        .summary-card{
            background:rgba(255,255,255,.96);
            color:var(--ink);
            border-radius:20px;
            padding:18px;
            box-shadow:var(--shadow);
            border:1px solid rgba(255,255,255,.35);
        }
        .summary-label{
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.10em;
            font-weight:900;
            color:#64748b;
            margin-bottom:8px;
        }
        .summary-value{
            font-size:30px;
            font-weight:900;
            line-height:1;
        }
        .controls{
            display:grid;
            grid-template-columns:1.3fr .9fr .8fr .8fr;
            gap:16px;
            margin-bottom:18px;
        }
        .panel{
            background:rgba(255,255,255,.96);
            color:var(--ink);
            border-radius:24px;
            box-shadow:var(--shadow);
            border:1px solid rgba(255,255,255,.35);
            overflow:hidden;
        }
        .panel-body{padding:20px}
        .filters{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .filters a{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:40px;
            padding:0 14px;
            border-radius:999px;
            font-size:13px;
            font-weight:800;
            color:#334155;
            background:#f8fafc;
            border:1px solid #e2e8f0;
        }
        .filters a.active{
            color:#0b140f;
            background:#f2e1bb;
            border-color:#e2c788;
        }
        .search-form,
        .filter-form{
            display:flex;
            gap:10px;
            align-items:center;
        }
        .search-form input,
        .filter-form select,
        .action-form textarea{
            width:100%;
            min-height:44px;
            border-radius:14px;
            border:1px solid #dbe2ea;
            padding:0 14px;
            font-size:14px;
            color:#0f172a;
            background:#fff;
            font-family:inherit;
        }
        .action-form textarea{
            min-height:88px;
            resize:vertical;
            padding:12px 14px;
        }
        .alert{
            margin:0 0 18px;
            padding:14px 16px;
            border-radius:16px;
            font-size:14px;
            font-weight:700;
        }
        .alert.success{
            background:#ecfdf3;
            color:#166534;
            border:1px solid #bbf7d0;
        }
        .alert.error{
            background:#fef2f2;
            color:#b91c1c;
            border:1px solid #fecaca;
        }
        .request-list{
            display:grid;
            gap:18px;
        }
        .request-card{
            background:#fff;
            color:var(--ink);
            border:1px solid #e7edf4;
            border-radius:22px;
            padding:18px;
        }
        .request-head{
            display:flex;
            justify-content:space-between;
            gap:18px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }
        .request-code{
            font-size:24px;
            font-weight:900;
            line-height:1.1;
        }
        .request-meta{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:center;
            margin-top:8px;
        }
        .badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:30px;
            padding:0 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:900;
            letter-spacing:.02em;
        }
        .request-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:14px;
        }
        .meta-box{
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:16px;
            padding:14px;
        }
        .meta-box .label{
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.10em;
            color:#64748b;
            font-weight:900;
            margin-bottom:7px;
        }
        .meta-box .value{
            font-size:15px;
            font-weight:800;
            color:#0f172a;
            word-break:break-word;
            line-height:1.6;
        }
        .reason-box{
            background:#fffaf0;
            border:1px solid #fde6b3;
            border-radius:18px;
            padding:16px;
            margin-bottom:14px;
        }
        .reason-title{
            font-size:16px;
            font-weight:900;
            margin-bottom:8px;
            color:#92400e;
        }
        .reason-text{
            color:#475569;
            font-size:14px;
            line-height:1.8;
            white-space:pre-wrap;
        }
        .items-box{
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:18px;
            padding:14px;
            margin-bottom:14px;
        }
        .items-title{
            font-size:16px;
            font-weight:900;
            margin-bottom:10px;
            color:#0f172a;
        }
        .items-table{
            width:100%;
            border-collapse:collapse;
        }
        .items-table th,
        .items-table td{
            text-align:left;
            padding:10px 8px;
            border-bottom:1px solid #e2e8f0;
            font-size:13px;
            color:#334155;
            vertical-align:top;
        }
        .items-table th{
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:#64748b;
        }
        .request-footer{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
            align-items:start;
            padding-top:14px;
            border-top:1px solid #ecf0f4;
        }
        .action-form{
            display:grid;
            gap:10px;
        }
        .action-row{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .empty{
            padding:34px 20px;
            text-align:center;
            color:#64748b;
        }
        .pagination{
            display:flex;
            justify-content:center;
            gap:10px;
            flex-wrap:wrap;
            margin-top:22px;
        }
        .pagination a,
        .pagination span{
            min-width:42px;
            height:42px;
            padding:0 14px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            background:rgba(255,255,255,.96);
            color:#0f172a;
            font-weight:900;
            border:1px solid rgba(255,255,255,.4);
        }
        .pagination .current{
            background:#f2e1bb;
            border-color:#e2c788;
        }
        @media (max-width: 1200px){
            .summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
            .controls{grid-template-columns:1fr 1fr}
            .request-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
            .request-footer{grid-template-columns:1fr}
        }
        @media (max-width: 720px){
            .summary-grid{grid-template-columns:1fr}
            .controls{grid-template-columns:1fr}
            .request-grid{grid-template-columns:1fr}
            .request-code{font-size:20px}
            .items-table{display:block;overflow-x:auto}
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div class="crumbs">
                <a href="<?= bv_admin_order_cancellations_h($dashboardUrl); ?>">Admin</a>
                <span> / </span>
                <span>Order Cancellations</span>
            </div>
            <div class="actions">
                <a class="btn-outline" href="<?= bv_admin_order_cancellations_h($dashboardUrl); ?>">Back to Dashboard</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Cancellation Queue</div>
            <h1>Order Cancellations</h1>
            <p>Review customer cancellation requests, approve or reject them, and complete the cancellation flow when the case is ready. This is the command room, not the guessing room.</p>
        </section>

        <section class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">All Requests</div>
                <div class="summary-value"><?= (int) $summary['all']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Requested</div>
                <div class="summary-value"><?= (int) $summary['requested']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Approved</div>
                <div class="summary-value"><?= (int) $summary['approved']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Rejected</div>
                <div class="summary-value"><?= (int) $summary['rejected']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Completed</div>
                <div class="summary-value"><?= (int) $summary['completed']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Refund Work</div>
                <div class="summary-value"><?= (int) $summary['refund_ready']; ?></div>
            </div>
        </section>

        <?php if ($flashMessage !== ''): ?>
            <div class="alert <?= in_array($flashStatus, ['success', 'approved', 'completed', 'rejected'], true) ? 'success' : 'error'; ?>">
                <?= bv_admin_order_cancellations_h($flashMessage); ?>
            </div>
        <?php endif; ?>

        <section class="controls">
            <div class="panel">
                <div class="panel-body">
                    <div class="filters">
                        <?php
                        $statusMap = [
                            'all' => 'All',
                            'requested' => 'Requested',
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                            'completed' => 'Completed',
                        ];
                        foreach ($statusMap as $key => $label):
                            $url = bv_admin_order_cancellations_build_url('/admin/order_cancellations.php', [
                                'status' => $key,
                                'refund_status' => $refundStatusFilter !== 'all' ? $refundStatusFilter : null,
                                'cancel_source' => $cancelSourceFilter !== 'all' ? $cancelSourceFilter : null,
                                'q' => $search !== '' ? $search : null,
                            ]);
                        ?>
                            <a class="<?= $statusFilter === $key ? 'active' : ''; ?>" href="<?= bv_admin_order_cancellations_h($url); ?>"><?= bv_admin_order_cancellations_h($label); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-body">
                    <form class="filter-form" method="get" action="/admin/order_cancellations.php">
                        <input type="hidden" name="status" value="<?= bv_admin_order_cancellations_h($statusFilter); ?>">
                        <select name="refund_status">
                            <?php foreach ($allowedRefundStatus as $option): ?>
                                <option value="<?= bv_admin_order_cancellations_h($option); ?>" <?= $refundStatusFilter === $option ? 'selected' : ''; ?>>
                                    <?= bv_admin_order_cancellations_h($option === 'all' ? 'All Refund Statuses' : ucwords(str_replace('_', ' ', $option))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Filter</button>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="panel-body">
                    <form class="filter-form" method="get" action="/admin/order_cancellations.php">
                        <input type="hidden" name="status" value="<?= bv_admin_order_cancellations_h($statusFilter); ?>">
                        <input type="hidden" name="refund_status" value="<?= bv_admin_order_cancellations_h($refundStatusFilter); ?>">
                        <select name="cancel_source">
                            <?php foreach ($allowedCancelSource as $option): ?>
                                <option value="<?= bv_admin_order_cancellations_h($option); ?>" <?= $cancelSourceFilter === $option ? 'selected' : ''; ?>>
                                    <?= bv_admin_order_cancellations_h($option === 'all' ? 'All Sources' : ucfirst($option)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Source</button>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="panel-body">
                    <form class="search-form" method="get" action="/admin/order_cancellations.php">
                        <input type="hidden" name="status" value="<?= bv_admin_order_cancellations_h($statusFilter); ?>">
                        <input type="hidden" name="refund_status" value="<?= bv_admin_order_cancellations_h($refundStatusFilter); ?>">
                        <input type="hidden" name="cancel_source" value="<?= bv_admin_order_cancellations_h($cancelSourceFilter); ?>">
                        <input type="text" name="q" value="<?= bv_admin_order_cancellations_h($search); ?>" placeholder="Order code, buyer, note, refund ref">
                        <button class="btn" type="submit">Search</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <?php if (!$rows): ?>
                    <div class="empty">
                        <h2 style="margin:0 0 8px;color:#0f172a;">No cancellation requests found</h2>
                        <p style="margin:0;">Nothing matches the current filters. Which is either peaceful or suspicious, depending on the day.</p>
                    </div>
                <?php else: ?>
                    <div class="request-list">
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $cancellationId = (int) ($row['id'] ?? 0);
                            $orderId = (int) ($row['order_id'] ?? 0);
                            $currency = strtoupper(trim((string) (($row['currency'] ?? '') ?: 'USD')));
                            $status = strtolower(trim((string) ($row['status'] ?? 'requested')));
                            $refundStatus = strtolower(trim((string) (($row['refund_status'] ?? '') ?: 'not_required')));
                            $orderStatusSnapshot = strtolower(trim((string) (($row['order_status_snapshot'] ?? '') ?: '')));
                            $liveOrderStatus = strtolower(trim((string) (($row['live_order_status'] ?? '') ?: '')));
                            $cancelSource = strtolower(trim((string) (($row['cancel_source'] ?? '') ?: 'buyer')));
                            $reasonCode = trim((string) ($row['cancel_reason_code'] ?? ''));
                            $reasonText = trim((string) ($row['cancel_reason_text'] ?? ''));
                            $adminNote = trim((string) ($row['admin_note'] ?? ''));
                            $requestedAt = trim((string) ($row['requested_at'] ?? ''));
                            $approvedAt = trim((string) ($row['approved_at'] ?? ''));
                            $completedAt = trim((string) ($row['completed_at'] ?? ''));
                            $buyerName = trim((string) ($row['buyer_name'] ?? ''));
                            $buyerEmail = trim((string) ($row['buyer_email'] ?? ''));
                            $buyerPhone = trim((string) ($row['buyer_phone'] ?? ''));
                            $refundableAmount = (float) (($row['refundable_amount'] ?? 0) ?: 0);
                            $totalSnapshot = (float) (($row['total_snapshot'] ?? 0) ?: 0);
                            $restockRequired = !empty($row['restock_required']);
                            $items = $itemsMap[$cancellationId] ?? [];
                            $canApprove = $status === 'requested';
                            $canReject = in_array($status, ['requested', 'approved'], true);
                            $canComplete = in_array($status, ['approved', 'requested'], true);
                            [$statusLabel, $statusColor, $statusBg] = bv_admin_order_cancellations_status_badge($status);
                            [$refundLabel, $refundColor, $refundBg] = bv_admin_order_cancellations_refund_badge($refundStatus);
                            [$orderLabel, $orderColor, $orderBg] = bv_admin_order_cancellations_order_status_badge($liveOrderStatus !== '' ? $liveOrderStatus : $orderStatusSnapshot);
                            ?>
                            <article class="request-card">
                                <div class="request-head">
                                    <div>
                                        <div class="request-code"><?= bv_admin_order_cancellations_h((string) (($row['order_code_snapshot'] ?? '') ?: ('Order #' . $orderId))); ?></div>
                                        <div class="request-meta">
                                            <span class="badge" style="color:<?= bv_admin_order_cancellations_h($statusColor); ?>;background:<?= bv_admin_order_cancellations_h($statusBg); ?>;"><?= bv_admin_order_cancellations_h($statusLabel); ?></span>
                                            <span class="badge" style="color:<?= bv_admin_order_cancellations_h($refundColor); ?>;background:<?= bv_admin_order_cancellations_h($refundBg); ?>;"><?= bv_admin_order_cancellations_h($refundLabel); ?></span>
                                            <span class="badge" style="color:<?= bv_admin_order_cancellations_h($orderColor); ?>;background:<?= bv_admin_order_cancellations_h($orderBg); ?>;"><?= bv_admin_order_cancellations_h($orderLabel); ?></span>
                                            <span class="badge" style="color:#334155;background:#e2e8f0;"><?= bv_admin_order_cancellations_h(strtoupper($cancelSource !== '' ? $cancelSource : 'buyer')); ?></span>
                                            <?php if ($restockRequired): ?>
                                                <span class="badge" style="color:#166534;background:#dcfce7;">Restock Required</span>
                                            <?php else: ?>
                                                <span class="badge" style="color:#475569;background:#f1f5f9;">No Restock</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.10em;color:#64748b;font-weight:900;margin-bottom:6px;">Refundable</div>
                                        <div style="font-size:28px;font-weight:900;color:#0f172a;"><?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_money($refundableAmount, $currency)); ?></div>
                                    </div>
                                </div>

                                <div class="request-grid">
                                    <div class="meta-box">
                                        <div class="label">Cancellation ID</div>
                                        <div class="value"><?= (int) $cancellationId; ?></div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Order ID</div>
                                        <div class="value"><?= (int) $orderId; ?></div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Requested At</div>
                                        <div class="value"><?= bv_admin_order_cancellations_h($requestedAt !== '' ? $requestedAt : '-'); ?></div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Approved / Completed</div>
                                        <div class="value">
                                            <?= bv_admin_order_cancellations_h($approvedAt !== '' ? $approvedAt : '-'); ?><br>
                                            <?= bv_admin_order_cancellations_h($completedAt !== '' ? $completedAt : '-'); ?>
                                        </div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Buyer</div>
                                        <div class="value">
                                            <?= bv_admin_order_cancellations_h($buyerName !== '' ? $buyerName : '-'); ?><br>
                                            <?= bv_admin_order_cancellations_h($buyerEmail !== '' ? $buyerEmail : '-'); ?>
                                        </div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Buyer Phone</div>
                                        <div class="value"><?= bv_admin_order_cancellations_h($buyerPhone !== '' ? $buyerPhone : '-'); ?></div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Snapshot Total</div>
                                        <div class="value"><?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_money($totalSnapshot, $currency)); ?></div>
                                    </div>
                                    <div class="meta-box">
                                        <div class="label">Reason</div>
                                        <div class="value"><?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_reason_label($reasonCode)); ?></div>
                                    </div>
                                </div>

                                <div class="reason-box">
                                    <div class="reason-title">Customer Reason</div>
                                    <div class="reason-text"><?= bv_admin_order_cancellations_h($reasonText !== '' ? $reasonText : '-'); ?></div>
                                </div>

                                <?php if ($adminNote !== ''): ?>
                                    <div class="reason-box" style="background:#eff6ff;border-color:#bfdbfe;">
                                        <div class="reason-title" style="color:#1d4ed8;">Admin Note</div>
                                        <div class="reason-text"><?= bv_admin_order_cancellations_h($adminNote); ?></div>
                                    </div>
                                <?php endif; ?>

                                <div class="items-box">
                                    <div class="items-title">Cancellation Items</div>
                                    <table class="items-table">
                                        <thead>
                                            <tr>
                                                <th>Listing ID</th>
                                                <th>Order Item ID</th>
                                                <th>Qty</th>
                                                <th>Unit Price</th>
                                                <th>Line Total</th>
                                                <th>Restock Qty</th>
                                                <th>Stock Reversed</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!$items): ?>
                                                <tr>
                                                    <td colspan="7">No cancellation items found.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td><?= (int) (($item['listing_id'] ?? 0) ?: 0); ?></td>
                                                        <td><?= (int) (($item['order_item_id'] ?? 0) ?: 0); ?></td>
                                                        <td><?= (int) (($item['qty'] ?? 0) ?: 0); ?></td>
                                                        <td><?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_money((float) (($item['unit_price_snapshot'] ?? 0) ?: 0), $currency)); ?></td>
                                                        <td><?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_money((float) (($item['line_total_snapshot'] ?? 0) ?: 0), $currency)); ?></td>
                                                        <td><?= (int) (($item['restock_qty'] ?? 0) ?: 0); ?></td>
                                                        <td><?= !empty($item['stock_reversed']) ? 'Yes' : 'No'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="request-footer">
                                    <div class="meta-box">
                                        <div class="label">Current Flow Notes</div>
                                        <div class="value" style="font-size:14px;font-weight:700;color:#475569;">
                                            Snapshot order status: <strong style="color:#0f172a;"><?= bv_admin_order_cancellations_h($orderStatusSnapshot !== '' ? $orderStatusSnapshot : '-'); ?></strong><br>
                                            Live order status: <strong style="color:#0f172a;"><?= bv_admin_order_cancellations_h($liveOrderStatus !== '' ? $liveOrderStatus : '-'); ?></strong><br>
                                            Refund reference: <strong style="color:#0f172a;"><?= bv_admin_order_cancellations_h((string) (($row['refund_reference'] ?? '') ?: '-')); ?></strong><br>
                                            Requested by user ID: <strong style="color:#0f172a;"><?= (int) (($row['requested_by_user_id'] ?? 0) ?: 0); ?></strong><br>
                                            Requested by role: <strong style="color:#0f172a;"><?= bv_admin_order_cancellations_h((string) (($row['requested_by_role'] ?? '') ?: '-')); ?></strong>
                                        </div>
                                    </div>

                                    <form class="action-form" method="post" action="<?= bv_admin_order_cancellations_h($actionUrl); ?>">
                                        <input type="hidden" name="cancellation_id" value="<?= (int) $cancellationId; ?>">
                                        <input type="hidden" name="return_url" value="<?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_build_url('/admin/order_cancellations.php', [
                                            'status' => $statusFilter !== 'all' ? $statusFilter : null,
                                            'refund_status' => $refundStatusFilter !== 'all' ? $refundStatusFilter : null,
                                            'cancel_source' => $cancelSourceFilter !== 'all' ? $cancelSourceFilter : null,
                                            'q' => $search !== '' ? $search : null,
                                            'page' => $page > 1 ? $page : null,
                                        ])); ?>">
                                        <input type="hidden" name="csrf_token" value="<?= bv_admin_order_cancellations_h($csrfToken); ?>">
                                        <textarea name="admin_note" placeholder="Admin note for this cancellation..."><?= bv_admin_order_cancellations_h($adminNote); ?></textarea>
                                        <div class="action-row">
                                            <?php if ($canApprove): ?>
                                                <button class="btn-success" type="submit" name="action" value="approve">Approve</button>
                                            <?php endif; ?>
                                            <?php if ($canReject): ?>
                                                <button class="btn-danger" type="submit" name="action" value="reject">Reject</button>
                                            <?php endif; ?>
                                            <?php if ($canComplete): ?>
                                                <button class="btn-warning" type="submit" name="action" value="complete">Complete</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php
                            $prevPage = $page > 1 ? $page - 1 : null;
                            $nextPage = $page < $totalPages ? $page + 1 : null;

                            if ($prevPage !== null):
                            ?>
                                <a href="<?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_build_url('/admin/order_cancellations.php', [
                                    'status' => $statusFilter !== 'all' ? $statusFilter : null,
                                    'refund_status' => $refundStatusFilter !== 'all' ? $refundStatusFilter : null,
                                    'cancel_source' => $cancelSourceFilter !== 'all' ? $cancelSourceFilter : null,
                                    'q' => $search !== '' ? $search : null,
                                    'page' => $prevPage,
                                ])); ?>">‹</a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <?php if ($i === $page): ?>
                                    <span class="current"><?= (int) $i; ?></span>
                                <?php else: ?>
                                    <a href="<?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_build_url('/admin/order_cancellations.php', [
                                        'status' => $statusFilter !== 'all' ? $statusFilter : null,
                                        'refund_status' => $refundStatusFilter !== 'all' ? $refundStatusFilter : null,
                                        'cancel_source' => $cancelSourceFilter !== 'all' ? $cancelSourceFilter : null,
                                        'q' => $search !== '' ? $search : null,
                                        'page' => $i,
                                    ])); ?>"><?= (int) $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($nextPage !== null): ?>
                                <a href="<?= bv_admin_order_cancellations_h(bv_admin_order_cancellations_build_url('/admin/order_cancellations.php', [
                                    'status' => $statusFilter !== 'all' ? $statusFilter : null,
                                    'refund_status' => $refundStatusFilter !== 'all' ? $refundStatusFilter : null,
                                    'cancel_source' => $cancelSourceFilter !== 'all' ? $cancelSourceFilter : null,
                                    'q' => $search !== '' ? $search : null,
                                    'page' => $nextPage,
                                ])); ?>">›</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>