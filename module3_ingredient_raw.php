<?php
/**
 * Module 3: Ingredient & Raw Material Management
 * Suka Dessert Inventory Management System
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
$activeTab = (string) ($_GET['tab'] ?? 'materials');
$validTabs = ['materials', 'add-material', 'adjustments'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'materials';
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
        redirectTo('materials', 'error', 'Your session token expired. Please try again.');
    }
}

function stockStatus(float $quantity, float $reorderLevel): string
{
    if ($quantity <= 0) {
        return 'Out of Stock';
    }
    return $quantity <= $reorderLevel ? 'Low Stock' : 'Available';
}

function validPositiveNumber(mixed $value, bool $allowZero = true): float|false
{
    if (!is_numeric($value)) {
        return false;
    }
    $number = (float) $value;
    if ($number < 0 || (!$allowZero && $number === 0.0)) {
        return false;
    }
    return $number;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        requireCsrf((string) ($_POST['csrf_token'] ?? ''), $csrfToken);

        if ($action === 'create_material' || $action === 'update_material') {
            $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
            $materialName = trim((string) ($_POST['material_name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $unit = trim((string) ($_POST['unit'] ?? ''));
            $quantity = validPositiveNumber($_POST['quantity_in_stock'] ?? null);
            $reorderLevel = validPositiveNumber($_POST['reorder_level'] ?? null);
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);

            if ($materialName === '' || $category === '' || $unit === '' || $quantity === false || $reorderLevel === false || $supplierId === false || $supplierId < 1) {
                redirectTo($action === 'create_material' ? 'add-material' : 'materials', 'error', 'Please complete all raw material fields with valid values.');
            }

            if ($action === 'create_material') {
                $statement = $pdo->prepare(
                    'INSERT INTO raw_materials
                        (material_name, category, unit, quantity_in_stock, reorder_level, supplier_id)
                     VALUES (:material_name, :category, :unit, :quantity_in_stock, :reorder_level, :supplier_id)'
                );
                $statement->execute([
                    ':material_name' => $materialName,
                    ':category' => $category,
                    ':unit' => $unit,
                    ':quantity_in_stock' => $quantity,
                    ':reorder_level' => $reorderLevel,
                    ':supplier_id' => $supplierId,
                ]);
                redirectTo('materials', 'success', 'Raw material added successfully.');
            }

            if ($materialId === false || $materialId < 1) {
                redirectTo('materials', 'error', 'The selected raw material is invalid.');
            }

            $statement = $pdo->prepare(
                'UPDATE raw_materials SET material_name = :material_name, category = :category,
                    unit = :unit, quantity_in_stock = :quantity_in_stock, reorder_level = :reorder_level,
                    supplier_id = :supplier_id WHERE material_id = :material_id'
            );
            $statement->execute([
                ':material_name' => $materialName,
                ':category' => $category,
                ':unit' => $unit,
                ':quantity_in_stock' => $quantity,
                ':reorder_level' => $reorderLevel,
                ':supplier_id' => $supplierId,
                ':material_id' => $materialId,
            ]);
            redirectTo('materials', 'success', 'Raw material details updated.');
        }

        if ($action === 'adjust_stock') {
            $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
            $adjustment = validPositiveNumber($_POST['adjustment'] ?? null, false);
            $direction = (string) ($_POST['direction'] ?? '');

            if ($materialId === false || $materialId < 1 || $adjustment === false || !in_array($direction, ['received', 'used'], true)) {
                redirectTo('adjustments', 'error', 'Please provide a valid stock adjustment.');
            }

            $statement = $pdo->prepare('SELECT quantity_in_stock FROM raw_materials WHERE material_id = :material_id FOR UPDATE');
            $pdo->beginTransaction();
            $statement->execute([':material_id' => $materialId]);
            $material = $statement->fetch();

            if (!$material) {
                $pdo->rollBack();
                redirectTo('adjustments', 'error', 'The selected raw material could not be found.');
            }

            $currentQuantity = (float) $material['quantity_in_stock'];
            $newQuantity = $direction === 'received'
                ? $currentQuantity + $adjustment
                : $currentQuantity - $adjustment;

            if ($newQuantity < 0) {
                $pdo->rollBack();
                redirectTo('adjustments', 'error', 'Quantity cannot be negative.');
            }

            $update = $pdo->prepare('UPDATE raw_materials SET quantity_in_stock = :quantity WHERE material_id = :material_id');
            $update->execute([':quantity' => $newQuantity, ':material_id' => $materialId]);
            $pdo->commit();
            redirectTo('adjustments', 'success', $direction === 'received' ? 'Received stock added successfully.' : 'Used stock deducted successfully.');
        }

        if ($action === 'delete_material') {
            $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
            if ($materialId === false || $materialId < 1) {
                redirectTo('materials', 'error', 'The selected raw material is invalid.');
            }

            $statement = $pdo->prepare('DELETE FROM raw_materials WHERE material_id = :material_id');
            $statement->execute([':material_id' => $materialId]);
            redirectTo('materials', 'success', 'Raw material deleted successfully.');
        }

        redirectTo('materials', 'error', 'That raw material action is not available.');
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $categoryFilter = trim((string) ($_GET['category'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(rm.material_name LIKE :search OR rm.category LIKE :search OR s.supplier_name LIKE :search)';
        $parameters[':search'] = '%' . $search . '%';
    }
    if ($categoryFilter !== '') {
        $conditions[] = 'rm.category = :category';
        $parameters[':category'] = $categoryFilter;
    }
    if ($statusFilter === 'Available') {
        $conditions[] = 'rm.quantity_in_stock > rm.reorder_level';
    } elseif ($statusFilter === 'Low Stock') {
        $conditions[] = 'rm.quantity_in_stock > 0 AND rm.quantity_in_stock <= rm.reorder_level';
    } elseif ($statusFilter === 'Out of Stock') {
        $conditions[] = 'rm.quantity_in_stock <= 0';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $materialStatement = $pdo->prepare(
        "SELECT rm.material_id, rm.material_name, rm.category, rm.unit, rm.quantity_in_stock,
                rm.reorder_level, rm.supplier_id, s.supplier_name
         FROM raw_materials rm
         INNER JOIN suppliers s ON s.supplier_id = rm.supplier_id
         {$where}
         ORDER BY CASE WHEN rm.quantity_in_stock <= 0 THEN 1 WHEN rm.quantity_in_stock <= rm.reorder_level THEN 2 ELSE 3 END,
                  rm.material_name ASC"
    );
    $materialStatement->execute($parameters);
    $materials = $materialStatement->fetchAll();

    $allMaterials = $pdo->query(
        'SELECT rm.material_id, rm.material_name, rm.category, rm.unit, rm.quantity_in_stock,
                rm.reorder_level, rm.supplier_id, s.supplier_name
         FROM raw_materials rm INNER JOIN suppliers s ON s.supplier_id = rm.supplier_id
         ORDER BY rm.material_name ASC'
    )->fetchAll();
    $suppliers = $pdo->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name ASC')->fetchAll();
    $categories = $pdo->query('SELECT DISTINCT category FROM raw_materials ORDER BY category ASC')->fetchAll(PDO::FETCH_COLUMN);
    $categories = array_values(array_unique(array_merge(['Ingredient', 'Packaging'], $categories)));

    $stats = $pdo->query(
        "SELECT COUNT(*) AS total_materials,
                COALESCE(SUM(quantity_in_stock > reorder_level), 0) AS available_count,
                COALESCE(SUM(quantity_in_stock > 0 AND quantity_in_stock <= reorder_level), 0) AS low_stock_count,
                COALESCE(SUM(quantity_in_stock <= 0), 0) AS out_of_stock_count
         FROM raw_materials"
    )->fetch();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $flash = ['type' => 'error', 'message' => 'Database error: ' . $exception->getMessage()];
    $materials = $allMaterials = $suppliers = $categories = [];
    $stats = ['total_materials' => 0, 'available_count' => 0, 'low_stock_count' => 0, 'out_of_stock_count' => 0];
}

$statCards = [
    ['label' => 'Total Raw Materials', 'value' => $stats['total_materials'], 'icon' => 'fa-boxes-stacked', 'class' => 'stat-coral'],
    ['label' => 'Available Count', 'value' => $stats['available_count'], 'icon' => 'fa-circle-check', 'class' => 'stat-green'],
    ['label' => 'Low Stock Count', 'value' => $stats['low_stock_count'], 'icon' => 'fa-clock', 'class' => 'stat-orange'],
    ['label' => 'Out of Stock Count', 'value' => $stats['out_of_stock_count'], 'icon' => 'fa-triangle-exclamation', 'class' => 'stat-red'],
];
$today = new DateTimeImmutable('today');
$alerts = array_values(array_filter($allMaterials, static fn (array $material): bool => (float) $material['quantity_in_stock'] <= (float) $material['reorder_level']));
$tabLabels = ['materials' => 'Material Inventory', 'add-material' => 'Add Raw Material', 'adjustments' => 'Adjustments & Alerts'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingredient &amp; Raw Material Management | Suka Dessert</title>
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
        .status-pill { border-radius:999px; display:inline-flex; font-size:.75rem; font-weight:700; padding:.35rem .65rem; } .status-available { background:#E7F5EA; color:#37754D; } .status-low { background:#FFF0D9; color:#9A5B0B; } .status-out { background:#F9E1E1; color:var(--dark); }
        .icon-button { align-items:center; border-radius:8px; color:#AD9290; display:inline-flex; height:32px; justify-content:center; transition:all .2s ease; width:32px; } .icon-button:hover { background:#FFF0EC; color:var(--dark); } .field { background:#FFFDFD; border:1px solid #EEDBD7; border-radius:8px; color:var(--ink); outline:none; padding:.7rem .8rem; width:100%; } .field:focus { border-color:var(--secondary); box-shadow:0 0 0 3px rgba(235,137,91,.13); }
        .category-badge { background:#FFF1EC; border-radius:999px; color:var(--dark); display:inline-flex; font-size:.7rem; font-weight:700; padding:.3rem .55rem; } .category-badge.packaging { background:#FFF4E1; color:#9A5B0B; } .ratio-track { background:#F5EAE7; border-radius:999px; height:7px; overflow:hidden; width:100%; } .ratio-fill { background:#10B981; border-radius:999px; height:100%; } .ratio-fill.low { background:var(--secondary); } .ratio-fill.out { background:#EF4444; } .alert-banner { background:#FFF4F1; border:1px solid #F5D4CC; } .alert-item { background:#fff; border:1px solid #F3DEDA; }
        .tab-link { color:var(--muted); border-bottom:2px solid transparent; } .tab-link.active { border-color:var(--primary); color:var(--dark); font-weight:700; } .modal-backdrop { background:rgba(59,42,42,.48); } .modal-panel { animation:rise .18s ease-out; } @keyframes rise { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } } @media (max-width:1023px) { .sidebar { display:none; } }
    </style>
</head>
<body>
    <div class="prototype-bar px-4 py-2 text-center text-xs font-semibold tracking-wide text-white"><span class="opacity-80">PROTOTYPE SWITCHER</span><span class="mx-2 opacity-50">/</span><span>Module 3 of 3</span><span class="mx-2 opacity-50">/</span><a class="underline underline-offset-2 hover:text-[#FDB773]" href="#">Switch prototype</a></div>
    <header class="topbar fixed left-0 right-0 top-8 z-30 flex h-[72px] items-center justify-between border-b px-5 lg:left-64 lg:px-8"><div class="flex min-w-0 items-center gap-3"><button class="mr-1 text-[#A14646] lg:hidden" type="button" aria-label="Open navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars text-lg"></i></button><div class="brand-name truncate text-lg font-bold text-[#A14646] sm:text-xl">Suka Dessert <span class="font-normal text-[#8A7777]">- Inventory System</span></div></div><div class="hidden items-center gap-6 md:flex"><div class="border-l border-[#F0E4E2] pl-6 text-right"><div class="text-[10px] font-bold tracking-[.15em] text-[#DA6556]">CURRENT VIEW</div><div class="text-xs font-semibold text-[#3B2A2A]">VIEW 3: INGREDIENT &amp; RAW MATERIAL MANAGEMENT</div></div><button class="relative text-[#A14646]" type="button" aria-label="Notifications"><i class="fa-regular fa-bell text-lg"></i><span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-[#EB895B]"></span></button><details class="relative"><summary class="flex cursor-pointer list-none items-center gap-2"><span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#FDB773] text-sm font-bold text-[#A14646]">AD</span><span class="hidden text-left xl:block"><span class="block text-xs font-bold">Admin</span><span class="block text-[10px] text-[#8A7777]">Administrator</span></span><i class="fa-solid fa-chevron-down text-[10px] text-[#8A7777]"></i></summary><div class="soft-card absolute right-0 mt-3 w-44 rounded-lg p-2 text-sm"><a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Profile settings</a><a class="block rounded px-3 py-2 hover:bg-[#FFF0EC]" href="#">Sign out</a></div></details></div></header>
    <aside id="sidebar" class="sidebar fixed bottom-0 left-0 top-[104px] z-40 w-64 px-4 py-7"><div class="mb-7 flex items-center gap-3 px-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#A14646] text-white"><i class="fa-solid fa-cake-candles"></i></span><div><div class="text-[10px] font-bold uppercase tracking-[.18em] text-[#EB895B]">Welcome back</div><div class="font-bold text-[#3B2A2A]">Suka Kitchen</div></div></div><div class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[.18em] text-[#BBA5A3]">Workspace</div><nav class="space-y-1"><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-chart-line w-5 text-center"></i> Dashboard</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module2_inventory_expiry.php"><i class="fa-solid fa-boxes-stacked w-5 text-center"></i> Inventory &amp; Expiry</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="module1_supplier_order.php?tab=suppliers"><i class="fa-solid fa-truck-field w-5 text-center"></i> Supplier &amp; Order</a><a class="nav-item active relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="?tab=materials"><i class="fa-solid fa-flask w-5 text-center"></i> Ingredient &amp; Raw Material</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-chart-pie w-5 text-center"></i> Reports</a><a class="nav-item relative flex items-center gap-3 rounded-lg px-3 py-3 text-sm" href="#"><i class="fa-solid fa-gear w-5 text-center"></i> Settings</a></nav><div class="absolute bottom-7 left-7 right-7 rounded-xl bg-[#FFF4F1] p-4"><div class="mb-2 flex items-center gap-2 text-xs font-bold text-[#A14646]"><i class="fa-solid fa-circle-info"></i> Stock reminder</div><p class="text-[11px] leading-relaxed text-[#8A7777]">Review orange and red materials before ordering.</p></div></aside>
    <main class="min-h-screen px-4 pb-12 pt-[136px] lg:ml-64 lg:px-8"><div class="mx-auto max-w-[1500px]"><div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><div class="mb-2 text-xs font-bold uppercase tracking-[.2em] text-[#EB895B]">Kitchen supply overview</div><h1 class="text-3xl font-bold text-[#3B2A2A] sm:text-4xl">Ingredient &amp; Raw Materials</h1><p class="mt-2 text-sm text-[#8A7777]">Keep the ingredients and packaging behind every dessert in balance.</p></div><div class="text-left text-xs text-[#8A7777] sm:text-right"><div>Today</div><div class="font-bold text-[#A14646]"><?= e($today->format('l, d M Y')) ?></div></div></div>
        <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><?php foreach ($statCards as $card): ?><div class="soft-card stat-card <?= e($card['class']) ?> rounded-xl p-5"><div class="mb-4 flex items-start justify-between"><div class="stat-icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div><i class="fa-solid fa-arrow-up-right text-xs opacity-60"></i></div><div class="text-3xl font-bold text-[#3B2A2A]"><?= e($card['value']) ?></div><div class="mt-1 text-xs font-semibold text-[#8A7777]"><?= e($card['label']) ?></div></div><?php endforeach; ?></div>
        <?php if ($flash): ?><div class="mb-5 flex items-center gap-3 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-[#CBE7D1] bg-[#F0FAF2] text-[#37754D]' : 'border-[#F0CCCC] bg-[#FFF0F0] text-[#A14646]' ?>" role="alert"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><span><?= e($flash['message']) ?></span></div><?php endif; ?>
        <?php if ($activeTab === 'materials' && $alerts): ?><section class="alert-banner mb-6 rounded-xl p-5 sm:p-6"><div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><div class="flex items-center gap-2 text-sm font-bold text-[#A14646]"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock Alert: Items Need Reordering</div><p class="mt-1 text-xs text-[#8A7777]">Review these materials before your next production run.</p></div><a class="action-button rounded-lg px-4 py-2.5 text-center text-xs font-bold text-white" href="module1_supplier_order.php?tab=create-order">Create PO in Module 1 <i class="fa-solid fa-arrow-right ml-1"></i></a></div><div class="mt-5 grid gap-3 md:grid-cols-3"><?php foreach (array_slice($alerts, 0, 3) as $alert): ?><div class="alert-item rounded-lg p-4"><div class="flex items-start justify-between gap-3"><div><div class="font-bold text-[#3B2A2A]"><?= e($alert['material_name']) ?></div><div class="mt-1 text-[11px] text-[#8A7777]"><?= e($alert['supplier_name']) ?></div></div><span class="status-pill <?= (float) $alert['quantity_in_stock'] <= 0 ? 'status-out' : 'status-low' ?>"><?= (float) $alert['quantity_in_stock'] <= 0 ? 'Critical' : 'Reorder' ?></span></div><div class="mt-3 flex items-end justify-between"><div><div class="text-[10px] uppercase tracking-wider text-[#BBA5A3]">Current / Reorder</div><div class="mt-1 text-sm font-bold text-[#A14646]"><?= e(number_format((float) $alert['quantity_in_stock'], 2)) ?> / <?= e(number_format((float) $alert['reorder_level'], 2)) ?> <?= e($alert['unit']) ?></div></div><button class="icon-button" type="button" title="Adjust stock" aria-label="Adjust <?= e($alert['material_name']) ?>" onclick='openAdjustment(<?= json_encode($alert, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-arrows-rotate"></i></button></div></div><?php endforeach; ?></div></section><?php endif; ?>
        <section class="soft-card rounded-xl"><div class="overflow-x-auto border-b border-[#F0E4E2]"><nav class="flex min-w-max px-5 sm:px-6" aria-label="Raw material tabs"><?php foreach ($tabLabels as $tab => $label): ?><a class="tab-link px-4 py-4 text-xs sm:text-sm <?= $activeTab === $tab ? 'active' : '' ?>" href="?tab=<?= e($tab) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></div>
        <?php if ($activeTab === 'materials'): ?>
            <div class="flex flex-col justify-between gap-4 border-b border-[#F0E4E2] p-5 sm:flex-row sm:items-center sm:p-6"><div><h2 class="text-xl font-bold">Raw material inventory</h2><p class="mt-1 text-xs text-[#8A7777]">Track ingredients, packaging, and reorder thresholds.</p></div><a class="action-button rounded-lg px-4 py-2.5 text-center text-sm font-bold text-white" href="?tab=add-material"><i class="fa-solid fa-plus mr-2"></i>Add Raw Material</a></div>
            <form class="grid gap-3 border-b border-[#F0E4E2] bg-[#FFFDFC] p-5 sm:grid-cols-[1.4fr_1fr_1fr_auto] sm:p-6" method="get"><input type="hidden" name="tab" value="materials"><label class="relative"><span class="sr-only">Search ingredients or packaging</span><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#BBA5A3]"></i><input class="field pl-9 text-sm" name="q" type="search" value="<?= e($search) ?>" placeholder="Search ingredients or packaging..."></label><label><span class="sr-only">Category filter</span><select class="field text-sm" name="category"><option value="">All Categories</option><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?></select></label><label><span class="sr-only">Stock status filter</span><select class="field text-sm" name="status"><option value="">All Statuses</option><?php foreach (['Available', 'Low Stock', 'Out of Stock'] as $status): ?><option <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><a class="flex items-center justify-center rounded-lg border border-[#EEDBD7] px-4 py-2 text-sm font-bold text-[#8A7777] hover:bg-[#FFF4F1]" href="?tab=materials"><i class="fa-solid fa-rotate-left mr-2"></i>Reset</a><button class="hidden" type="submit">Apply filters</button></form>
            <div class="overflow-x-auto"><table class="min-w-[1400px] w-full border-collapse text-left text-sm"><thead class="table-head text-[11px] uppercase tracking-wider"><tr><th class="px-6 py-4 font-bold">Material ID</th><th class="px-4 py-4 font-bold">Material Name</th><th class="px-4 py-4 font-bold">Category</th><th class="px-4 py-4 font-bold">Current Qty</th><th class="px-4 py-4 font-bold">Unit</th><th class="px-4 py-4 font-bold">Reorder Level</th><th class="w-44 px-4 py-4 font-bold">Stock Ratio</th><th class="px-4 py-4 font-bold">Supplier</th><th class="px-4 py-4 font-bold">Stock Status</th><th class="px-4 py-4 font-bold">Actions</th></tr></thead><tbody><?php if (!$materials): ?><tr><td class="px-6 py-14 text-center text-[#8A7777]" colspan="10"><i class="fa-solid fa-box-open mb-3 block text-2xl text-[#FDB773]"></i>No raw materials found.</td></tr><?php endif; ?><?php foreach ($materials as $material): ?><?php $status = stockStatus((float) $material['quantity_in_stock'], (float) $material['reorder_level']); $statusClass = $status === 'Available' ? 'status-available' : ($status === 'Low Stock' ? 'status-low' : 'status-out'); $progress = (float) $material['reorder_level'] > 0 ? min(100, ((float) $material['quantity_in_stock'] / (float) $material['reorder_level']) * 100) : ((float) $material['quantity_in_stock'] > 0 ? 100 : 0); $progressClass = $status === 'Available' ? '' : ($status === 'Low Stock' ? 'low' : 'out'); $categoryClass = strtolower($material['category']) === 'packaging' ? 'packaging' : ''; ?><tr class="table-row"><td class="px-6 py-4 font-bold text-[#A14646]">RMB<?= e(str_pad((string) $material['material_id'], 2, '0', STR_PAD_LEFT)) ?></td><td class="px-4 py-4 font-bold text-[#3B2A2A]"><?= e($material['material_name']) ?></td><td class="px-4 py-4"><span class="category-badge <?= e($categoryClass) ?>"><?= e($material['category']) ?></span></td><td class="px-4 py-4 font-bold text-[#3B2A2A]"><?= e(number_format((float) $material['quantity_in_stock'], 2)) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e($material['unit']) ?></td><td class="px-4 py-4 text-[#6F5A59]"><?= e(number_format((float) $material['reorder_level'], 2)) ?></td><td class="px-4 py-4"><div class="mb-1 flex justify-between text-[10px] text-[#8A7777]"><span><?= e(number_format($progress, 0)) ?>%</span><span>Par <?= e(number_format((float) $material['reorder_level'], 2)) ?></span></div><div class="ratio-track"><div class="ratio-fill <?= e($progressClass) ?>" style="width: <?= e((string) $progress) ?>%"></div></div></td><td class="px-4 py-4 text-[#6F5A59]"><div class="font-semibold text-[#3B2A2A]"><?= e($material['supplier_name']) ?></div><div class="text-[10px] text-[#BBA5A3]">Supply partner</div></td><td class="px-4 py-4"><span class="status-pill <?= e($statusClass) ?>"><span class="mr-1.5">●</span><?= e($status) ?></span></td><td class="px-4 py-4"><div class="flex items-center gap-1"><button class="icon-button" type="button" title="View details" aria-label="View details" onclick='showDetails(<?= json_encode($material, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-eye"></i></button><button class="icon-button" type="button" title="Quick stock adjust" aria-label="Quick stock adjust" onclick='openAdjustment(<?= json_encode($material, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-arrows-rotate"></i></button><button class="icon-button" type="button" title="Edit material" aria-label="Edit material" onclick='editMaterial(<?= json_encode($material, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen-to-square"></i></button><button class="icon-button hover:!bg-[#F9E1E1] hover:!text-[#A14646]" type="button" title="Delete material" aria-label="Delete material" onclick='deleteMaterial(<?= (int) $material['material_id'] ?>, <?= json_encode($material['material_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-trash-can"></i></button></div></td></tr><?php endforeach; ?></tbody></table></div><div class="border-t border-[#F0E4E2] px-6 py-4 text-xs text-[#8A7777]">Showing <?= e(count($materials)) ?> material<?= count($materials) === 1 ? '' : 's' ?>.</div>
        <?php elseif ($activeTab === 'add-material'): ?>
            <div class="max-w-3xl p-5 sm:p-8"><div class="mb-6"><h2 class="text-xl font-bold">Add raw material</h2><p class="mt-1 text-xs text-[#8A7777]">Register a new ingredient or packaging item.</p></div><form method="post"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="create_material"><input name="material_id" type="hidden" value=""><div class="grid gap-5 sm:grid-cols-2"><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Material name<input class="field mt-2" name="material_name" required maxlength="150" placeholder="e.g. Milk Chocolate Couverture"></label><label class="text-xs font-bold text-[#6F5A59]">Category<select class="field mt-2" name="category" required><option value="">Choose category</option><option>Ingredient</option><option>Packaging</option></select></label><label class="text-xs font-bold text-[#6F5A59]">Unit<input class="field mt-2" name="unit" required maxlength="30" placeholder="kg, litres, pieces"></label><label class="text-xs font-bold text-[#6F5A59]">Quantity in stock<input class="field mt-2" name="quantity_in_stock" type="number" min="0" step="0.01" required placeholder="0.00"></label><label class="text-xs font-bold text-[#6F5A59]">Reorder level<input class="field mt-2" name="reorder_level" type="number" min="0" step="0.01" required placeholder="0.00"></label><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Supplier<select class="field mt-2" name="supplier_id" required><option value="">Choose supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier['supplier_id']) ?>"><?= e($supplier['supplier_name']) ?></option><?php endforeach; ?></select></label></div><div class="mt-7 flex justify-end"><button class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit"><i class="fa-solid fa-plus mr-2"></i>Add raw material</button></div></form></div>
        <?php else: ?>
            <div class="p-5 sm:p-8"><div class="mb-6"><div class="mb-2 text-xs font-bold uppercase tracking-[.18em] text-[#EB895B]">Stock control desk</div><h2 class="text-xl font-bold">Adjustments &amp; reorder alerts</h2><p class="mt-1 text-xs text-[#8A7777]">Record received or used stock and act on low inventory.</p></div><div class="grid gap-6 lg:grid-cols-[1.1fr_.9fr]"><div><h3 class="mb-3 text-lg font-bold">Adjust stock</h3><form method="post" class="soft-card rounded-xl p-5"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="adjust_stock"><label class="block text-xs font-bold text-[#6F5A59]">Material<select class="field mt-2" name="material_id" required><option value="">Choose material</option><?php foreach ($allMaterials as $material): ?><option value="<?= e($material['material_id']) ?>"><?= e($material['material_name']) ?> (<?= e(number_format((float) $material['quantity_in_stock'], 2)) ?> <?= e($material['unit']) ?>)</option><?php endforeach; ?></select></label><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-xs font-bold text-[#6F5A59]">Adjustment quantity<input class="field mt-2" name="adjustment" type="number" min="0.01" step="0.01" required placeholder="0.00"></label><label class="text-xs font-bold text-[#6F5A59]">Adjustment type<select class="field mt-2" name="direction" required><option value="received">+ Received</option><option value="used">- Used</option></select></label></div><button class="action-button mt-5 w-full rounded-lg px-4 py-2.5 text-sm font-bold text-white" type="submit"><i class="fa-solid fa-arrows-rotate mr-2"></i>Update stock</button></form></div><div><div class="mb-3 flex items-center justify-between"><h3 class="text-lg font-bold">Reorder alerts</h3><span class="rounded-full bg-[#FFF0D9] px-2.5 py-1 text-[10px] font-bold text-[#9A5B0B]"><?= e($stats['low_stock_count'] + $stats['out_of_stock_count']) ?> alerts</span></div><?php $alerts = array_filter($allMaterials, static fn (array $material): bool => (float) $material['quantity_in_stock'] <= (float) $material['reorder_level']); ?><?php if (!$alerts): ?><div class="soft-card rounded-xl p-8 text-center"><i class="fa-solid fa-circle-check mb-3 text-3xl text-[#5B9072]"></i><h3 class="font-bold text-[#37754D]">Stock levels look good</h3><p class="mt-1 text-sm text-[#8A7777]">No materials need reordering right now.</p></div><?php else: ?><div class="space-y-3"><?php foreach ($alerts as $material): ?><div class="soft-card rounded-xl p-4"><div class="flex items-start justify-between gap-3"><div><div class="font-bold text-[#3B2A2A]"><?= e($material['material_name']) ?></div><div class="mt-1 text-xs text-[#8A7777]"><?= e($material['supplier_name']) ?> · Reorder at <?= e(number_format((float) $material['reorder_level'], 2)) ?> <?= e($material['unit']) ?></div></div><span class="status-pill <?= (float) $material['quantity_in_stock'] <= 0 ? 'status-out' : 'status-low' ?>"><?= (float) $material['quantity_in_stock'] <= 0 ? 'Out of Stock' : 'Low Stock' ?></span></div><div class="mt-3 flex items-center justify-between text-xs"><span class="font-bold text-[#A14646]">Current: <?= e(number_format((float) $material['quantity_in_stock'], 2)) ?> <?= e($material['unit']) ?></span><a class="font-bold text-[#DA6556] hover:text-[#A14646]" href="module1_supplier_order.php?tab=create-order">Reorder / Create PO <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i></a></div></div><?php endforeach; ?></div><?php endif; ?></div></div></div>
        <?php endif; ?></section></div></main>

    <div id="adjustmentModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="adjustment-title"><div class="modal-panel my-auto w-full max-w-md rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><div><h2 id="adjustment-title" class="text-xl font-bold">Quick stock adjustment</h2><p id="adjustment-subtitle" class="mt-1 text-xs text-[#8A7777]"></p></div><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('adjustmentModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><form method="post" class="p-6"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="adjust_stock"><input id="adjustment-material-id" name="material_id" type="hidden"><div class="mb-5 grid grid-cols-2 gap-2 rounded-lg bg-[#FFF4F1] p-1"><label class="cursor-pointer rounded-md px-3 py-2 text-center text-xs font-bold text-[#A14646] has-[:checked]:bg-white has-[:checked]:shadow-sm"><input class="sr-only" name="direction" type="radio" value="received" checked>Stock Received (+)</label><label class="cursor-pointer rounded-md px-3 py-2 text-center text-xs font-bold text-[#A14646] has-[:checked]:bg-white has-[:checked]:shadow-sm"><input class="sr-only" name="direction" type="radio" value="used">Stock Used (-)</label></div><label class="block text-xs font-bold text-[#6F5A59]">Adjustment quantity<input class="field mt-2" name="adjustment" type="number" min="0.01" step="0.01" required placeholder="0.00"></label><div class="mt-6 flex justify-end gap-3"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777]" type="button" onclick="closeModal('adjustmentModal')">Cancel</button><button class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit">Update stock</button></div></form></div></div>
    <div id="materialModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="material-title"><div class="modal-panel my-auto w-full max-w-2xl rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><div><h2 id="material-title" class="text-xl font-bold">Edit raw material</h2><p class="mt-1 text-xs text-[#8A7777]">Update inventory details and reorder settings.</p></div><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('materialModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><form method="post" class="p-6"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="update_material"><input name="material_id" type="hidden"><div class="grid gap-4 sm:grid-cols-2"><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Material name<input class="field mt-2" name="material_name" required maxlength="150"></label><label class="text-xs font-bold text-[#6F5A59]">Category<select class="field mt-2" name="category" required><option>Ingredient</option><option>Packaging</option><?php foreach ($categories as $category): ?><option><?= e($category) ?></option><?php endforeach; ?></select></label><label class="text-xs font-bold text-[#6F5A59]">Unit<input class="field mt-2" name="unit" required maxlength="30"></label><label class="text-xs font-bold text-[#6F5A59]">Quantity in stock<input class="field mt-2" name="quantity_in_stock" type="number" min="0" step="0.01" required></label><label class="text-xs font-bold text-[#6F5A59]">Reorder level<input class="field mt-2" name="reorder_level" type="number" min="0" step="0.01" required></label><label class="text-xs font-bold text-[#6F5A59] sm:col-span-2">Supplier<select class="field mt-2" name="supplier_id" required><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier['supplier_id']) ?>"><?= e($supplier['supplier_name']) ?></option><?php endforeach; ?></select></label></div><div class="mt-6 flex justify-end gap-3"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777]" type="button" onclick="closeModal('materialModal')">Cancel</button><button class="action-button rounded-lg px-5 py-2.5 text-sm font-bold text-white" type="submit">Save changes</button></div></form></div></div>
    <div id="detailsModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="details-title"><div class="modal-panel my-auto w-full max-w-md rounded-xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-[#F0E4E2] px-6 py-5"><h2 id="details-title" class="text-xl font-bold">Material details</h2><button class="text-[#BBA5A3] hover:text-[#A14646]" type="button" aria-label="Close" onclick="closeModal('detailsModal')"><i class="fa-solid fa-xmark text-xl"></i></button></div><div id="detailsContent" class="space-y-3 p-6"></div></div></div>
    <div id="confirmModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="confirm-title"><div class="modal-panel my-auto w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"><div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-[#F9E1E1] text-[#A14646]"><i class="fa-solid fa-trash-can"></i></div><h2 id="confirm-title" class="text-xl font-bold">Delete raw material?</h2><p id="confirm-text" class="mt-2 text-sm leading-relaxed text-[#8A7777]"></p><form method="post" class="mt-6 flex justify-end gap-3"><input name="csrf_token" type="hidden" value="<?= e($csrfToken) ?>"><input name="action" type="hidden" value="delete_material"><input id="confirm-id" name="material_id" type="hidden"><button class="rounded-lg border border-[#EEDBD7] px-4 py-2.5 text-sm font-bold text-[#8A7777]" type="button" onclick="closeModal('confirmModal')">Keep material</button><button class="rounded-lg bg-[#A14646] px-4 py-2.5 text-sm font-bold text-white" type="submit">Delete material</button></form></div></div>
    <script>
        function openModal(id) { const modal = document.getElementById(id); modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.classList.add('overflow-hidden'); }
        function closeModal(id) { const modal = document.getElementById(id); modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.classList.remove('overflow-hidden'); }
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('!block'); }
        function openAdjustment(material) { document.getElementById('adjustment-material-id').value = material.material_id; document.getElementById('adjustment-subtitle').textContent = `${material.material_name} - ${material.quantity_in_stock} ${material.unit} currently available`; openModal('adjustmentModal'); }
        function editMaterial(material) { const form = document.querySelector('#materialModal form'); form.querySelector('[name="action"]').value = 'update_material'; form.querySelector('[name="material_id"]').value = material.material_id; form.querySelector('[name="material_name"]').value = material.material_name; form.querySelector('[name="category"]').value = material.category; form.querySelector('[name="unit"]').value = material.unit; form.querySelector('[name="quantity_in_stock"]').value = material.quantity_in_stock; form.querySelector('[name="reorder_level"]').value = material.reorder_level; form.querySelector('[name="supplier_id"]').value = material.supplier_id; openModal('materialModal'); }
        function deleteMaterial(id, name) { document.getElementById('confirm-id').value = id; document.getElementById('confirm-text').textContent = `${name} will be permanently deleted.`; openModal('confirmModal'); }
        function showDetails(material) { const status = Number(material.quantity_in_stock) <= 0 ? 'Out of Stock' : (Number(material.quantity_in_stock) <= Number(material.reorder_level) ? 'Low Stock' : 'Available'); const rows = [['Category', material.category], ['Quantity', `${material.quantity_in_stock} ${material.unit}`], ['Reorder level', `${material.reorder_level} ${material.unit}`], ['Supplier', material.supplier_name], ['Stock status', status]]; document.getElementById('detailsContent').innerHTML = `<div class="mb-4 rounded-lg bg-[#FFF4F1] p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-[#EB895B]">Material #${material.material_id}</div><div class="mt-1 text-lg font-bold text-[#3B2A2A]">${escapeHtml(material.material_name)}</div></div>` + rows.map(([label, value]) => `<div class="flex justify-between gap-4 border-b border-[#F6EEEC] pb-2 text-sm"><span class="text-[#8A7777]">${label}</span><span class="text-right font-semibold text-[#3B2A2A]">${escapeHtml(value)}</span></div>`).join(''); openModal('detailsModal'); }
        function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character])); }
        document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('[role="dialog"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); });
        document.querySelectorAll('[role="dialog"]').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal.id); }));
    </script>
</body>
</html>
