<?php

declare(strict_types=1);

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

try {
    $totalRawMaterials = (int) $pdo->query('SELECT COUNT(*) FROM raw_materials')->fetchColumn();
    $lowStockRawMaterials = (int) $pdo->query('SELECT COUNT(*) FROM raw_materials WHERE quantity_in_stock <= reorder_level')->fetchColumn();
    $freshAndExpiringBatches = (int) $pdo->query("SELECT COUNT(*) FROM dessert_batches WHERE status IN ('Fresh', 'Expiring Soon')")->fetchColumn();
    $pendingPurchaseOrders = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE order_status = 'Pending'")->fetchColumn();

    $lowStockItems = $pdo->query(
        "SELECT material_id, material_name, quantity_in_stock, reorder_level, unit
         FROM raw_materials
         WHERE quantity_in_stock <= reorder_level
         ORDER BY quantity_in_stock ASC, material_name ASC
         LIMIT 6"
    )->fetchAll();

    $urgentExpiryItems = $pdo->query(
        "SELECT batch_id, item_name, quantity, expiry_date,
                CASE
                    WHEN expiry_date < CURDATE() THEN 'Expired'
                    WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) THEN 'Expiring Soon'
                    ELSE 'Fresh'
                END AS status
         FROM dessert_batches
         WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
         ORDER BY expiry_date ASC, batch_id DESC
         LIMIT 6"
    )->fetchAll();

    $healthyStock = (int) $pdo->query('SELECT COUNT(*) FROM raw_materials WHERE quantity_in_stock > reorder_level')->fetchColumn();
    $lowStockOverview = (int) $pdo->query('SELECT COUNT(*) FROM raw_materials WHERE quantity_in_stock > 0 AND quantity_in_stock <= reorder_level')->fetchColumn();
    $expiringSoonOverview = (int) $pdo->query("SELECT COUNT(*) FROM dessert_batches WHERE status = 'Expiring Soon'")->fetchColumn();
    $outOfStockOverview = (int) $pdo->query('SELECT COUNT(*) FROM raw_materials WHERE quantity_in_stock <= 0')->fetchColumn();

    $recentOrders = $pdo->query(
        "SELECT po.order_id, s.supplier_name, po.quantity_ordered AS total_items, po.order_status
         FROM purchase_orders po
         INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
         ORDER BY po.order_date DESC, po.order_id DESC
         LIMIT 6"
    )->fetchAll();

    $overviewTotal = max(1, $healthyStock + $lowStockOverview + $expiringSoonOverview + $outOfStockOverview);
    $healthyPct = (int) round(($healthyStock / $overviewTotal) * 100);
    $lowPct = (int) round(($lowStockOverview / $overviewTotal) * 100);
    $expiringPct = (int) round(($expiringSoonOverview / $overviewTotal) * 100);
    $outPct = 100 - ($healthyPct + $lowPct + $expiringPct);
} catch (Throwable $exception) {
    $totalRawMaterials = 0;
    $lowStockRawMaterials = 0;
    $freshAndExpiringBatches = 0;
    $pendingPurchaseOrders = 0;
    $lowStockItems = [];
    $urgentExpiryItems = [];
    $healthyStock = 0;
    $lowStockOverview = 0;
    $expiringSoonOverview = 0;
    $outOfStockOverview = 0;
    $recentOrders = [];
    $healthyPct = 0;
    $lowPct = 0;
    $expiringPct = 0;
    $outPct = 100;
}

