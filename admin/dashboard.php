<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$page_title = 'Admin Dashboard';
$stats = today_stats($pdo);

// 1. Yesterday's Comprehensive Metrics Data (Kal Ka Poora Hisab)
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));

// Yesterday Sales Breakdown
$ySalesStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(net_payable), 0) as total_sales,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN net_payable ELSE 0 END), 0) as cash_sales,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN net_payable ELSE 0 END), 0) as bank_sales,
    COALESCE(SUM(CASE WHEN payment_mode = 'Udhar' THEN net_payable ELSE 0 END), 0) as udhar_sales,
    COALESCE(SUM(bonus_discount), 0) as total_discounts
    FROM sales 
    WHERE DATE(created_at) = ? AND status != 'Returned'");
$ySalesStmt->execute([$yesterdayDate]);
$ySales = $ySalesStmt->fetch();

// Yesterday COGS
$yCogsStmt = $pdo->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity), 0) 
    FROM sale_items si 
    JOIN sales s ON s.id = si.sale_id 
    WHERE DATE(s.created_at) = ? AND s.status != 'Returned'");
$yCogsStmt->execute([$yesterdayDate]);
$yCogs = (float) $yCogsStmt->fetchColumn();

// Yesterday Expenses
$yExpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date = ?");
$yExpStmt->execute([$yesterdayDate]);
$yExp = (float) $yExpStmt->fetchColumn();

// Yesterday Dealer Payments
$yDealerStmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM dealer_payments WHERE date = ?");
$yDealerStmt->execute([$yesterdayDate]);
$yDealerPaid = (float) $yDealerStmt->fetchColumn();

// Yesterday Udhar Recovered
$yUdharRecStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM udhar_payments WHERE date = ?");
$yUdharRecStmt->execute([$yesterdayDate]);
$yUdharRecovered = (float) $yUdharRecStmt->fetchColumn();

// Yesterday Returns
$yRetStmt = $pdo->prepare("SELECT COUNT(*) as returns_count, COALESCE(SUM(refund_amount), 0) as total_refund FROM returns WHERE date = ?");
$yRetStmt->execute([$yesterdayDate]);
$yRet = $yRetStmt->fetch();

// Yesterday Profit Calculation
$yGrossProfit = (float) $ySales['total_sales'] - $yCogs;
$yNetProfit = $yGrossProfit - $yExp - (float)$yRet['total_refund'];

// 2. Total Inventory Wholesale Value (Using 'manufacturer_price' from products table)
$totalInventoryCost = (float) $pdo->query("SELECT COALESCE(SUM(stock_quantity * manufacturer_price), 0) FROM products")->fetchColumn();

