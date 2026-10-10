<?php
/**
 * Module 1: Supplier & Order Management
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
$activeTab = (string) ($_GET['tab'] ?? 'suppliers');
$validTabs = ['suppliers', 'create-order', 'orders', 'receive'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'suppliers';
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $tab, string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=' . urlencode($tab));
    exit;
}

function requireCsrf(string $submittedToken, string $csrfToken): void
{
    if (!hash_equals($csrfToken, $submittedToken)) {
        redirectTo('suppliers', 'error', 'Your session token expired. Please try again.');
    }
}

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

try {
    // Module 1 needs monetary totals, while the original table only stores quantity.
    // Add the nullable-compatible column once so existing installations continue to work.
    $columnCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'total_price'"
    );
    $columnCheck->execute();
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE purchase_orders ADD COLUMN total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity_ordered');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        requireCsrf((string) ($_POST['csrf_token'] ?? ''), $csrfToken);

        if ($action === 'create_supplier' || $action === 'update_supplier') {
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
            $contactPerson = trim((string) ($_POST['contact_person'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));

            if ($supplierName === '' || $contactPerson === '' || $phone === '' || $email === '' || $address === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirectTo('suppliers', 'error', 'Please complete all supplier fields with a valid email address.');
            }

            if ($action === 'create_supplier') {
                $statement = $pdo->prepare(
                    'INSERT INTO suppliers (supplier_name, contact_person, phone, email, address)
                     VALUES (:supplier_name, :contact_person, :phone, :email, :address)'
                );
                $statement->execute([
                    ':supplier_name' => $supplierName,
                    ':contact_person' => $contactPerson,
                    ':phone' => $phone,
                    ':email' => $email,
                    ':address' => $address,
                ]);
                redirectTo('suppliers', 'success', 'Supplier added successfully.');
            }

            if ($supplierId === false || $supplierId < 1) {
                redirectTo('suppliers', 'error', 'The selected supplier is invalid.');
            }

            $statement = $pdo->prepare(
                'UPDATE suppliers SET supplier_name = :supplier_name, contact_person = :contact_person,
                    phone = :phone, email = :email, address = :address WHERE supplier_id = :supplier_id'
            );
            $statement->execute([
                ':supplier_name' => $supplierName,
                ':contact_person' => $contactPerson,
                ':phone' => $phone,
                ':email' => $email,
                ':address' => $address,
                ':supplier_id' => $supplierId,
            ]);
            redirectTo('suppliers', 'success', 'Supplier details updated.');
        }

        if ($action === 'delete_supplier') {
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            if ($supplierId === false || $supplierId < 1) {
                redirectTo('suppliers', 'error', 'The selected supplier is invalid.');
            }

            $statement = $pdo->prepare('DELETE FROM suppliers WHERE supplier_id = :supplier_id');
            $statement->execute([':supplier_id' => $supplierId]);
            redirectTo('suppliers', 'success', 'Supplier deleted. Related purchase orders were removed by the database.');
        }

        if ($action === 'create_order') {
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
            $totalPrice = filter_var($_POST['total_price'] ?? null, FILTER_VALIDATE_FLOAT);
            $orderStatus = (string) ($_POST['order_status'] ?? 'Pending');
            $orderDate = (string) ($_POST['order_date'] ?? '');

            if ($supplierId === false || $supplierId < 1 || $materialId === false || $materialId < 1 || $quantity === false || $quantity < 1 || $totalPrice === false || $totalPrice < 0 || !in_array($orderStatus, ['Pending', 'Received', 'Cancelled'], true) || !validDate($orderDate)) {
                redirectTo('create-order', 'error', 'Please complete the purchase order with valid values.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO purchase_orders
                    (supplier_id, material_id, quantity_ordered, total_price, order_status, order_date)
                 VALUES (:supplier_id, :material_id, :quantity_ordered, :total_price, :order_status, :order_date)'
            );
            $statement->execute([
                ':supplier_id' => $supplierId,
                ':material_id' => $materialId,
                ':quantity_ordered' => $quantity,
                ':total_price' => number_format((float) $totalPrice, 2, '.', ''),
                ':order_status' => $orderStatus,
                ':order_date' => $orderDate,
            ]);
            redirectTo('orders', 'success', 'Purchase order created successfully.');
        }

        if ($action === 'update_order_status') {
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
            $orderStatus = (string) ($_POST['order_status'] ?? '');
            if ($orderId === false || $orderId < 1 || !in_array($orderStatus, ['Pending', 'Received', 'Cancelled'], true)) {
                redirectTo('orders', 'error', 'The selected purchase order or status is invalid.');
            }

            $statement = $pdo->prepare('UPDATE purchase_orders SET order_status = :order_status WHERE order_id = :order_id');
            $statement->execute([':order_status' => $orderStatus, ':order_id' => $orderId]);
            redirectTo($orderStatus === 'Received' ? 'receive' : 'orders', 'success', $orderStatus === 'Received'
                ? 'Inventory Updated Successfully - Received items logged.'
                : 'Purchase order status updated.');
        }

        if ($action === 'delete_order') {
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
            if ($orderId === false || $orderId < 1) {
                redirectTo('orders', 'error', 'The selected purchase order is invalid.');
            }

            $statement = $pdo->prepare('DELETE FROM purchase_orders WHERE order_id = :order_id');
            $statement->execute([':order_id' => $orderId]);
            redirectTo('orders', 'success', 'Purchase order deleted.');
        }

        redirectTo('suppliers', 'error', 'That supplier or order action is not available.');
    }

    $supplierSearch = trim((string) ($_GET['supplier_q'] ?? ''));
    $supplierSql = '';
    $supplierParams = [];
    if ($supplierSearch !== '') {
        $supplierSql = 'WHERE supplier_name LIKE :supplier_search OR contact_person LIKE :supplier_search OR email LIKE :supplier_search';
        $supplierParams[':supplier_search'] = '%' . $supplierSearch . '%';
    }

    $supplierStatement = $pdo->prepare("SELECT supplier_id, supplier_name, contact_person, phone, email, address FROM suppliers {$supplierSql} ORDER BY supplier_name ASC");
    $supplierStatement->execute($supplierParams);
    $suppliers = $supplierStatement->fetchAll();

    $materials = $pdo->query('SELECT material_id, material_name, unit FROM raw_materials ORDER BY material_name ASC')->fetchAll();

    $orderSearch = trim((string) ($_GET['order_q'] ?? ''));
    $orderSql = '';
    $orderParams = [];
    if ($orderSearch !== '') {
        $orderSql = 'WHERE s.supplier_name LIKE :order_search OR m.material_name LIKE :order_search OR po.order_status LIKE :order_search';
        $orderParams[':order_search'] = '%' . $orderSearch . '%';
    }

    $orderStatement = $pdo->prepare(
        "SELECT po.order_id, po.supplier_id, po.material_id, po.quantity_ordered, po.total_price,
                po.order_status, po.order_date, s.supplier_name, m.material_name, m.unit
         FROM purchase_orders po
         INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
         INNER JOIN raw_materials m ON m.material_id = po.material_id
         {$orderSql}
         ORDER BY CASE po.order_status WHEN 'Pending' THEN 1 WHEN 'Received' THEN 2 ELSE 3 END, po.order_date DESC, po.order_id DESC"
    );
    $orderStatement->execute($orderParams);
    $orders = $orderStatement->fetchAll();

    $stats = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM suppliers) AS total_suppliers,
            COALESCE(SUM(order_status = 'Received'), 0) AS active_orders,
            COALESCE(SUM(order_status = 'Pending'), 0) AS pending_orders,
            COALESCE(SUM(total_price), 0) AS total_order_value
         FROM purchase_orders"
    )->fetch();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $flash = ['type' => 'error', 'message' => 'Database error: ' . $exception->getMessage()];
    $suppliers = $materials = $orders = [];
    $stats = ['total_suppliers' => 0, 'active_orders' => 0, 'pending_orders' => 0, 'total_order_value' => 0];
}

$statCards = [
    ['label' => 'Total Suppliers', 'value' => $stats['total_suppliers'], 'icon' => 'fa-truck-field', 'class' => 'stat-coral'],
    ['label' => 'Active Purchase Orders', 'value' => $stats['active_orders'], 'icon' => 'fa-boxes-stacked', 'class' => 'stat-green'],
    ['label' => 'Pending POs', 'value' => $stats['pending_orders'], 'icon' => 'fa-clock', 'class' => 'stat-orange'],
    ['label' => 'Total Order Value', 'value' => 'RM ' . number_format((float) $stats['total_order_value'], 2), 'icon' => 'fa-coins', 'class' => 'stat-red'],
];
$today = new DateTimeImmutable('today');
$tabLabels = ['suppliers' => 'Supplier List', 'create-order' => 'Create Purchase Order', 'orders' => 'Order Tracking', 'receive' => 'Receive Order'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier &amp; Order Management | Suka Dessert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root { --dark:#A14646; --primary:#DA6556; --secondary:#EB895B; --soft:#FDB773; --canvas:#F8F9FA; --ink:#3B2A2A; --muted:#8A7777; --line:#F0E4E2; }
        * { box-sizing:border-box; } body { background:var(--canvas); color:var(--ink); font-family:'DM Sans',sans-serif; } h1,h2,h3,.brand-name { font-family:'Playfair Display',serif; }
        .prototype-bar { background:var(--dark); } .topbar,.sidebar { background:#fff; border-color:var(--line); } .sidebar { border-right:1px solid var(--line); }
        .nav-item { color:#8A7777; transition:all .2s ease; } .nav-item:hover { color:var(--dark); background:#FFF4F1; } .nav-item.active { color:var(--dark); background:#FFF0EC; font-weight:700; } .nav-item.active::before { background:var(--primary); border-radius:0 4px 4px 0; content:''; height:32px; left:0; position:absolute; width:4px; }
        .soft-card { background:#fff; border:1px solid rgba(240,228,226,.8); box-shadow:0 8px 28px rgba(161,70,70,.06); } .stat-card { overflow:hidden; position:relative; } .stat-card::after { border:1px solid currentColor; border-radius:50%; content:''; height:90px; opacity:.09; position:absolute; right:-30px; top:-30px; width:90px; } .stat-icon { align-items:center; border-radius:12px; display:flex; height:42px; justify-content:center; width:42px; }
        .stat-coral { color:var(--primary); } .stat-coral .stat-icon { background:#FFF0EC; } .stat-green { color:#5B9072; } .stat-green .stat-icon { background:#EDF8F0; } .stat-orange { color:var(--secondary); } .stat-orange .stat-icon { background:#FFF6E9; } .stat-red { color:var(--dark); } .stat-red .stat-icon { background:#FCECEC; }
        .action-button { background:var(--primary); box-shadow:0 6px 14px rgba(218,101,86,.2); transition:all .2s ease; } .action-button:hover { background:var(--dark); transform:translateY(-1px); } .table-head { background:#FFF9F7; color:var(--muted); } .table-row { border-top:1px solid #F6EEEC; } .table-row:hover { background:#FFFCFB; }
        .status-pill { border-radius:999px; display:inline-flex; font-size:.75rem; font-weight:700; padding:.35rem .65rem; } .status-pending { background:#FFF0D9; color:#9A5B0B; } .status-received { background:#E7F5EA; color:#37754D; } .status-cancelled { background:#F9E1E1; color:var(--dark); }
        .icon-button { align-items:center; border-radius:8px; color:#AD9290; display:inline-flex; height:32px; justify-content:center; transition:all .2s ease; width:32px; } .icon-button:hover { background:#FFF0EC; color:var(--dark); } .field { background:#FFFDFD; border:1px solid #EEDBD7; border-radius:8px; color:var(--ink); outline:none; padding:.7rem .8rem; width:100%; } .field:focus { border-color:var(--secondary); box-shadow:0 0 0 3px rgba(235,137,91,.13); }
        .tab-link { color:var(--muted); border-bottom:2px solid transparent; } .tab-link.active { border-color:var(--primary); color:var(--dark); font-weight:700; } .modal-backdrop { background:rgba(59,42,42,.48); } .modal-panel { animation:rise .18s ease-out; } @keyframes rise { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } } @media (max-width:1023px) { .sidebar { display:none; } }
    </style>
</head>
<body>
    <div class="prototype-bar px-4 py-2 text-center text-xs font-semibold tracking-wide text-white">
        <span class="opacity-80">PROTOTYPE SWITCHER</span>
        <span class="mx-2 opacity-50">/</span>
        <div class="inline-flex items-center gap-2 text-xs">
            <a class="rounded px-2 py-1 font-bold text-[#FDB773]" href="module1_supplier_order.php">Module 1</a>
            <span class="opacity-50">/</span>
            <a class="rounded px-2 py-1 text-white hover:text-[#FDB773]" href="module2_inventory_expiry.php">Module 2</a>
            <span class="opacity-50">/</span>
            <a class="rounded px-2 py-1 text-white hover:text-[#FDB773]" href="module3_ingredient_raw.php">Module 3</a>
            <span class="opacity-50">/</span>
            <a class="rounded px-2 py-1 text-white hover:text-[#FDB773]" href="dashboard.php">Dashboard</a>
        </div>
    </div>
    <header class="topbar fixed left-0 right-0 top-8 z-30 flex h-[72px] items-center justify-between border-b px-5 lg:left-64 lg:px-8"><div class="flex min-w-0 items-center gap-3"><button class="mr-1 text-[#A14646] lg:hidden" type="button" aria-label="Open navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars text-lg"></i></button><div class="brand-name truncate text-lg font-bold text-[#A14646] sm:text-xl">Suka Dessert <span class="font-normal text-[#8A7777]">- Inventory System</span></div></div><div class="hidden items-center gap-6 md:flex"><div class="border-l border-[#F0E4E2] pl-6 text-right"><div class="text-[10px] font-bold tracking-[.15em] text-[#DA6556]">CURRENT VIEW</div><div class="text-xs font-semibold text-[#3B2A2A]">VIEW 1: SUPPLIER &amp; ORDER MANAGEMENT</div></div><button class="relative text-[#A14646]" type="button" aria-label="Notifications"><i class="fa-regular fa-bell text-lg"></i><span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-[#EB895B]"></span></button><details class="relative"><summary class="flex cursor-pointer list-none items-center gap-2"><span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#FDB773] text-sm font-bold text-[#A14646]">AD</span><span class="hidden text-left xl:block"><span class="block text-xs font-bold">Admin</span><span class="block text-[10px] text-[#8A7777]">Administrator</span></span><i class="fa-solid fa-chevron-down text-[10px] text-[#8A7777]"></i></summary><div class="soft-card absolute right-0 mt-3 w-44 rounded-lg p-2 text-sm"><a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Profile settings</a><a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Sign out</a></div></details></div></header>
    <aside id="sidebar" class="sidebar fixed bottom-0 left-0 top-[104px] z-40 w-64 px-4 py-7"><div class="mb-7 flex items-center gap-3 px-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white">
    <img
        src="sukadessertlogo.jpg"
        alt="Suka Dessert Logo"
        class="h-full w-full object-contain"
    >
</span>
<div><div class="text-[10px] font-bold uppercase tracking-[.18em] text-[#EB895B]">Welcome back</div><div class="font-bold text-[#3B2A2A]">Suka Dessert</div></div></div><div class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[.18em] text-[#BBA5A3]">Workspace</div><nav class="space-y-1"><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="dashboard.php"><i class="fa-solid fa-chart-line w-5 text-center"></i> Dashboard</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module2_inventory_expiry.php"><i class="fa-solid fa-boxes-stacked w-5 text-center"></i> Inventory &amp; Expiry</a><a class="nav-item active relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module1_supplier_order.php"><i class="fa-solid fa-truck-field w-5 text-center"></i> Supplier &amp; Order</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module3_ingredient_raw.php"><i class="fa-solid fa-flask w-5 text-center"></i> Ingredient &amp; Raw Material</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-chart-pie w-5 text-center"></i> Reports</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-gear w-5 text-center"></i> Settings</a></nav><div class="absolute bottom-7 left-7 right-7 rounded-xl bg-[#FFF4F1] p-4"><div class="mb-2 flex items-center gap-2 text-xs font-bold text-[#A14646]"><i class="fa-solid fa-circle-info"></i> Order reminder</div><p class="text-[11px] leading-relaxed text-[#8A7777]">Review pending supplier orders before closing.</p></div></aside>
    <main class="min-h-screen px-4 pb-12 pt-[136px] lg:ml-64 lg:px-8"><div class="mx-auto max-w-[1500px]"><div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><div class="mb-2 text-xs font-bold uppercase tracking-[.2em] text-[#EB895B]">Procurement overview</div><h1 class="text-3xl font-bold text-[#3B2A2A] sm:text-4xl">Supplier &amp; Order Management</h1><p class="mt-2 text-sm text-[#8A7777]">Build reliable supply relationships and keep every order moving.</p></div><div class="text-left text-xs text-[#8A7777] sm:text-right"><div>Today</div><div class="font-bold text-[#A14646]"><?= e($today->format('l, d M Y')) ?></div></div></div>
        <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><?php foreach ($statCards as $card): ?><div class="soft-card stat-card <?= e($card['class']) ?> rounded-xl p-5"><div class="mb-4 flex items-start justify-between"><div class="stat-icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div><i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i></div><div class="text-2xl font-bold text-[#3B2A2A]"><?= e($card['value']) ?></div><div class="mt-1 text-xs font-semibold text-[#8A7777]"><?= e($card['label']) ?></div></div><?php endforeach; ?></div>
        <?php if ($flash): ?><div class="mb-5 flex items-center gap-3 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-[#CBE7D1] bg-[#F0FAF2] text-[#37754D]' : 'border-[#F0CCCC] bg-[#FFF0F0] text-[#A14646]' ?>" role="alert"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><span><?= e($flash['message']) ?></span></div><?php endif; ?>
        <section class="soft-card rounded-xl"><div class="overflow-x-auto border-b border-[#F0E4E2]"><nav class="flex min-w-max px-5 sm:px-6" aria-label="Supplier and order tabs"><?php foreach ($tabLabels as $tab => $label): ?><a class="tab-link px-4 py-4 text-xs sm:text-sm <?= $activeTab === $tab ? 'active' : '' ?>" href="?tab=<?= e($tab) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></div>
        <?php if ($activeTab === 'suppliers'): ?>
            <div class="flex flex-col justify-between gap-4 border-b border-[#F0E4E2] p-5 sm:flex-row sm:items-center sm:p-6"><div><h2 class="text-xl font-bold">Supplier directory</h2><p class="mt-1 text-xs text-[#8A7777]">Manage the partners behind every Suka Dessert ingredient.</p></div><div class="flex flex-col gap-3 sm:flex-row"><form class="relative" method="get"><input type="hidden" name="tab" value="suppliers"><label class="sr-only" for="supplier_q">Search suppliers</label><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#BBA5A3]"></i><input class="field w-full pl-9 text-sm sm:w-64" id="supplier_q" name="supplier_q" type="search" value="<?= e($supplierSearch) ?>" placeholder="Search suppliers..."></form><button class="action-button rounded-lg px-4 py-2.5 text-sm font-bold text-white" type="button" onclick="openModal('supplierModal')"><i class="fa-solid fa-plus mr-2"></i>Add Supplier</button></div></div>
            <div class="overflow-x-auto"><table class="min-w-[900px] w-full border-collapse text-left text-sm"><thead class="table-head text-[11px] uppercase tracking-wider"><tr><th class="px-6 py-4 font-bold">No.</th><th class="px-4 py-4 font-bold">Supplier</th><th class="px-4 py-4 font-bold">Contact Person</th><th class="px-4 py-4 font-bold">Phone</th><th class="px-4 py-4 font-bold">Email</th><th class="px-4 py-4 font-bold">Address</th><th class="px-4 py-4 font-bold">Actions</th></tr></thead><tbody><?php if (!$suppliers): ?><tr><td class="px-6 py-14 text-center text-[#8A7777]" colspan="7"><i class="fa-solid fa-truck-ramp-box mb-3 block text-2xl text-[#FDB773]"></i>No suppliers found.</td></tr><?php endif; ?><?php foreach ($suppliers as $index => $supplier): ?><tr class="table-row"><td class="px-6 py-4 font-semibold text-[#BBA5A3]"><?= e($index + 1) ?></td><td class="px-4 py-4 font-bold text-[#3B2A2A]"><?= e($supplier['supplier_name']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($supplier['contact_person']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($supplier['phone']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($supplier['email']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($supplier['address']) ?></td><td class="px-4 py-4"><div class="flex items-center gap-1"><button class="icon-button" type="button" title="Edit supplier" aria-label="Edit supplier" onclick='editSupplier(<?= json_encode($supplier, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen-to-square"></i></button><button class="icon-button hover:!bg-[#F9E1E1] hover:!text-[#A14646]" type="button" title="Delete supplier" aria-label="Delete supplier" onclick='deleteSupplier(<?= (int) $supplier['supplier_id'] ?>, <?= json_encode($supplier['supplier_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-trash-can"></i></button></div></td></tr><?php endforeach; ?></tbody></table></div><div class="border-t border-[#F0E4E2] px-6 py-4 text-xs text-[#8A7777]">Showing <?= e(count($suppliers)) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?>.</div>
        <?php elseif ($activeTab === 'create-order'): ?>
            <div class="max-w-3xl p-5 sm:p-8"><div class="mb-6"><h2 class="text-xl font-bold">Create purchase order</h2><p class="mt-1 text-xs text-[#8A7777]">Request ingredients or packaging from a trusted supplier.</p></div><form method="post"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="create_order"><div class="grid gap-5 sm:grid-cols-2"><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Supplier<select class="field mt-2" name="supplier_id" required><option value="">Choose supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier['supplier_id']) ?>"><?= e($supplier['supplier_name']) ?></option><?php endforeach; ?></select></label><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Material<select class="field mt-2" name="material_id" required><option value="">Choose material</option><?php foreach ($materials as $material): ?><option value="<?= e($material['material_id']) ?>"><?= e($material['material_name']) ?> (<?= e($material['unit']) ?>)</option><?php endforeach; ?></select></label><label class="text-xs font-bold text-[#6F5A59]">Quantity ordered<input class="field mt-2" name="quantity_ordered" type="number" min="1" required placeholder="0"></label><label class="text-xs font-bold text-[#6F5A59]">Total price (RM)<input class="field mt-2" name="total_price" type="number" min="0" step="0.01" required placeholder="0.00"></label><label class="text-xs font-bold text-[#6F5A59]">Order date<input class="field mt-2" name="order_date" type="date" value="<?= e($today->format('Y-m-d')) ?>" required></label><label class="text-xs font-bold text-[#6F5A59]">Order status<select class="field mt-2" name="order_status"><option>Pending</option><option>Received</option><option>Cancelled</option></select></label></div><div class="mt-7 flex justify-end"><button class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit"><i class="fa-solid fa-paper-plane mr-2"></i>Create purchase order</button></div></form></div>
        <?php elseif ($activeTab === 'orders'): ?>
            <div class="flex flex-col justify-between gap-4 border-b border-[#F0E4E2] p-5 sm:flex-row sm:items-center sm:p-6"><div><h2 class="text-xl font-bold">Purchase order tracking</h2><p class="mt-1 text-xs text-[#8A7777]">Follow every order from request to receipt.</p></div><form class="relative" method="get"><input type="hidden" name="tab" value="orders"><label class="sr-only" for="order_q">Search purchase orders</label><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#BBA5A3]"></i><input class="field w-full pl-9 text-sm sm:w-64" id="order_q" name="order_q" type="search" value="<?= e($orderSearch) ?>" placeholder="Search orders..."></form></div><div class="overflow-x-auto"><table class="min-w-[1000px] w-full border-collapse text-left text-sm"><thead class="table-head text-[11px] uppercase tracking-wider"><tr><th class="px-6 py-4 font-bold">PO No.</th><th class="px-4 py-4 font-bold">Supplier</th><th class="px-4 py-4 font-bold">Material</th><th class="px-4 py-4 font-bold">Quantity</th><th class="px-4 py-4 font-bold">Total Price</th><th class="px-4 py-4 font-bold">Order Date</th><th class="px-4 py-4 font-bold">Status</th><th class="px-4 py-4 font-bold">Actions</th></tr></thead><tbody><?php if (!$orders): ?><tr><td class="px-6 py-14 text-center text-[#8A7777]" colspan="8"><i class="fa-solid fa-file-invoice mb-3 block text-2xl text-[#FDB773]"></i>No purchase orders found.</td></tr></tr><?php endif; ?><?php foreach ($orders as $order): ?><?php $statusClass = $order['order_status'] === 'Pending' ? 'status-pending' : ($order['order_status'] === 'Received' ? 'status-received' : 'status-cancelled'); ?><tr class="table-row"><td class="px-6 py-4 font-bold text-[#A14646]">PO-<?= e(str_pad((string) $order['order_id'], 4, '0', STR_PAD_LEFT)) ?></td><td class="px-4 py-4 font-semibold text-[#3B2A2A]"><?= e($order['supplier_name']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($order['material_name']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($order['quantity_ordered']) ?> <?= e($order['unit']) ?></td><td class="px-4 py-4 font-semibold text-[#3B2A2A]">RM <?= e(number_format((float) $order['total_price'], 2)) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e(date('d M Y', strtotime($order['order_date']))) ?></td><td class="px-4 py-4"><span class="status-pill <?= e($statusClass) ?>"><?= e($order['order_status']) ?></span></td><td class="px-4 py-4"><div class="flex items-center gap-1"><?php if ($order['order_status'] === 'Pending'): ?><button class="icon-button" type="button" title="Receive order" aria-label="Receive order" onclick="updateOrder(<?= (int) $order['order_id'] ?>, 'Received')"><i class="fa-solid fa-box-open"></i></button><button class="icon-button hover:!bg-[#F9E1E1] hover:!text-[#A14646]" type="button" title="Cancel order" aria-label="Cancel order" onclick="updateOrder(<?= (int) $order['order_id'] ?>, 'Cancelled')"><i class="fa-solid fa-ban"></i></button><?php endif; ?><button class="icon-button hover:!bg-[#F9E1E1] hover:!text-[#A14646]" type="button" title="Delete order" aria-label="Delete order" onclick="deleteOrder(<?= (int) $order['order_id'] ?>)"><i class="fa-regular fa-trash-can"></i></button></div></td></tr><?php endforeach; ?></tbody></table></div><div class="border-t border-[#F0E4E2] px-6 py-4 text-xs text-[#8A7777]">Showing <?= e(count($orders)) ?> purchase order<?= count($orders) === 1 ? '' : 's' ?>.</div>
        <?php else: ?>
            <div class="p-5 sm:p-8"><div class="mb-6"><div class="mb-2 text-xs font-bold uppercase tracking-[.18em] text-[#EB895B]">Receiving desk</div><h2 class="text-xl font-bold">Receive order workflow</h2><p class="mt-1 text-xs text-[#8A7777]">Mark a pending order as received to complete the handoff.</p></div><?php $pendingOrders = array_filter($orders, static fn (array $order): bool => $order['order_status'] === 'Pending'); ?><?php if (!$pendingOrders): ?><div class="rounded-xl border border-[#CBE7D1] bg-[#F0FAF2] p-8 text-center"><i class="fa-solid fa-circle-check mb-3 text-3xl text-[#5B9072]"></i><h3 class="text-lg font-bold text-[#37754D]">All caught up</h3><p class="mt-1 text-sm text-[#6F7F70]">There are no pending purchase orders to receive.</p></div><?php else: ?><div class="grid gap-4 md:grid-cols-2"><?php foreach ($pendingOrders as $order): ?><div class="rounded-xl border border-[#F0E4E2] p-5"><div class="flex items-start justify-between"><div><div class="text-xs font-bold uppercase tracking-wider text-[#EB895B]">PO-<?= e(str_pad((string) $order['order_id'], 4, '0', STR_PAD_LEFT)) ?></div><h3 class="mt-1 text-lg font-bold"><?= e($order['material_name']) ?></h3></div><span class="status-pill status-pending">Pending</span></div><div class="mt-4 space-y-2 text-sm text-[#6F5A59]"><div class="flex justify-between"><span>Supplier</span><strong><?= e($order['supplier_name']) ?></strong></div><div class="flex justify-between"><span>Quantity</span><strong><?= e($order['quantity_ordered']) ?> <?= e($order['unit']) ?></strong></div><div class="flex justify-between"><span>Total</span><strong>RM <?= e(number_format((float) $order['total_price'], 2)) ?></strong></div></div><button class="action-button mt-5 w-full rounded-lg px-4 py-2.5 text-sm font-bold text-white" type="button" onclick="updateOrder(<?= (int) $order['order_id'] ?>, 'Received')"><i class="fa-solid fa-check mr-2"></i>Receive order</button></div><?php endforeach; ?></div><?php endif; ?></div>
        <?php endif; ?></section></div></main>

    <div id="supplierModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="supplier-title"><div class="modal-panel my-auto w-full max-w-2xl rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><div><h2 id="supplier-title" class="text-xl font-bold">Add supplier</h2><p class="mt-1 text-xs text-[#8A7777]">Save a trusted supply partner.</p></div><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('supplierModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><form method="post" class="p-6"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input id="supplier-action" name="action" type="hidden" value="create_supplier"><input id="supplier-id" name="supplier_id" type="hidden"><div class="grid gap-4 sm:grid-cols-2"><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Supplier name<input id="supplier-name" class="field mt-2" name="supplier_name" required maxlength="150" placeholder="e.g. Premium Cocoa Traders"></label><label class="text-xs font-bold text-[#6F5A59]">Contact person<input id="supplier-contact" class="field mt-2" name="contact_person" required maxlength="120"></label><label class="text-xs font-bold text-[#6F5A59]">Phone<input id="supplier-phone" class="field mt-2" name="phone" required maxlength="30"></label><label class="text-xs font-bold text-[#6F5A59]">Email<input id="supplier-email" class="field mt-2" name="email" type="email" required maxlength="150"></label><label class="text-xs font-bold text-[#6F5A59]">Address<input id="supplier-address" class="field mt-2" name="address" required maxlength="255"></label></div><div class="mt-6 flex justify-end gap-3"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777]" type="button" onclick="closeModal('supplierModal')">Cancel</button><button id="supplier-submit" class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit">Save supplier</button></div></form></div></div>
    <div id="confirmModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="confirm-title"><div class="modal-panel my-auto w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"><div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-[#F9E1E1] text-[#A14646]"><i class="fa-solid fa-triangle-exclamation"></i></div><h2 id="confirm-title" class="text-xl font-bold">Confirm action</h2><p id="confirm-text" class="mt-2 text-sm leading-relaxed text-[#8A7777]"></p><form id="confirm-form" method="post" class="mt-6 flex justify-end gap-3"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input id="confirm-action" name="action" type="hidden"><input id="confirm-id" name="supplier_id" type="hidden"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777]" type="button" onclick="closeModal('confirmModal')">Cancel</button><button class="rounded-lg bg-[#A14646] px-4 py-2.5 text-sm font-bold text-white" type="submit">Confirm</button></form></div></div>
    <form id="order-action-form" method="post" class="hidden"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input id="order-action" name="action" type="hidden"><input id="order-id" name="order_id" type="hidden"><input id="order-status" name="order_status" type="hidden"></form>
    <script>
        function openModal(id) { const modal = document.getElementById(id); modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.classList.add('overflow-hidden'); }
        function closeModal(id) { const modal = document.getElementById(id); modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.classList.remove('overflow-hidden'); }
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('!block'); }
        function editSupplier(supplier) { document.getElementById('supplier-title').textContent = 'Edit supplier'; document.getElementById('supplier-action').value = 'update_supplier'; document.getElementById('supplier-id').value = supplier.supplier_id; document.getElementById('supplier-name').value = supplier.supplier_name; document.getElementById('supplier-contact').value = supplier.contact_person; document.getElementById('supplier-phone').value = supplier.phone; document.getElementById('supplier-email').value = supplier.email; document.getElementById('supplier-address').value = supplier.address; document.getElementById('supplier-submit').textContent = 'Update supplier'; openModal('supplierModal'); }
        function deleteSupplier(id, name) { document.getElementById('confirm-title').textContent = 'Delete supplier?'; document.getElementById('confirm-text').textContent = `${name} and its related purchase orders will be deleted.`; document.getElementById('confirm-action').value = 'delete_supplier'; document.getElementById('confirm-id').name = 'supplier_id'; document.getElementById('confirm-id').value = id; openModal('confirmModal'); }
        function updateOrder(id, status) { document.getElementById('order-action').value = 'update_order_status'; document.getElementById('order-id').value = id; document.getElementById('order-status').value = status; document.getElementById('order-action-form').submit(); }
        function deleteOrder(id) { document.getElementById('confirm-title').textContent = 'Delete purchase order?'; document.getElementById('confirm-text').textContent = 'This purchase order will be permanently removed.'; document.getElementById('confirm-action').value = 'delete_order'; document.getElementById('confirm-id').name = 'order_id'; document.getElementById('confirm-id').value = id; openModal('confirmModal'); }
        document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('[role="dialog"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); });
        document.querySelectorAll('[role="dialog"]').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal.id); }));
    </script>
</body>
</html>