$brandTitle = 'Suka Dessert - Inventory Central';
$brandBadge = 'v1.0.4 SQA LIVE';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8') ?> | Dashboard</title>
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
            --white: #FFFFFF;
        }

        * { box-sizing: border-box; }
        body {
            background: var(--canvas);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
        }
        h1, h2, h3, .brand-name {
            font-family: 'Playfair Display', serif;
        }
        .prototype-bar { background: var(--dark); }
        .topbar, .sidebar { background: #fff; border-color: var(--line); }
        .sidebar { border-right: 1px solid var(--line); }
        .nav-item {
            color: #8A7777;
            transition: all .2s ease;
            position: relative;
        }
        .nav-item:hover {
            color: var(--dark);
            background: #FFF4F1;
        }
        .nav-item.active {
            color: var(--dark);
            background: #FFF0EC;
            font-weight: 700;
        }
        .nav-item.active::before {
            background: var(--primary);
            border-radius: 0 4px 4px 0;
            content: '';
            height: 32px;
            left: 0;
            position: absolute;
            width: 4px;
        }
        .soft-card {
            background: #fff;
            border: 1px solid rgba(240, 228, 226, .8);
            box-shadow: 0 8px 28px rgba(161, 70, 70, .06);
        }
        .stat-card {
            overflow: hidden;
            position: relative;
        }
        .stat-card::after {
            border: 1px solid currentColor;
            border-radius: 50%;
            content: '';
            height: 90px;
            opacity: .09;
            position: absolute;
            right: -30px;
            top: -30px;
            width: 90px;
        }
        .stat-icon {
            align-items: center;
            border-radius: 12px;
            display: flex;
            height: 42px;
            justify-content: center;
            width: 42px;
        }
        .stat-coral { color: var(--primary); }
        .stat-coral .stat-icon { background: #FFF0EC; }
        .stat-green { color: #5B9072; }
        .stat-green .stat-icon { background: #EDF8F0; }
        .stat-orange { color: var(--secondary); }
        .stat-orange .stat-icon { background: #FFF6E9; }
        .stat-red { color: var(--dark); }
        .stat-red .stat-icon { background: #FCECEC; }
        .action-button {
            background: var(--primary);
            box-shadow: 0 6px 14px rgba(218, 101, 86, .2);
            transition: all .2s ease;
        }
        .action-button:hover {
            background: var(--dark);
            transform: translateY(-1px);
        }
        .status-pill {
            border-radius: 999px;
            display: inline-flex;
            font-size: .75rem;
            font-weight: 700;
            padding: .35rem .65rem;
        }
        .status-low { background: #FFF0D9; color: #9A5B0B; }
        .status-expiring { background: #FFF0D9; color: #9A5B0B; }
        .status-expired { background: #F9E1E1; color: var(--dark); }
        .status-pending { background: #FFF0D9; color: #9A5B0B; }
        .status-received { background: #E7F5EA; color: #37754D; }
        .status-cancelled { background: #F9E1E1; color: var(--dark); }
        .table-head { background: #FFF9F7; color: var(--muted); }
        .table-row { border-top: 1px solid #F6EEEC; }
        .table-row:hover { background: #FFFCFB; }
        .ring {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            position: relative;
            display: grid;
            place-items: center;
        }
        .ring::before {
            content: '';
            position: absolute;
            inset: 16px;
            background: #fff;
            border-radius: 50%;
            box-shadow: inset 0 0 0 1px #F0E4E2;
        }
        .ring-inner {
            position: relative;
            z-index: 1;
            text-align: center;
        }
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        @media (max-width: 1023px) {
            .sidebar { display: none; }
        }
    </style>
</head>
<body>
    <div class="prototype-bar px-4 py-2 text-center text-xs font-semibold tracking-wide text-white">
        <span class="opacity-80">PROTOTYPE SWITCHER</span>
        <span class="mx-2 opacity-50">/</span>
        <div class="inline-flex items-center gap-2 text-xs">
            <a class="rounded px-2 py-1 text-white hover:text-[#FDB773]" href="module1_supplier_order.php">Module 1</a>
            <span class="opacity-50">/</span>
            <a class="rounded px-2 py-1 text-white hover:text-[#FDB773]" href="module2_inventory_expiry.php">Module 2</a>
            <span class="opacity-50">/</span>
            <a class="rounded px-2 py-1 text-white hover:text-[#FDB773]" href="module3_ingredient_raw.php">Module 3</a>
            <span class="opacity-50">/</span>
            <a class="rounded px-2 py-1 font-bold text-[#FDB773]" href="dashboard.php">Dashboard</a>
        </div>
    </div>

    <header class="topbar fixed left-0 right-0 top-8 z-30 flex h-[72px] items-center justify-between border-b px-5 lg:left-64 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button class="mr-1 text-[#A14646] lg:hidden" type="button" aria-label="Open navigation">
                <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div class="brand-name truncate text-lg font-bold text-[#A14646] sm:text-xl">
                <?= htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <span class="inline-flex items-center rounded-full border border-[#F0E4E2] bg-[#FFF4F1] px-2 py-1 text-[10px] font-bold uppercase tracking-[.12em] text-[#A14646]">
                <?= htmlspecialchars($brandBadge, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <div class="hidden items-center gap-6 md:flex">
            <button class="relative text-[#A14646]" type="button" aria-label="Notifications">
                <i class="fa-regular fa-bell text-lg"></i>
                <span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-[#EB895B]"></span>
            </button>
            <details class="relative">
                <summary class="flex cursor-pointer list-none items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#FDB773] text-sm font-bold text-[#A14646]">AD</span>
                    <span class="hidden text-left xl:block">
                        <span class="block text-xs font-bold">Admin</span>
                        <span class="block text-[10px] text-[#8A7777]">Suka Kitchen</span>
                    </span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-[#8A7777]"></i>
                </summary>
                <div class="soft-card absolute right-0 mt-3 w-44 rounded-lg p-2 text-sm">
                    <a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Profile settings</a>
                    <a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Sign out</a>
                </div>
            </details>
        </div>
    </header>

    <aside id="sidebar" class="sidebar fixed bottom-0 left-0 top-[104px] z-40 w-64 px-4 py-7">
        <div class="mb-7 flex items-center gap-3 px-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white">
                <img src="sukadessertlogo.jpg" alt="Suka Dessert Logo" class="h-full w-full object-contain">
            </span>
            <div>
                <div class="text-[10px] font-bold uppercase tracking-[.18em] text-[#EB895B]">Welcome back</div>
                <div class="font-bold text-[#3B2A2A]">Suka Dessert</div>
            </div>
        </div>

        <div class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[.18em] text-[#BBA5A3]">Workspace</div>
        <nav class="space-y-1">
            <a class="nav-item active relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="dashboard.php">
                <i class="fa-solid fa-gauge-high w-5 text-center"></i> Dashboard
            </a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module2_inventory_expiry.php">
                <i class="fa-solid fa-boxes-stacked w-5 text-center"></i> Inventory &amp; Expiry
            </a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module1_supplier_order.php">
                <i class="fa-solid fa-truck-field w-5 text-center"></i> Supplier &amp; Order
            </a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module3_ingredient_raw.php">
                <i class="fa-solid fa-flask w-5 text-center"></i> Ingredient &amp; Raw Material
            </a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i> Reports
            </a>
            <a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#">
                <i class="fa-solid fa-gear w-5 text-center"></i> Settings
            </a>
        </nav>

        <div class="absolute bottom-7 left-7 right-7 rounded-xl bg-[#FFF4F1] p-4">
            <div class="mb-2 flex items-center gap-2 text-xs font-bold text-[#A14646]">
                <i class="fa-solid fa-circle-info"></i> System status
            </div>
            <p class="text-[11px] leading-relaxed text-[#8A7777]">Inventory sync is running and procurement actions are ready.</p>
        </div>
    </aside>

    <main class="min-h-screen px-4 pb-12 pt-[136px] lg:ml-64 lg:px-8">
        <div class="mx-auto max-w-[1500px]">
            <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <div class="mb-2 text-xs font-bold uppercase tracking-[.2em] text-[#EB895B]">Operations overview</div>
                    <h1 class="text-3xl font-bold text-[#3B2A2A] sm:text-4xl">Central Dashboard</h1>
                    <p class="mt-2 text-sm text-[#8A7777]">Monitor stock, procurement, expiry alerts, and overall system health from one unified view.</p>
                </div>
                <div class="text-left text-xs text-[#8A7777] sm:text-right">
                    <div>Today</div>
                    <div class="font-bold text-[#A14646]">
                        <?php echo date('l, d M Y'); ?>
                    </div>
                </div>
            </div>

            <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="soft-card stat-card stat-coral rounded-xl p-5">
                    <div class="mb-4 flex items-start justify-between">
                        <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                        <i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i>
                    </div>
                    <div class="text-3xl font-bold text-[#3B2A2A]"><?= htmlspecialchars((string) $totalRawMaterials, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-1 text-xs font-semibold text-[#8A7777]">Total Raw Materials</div>
                </div>

                <div class="soft-card stat-card stat-orange rounded-xl p-5">
                    <div class="mb-4 flex items-start justify-between">
                        <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i>
                    </div>
                    <div class="text-3xl font-bold text-[#3B2A2A]"><?= htmlspecialchars((string) $lowStockRawMaterials, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-1 text-xs font-semibold text-[#8A7777]">Low Stock Raw Materials</div>
                </div>

                <div class="soft-card stat-card stat-green rounded-xl p-5">
                    <div class="mb-4 flex items-start justify-between">
                        <div class="stat-icon"><i class="fa-solid fa-leaf"></i></div>
                        <i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i>
                    </div>
                    <div class="text-3xl font-bold text-[#3B2A2A]"><?= htmlspecialchars((string) $freshAndExpiringBatches, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-1 text-xs font-semibold text-[#8A7777]">Active Dessert Batches</div>
                </div>

                <div class="soft-card stat-card stat-red rounded-xl p-5">
                    <div class="mb-4 flex items-start justify-between">
                        <div class="stat-icon"><i class="fa-solid fa-cart-shopping"></i></div>
                        <i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i>
                    </div>
                    <div class="text-3xl font-bold text-[#3B2A2A]"><?= htmlspecialchars((string) $pendingPurchaseOrders, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-1 text-xs font-semibold text-[#8A7777]">Pending Purchase Orders</div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                <div class="space-y-6">
                    <section class="soft-card rounded-xl p-5">
                        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-xl font-bold text-[#3B2A2A]">Low Stock Raw Materials</h2>
                                <p class="mt-1 text-xs text-[#8A7777]">Items at or below reorder level requiring action.</p>
                            </div>
                            <a href="module1_supplier_order.php" class="action-button inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-bold text-white">
                                <i class="fa-solid fa-plus mr-2"></i>Reorder / Create PO
                            </a>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full border-collapse text-left text-sm">
                                <thead class="table-head text-[11px] uppercase tracking-wider">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Item Name</th>
                                        <th class="px-4 py-3 font-bold">Current Qty</th>
                                        <th class="px-4 py-3 font-bold">Reorder Level</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                        <th class="px-4 py-3 font-bold">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($lowStockItems)): ?>
                                        <tr>
                                            <td colspan="5" class="px-4 py-8 text-center text-[#8A7777]">No items are currently below reorder level.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($lowStockItems as $item): ?>
                                            <tr class="table-row">
                                                <td class="px-4 py-3 font-bold text-[#3B2A2A]">
                                                    <?= htmlspecialchars((string) $item['material_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3 text-[#6F5A59]">
                                                    <?= htmlspecialchars((string) number_format((float) $item['quantity_in_stock'], 2), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3 text-[#6F5A59]">
                                                    <?= htmlspecialchars((string) number_format((float) $item['reorder_level'], 2), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="status-pill status-low">Low Stock</span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <a href="module1_supplier_order.php" class="inline-flex items-center rounded-lg bg-[#DA6556] px-2.5 py-2 text-[11px] font-bold text-white hover:bg-[#A14646]">
                                                        Reorder
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="soft-card rounded-xl p-5">
                        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-xl font-bold text-[#3B2A2A]">Urgent Expiry &amp; Shelf-Life Alerts</h2>
                                <p class="mt-1 text-xs text-[#8A7777]">Items expiring within 2 days or already expired.</p>
                            </div>
                            <a href="module2_inventory_expiry.php" class="inline-flex items-center gap-2 text-sm font-bold text-[#A14646] hover:text-[#DA6556]">
                                Manage Expiry &amp; Wastage <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full border-collapse text-left text-sm">
                                <thead class="table-head text-[11px] uppercase tracking-wider">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Batch Item</th>
                                        <th class="px-4 py-3 font-bold">Available Qty</th>
                                        <th class="px-4 py-3 font-bold">Expiry Date</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($urgentExpiryItems)): ?>
                                        <tr>
                                            <td colspan="4" class="px-4 py-8 text-center text-[#8A7777]">No urgent expiry alerts at the moment.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($urgentExpiryItems as $batch): ?>
                                            <?php $statusClass = $batch['status'] === 'Expired' ? 'status-expired' : 'status-expiring'; ?>
                                            <tr class="table-row">
                                                <td class="px-4 py-3 font-bold text-[#3B2A2A]">
                                                    <?= htmlspecialchars((string) $batch['item_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3 text-[#6F5A59]">
                                                    <?= htmlspecialchars((string) $batch['quantity'], ENT_QUOTES, 'UTF-8') ?> pcs
                                                </td>
                                                <td class="px-4 py-3 text-[#6F5A59]">
                                                    <?= htmlspecialchars((string) date('d M Y', strtotime((string) $batch['expiry_date'])), ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $batch['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="soft-card rounded-xl p-5">
                        <div class="mb-4">
                            <h2 class="text-xl font-bold text-[#3B2A2A]">Overall Inventory Stock Overview</h2>
                            <p class="mt-1 text-xs text-[#8A7777]">Health split across active stock, low stock, expiry, and out-of-stock segments.</p>
                        </div>

                        <div class="flex flex-col items-center gap-5 md:flex-row md:items-center md:justify-between">
                            <div class="ring" style="background: conic-gradient(#5B9072 0 <?= (int) $healthyPct ?>%, #EB895B <?= (int) $healthyPct ?>% <?= (int) ($healthyPct + $lowPct) ?>%, #FDB773 <?= (int) ($healthyPct + $lowPct) ?>% <?= (int) ($healthyPct + $lowPct + $expiringPct) ?>%, #A14646 <?= (int) ($healthyPct + $lowPct + $expiringPct) ?>% 100%);">
                                <div class="ring-inner text-center">
                                    <div class="text-2xl font-bold text-[#3B2A2A]">
                                        <?= htmlspecialchars((string) $healthyPct, ENT_QUOTES, 'UTF-8') ?>%
                                    </div>
                                    <div class="text-[10px] uppercase tracking-[.14em] text-[#8A7777]">Healthy</div>
                                </div>
                            </div>

                            <div class="w-full max-w-md space-y-4">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2"><span class="legend-dot bg-[#5B9072]"></span>Healthy Stock</span>
                                    <strong><?= htmlspecialchars((string) $healthyPct, ENT_QUOTES, 'UTF-8') ?>%</strong>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2"><span class="legend-dot bg-[#EB895B]"></span>Low Stock</span>
                                    <strong><?= htmlspecialchars((string) $lowPct, ENT_QUOTES, 'UTF-8') ?>%</strong>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2"><span class="legend-dot bg-[#FDB773]"></span>Expiring Soon</span>
                                    <strong><?= htmlspecialchars((string) $expiringPct, ENT_QUOTES, 'UTF-8') ?>%</strong>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2"><span class="legend-dot bg-[#A14646]"></span>Out of Stock</span>
                                    <strong><?= htmlspecialchars((string) $outPct, ENT_QUOTES, 'UTF-8') ?>%</strong>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="soft-card rounded-xl p-5">
                        <div class="mb-4">
                            <h2 class="text-xl font-bold text-[#3B2A2A]">Recent Supplier Purchase Orders</h2>
                            <p class="mt-1 text-xs text-[#8A7777]">Latest procurement items across the supplier network.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full border-collapse text-left text-sm">
                                <thead class="table-head text-[11px] uppercase tracking-wider">
                                    <tr>
                                        <th class="px-4 py-3 font-bold">Order ID</th>
                                        <th class="px-4 py-3 font-bold">Supplier</th>
                                        <th class="px-4 py-3 font-bold">Total Items</th>
                                        <th class="px-4 py-3 font-bold">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentOrders)): ?>
                                        <tr>
                                            <td colspan="4" class="px-4 py-8 text-center text-[#8A7777]">No recent purchase orders found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentOrders as $order): ?>
                                            <?php $statusClass = $order['order_status'] === 'Pending' ? 'status-pending' : ($order['order_status'] === 'Received' ? 'status-received' : 'status-cancelled'); ?>
                                            <tr class="table-row">
                                                <td class="px-4 py-3 font-bold text-[#A14646]">PO-<?= htmlspecialchars((string) str_pad((string) $order['order_id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-4 py-3 font-semibold text-[#3B2A2A]">
                                                    <?= htmlspecialchars((string) $order['supplier_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3 text-[#6F5A59]">
                                                    <?= htmlspecialchars((string) $order['total_items'], ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $order['order_status'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