// 3. Total Udhar Balance Outstanding (Overall Customer Ledger Pending)
$totalUdharPending = (float) $pdo->query("SELECT COALESCE(SUM(balance_amount), 0) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();

// 7-day sales trend data
$trendQuery = $pdo->query("SELECT 
    DATE(created_at) as sale_date,
    COALESCE(SUM(net_payable), 0) as total_amount
    FROM sales 
    WHERE created_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC")->fetchAll();

$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('d M', strtotime($d));
    $found = 0;
    foreach ($trendQuery as $row) {
        if ($row['sale_date'] === $d) {
            $found = (float) $row['total_amount'];
            break;
        }
    }
    $chartData[] = $found;
}

// Payment method breakdown today
$payBreakdown = [
    'Cash' => $stats['cash_sales'],
    'Bank' => $stats['bank_sales'],
    'Udhar' => $stats['udhar_sales']
];

// Low stock items list
$lowStockProducts = $pdo->query("SELECT p.*, c.name as category_name, d.name as dealer_name 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    LEFT JOIN dealers d ON d.id = p.dealer_id 
    WHERE p.stock_quantity <= p.min_alert_limit 
    ORDER BY p.stock_quantity ASC 
    LIMIT 6")->fetchAll();

// Recent sales
$recentSales = $pdo->query("SELECT s.*, u.username as operator_name 
    FROM sales s 
    LEFT JOIN users u ON u.id = s.operator_id 
    ORDER BY s.id DESC 
    LIMIT 8")->fetchAll();

// Expiry alerts
$expiryAlertItems = $pdo->query("SELECT p.*, c.name as category_name, DATEDIFF(p.expiry_date, CURDATE()) as days_left 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    WHERE p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE() + INTERVAL 30 DAY 
    ORDER BY p.expiry_date ASC 
    LIMIT 5")->fetchAll();
$expiryAlertTotal = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL 30 DAY")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Low Stock Alert Banner -->
<?php if ($stats['low_stock_count'] > 0): ?>
    <div class="card" style="border-left: 4px solid var(--danger); background: #fffdfd; margin-bottom: 14px;">
        <div class="card-body" style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="badge badge-danger" style="font-size: 13px; padding: 6px 12px;">⚠️ Stock Alert</span>
                <strong><?= $stats['low_stock_count'] ?> product(s) are low in stock (below threshold limit).</strong>
            </div>
            <a href="<?= url('/admin/products.php?filter=low') ?>" class="btn btn-sm btn-danger">View Low Stock Items</a>
        </div>
    </div>
<?php endif; ?>

<!-- Expiry Alert Banner -->
<?php if ($expiryAlertTotal > 0): ?>
    <div class="card" style="border-left: 4px solid #e11d48; background: #fff5f5; margin-bottom: 20px;">
        <div class="card-body" style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="badge" style="background: #e11d48; color: #fff; font-size: 13px; padding: 6px 12px;">📅 Expiry Alert</span>
                <strong><?= $expiryAlertTotal ?> product(s) are already expired or expiring within 30 days!</strong>
            </div>
            <a href="<?= url('/admin/products.php?filter=expired') ?>" class="btn btn-sm" style="background: #e11d48; color: #fff;">View Expiring Products</a>
        </div>
    </div>
<?php endif; ?>

<!-- OVERALL STORE VALUATION & TOTAL UDHAR HIGHLIGHT CARDS -->
<div class="stats-row" style="margin-bottom: 22px;">
    <!-- Overall Inventory Valuation -->
    <div class="stat-box primary" style="background: linear-gradient(135deg, #eff6ff, #ffffff); border-left: 5px solid #2563eb;">
        <div class="stat-box-header">
            <span class="stat-title" style="font-weight: 800; color: #1e40af;">📦 Total Inventory Wholesale Value</span>
            <div class="stat-icon" style="background: #dbeafe; color: #2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
            </div>
        </div>
        <div class="stat-value" style="color: #1e40af; font-size: 28px;">Rs <?= money($totalInventoryCost) ?></div>
        <div class="stat-desc">Dukaan mein mojood total stock ki khareed qeemat</div>
    </div>

    <!-- Overall Outstanding Udhar -->
    <div class="stat-box warning" style="background: linear-gradient(135deg, #fffbeb, #ffffff); border-left: 5px solid #d97706;">
        <div class="stat-box-header">
            <span class="stat-title" style="font-weight: 800; color: #92400e;">📒 Total Outstanding Udhar (Ledger)</span>
            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
        </div>
        <div class="stat-value text-warning" style="font-size: 28px;">Rs <?= money($totalUdharPending) ?></div>
        <div class="stat-desc">Aam khareedaron ke zimme baqaya total udhar</div>
    </div>
</div>

<!-- YESTERDAY TOGGLE SECTION -->
<div class="card" style="margin-bottom: 24px; border-top: 3px solid #64748b; background: #f8fafc;">
    <div class="card-body" style="padding: 16px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; margin: 0; color: #334155;">
                    ⏮️ Yesterday's Summary Overview (<?= date('d M Y', strtotime($yesterdayDate)) ?>)
                </h3>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" id="toggleYesterdayBtn" onclick="toggleYesterdayOverview()" class="btn btn-sm btn-secondary" style="font-weight: 700; background: #475569; color: #ffffff; border: none; padding: 6px 14px;">
                    👁️ Show Yesterday's Overview
                </button>
                <a href="<?= url('/admin/analytics.php?preset=yesterday') ?>" class="btn btn-sm btn-light">Full Analytics &rarr;</a>
            </div>
        </div>

        <!-- COLLAPSIBLE CONTENT CONTAINER (HIDDEN BY DEFAULT) -->
        <div id="yesterdayContent" style="display: none; margin-top: 16px; border-top: 1px dashed #cbd5e1; padding-top: 16px;">
            <div class="stats-row">
                <!-- 1. Yesterday Total Sale -->
                <div class="stat-box primary">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday's Total Sale</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                    </div>
                    <div class="stat-value">Rs <?= money($ySales['total_sales']) ?></div>
                    <div class="stat-desc">
                        <span>Cash: <strong>Rs <?= money($ySales['cash_sales']) ?></strong></span> · 
                        <span>Bank: <strong>Rs <?= money($ySales['bank_sales']) ?></strong></span>
                    </div>
                </div>

                <!-- 2. Yesterday Cash Sales -->
                <div class="stat-box success">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday Cash Collected</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M7 15h0M2 9.5h20"></path></svg>
                        </div>
                    </div>
                    <div class="stat-value text-success">Rs <?= money($ySales['cash_sales']) ?></div>
                    <div class="stat-desc">Cash received yesterday</div>
                </div>

                <!-- 3. Yesterday Net Profit -->
                <div class="stat-box emerald">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday Net Profit</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                        </div>
                    </div>
                    <div class="stat-value" style="color: <?= $yNetProfit >= 0 ? '#16a34a' : '#dc2626' ?>;">
                        Rs <?= money($yNetProfit) ?>
                    </div>
                    <div class="stat-desc">Sales revenue minus COGS & expenses</div>
                </div>

                <!-- 4. Yesterday Udhar Given -->
                <div class="stat-box warning">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday's Udhar (Credit)</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        </div>
                    </div>
                    <div class="stat-value text-warning">Rs <?= money($ySales['udhar_sales']) ?></div>
                    <div class="stat-desc">Recovered yesterday: Rs <?= money($yUdharRecovered) ?></div>
                </div>

                <!-- 5. Yesterday Dealer Payments -->
                <div class="stat-box purple">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday Dealer Payments</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                        </div>
                    </div>
                    <div class="stat-value">Rs <?= money($yDealerPaid) ?></div>
                    <div class="stat-desc">Paid to suppliers yesterday</div>
                </div>

                <!-- 6. Yesterday Expenses -->
                <div class="stat-box danger">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday's Expenses</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        </div>
                    </div>
                    <div class="stat-value text-danger">Rs <?= money($yExp) ?></div>
                    <div class="stat-desc">Expenses recorded yesterday</div>
                </div>

                <!-- 7. Yesterday Returns -->
                <div class="stat-box info">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday Returns</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $yRet['returns_count'] ?> <span style="font-size: 14px; font-weight: 600;">Items</span></div>
                    <div class="stat-desc">Refunded: Rs <?= money($yRet['total_refund']) ?></div>
                </div>

                <!-- 8. Yesterday Discounts -->
                <div class="stat-box primary">
                    <div class="stat-box-header">
                        <span class="stat-title">Yesterday Discounts</span>
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                        </div>
                    </div>
                    <div class="stat-value">Rs <?= money($ySales['total_discounts']) ?></div>
                    <div class="stat-desc">Discounts given yesterday</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TODAY'S LIVE OVERVIEW HEADER & SHORTCUTS -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="font-size: 18px; font-weight: 800; color: var(--text-primary);">Today's Live Overview & Shortcuts</h2>
        <p class="text-muted" style="font-size: 12.5px;">Live real-time summary for <?= date('d F Y') ?></p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="<?= url('/operator/index.php') ?>" class="btn btn-primary btn-sm">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            + POS Sale
        </a>
        <a href="<?= url('/admin/products.php') ?>" class="btn btn-light btn-sm">+ Product</a>
        <a href="<?= url('/admin/dealers.php') ?>" class="btn btn-light btn-sm">Pay Dealer</a>
        <a href="<?= url('/admin/expenses.php') ?>" class="btn btn-light btn-sm">+ Expense</a>
    </div>
</div>

<!-- TODAY'S METRICS CARDS GRID -->
<div class="stats-row">
    <!-- 1. Total Sales -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Today's Total Sale</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($stats['total_sales']) ?></div>
        <div class="stat-desc">
            <span>Cash: <strong>Rs <?= money($stats['cash_sales']) ?></strong></span> · 
            <span>Bank: <strong>Rs <?= money($stats['bank_sales']) ?></strong></span>
        </div>
    </div>

    <!-- 2. Cash In Drawer -->
    <div class="stat-box success">
        <div class="stat-box-header">
            <span class="stat-title">Cash in Drawer</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M7 15h0M2 9.5h20"></path></svg>
            </div>
        </div>
        <div class="stat-value text-success">Rs <?= money($stats['drawer_cash']) ?></div>
        <div class="stat-desc">Physical cash available in drawer</div>
    </div>

    <!-- 3. Net Profit Today -->
    <div class="stat-box emerald">
        <div class="stat-box-header">
            <span class="stat-title">Net Profit Today</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
            </div>
        </div>
        <div class="stat-value" style="color: #16a34a;">Rs <?= money($stats['net_profit']) ?></div>
        <div class="stat-desc">Sales revenue minus COGS & expenses</div>
    </div>

    <!-- 4. Today's Udhar Given -->
    <div class="stat-box warning">
        <div class="stat-box-header">
            <span class="stat-title">Today's Udhar (Credit)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
        </div>
        <div class="stat-value text-warning">Rs <?= money($stats['udhar_sales']) ?></div>
        <div class="stat-desc">Recovered today: Rs <?= money($stats['udhar_recovered']) ?></div>
    </div>

    <!-- 5. Dealer Payments Today -->
    <div class="stat-box purple">
        <div class="stat-box-header">
            <span class="stat-title">Dealer Payments</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($stats['dealer_paid']) ?></div>
        <div class="stat-desc">Paid to suppliers today</div>
    </div>

    <!-- 6. Today's Expenses -->
    <div class="stat-box danger">
        <div class="stat-box-header">
            <span class="stat-title">Today's Expenses</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
        </div>
        <div class="stat-value text-danger">Rs <?= money($stats['total_expense']) ?></div>
        <div class="stat-desc">Petty cash & bills recorded</div>
    </div>

    <!-- 7. Today's Returns -->
    <div class="stat-box info">
        <div class="stat-box-header">
            <span class="stat-title">Returns / Refunds</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
            </div>
        </div>
        <div class="stat-value"><?= $stats['returns_count'] ?> <span style="font-size: 14px; font-weight: 600;">Items</span></div>
        <div class="stat-desc">Refunded: Rs <?= money($stats['total_refund']) ?></div>
    </div>

    <!-- 8. Discounts / Bonus -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Bonus / Discount</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($stats['total_discounts']) ?></div>
        <div class="stat-desc">Discounts given by cashier</div>
    </div>
</div>

<!-- Charts Section -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; width: 100%; box-sizing: border-box;">
    <div class="card" style="margin-bottom: 0; min-width: 0;">
        <div class="card-header">
            <h3>Sales Revenue Trend (Last 7 Days)</h3>
            <span class="badge badge-primary">Completed Sales</span>
        </div>
        <div class="card-body">
            <div style="position: relative; height: 260px; width: 100%; min-width: 0;">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0; min-width: 0;">
        <div class="card-header">
            <h3>Today's Payment Modes</h3>
            <span class="badge badge-gray"><?= $stats['invoice_count'] ?> Invoices</span>
        </div>
        <div class="card-body">
            <div style="position: relative; height: 260px; width: 100%; min-width: 0;">
                <canvas id="paymentModeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Low Stock & Recent Sales Rows -->
<div class="row" style="margin-top: 24px;">
    <?php if ($lowStockProducts): ?>
    <div class="col-4">
        <div class="card">
            <div class="card-header">
                <h3>Low Stock Alert</h3>
                <a href="<?= url('/admin/products.php?filter=low') ?>" class="btn btn-sm btn-light">Manage All</a>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockProducts as $lp): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($lp['name']) ?></strong><br>
                                        <small class="text-muted"><?= e($lp['category_name'] ?? 'General') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-danger"><?= $lp['stock_quantity'] ?> left</span>
                                    </td>
                                    <td>
                                        <a href="<?= url('/admin/products.php?edit_id=' . $lp['id']) ?>" class="btn btn-sm btn-light">Restock</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $lowStockProducts ? 'col-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header">
                <h3>Recent Sales Invoices</h3>
                <a href="<?= url('/admin/invoices.php') ?>" class="btn btn-sm btn-light">View All Invoices</a>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Buyer</th>
                                <th>Net Payable</th>
                                <th>Mode</th>
                                <th>Time</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSales)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted" style="padding: 30px;">
                                        No sales recorded yet today. Click <strong>+ POS Sale</strong> to create an invoice.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= url('/print_invoice.php?invoice=' . urlencode($sale['invoice_number'])) ?>" target="_blank" style="color: var(--primary); font-weight: 700;">
                                                <?= e($sale['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <strong><?= e($sale['buyer_name'] ?: 'Walk-in Customer') ?></strong>
                                            <?php if ($sale['buyer_phone']): ?>
                                                <br><small class="text-muted"><?= e($sale['buyer_phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong>Rs <?= money($sale['net_payable']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?= $sale['payment_mode'] === 'Cash' ? 'success' : ($sale['payment_mode'] === 'Bank' ? 'info' : 'warning') ?>">
                                                <?= e($sale['payment_mode']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('d M, h:i A', strtotime($sale['created_at'])) ?></td>
                                        <td class="text-right">
                                            <a href="<?= url('/print_invoice.php?invoice=' . urlencode($sale['invoice_number'])) ?>" target="_blank" class="btn btn-sm btn-light" title="Print Receipt">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                                Print
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle Function for Yesterday's Section
function toggleYesterdayOverview() {
    const container = document.getElementById('yesterdayContent');
    const btn = document.getElementById('toggleYesterdayBtn');
    
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.innerHTML = '🙈 Hide Yesterday\'s Overview';
    } else {
        container.style.display = 'none';
        btn.innerHTML = '👁️ Show Yesterday\'s Overview';
    }
}

if (window.Chart) {
    Chart.defaults.devicePixelRatio = window.devicePixelRatio || 2;
}

// Sales Trend Chart
const ctxTrend = document.getElementById('salesTrendChart').getContext('2d');
new Chart(ctxTrend, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Sales Revenue (Rs)',
            data: <?= json_encode($chartData) ?>,
            borderColor: '#22c55e',
            backgroundColor: 'rgba(34, 197, 94, 0.08)',
            borderWidth: 2.5,
            fill: true,
            tension: 0.35,
            pointBackgroundColor: '#22c55e',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: {
                    color: '#64748b',
                    font: { weight: '600' },
                    callback: (value) => 'Rs ' + value.toLocaleString()
                }
            },
            x: {
                grid: { display: false },
                ticks: {
                    color: '#64748b',
                    font: { weight: '600' }
                }
            }
        }
    }
});

// Payment Mode Donut Chart
const ctxPay = document.getElementById('paymentModeChart').getContext('2d');
const payData = <?= json_encode(array_values($payBreakdown)) ?>;
const hasSales = payData.some(v => v > 0);

new Chart(ctxPay, {
    type: 'doughnut',
    data: {
        labels: ['Cash', 'Bank', 'Udhar (Credit)'],
        datasets: [{
            data: hasSales ? payData : [1, 0, 0],
            backgroundColor: hasSales ? ['#22c55e', '#0284c7', '#f59e0b'] : ['#e2e8f0', '#e2e8f0', '#e2e8f0'],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 12, padding: 16, font: { weight: '700', size: 12 } }
            }
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>