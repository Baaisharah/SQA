<?php
/**
 * Module 2: Inventory & Expiry Management
 * Suka Dessert Inventory Management System
 * Drop this file into XAMPP's htdocs directory.
 */

declare(strict_types=1);

session_start();

$pdo = new PDO(
    'mysql:host=localhost;dbname=suka_dessert_inventory',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$today = new DateTimeImmutable('today');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function calculateStatus(string $expiryDate): string
{
    $today = new DateTimeImmutable('today');
    $expiry = new DateTimeImmutable($expiryDate);
    $daysUntilExpiry = (int) $today->diff($expiry)->format('%r%a');

    if ($daysUntilExpiry <= 0) {
        return 'Expired';
    }

    return $daysUntilExpiry <= 3 ? 'Expiring Soon' : 'Fresh';
}

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function requireCsrf(string $submittedToken, string $csrfToken): void
{
    if (!hash_equals($csrfToken, $submittedToken)) {
        redirectWithMessage('error', 'Your session token expired. Please try again.');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        requireCsrf((string) ($_POST['csrf_token'] ?? ''), $csrfToken);

        if ($action === 'create_batch') {
            $itemName = trim((string) ($_POST['item_name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            $productionDate = (string) ($_POST['production_date'] ?? '');
            $expiryDate = (string) ($_POST['expiry_date'] ?? '');
            $storageLocation = trim((string) ($_POST['storage_location'] ?? ''));

            if ($itemName === '' || $category === '' || $storageLocation === '' || $quantity === false || $quantity < 0) {
                redirectWithMessage('error', 'Please complete every batch field with valid values.');
            }

            if (!validDate($productionDate) || !validDate($expiryDate) || $expiryDate < $productionDate) {
                redirectWithMessage('error', 'Please provide valid dates. Expiry must be on or after production.');
            }

            $status = calculateStatus($expiryDate);
            $statement = $pdo->prepare(
                'INSERT INTO dessert_batches
                    (item_name, category, quantity, production_date, expiry_date, storage_location, status)
                 VALUES (:item_name, :category, :quantity, :production_date, :expiry_date, :storage_location, :status)'
            );
            $statement->execute([
                ':item_name' => $itemName,
                ':category' => $category,
                ':quantity' => $quantity,
                ':production_date' => $productionDate,
                ':expiry_date' => $expiryDate,
                ':storage_location' => $storageLocation,
                ':status' => $status,
            ]);

            redirectWithMessage('success', 'New dessert batch added successfully.');
        }

        if ($action === 'update_stock') {
            $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
            $operation = (string) ($_POST['operation'] ?? '');
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            $wastageReason = (string) ($_POST['wastage_reason'] ?? '');
            $loggedBy = trim((string) ($_POST['logged_by'] ?? ''));

            if ($batchId === false || $batchId < 1 || $quantity === false || $quantity < 1 || !in_array($operation, ['sold', 'wasted'], true)) {
                redirectWithMessage('error', 'Please provide a valid stock adjustment.');
            }

            if ($operation === 'wasted' && !in_array($wastageReason, ['Expired', 'Handling Damage', 'Quality Defect'], true)) {
                redirectWithMessage('error', 'Please choose a valid wastage reason.');
            }

            if ($operation === 'wasted' && $loggedBy === '') {
                redirectWithMessage('error', 'Please enter the staff member recording the wastage.');
            }

            $pdo->beginTransaction();
            $batchStatement = $pdo->prepare(
                'SELECT batch_id, item_name, quantity, expiry_date
                 FROM dessert_batches
                 WHERE batch_id = :batch_id
                 FOR UPDATE'
            );
            $batchStatement->execute([':batch_id' => $batchId]);
            $batch = $batchStatement->fetch();

            if (!$batch) {
                $pdo->rollBack();
                redirectWithMessage('error', 'The selected batch could not be found.');
            }

            if ($quantity > (int) $batch['quantity']) {
                $pdo->rollBack();
                redirectWithMessage('error', 'Adjustment quantity cannot exceed available stock.');
            }

            $newQuantity = (int) $batch['quantity'] - $quantity;
            $updateStatement = $pdo->prepare(
                'UPDATE dessert_batches
                 SET quantity = :quantity, status = :status
                 WHERE batch_id = :batch_id'
            );
            $updateStatement->execute([
                ':quantity' => $newQuantity,
                ':status' => calculateStatus((string) $batch['expiry_date']),
                ':batch_id' => $batchId,
            ]);

            if ($operation === 'wasted') {
                $wastageStatement = $pdo->prepare(
                    'INSERT INTO wastage_logs
                        (batch_id, item_name, quantity_wasted, wastage_reason, logged_date, logged_by)
                     VALUES (:batch_id, :item_name, :quantity_wasted, :wastage_reason, :logged_date, :logged_by)'
                );
                $wastageStatement->execute([
                    ':batch_id' => $batchId,
                    ':item_name' => $batch['item_name'],
                    ':quantity_wasted' => $quantity,
                    ':wastage_reason' => $wastageReason,
                    ':logged_date' => $today->format('Y-m-d'),
                    ':logged_by' => $loggedBy,
                ]);
            }

            $pdo->commit();
            redirectWithMessage('success', $operation === 'wasted'
                ? 'Wastage recorded and stock updated.'
                : 'Sold quantity deducted from stock.');
        }

        if ($action === 'delete_batch') {
            $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);

            if ($batchId === false || $batchId < 1) {
                redirectWithMessage('error', 'The selected batch is invalid.');
            }

            $statement = $pdo->prepare('DELETE FROM dessert_batches WHERE batch_id = :batch_id');
            $statement->execute([':batch_id' => $batchId]);
            redirectWithMessage(
                'success',
                $statement->rowCount() > 0
                    ? 'Batch deleted. Related wastage logs were removed by the database.'
                    : 'The selected batch was already removed.'
            );
        }

        redirectWithMessage('error', 'That inventory action is not available.');
    }

    // Keep stored statuses aligned with the current date whenever the module loads.
    $statusUpdate = $pdo->query(
        "UPDATE dessert_batches
         SET status = CASE
             WHEN expiry_date <= CURDATE() THEN 'Expired'
             WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 'Expiring Soon'
             ELSE 'Fresh'
         END"
    );

    $search = trim((string) ($_GET['q'] ?? ''));
    $searchSql = '';
    $searchParameters = [];

    if ($search !== '') {
        $searchSql = 'WHERE item_name LIKE :search OR category LIKE :search OR storage_location LIKE :search';
        $searchParameters[':search'] = '%' . $search . '%';
    }

    $batchStatement = $pdo->prepare(
        "SELECT batch_id, item_name, category, quantity, production_date, expiry_date, storage_location, status
         FROM dessert_batches
         {$searchSql}
         ORDER BY
             CASE status WHEN 'Expired' THEN 1 WHEN 'Expiring Soon' THEN 2 ELSE 3 END,
             expiry_date ASC,
             batch_id DESC"
    );
    $batchStatement->execute($searchParameters);
    $batches = $batchStatement->fetchAll();

    $stats = $pdo->query(
        "SELECT
            COUNT(*) AS total_batches,
            COALESCE(SUM(status = 'Fresh'), 0) AS fresh_items,
            COALESCE(SUM(status = 'Expiring Soon'), 0) AS expiring_soon,
            COALESCE(SUM(status = 'Expired'), 0) +
                (SELECT COUNT(*) FROM wastage_logs) AS expired_wasted
         FROM dessert_batches"
    )->fetch();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $flash = [
        'type' => 'error',
        'message' => 'Database error: ' . $exception->getMessage(),
    ];
    $batches = [];
    $stats = [
        'total_batches' => 0,
        'fresh_items' => 0,
        'expiring_soon' => 0,
        'expired_wasted' => 0,
    ];
}

$statCards = [
    ['label' => 'Total Dessert Batches', 'value' => $stats['total_batches'], 'icon' => 'fa-layer-group', 'class' => 'stat-coral'],
    ['label' => 'Fresh Items', 'value' => $stats['fresh_items'], 'icon' => 'fa-leaf', 'class' => 'stat-green'],
    ['label' => 'Expiring Soon', 'value' => $stats['expiring_soon'], 'icon' => 'fa-clock', 'class' => 'stat-orange'],
    ['label' => 'Expired / Wasted Logs', 'value' => $stats['expired_wasted'], 'icon' => 'fa-triangle-exclamation', 'class' => 'stat-red'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory &amp; Expiry Management | Suka Dessert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --dark: #A14646;
            --primary: #DA6556;
            --secondary: #EB895B;
            --soft: #FDB773;
            --canvas: #F8F9FA;
            --ink: #3B2A2A;
            --muted: #8A7777;
            --line: #F0E4E2;
        }

        * { box-sizing: border-box; }
        body { background: var(--canvas); color: var(--ink); font-family: 'DM Sans', sans-serif; }
        h1, h2, h3, .brand-name { font-family: 'Playfair Display', serif; }
        .prototype-bar { background: var(--dark); }
        .topbar { background: #fff; border-bottom: 1px solid var(--line); }
        .sidebar { background: #fff; border-right: 1px solid var(--line); }
        .nav-item { color: #8A7777; transition: all .2s ease; }
        .nav-item:hover { color: var(--dark); background: #FFF4F1; }
        .nav-item.active { color: var(--dark); background: #FFF0EC; font-weight: 700; }
        .nav-item.active::before { background: var(--primary); border-radius: 0 4px 4px 0; content: ''; height: 32px; left: 0; position: absolute; width: 4px; }
        .soft-card { background: #fff; border: 1px solid rgba(240, 228, 226, .8); box-shadow: 0 8px 28px rgba(161, 70, 70, .06); }
        .stat-card { overflow: hidden; position: relative; }
        .stat-card::after { border: 1px solid currentColor; border-radius: 50%; content: ''; height: 90px; opacity: .09; position: absolute; right: -30px; top: -30px; width: 90px; }
        .stat-icon { align-items: center; border-radius: 12px; display: flex; height: 42px; justify-content: center; width: 42px; }
        .stat-coral { color: var(--primary); }
        .stat-coral .stat-icon { background: #FFF0EC; }
        .stat-green { color: #5B9072; }
        .stat-green .stat-icon { background: #EDF8F0; }
        .stat-orange { color: var(--secondary); }
        .stat-orange .stat-icon { background: #FFF6E9; }
        .stat-red { color: var(--dark); }
        .stat-red .stat-icon { background: #FCECEC; }
        .action-button { background: var(--primary); box-shadow: 0 6px 14px rgba(218, 101, 86, .2); transition: all .2s ease; }
        .action-button:hover { background: var(--dark); transform: translateY(-1px); }
        .table-head { background: #FFF9F7; color: var(--muted); }
        .table-row { border-top: 1px solid #F6EEEC; }
        .table-row:hover { background: #FFFCFB; }
        .status-pill { border-radius: 999px; display: inline-flex; font-size: .75rem; font-weight: 700; padding: .35rem .65rem; }
        .status-fresh { background: #E7F5EA; color: #37754D; }
        .status-expiring { background: #FFF0D9; color: #9A5B0B; }
        .status-expired { background: #F9E1E1; color: var(--dark); }
        .icon-button { align-items: center; border-radius: 8px; color: #AD9290; display: inline-flex; height: 32px; justify-content: center; transition: all .2s ease; width: 32px; }
        .icon-button:hover { background: #FFF0EC; color: var(--dark); }
        .modal-backdrop { background: rgba(59, 42, 42, .48); }
        .modal-panel { animation: rise .18s ease-out; }
        @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .field { background: #FFFDFD; border: 1px solid #EEDBD7; border-radius: 8px; color: var(--ink); outline: none; padding: .7rem .8rem; width: 100%; }
        .field:focus { border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(235, 137, 91, .13); }
        @media (max-width: 1023px) { .sidebar { display: none; } }
    </style>
</head>
<body>
    <div class="prototype-bar px-4 py-2 text-center text-xs font-semibold tracking-wide text-white">
        <span class="opacity-80">PROTOTYPE SWITCHER</span>
        <span class="mx-2 opacity-50">/</span>
        <span>Module 2 of 6</span>
        <span class="mx-2 opacity-50">/</span>
        <a class="underline underline-offset-2 hover:text-[#FDB773]" href="#">Switch prototype</a>
    </div>

    <header class="topbar fixed left-0 right-0 top-8 z-30 flex h-[72px] items-center justify-between px-5 lg:left-64 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button class="mr-1 text-[#A14646] lg:hidden" type="button" aria-label="Open navigation" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div class="brand-name truncate text-lg font-bold text-[#A14646] sm:text-xl">Suka Dessert <span class="font-normal text-[#8A7777]">- Inventory System</span></div>
        </div>
        <div class="hidden items-center gap-6 md:flex">
            <div class="border-l border-[#F0E4E2] pl-6 text-right">
                <div class="text-[10px] font-bold tracking-[.15em] text-[#DA6556]">CURRENT VIEW</div>
                <div class="text-xs font-semibold text-[#3B2A2A]">VIEW 2: INVENTORY &amp; EXPIRY MANAGEMENT</div>
            </div>
            <button class="relative text-[#A14646]" type="button" aria-label="Notifications">
                <i class="fa-regular fa-bell text-lg"></i><span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-[#EB895B]"></span>
            </button>
            <details class="relative">
                <summary class="flex cursor-pointer list-none items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#FDB773] text-sm font-bold text-[#A14646]">AD</span>
                    <span class="hidden text-left xl:block"><span class="block text-xs font-bold">Admin</span><span class="block text-[10px] text-[#8A7777]">Administrator</span></span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-[#8A7777]"></i>
                </summary>
                <div class="soft-card absolute right-0 mt-3 w-44 rounded-lg p-2 text-sm"><a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Profile settings</a><a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Sign out</a></div>
            </details>
        </div>
    </header>

    <aside id="sidebar" class="sidebar fixed bottom-0 left-0 top-[104px] z-40 w-64 px-4 py-7">
        <div class="mb-7 flex items-center gap-3 px-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#A14646] text-white"><i class="fa-solid fa-cake-candles"></i></span><div><div class="text-[10px] font-bold uppercase tracking-[.18em] text-[#EB895B]">Welcome back</div><div class="font-bold text-[#3B2A2A]">Suka Kitchen</div></div></div>
        <div class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[.18em] text-[#BBA5A3]">Workspace</div>
        <nav class="space-y-1">
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-chart-line w-5 text-center"></i> Dashboard</a>
            <a class="nav-item active relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#inventory"><i class="fa-solid fa-boxes-stacked w-5 text-center"></i> Inventory &amp; Expiry</a>
                <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module1_supplier_order.php?tab=suppliers"><i class="fa-solid fa-truck-field w-5 text-center"></i> Supplier &amp; Order</a>
                <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module3_ingredient_raw.php?tab=materials"><i class="fa-solid fa-flask w-5 text-center"></i> Ingredient &amp; Raw Material</a>
                <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-receipt w-5 text-center"></i> Sales</a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-chart-pie w-5 text-center"></i> Reports</a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-gear w-5 text-center"></i> Settings</a>
        </nav>
        <div class="absolute bottom-7 left-7 right-7 rounded-xl bg-[#FFF4F1] p-4"><div class="mb-2 flex items-center gap-2 text-xs font-bold text-[#A14646]"><i class="fa-solid fa-circle-info"></i> Stock reminder</div><p class="text-[11px] leading-relaxed text-[#8A7777]">Check orange and red batches before today's close.</p></div>
    </aside>

    <main id="inventory" class="min-h-screen px-4 pb-12 pt-[136px] lg:ml-64 lg:px-8">
        <div class="mx-auto max-w-[1500px]">
            <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div><div class="mb-2 text-xs font-bold uppercase tracking-[.2em] text-[#EB895B]">Inventory overview</div><h1 class="text-3xl font-bold text-[#3B2A2A] sm:text-4xl">Inventory &amp; Expiry</h1><p class="mt-2 text-sm text-[#8A7777]">Keep every sweet batch fresh, visible, and ready to delight.</p></div>
                <div class="text-left text-xs text-[#8A7777] sm:text-right"><div>Today</div><div class="font-bold text-[#A14646]"><?= e($today->format('l, d M Y')) ?></div></div>
            </div>

            <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <?php foreach ($statCards as $card): ?>
                    <div class="soft-card stat-card <?= e($card['class']) ?> rounded-xl p-5"><div class="mb-4 flex items-start justify-between"><div class="stat-icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div><i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i></div><div class="text-3xl font-bold text-[#3B2A2A]"><?= e($card['value']) ?></div><div class="mt-1 text-xs font-semibold text-[#8A7777]"><?= e($card['label']) ?></div></div>
                <?php endforeach; ?>
            </div>

            <?php if ($flash): ?>
                <div class="mb-5 flex items-center gap-3 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-[#CBE7D1] bg-[#F0FAF2] text-[#37754D]' : 'border-[#F0CCCC] bg-[#FFF0F0] text-[#A14646]' ?>" role="alert"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><span><?= e($flash['message']) ?></span></div>
            <?php endif; ?>

            <section class="soft-card rounded-xl" aria-labelledby="batch-list-heading">
                <div class="flex flex-col justify-between gap-4 border-b border-[#F0E4E2] p-5 sm:flex-row sm:items-center sm:p-6"><div><h2 id="batch-list-heading" class="text-xl font-bold">Dessert batches</h2><p class="mt-1 text-xs text-[#8A7777]">Monitor production, shelf life, and stock adjustments.</p></div><div class="flex flex-col gap-3 sm:flex-row"><form class="relative" method="get"><label class="sr-only" for="search">Search batches</label><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#BBA5A3]"></i><input class="field w-full pl-9 text-sm sm:w-64" id="search" name="q" type="search" value="<?= e($search) ?>" placeholder="Search batches..."><button class="sr-only" type="submit">Search</button></form><button class="action-button rounded-lg px-4 py-2.5 text-sm font-bold text-white" type="button" onclick="openModal('createModal')"><i class="fa-solid fa-plus mr-2"></i>Add New Batch</button></div></div>
                <div class="overflow-x-auto"><table class="min-w-[980px] w-full border-collapse text-left text-sm"><thead class="table-head text-[11px] uppercase tracking-wider"><tr><th class="px-6 py-4 font-bold">No.</th><th class="px-4 py-4 font-bold">Batch Name / Type</th><th class="px-4 py-4 font-bold">Category</th><th class="px-4 py-4 font-bold">Available Qty.</th><th class="px-4 py-4 font-bold">Production Date</th><th class="px-4 py-4 font-bold">Expiry Date</th><th class="px-4 py-4 font-bold">Storage Location</th><th class="px-4 py-4 font-bold">Status</th><th class="px-4 py-4 font-bold">Actions</th></tr></thead><tbody>
                    <?php if (!$batches): ?><tr><td class="px-6 py-14 text-center text-sm text-[#8A7777]" colspan="9"><i class="fa-solid fa-box-open mb-3 block text-2xl text-[#FDB773]"></i><?= $search !== '' ? 'No batches match your search.' : 'No dessert batches have been added yet.' ?></td></tr><?php endif; ?>
                    <?php foreach ($batches as $index => $batch): ?>
                        <?php $statusClass = $batch['status'] === 'Fresh' ? 'status-fresh' : ($batch['status'] === 'Expiring Soon' ? 'status-expiring' : 'status-expired'); ?>
                        <tr class="table-row"><td class="px-6 py-4 font-semibold text-[#BBA5A3]"><?= e($index + 1) ?></td><td class="px-4 py-4"><div class="font-bold text-[#3B2A2A]"><?= e($batch['item_name']) ?></div><div class="mt-1 text-[11px] text-[#BBA5A3]">Batch #<?= e($batch['batch_id']) ?></div></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($batch['category']) ?></td><td class="px-4 py-4 font-bold text-[#3B2A2A]"><?= e($batch['quantity']) ?> <span class="text-xs font-normal text-[#BBA5A3]">pcs</span></td><td class="px-4 py-4 text-[#6F5A59]"><?= e(date('d M Y', strtotime($batch['production_date']))) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e(date('d M Y', strtotime($batch['expiry_date']))) ?></td><td class="px-4 py-4 text-[#6F5A59]"><i class="fa-solid fa-location-dot mr-2 text-[#EB895B]"></i><?= e($batch['storage_location']) ?></td><td class="px-4 py-4"><span class="status-pill <?= e($statusClass) ?>"><?= e($batch['status']) ?></span></td><td class="px-4 py-4"><div class="flex items-center gap-1"><button class="icon-button" type="button" title="View details" aria-label="View details for <?= e($batch['item_name']) ?>" onclick='showDetails(<?= json_encode($batch, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-eye"></i></button><button class="icon-button" type="button" title="Deduct stock or log wastage" aria-label="Adjust <?= e($batch['item_name']) ?>" onclick='openAdjustment(<?= json_encode($batch, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen-to-square"></i></button><button class="icon-button hover:!bg-[#F9E1E1] hover:!text-[#A14646]" type="button" title="Delete batch" aria-label="Delete <?= e($batch['item_name']) ?>" onclick='openDelete(<?= (int) $batch['batch_id'] ?>, <?= json_encode($batch['item_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-trash-can"></i></button></div></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="flex flex-col justify-between gap-2 border-t border-[#F0E4E2] px-6 py-4 text-xs text-[#8A7777] sm:flex-row"><span>Showing <?= e(count($batches)) ?> batch<?= count($batches) === 1 ? '' : 'es' ?><?= $search !== '' ? ' matching your search' : '' ?>.</span><span><i class="fa-solid fa-circle-info mr-1 text-[#EB895B]"></i>Statuses refresh automatically each day.</span></div>
            </section>
        </div>
    </main>

    <div id="createModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="create-title"><div class="modal-panel my-auto w-full max-w-2xl rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><div><h2 id="create-title" class="text-xl font-bold">Add new batch</h2><p class="mt-1 text-xs text-[#8A7777]">Create a fresh dessert batch record.</p></div><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('createModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><form method="post" class="p-6"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="create_batch"><div class="grid gap-4 sm:grid-cols-2"><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Batch name / type<input class="field mt-2" name="item_name" required maxlength="150" placeholder="e.g. Classic Choco Jars"></label><label class="text-xs font-bold text-[#6F5A59]">Category<select class="field mt-2" name="category" required><option value="">Select category</option><option>Cake</option><option>Brownie</option><option>Choco Jar</option><option>Cookie</option><option>Other</option></select></label><label class="text-xs font-bold text-[#6F5A59]">Available quantity<input class="field mt-2" name="quantity" type="number" min="0" required placeholder="0"></label><label class="text-xs font-bold text-[#6F5A59]">Production date<input class="field mt-2" name="production_date" type="date" value="<?= e($today->format('Y-m-d')) ?>" required></label><label class="text-xs font-bold text-[#6F5A59]">Expiry date<input class="field mt-2" name="expiry_date" type="date" required></label><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Storage location<input class="field mt-2" name="storage_location" required maxlength="120" placeholder="e.g. Chiller A - Shelf 1"></label></div><div class="mt-6 flex justify-end gap-3"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777] hover:bg-[#FFF9F7]" type="button" onclick="closeModal('createModal')">Cancel</button><button class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit">Save batch</button></div></form></div></div>

    <div id="adjustModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="adjust-title"><div class="modal-panel my-auto w-full max-w-lg rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><div><h2 id="adjust-title" class="text-xl font-bold">Adjust stock</h2><p id="adjust-subtitle" class="mt-1 text-xs text-[#8A7777]"></p></div><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('adjustModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><form method="post" class="p-6"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="update_stock"><input id="adjust-batch-id" name="batch_id" type="hidden"><div class="mb-5 grid grid-cols-2 gap-2 rounded-lg bg-[#FFF4F1] p-1"><label class="cursor-pointer rounded-md px-3 py-2 text-center text-xs font-bold text-[#A14646] has-[:checked]:bg-white has-[:checked]:shadow-sm"><input class="sr-only" name="operation" type="radio" value="sold" checked onchange="toggleWastageFields()">Deduct sold</label><label class="cursor-pointer rounded-md px-3 py-2 text-center text-xs font-bold text-[#A14646] has-[:checked]:bg-white has-[:checked]:shadow-sm"><input class="sr-only" name="operation" type="radio" value="wasted" onchange="toggleWastageFields()">Log wastage</label></div><label class="text-xs font-bold text-[#6F5A59]">Quantity to deduct<input class="field mt-2" name="quantity" type="number" min="1" required></label><div id="wastageFields" class="mt-4 hidden space-y-4"><label class="block text-xs font-bold text-[#6F5A59]">Wastage reason<select class="field mt-2" name="wastage_reason"><option value="">Select reason</option><option>Expired</option><option>Handling Damage</option><option>Quality Defect</option></select></label><label class="block text-xs font-bold text-[#6F5A59]">Logged by<input class="field mt-2" name="logged_by" maxlength="120" placeholder="e.g. Farah - Storekeeper"></label></div><div class="mt-6 flex justify-end gap-3"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777] hover:bg-[#FFF9F7]" type="button" onclick="closeModal('adjustModal')">Cancel</button><button class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit">Update stock</button></div></form></div></div>

    <div id="detailsModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="details-title"><div class="modal-panel my-auto w-full max-w-md rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><h2 id="details-title" class="text-xl font-bold">Batch details</h2><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('detailsModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><div id="detailsContent" class="space-y-3 p-6"></div></div></div>

    <div id="deleteModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="delete-title"><div class="modal-panel my-auto w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"><div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-[#F9E1E1] text-[#A14646]"><i class="fa-solid fa-trash-can"></i></div><h2 id="delete-title" class="text-xl font-bold">Delete this batch?</h2><p class="mt-2 text-sm leading-relaxed text-[#8A7777]">You are about to delete <strong id="deleteName" class="text-[#3B2A2A]"></strong>. Related wastage logs will also be deleted.</p><form method="post" class="mt-6 flex justify-end gap-3"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="delete_batch"><input id="deleteBatchId" name="batch_id" type="hidden"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777] hover:bg-[#FFF9F7]" type="button" onclick="closeModal('deleteModal')">Keep batch</button><button class="rounded-lg bg-[#A14646] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#833737]" type="submit">Delete batch</button></form></div></div>

    <script>
        function openModal(id) { const modal = document.getElementById(id); modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.classList.add('overflow-hidden'); }
        function closeModal(id) { const modal = document.getElementById(id); modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.classList.remove('overflow-hidden'); }
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('!block'); }
        function openAdjustment(batch) { document.getElementById('adjust-batch-id').value = batch.batch_id; document.getElementById('adjust-subtitle').textContent = `${batch.item_name} - ${batch.quantity} pcs available`; document.querySelector('#adjustModal input[name="quantity"]').max = batch.quantity; openModal('adjustModal'); }
        function toggleWastageFields() { const wasted = document.querySelector('#adjustModal input[name="operation"]:checked').value === 'wasted'; document.getElementById('wastageFields').classList.toggle('hidden', !wasted); document.querySelector('#wastageFields select').required = wasted; document.querySelector('#wastageFields input').required = wasted; }
        function openDelete(batchId, itemName) { document.getElementById('deleteBatchId').value = batchId; document.getElementById('deleteName').textContent = itemName; openModal('deleteModal'); }
        function showDetails(batch) { const labels = [['Category', batch.category], ['Available quantity', `${batch.quantity} pcs`], ['Production date', batch.production_date], ['Expiry date', batch.expiry_date], ['Storage location', batch.storage_location], ['Status', batch.status]]; document.getElementById('detailsContent').innerHTML = `<div class="mb-4 rounded-lg bg-[#FFF4F1] p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-[#EB895B]">Batch #${batch.batch_id}</div><div class="mt-1 text-lg font-bold text-[#3B2A2A]">${escapeHtml(batch.item_name)}</div></div>` + labels.map(([label, value]) => `<div class="flex justify-between gap-4 border-b border-[#F6EEEC] pb-2 text-sm"><span class="text-[#8A7777]">${label}</span><span class="text-right font-semibold text-[#3B2A2A]">${escapeHtml(value)}</span></div>`).join(''); openModal('detailsModal'); }
        function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character])); }
        document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('[role="dialog"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); });
        document.querySelectorAll('[role="dialog"]').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal.id); }));
    </script>
</body>
</html>
