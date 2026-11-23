<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by both Operator and Admin

$pdo = db();
$user = current_user();
$page_title = 'POS Billing Terminal';

// Handle Checkout POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    verify_csrf();
    $items = json_decode($_POST['items'] ?? '[]', true);
    $items = is_array($items) ? $items : [];
    $discount = post_float('bonus_discount');
    $mode = post_string('payment_mode', 'Cash');
    $buyerName = post_string('buyer_name') ?: 'Walk-in Customer';
    $buyerPhone = post_string('buyer_phone');
    $udharCustomerId = post_int('udhar_customer_id') ?: null;
    $paidAmount = post_float('paid_amount');
    $autoprint = isset($_POST['autoprint']) ? 1 : 0;

    if (empty($items)) {
        flash('error', 'Cart is empty. Please add at least one product.');
        redirect('/operator/index.php');
    }

    try {
        $pdo->beginTransaction();

        $ids = array_map('intval', array_column($items, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) FOR UPDATE");
        $pStmt->execute($ids);
        $productsById = [];
        foreach ($pStmt as $p) {
            $productsById[(int) $p['id']] = $p;
        }

        $totalAmount = 0.0;
        $processedItems = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $submittedPrice = isset($item['price']) ? (float) $item['price'] : 0.0;

            if (!$id || $qty <= 0.0001 || !isset($productsById[$id])) {
                throw new RuntimeException('Invalid product or quantity selected.');
            }

            $prod = $productsById[$id];
            $basePrice = (float) $prod['market_sale_price'];

            if (!empty($prod['expiry_date']) && strtotime($prod['expiry_date']) < strtotime(date('Y-m-d'))) {
                if (!in_array($user['role'], ['Admin', 'Master'], true)) {
                    throw new RuntimeException("Product '{$prod['name']}' has expired. Only Admin/Master can bill expired stock.");
                }
            }

            if (!in_array($user['role'], ['Admin', 'Master'], true)) {
                if ($submittedPrice > 0 && $submittedPrice < ($basePrice - 0.01)) {
                    throw new RuntimeException("Security Restriction: Operator cannot sell '{$prod['name']}' below rate of Rs " . money($basePrice) . ".");
                }
            }

            $unitPrice = ($submittedPrice > 0) ? $submittedPrice : $basePrice;

            if ((float) $prod['stock_quantity'] < $qty) {
                throw new RuntimeException("Insufficient stock for '{$prod['name']}'. Only {$prod['stock_quantity']} left.");
            }

            $lineTotal = round($unitPrice * $qty, 2);
            $totalAmount += $lineTotal;

            $processedItems[] = [
                'prod' => $prod,
                'qty' => $qty,
                'unit' => $prod['unit'] ?? 'piece',
                'costPrice' => (float) $prod['manufacturer_price'],
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal
            ];
        }

        $netPayable = max(0.0, $totalAmount - $discount);
        $changeReturned = 0.0;

        if ($mode === 'Udhar' && $udharCustomerId) {
            $cStmt = $pdo->prepare("SELECT uc.*, COALESCE(SUM(ul.balance_amount), 0) as current_balance 
                FROM udhar_customers uc 
                LEFT JOIN udhar_ledger ul ON ul.customer_id = uc.id AND ul.status != 'Cleared' 
                WHERE uc.id = ? GROUP BY uc.id");
            $cStmt->execute([$udharCustomerId]);
            $uCust = $cStmt->fetch();

            if ($uCust) {
                $buyerName = $uCust['name'];
                if (!empty($uCust['phone'])) $buyerPhone = $uCust['phone'];
                $activeBalance = (float) $uCust['current_balance'];
                $creditLimit = (float) $uCust['credit_limit'];

                if (($activeBalance + $netPayable) > $creditLimit) {
                    if (!in_array($user['role'], ['Admin', 'Master'], true)) {
                        throw new RuntimeException("Credit limit exceeded for '{$uCust['name']}'!");
                    }
                }
            }
        }

        if ($mode === 'Cash') {
            if ($paidAmount >= $netPayable) {
                $changeReturned = $paidAmount - $netPayable;
            } else {
                $paidAmount = $netPayable;
            }
        } else {
            $paidAmount = $netPayable;
        }

        $invoice = 'INV-' . date('ymd') . '-' . random_int(1000, 9999);

        $sStmt = $pdo->prepare("INSERT INTO sales (invoice_number, buyer_name, buyer_phone, total_amount, bonus_discount, net_payable, paid_amount, change_returned, payment_mode, operator_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $sStmt->execute([$invoice, $buyerName, $buyerPhone, $totalAmount, $discount, $netPayable, $paidAmount, $changeReturned, $mode, $user['id']]);
        $saleId = (int) $pdo->lastInsertId();

        $hasTrigger = (bool) $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'sale_item_after_insert'")->fetch();

        $siStmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit, cost_price, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stockDecStmt = $hasTrigger ? null : $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");

        foreach ($processedItems as $item) {
            $prod = $item['prod'];
            $qty = $item['qty'];
            $costPrice = $item['costPrice'];
            $unitPrice = $item['unitPrice'];
            $lineTotal = $item['lineTotal'];
            $itemUnit = $item['unit'];

            $siStmt->execute([$saleId, $prod['id'], $qty, $itemUnit, $costPrice, $unitPrice, $lineTotal]);

            if ($stockDecStmt) {
                $stockDecStmt->execute([$qty, $prod['id']]);
            }
        }

        if ($mode === 'Udhar') {
            $uStmt = $pdo->prepare("INSERT INTO udhar_ledger (customer_id, sale_id, buyer_name, buyer_phone, total_amount, balance_amount, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $uStmt->execute([$udharCustomerId, $saleId, $buyerName, $buyerPhone, $netPayable, $netPayable]);
        }

        $pdo->commit();

        $_SESSION['last_sale'] = [
            'invoice' => $invoice,
            'buyer' => $buyerName,
            'amount' => $netPayable,
            'mode' => $mode,
            'autoprint' => $autoprint
        ];

        redirect("/operator/index.php?sale_done=" . urlencode($invoice));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        redirect('/operator/index.php');
    }
}

// Fetch categories & products
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT p.*, c.name as category_name,
    DATEDIFF(p.expiry_date, CURDATE()) as days_until_expiry 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    ORDER BY p.name ASC")->fetchAll();

$udharCustomers = $pdo->query("
    SELECT uc.*, 
    COALESCE(SUM(ul.balance_amount), 0) as current_balance,
    GREATEST(0, uc.credit_limit - COALESCE(SUM(ul.balance_amount), 0)) as remaining_limit
    FROM udhar_customers uc
    LEFT JOIN udhar_ledger ul ON ul.customer_id = uc.id AND ul.status != 'Cleared'
    GROUP BY uc.id
    ORDER BY uc.name ASC
")->fetchAll();

$recentInvoices = $pdo->query("SELECT s.*, u.username as cashier 
    FROM sales s 
    LEFT JOIN users u ON u.id = s.operator_id 
    ORDER BY s.id DESC 
    LIMIT 10")->fetchAll();

$latestInvoiceNumber = $recentInvoices[0]['invoice_number'] ?? null;
$completedSaleInfo = $_SESSION['last_sale'] ?? null;
unset($_SESSION['last_sale']);

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Responsive Single-Screen POS Layout */
html, body {
    height: 100%;
    margin: 0;
    overflow: hidden;
}

.main-content,
.content-wrapper,
.page-content,
.container,
.container-fluid,
main {
    max-width: 100% !important;
    width: 100% !important;
    padding-bottom: 8px !important;
}

/* FULL SCREEN MODE (Works for both Toggle Button & Browser Fullscreen API) */
body.pos-fullscreen-mode,
:fullscreen body,
:-webkit-full-screen body {
    width: 100% !important;
    height: 100% !important;
    overflow: hidden !important;
}

body.pos-fullscreen-mode #checkoutForm,
:fullscreen #checkoutForm,
:-webkit-full-screen #checkoutForm {
    position: fixed !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
    max-width: none !important;
    max-height: none !important;
    margin: 0 !important;
    padding: 8px !important;
    background: #f1f5f9 !important;
    z-index: 99999 !important;
    box-sizing: border-box !important;
    display: flex !important;
}

body.pos-fullscreen-mode .pos-layout-grid,
:fullscreen .pos-layout-grid,
:-webkit-full-screen .pos-layout-grid {
    flex: 1 !important;
    width: 100% !important;
    height: 100% !important;
    grid-template-columns: minmax(380px, 34%) 1fr !important;
}

/* 2-Column Responsive POS Grid */
.pos-layout-grid {
    display: grid;
    grid-template-columns: minmax(380px, 36%) 1fr;
    gap: 10px;
    align-items: stretch;
    height: calc(100vh - 76px);
    width: 100%;
    box-sizing: border-box;
}

/* LEFT COLUMN: BILL + CHECKOUT STACKED */
.pos-left-bill-wrapper {
    display: flex;
    flex-direction: column;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    overflow: hidden;
    height: 100%;
    min-width: 0;
}

.pos-bill-header {
    padding: 8px 10px;
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.pos-cart-table-wrapper {
    flex: 1;
    overflow-y: auto;
    min-height: 120px;
}

.pos-cart-table {
    width: 100%;
    border-collapse: collapse;
}

.pos-cart-table th {
    background: #f1f5f9;
    color: #475569;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 6px 8px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 2;
}

.pos-cart-table td {
    padding: 5px 8px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

/* BOTTOM OF LEFT COLUMN: COMPACT CHECKOUT SUMMARY */
.pos-checkout-bottom {
    background: #f8fafc;
    border-top: 2px solid #e2e8f0;
    padding: 9px 10px;
    flex-shrink: 0;
}

.checkout-customer-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    margin-bottom: 7px;
}

.checkout-summary-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    padding: 7px 10px;
    margin-bottom: 7px;
}

.summary-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    margin-bottom: 4px;
}

.net-payable-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #0d9488;
    color: #ffffff;
    padding: 6px 10px;
    border-radius: 6px;
    margin-top: 4px;
}

.checkout-payment-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    align-items: center;
    margin-bottom: 7px;
}

.checkout-action-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

/* RIGHT COLUMN: SEARCH + CATEGORIES + PRODUCT IMAGE GRID */
.pos-catalog-column {
    display: flex;
    flex-direction: column;
    gap: 8px;
    height: 100%;
    min-width: 0;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 10px;
}

.search-dropdown-wrapper {
    position: relative;
    flex: 1;
}

.search-results-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    max-height: 220px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    margin-top: 4px;
}

.search-dropdown-item {
    padding: 8px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
}

.search-dropdown-item:hover {
    background: #f0fdf4;
}

/* Category Tabs Bar */
.pos-categories-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 7px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    max-height: 92px;
    overflow-y: auto;
    flex-shrink: 0;
}

.category-chip {
    padding: 7px 14px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}

.category-chip:hover {
    border-color: var(--primary);
    background: #f0fdf4;
}

.category-chip.active {
    background: var(--primary);
    color: #ffffff;
    border-color: var(--primary);
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}

/* Product Cards with Images - Responsive Auto-Fill */
.pos-products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
    overflow-y: auto;
    flex: 1;
    padding: 2px;
    align-content: start;
}

.pos-product-card {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    cursor: pointer;
    overflow: hidden;
    transition: transform 0.1s, border-color 0.15s, box-shadow 0.15s;
}

.pos-product-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}

.pos-product-card.out-of-stock {
    opacity: 0.55;
    background: #fef2f2;
}

.product-card-img-box {
    width: 100%;
    height: 95px;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 6px;
    border-bottom: 1px solid #f1f5f9;
}

.product-card-img-box img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.product-card-placeholder {
    font-size: 26px;
    color: #94a3b8;
    font-weight: 800;
}

.product-card-body {
    padding: 7px 8px;
}

.product-card-title {
    font-size: 12.5px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.product-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 4px;
}

.product-card-price {
    font-size: 12px;
    font-weight: 800;
    color: var(--primary);
}

.cart-qty-btn {
    width: 22px;
    height: 22px;
    border-radius: 4px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    font-weight: 700;
    cursor: pointer;
}

.fullscreen-toggle-btn {
    background: #0f172a;
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}

.fullscreen-toggle-btn:hover {
    background: #334155;
}

@media (max-width: 960px) {
    .pos-layout-grid,
    body.pos-fullscreen-mode .pos-layout-grid {
        grid-template-columns: 1fr 1fr !important;
    }
}
</style>

<?php if ($completedSaleInfo): ?>
    <div class="card" style="border-left: 4px solid var(--success); background: #f0fdf4; margin-bottom: 8px; position: relative; z-index: 100000;">
        <div class="card-body" style="padding: 6px 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px;">
                <span class="badge badge-success">✓ Sale Completed</span>
                <span>Invoice: <strong><?= e($completedSaleInfo['invoice']) ?></strong> · Total: <strong>Rs <?= money($completedSaleInfo['amount']) ?></strong> (<?= e($completedSaleInfo['mode']) ?>)</span>
            </div>
            <div style="display: flex; gap: 6px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="printReceipt('<?= e($completedSaleInfo['invoice']) ?>')">
                    🖨️ Print Bill
                </button>
                <button type="button" class="btn btn-light btn-sm" onclick="this.closest('.card').remove()">
                    Dismiss
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<form method="post" id="checkoutForm" onsubmit="return validateCheckout()" style="height: 100%;">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="checkout">
    <input type="hidden" name="items" id="itemsPayload">
    <input type="hidden" name="payment_mode" id="selectedPaymentMode" value="Cash">

    <div class="pos-layout-grid">
        <!-- 1. LEFT COLUMN: BILL TABLE (TOP) + CHECKOUT SUMMARY (BOTTOM) -->
        <div class="pos-left-bill-wrapper">
            <div class="pos-bill-header">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <button type="button" id="fullScreenToggleBtn" class="fullscreen-toggle-btn" onclick="togglePosFullScreen()" title="Hide/Show Sidebar & Full Screen">
                        ⛶ Full Screen
                    </button>
                    <h3 style="font-size: 13.5px; font-weight: 800; margin: 0; color: #0f172a;">🧾 Bill</h3>
                    <span class="badge badge-gray" id="cartItemCount">0</span>
                </div>
                <div style="display: flex; gap: 4px;">
                    <?php if ($latestInvoiceNumber): ?>
                        <button type="button" class="btn btn-light btn-sm" onclick="printReceipt('<?= e($latestInvoiceNumber) ?>')" title="Reprint Last Invoice" style="padding: 4px 7px; font-size: 11px;">
                            🖨️ Last
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-light" onclick="clearCart()" style="color: #dc2626; font-weight: 700; padding: 4px 8px; font-size: 11px;">New Invoice</button>
                </div>
            </div>

            <div class="pos-cart-table-wrapper">
                <table class="pos-cart-table">
                    <thead>
                        <tr>
                            <th style="width: 34%;">Description</th>
                            <th style="width: 28%;">Qty / Wt</th>
                            <th style="width: 15%; text-align: right;">Rate</th>
                            <th style="width: 15%; text-align: right;">Total</th>
                            <th style="width: 8%; text-align: center;">×</th>
                        </tr>
                    </thead>
                    <tbody id="cartItemsContainer">
                        <tr>
                            <td colspan="5" class="text-center text-muted" style="padding: 35px 12px; font-size: 12.5px;">
                                Cart is empty. Select category & click products on the right.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Bottom Checkout Summary -->
            <div class="pos-checkout-bottom">
                <div id="udharCustomerSection" style="display: none; margin-bottom: 6px;">
                    <select name="udhar_customer_id" id="udharCustomerSelect" class="form-control" onchange="onUdharCustomerSelected(this.value)" style="font-size: 11.5px; padding: 4px 8px;">
                        <option value="">-- Select Udhar Customer * --</option>
                        <?php foreach ($udharCustomers as $uc): ?>
                            <option value="<?= $uc['id'] ?>" data-name="<?= e($uc['name']) ?>" data-phone="<?= e($uc['phone'] ?? '') ?>">
                                <?= e($uc['name']) ?> (Left: Rs <?= money($uc['remaining_limit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="checkout-customer-row">
                    <input type="text" name="buyer_name" id="buyerNameInput" class="form-control" placeholder="Customer Name (Walk-in)" style="padding: 4px 8px; font-size: 11.5px;" onkeydown="preventInputSubmit(event)">
                    <input type="text" name="buyer_phone" id="buyerPhoneInput" class="form-control" placeholder="Phone: 0300-XXXXXXX" style="padding: 4px 8px; font-size: 11.5px;" onkeydown="preventInputSubmit(event)">
                </div>

                <div class="checkout-summary-box">
                    <div class="summary-line">
                        <span style="color: #475569; font-weight: 600;">Sub Total:</span>
                        <strong id="subtotalDisplay" style="font-size: 13px;">Rs 0.00</strong>
                    </div>
                    <div class="summary-line" style="margin-bottom: 0;">
                        <span style="color: #475569; font-weight: 600;">Discount (Rs):</span>
                        <input type="number" step="0.01" name="bonus_discount" id="discountInput" value="0" class="form-control" style="width: 85px; padding: 2px 6px; text-align: right; font-weight: 700; font-size: 12px;" onkeydown="preventInputSubmit(event)" oninput="updateCartTotalsOnly()">
                    </div>
                    <div class="net-payable-bar">
                        <span style="font-size: 12.5px; font-weight: 800; letter-spacing: 0.3px;">NET PAYABLE</span>
                        <span id="netPayableDisplay" style="font-size: 17px; font-weight: 900;">Rs 0.00</span>
                    </div>
                </div>

                <div class="checkout-payment-row">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px;">
                        <button type="button" class="payment-mode-btn active" data-mode="Cash" onclick="selectPaymentMode('Cash')" style="padding: 5px 2px; font-size: 11px;">💵 Cash</button>
                        <button type="button" class="payment-mode-btn" data-mode="Bank" onclick="selectPaymentMode('Bank')" style="padding: 5px 2px; font-size: 11px;">💳 Bank</button>
                        <button type="button" class="payment-mode-btn" data-mode="Udhar" onclick="selectPaymentMode('Udhar')" style="padding: 5px 2px; font-size: 11px;">📒 Udhar</button>
                    </div>

                    <div id="cashCalculatorBox" style="display: flex; align-items: center; gap: 5px;">
                        <input type="number" step="0.01" name="paid_amount" id="cashTendered" class="form-control" placeholder="Cash Recv" style="padding: 4px 6px; font-weight: 700; font-size: 12px; width: 52%;" onkeydown="preventInputSubmit(event)" oninput="calcChange()">
                        <div style="font-size: 11px; font-weight: 700; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 4px 6px; border-radius: 5px; width: 48%; text-align: right; white-space: nowrap; overflow: hidden;">
                            Ret: <span id="changeDisplay">0</span>
                        </div>
                    </div>
                </div>

                <div class="checkout-action-row">
                    <label for="autoPrintCheck" style="margin: 0; font-size: 11.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; white-space: nowrap;">
                        <input type="checkbox" name="autoprint" id="autoPrintCheck" value="1" checked style="width: auto; margin: 0;">
                        🖨️ Auto-print
                    </label>
                    <button type="submit" class="btn btn-success" id="checkoutBtn" disabled style="padding: 9px 14px; font-size: 13px; font-weight: 800; flex: 1;">
                        ✓ Complete Sale & Print
                    </button>
                </div>
            </div>
        </div>

        <!-- 2. RIGHT COLUMN: SEARCH + CATEGORIES + PRODUCT IMAGES GRID -->
        <div class="pos-catalog-column">
            <div style="display: flex; gap: 8px; align-items: center;">
                <div class="search-dropdown-wrapper">
                    <div class="search-box" style="margin: 0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="posProductSearch" placeholder="Search item name or scan barcode..." autofocus autocomplete="off" onkeydown="handleSearchKey(event)" oninput="filterSearchDropdown()">
                    </div>
                    <div id="searchResultsDropdown" class="search-results-dropdown"></div>
                </div>
                <button type="button" class="btn btn-light btn-sm" onclick="clearSearch()">Reset</button>
            </div>

            <div class="pos-categories-bar">
                <button type="button" class="category-chip active" data-cat="" onclick="filterCategory(this, '')">
                    All Items
                </button>
                <?php foreach ($categories as $cat): ?>
                    <button type="button" class="category-chip" data-cat="<?= e(strtolower($cat['name'])) ?>" onclick="filterCategory(this, '<?= e($cat['name']) ?>')">
                        <?= e($cat['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="pos-products-grid" id="posProductsGrid">
                <?php foreach ($products as $p): 
                    $isOut = (float)$p['stock_quantity'] <= 0;
                    $isKg = ($p['unit'] ?? 'piece') === 'kg';
                    if ($isKg) {
                        $stockText = rtrim(rtrim(number_format((float)$p['stock_quantity'], 3, '.', ''), '0'), '.') . ' kg';
                    } else {
                        $stockText = (int)$p['stock_quantity'] . ' PCS';
                    }

                    $imgSrc = '';
                    if (!empty($p['image'])) {
                        $imgSrc = preg_match('/^https?:\/\//i', $p['image']) ? $p['image'] : url('/' . ltrim($p['image'], '/'));
                    }
                    $firstLetter = mb_strtoupper(mb_substr(trim($p['name']), 0, 1));
                ?>
                    <div class="pos-product-card <?= $isOut ? 'out-of-stock' : '' ?>"
                         data-id="<?= $p['id'] ?>"
                         data-name="<?= e(strtolower($p['name'])) ?>"
                         data-barcode="<?= e(strtolower($p['barcode'] ?? '')) ?>"
                         data-category="<?= e(strtolower($p['category_name'] ?? '')) ?>"
                         onclick="addToCart(<?= htmlspecialchars(json_encode([
                             'id' => (int) $p['id'],
                             'name' => $p['name'],
                             'price' => (float) $p['market_sale_price'],
                             'stock' => (float) $p['stock_quantity'],
                             'unit' => $p['unit'] ?? 'piece',
                             'barcode' => $p['barcode'] ?? '',
                             'expiry_date' => $p['expiry_date'] ?? '',
                             'days_until_expiry' => $p['days_until_expiry'] ?? null
                         ]), ENT_QUOTES, 'UTF-8') ?>)">

                        <div class="product-card-img-box">
                            <?php if ($imgSrc !== ''): ?>
                                <img src="<?= e($imgSrc) ?>" alt="<?= e($p['name']) ?>" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <span class="product-card-placeholder" style="display:none;"><?= e($firstLetter) ?></span>
                            <?php else: ?>
                                <span class="product-card-placeholder"><?= e($firstLetter) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="product-card-body">
                            <div class="product-card-title" title="<?= e($p['name']) ?>">
                                <?= e($p['name']) ?>
                            </div>
                            <div class="product-card-footer">
                                <div class="product-card-price">Rs <?= money($p['market_sale_price']) ?></div>
                                <span class="badge <?= $isOut ? 'badge-danger' : 'badge-success' ?>" style="font-size: 9.5px; padding: 2px 5px;"><?= $stockText ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</form>

<script>
const userRole = '<?= $user['role'] ?>';
const productsList = <?= json_encode($products) ?>;
const udharCustomers = <?= json_encode($udharCustomers) ?>;

let cart = [];
let activeCategory = '';

/* Responsive Full Screen & Sidebar Toggle */
function applySidebarVisibility(isFull) {
    const btn = document.getElementById('fullScreenToggleBtn');
    const sidebars = document.querySelectorAll('aside, .sidebar, .app-sidebar, .left-sidebar, nav.sidebar');
    const headers = document.querySelectorAll('header, .topbar, .top-header');

    if (isFull) {
        document.body.classList.add('pos-fullscreen-mode');
        sidebars.forEach(el => el.style.setProperty('display', 'none', 'important'));
        headers.forEach(el => el.style.setProperty('display', 'none', 'important'));
        if (btn) btn.innerHTML = '↙ Show Sidebar';
    } else {
        document.body.classList.remove('pos-fullscreen-mode');
        sidebars.forEach(el => el.style.removeProperty('display'));
        headers.forEach(el => el.style.removeProperty('display'));
        if (btn) btn.innerHTML = '⛶ Full Screen';
    }
    window.dispatchEvent(new Event('resize'));
}

function togglePosFullScreen() {
    const isFull = !document.body.classList.contains('pos-fullscreen-mode');
    applySidebarVisibility(isFull);
    localStorage.setItem('pos_fullscreen', isFull ? '1' : '0');

    if (isFull) {
        if (!document.fullscreenElement && document.documentElement.requestFullscreen) {
            document.documentElement.requestFullscreen().catch(() => {});
        }
    } else {
        if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(() => {});
        }
    }
}

document.addEventListener('fullscreenchange', () => {
    window.dispatchEvent(new Event('resize'));
});

window.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('pos_fullscreen') === '1') {
        applySidebarVisibility(true);
    }
});

function preventInputSubmit(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        return false;
    }
}

function formatMoney(num) {
    const val = Number(num) || 0;
    return 'Rs ' + val.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function printReceipt(invNum) {
    window.open('<?= url('/print_invoice.php') ?>?invoice=' + encodeURIComponent(invNum) + '&autoprint=1', '_blank', 'width=420,height=650');
}

function addToCart(prod) {
    const isSuper = (userRole === 'Admin' || userRole === 'Master');
    if (prod.days_until_expiry !== null && prod.days_until_expiry < 0) {
        if (!isSuper) {
            alert('❌ Expired Product: ' + prod.name);
            return;
        } else if (!confirm('⚠️ Admin Alert: Product expired. Add anyway?')) {
            return;
        }
    }

    if (prod.stock <= 0 && (prod.stock_quantity === undefined || prod.stock_quantity <= 0)) {
        alert('Item is out of stock!');
        return;
    }

    const prodStock = Number(prod.stock_quantity !== undefined ? prod.stock_quantity : prod.stock);
    const isKg = (prod.unit === 'kg');
    const existingIndex = cart.findIndex(i => i.id === prod.id);

    if (existingIndex !== -1) {
        const existing = cart[existingIndex];

        if (isKg) {
            const nextQty = existing.qty + 0.25;
            if (nextQty <= prodStock) {
                existing.qty = nextQty;
                existing.grams = Math.round(existing.qty * 1000);
                existing.amount = Math.round(existing.qty * existing.price * 100) / 100;
            } else {
                alert('Stock limit reached!');
                return;
            }
        } else {
            if (existing.qty < prodStock) {
                existing.qty++;
                existing.amount = Math.round(existing.qty * existing.price * 100) / 100;
            } else {
                alert('Stock limit reached!');
                return;
            }
        }

        cart.splice(existingIndex, 1);
        cart.unshift(existing);

    } else {
        if (isKg) {
            const defaultQty = prodStock >= 1 ? 1 : Number(prodStock);
            const defaultGrams = Math.round(defaultQty * 1000);
            const unitPrice = Number(prod.market_sale_price || prod.price || 0);
            cart.unshift({
                id: prod.id,
                name: prod.name,
                unit: 'kg',
                price: unitPrice,
                original_price: unitPrice,
                stock: prodStock,
                qty: defaultQty,
                grams: defaultGrams,
                amount: Math.round(defaultQty * unitPrice * 100) / 100
            });
        } else {
            const unitPrice = Number(prod.market_sale_price || prod.price || 0);
            cart.unshift({
                id: prod.id,
                name: prod.name,
                unit: 'piece',
                price: unitPrice,
                original_price: unitPrice,
                stock: prodStock,
                qty: 1,
                grams: 0,
                amount: unitPrice
            });
        }
    }

    const searchInput = document.getElementById('posProductSearch');
    if (searchInput) searchInput.value = '';
    document.getElementById('searchResultsDropdown').style.display = 'none';
    filterProducts();

    renderCart();
}

function updateQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    const newQty = item.qty + delta;
    if (newQty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (newQty > item.stock) {
        alert('Stock limit reached!');
    } else {
        item.qty = newQty;
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    }
    renderCart();
}

function setQty(id, val) {
    const qty = parseFloat(val);
    const item = cart.find(i => i.id === id);
    if (!item) return;

    if (isNaN(qty) || qty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (qty > item.stock) {
        alert('Stock limit reached!');
        item.qty = item.stock;
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    } else {
        item.qty = qty;
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    }
    renderCart();
}

function onKgGramsChange(id, gramsVal) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    let grams = parseFloat(gramsVal) || 0;
    let qty = grams / 1000;

    if (qty > item.stock) {
        alert('Weight exceeds available stock');
        qty = item.stock;
        grams = Math.round(qty * 1000);
        const gInput = document.getElementById('kgGrams_' + id);
        if (gInput) gInput.value = grams;
    }

    item.grams = grams;
    item.qty = qty;
    item.amount = Math.round(qty * (item.price || 0) * 100) / 100;

    const amountInput = document.getElementById('kgAmount_' + id);
    if (amountInput) amountInput.value = item.amount.toFixed(2);

    updateCartTotalsOnly();
}

function onKgAmountChange(id, amountVal) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    let amount = parseFloat(amountVal) || 0;
    let qty = (item.price > 0) ? (amount / item.price) : 0;
    let grams = Math.round(qty * 1000);

    if (qty > item.stock) {
        alert('Amount exceeds available stock');
        qty = item.stock;
        grams = Math.round(qty * 1000);
        amount = Math.round(qty * (item.price || 0) * 100) / 100;
        const aInput = document.getElementById('kgAmount_' + id);
        if (aInput) aInput.value = amount.toFixed(2);
    }

    item.amount = amount;
    item.qty = qty;
    item.grams = grams;

    const gramsInput = document.getElementById('kgGrams_' + id);
    if (gramsInput) gramsInput.value = item.grams;

    updateCartTotalsOnly();
}

function updateItemPrice(id, newPriceVal) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    let newPrice = parseFloat(newPriceVal);
    if (isNaN(newPrice) || newPrice <= 0) {
        renderCart();
        return;
    }

    const isSuper = (userRole === 'Admin' || userRole === 'Master');
    if (!isSuper && newPrice < (item.original_price - 0.01)) {
        alert('Operator cannot sell below set rate Rs ' + item.original_price);
        renderCart();
        return;
    }

    item.price = newPrice;
    if (item.unit === 'kg') {
        item.amount = Math.round((item.grams / 1000) * item.price * 100) / 100;
        const amountInput = document.getElementById('kgAmount_' + id);
        if (amountInput) amountInput.value = item.amount.toFixed(2);
    } else {
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    }
    renderCart();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    if (confirm('Clear current cart?')) {
        cart = [];
        renderCart();
    }
}

function updateCartTotalsOnly() {
    const subtotal = cart.reduce((acc, i) => {
        const itemVal = (i.unit === 'kg') ? (Number(i.amount) || 0) : ((Number(i.price) || 0) * (Number(i.qty) || 0));
        return acc + itemVal;
    }, 0);

    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const net = Math.max(0, subtotal - discount);

    document.getElementById('subtotalDisplay').textContent = formatMoney(subtotal);
    document.getElementById('netPayableDisplay').textContent = formatMoney(net);
    document.getElementById('itemsPayload').value = JSON.stringify(cart.map(i => ({
        id: i.id,
        qty: i.qty,
        unit: i.unit,
        price: i.price
    })));

    cart.forEach(i => {
        const totalTd = document.getElementById('itemLineTotal_' + i.id);
        if (totalTd) {
            const lineAmt = (i.unit === 'kg') ? (Number(i.amount) || 0) : ((Number(i.price) || 0) * (Number(i.qty) || 0));
            totalTd.textContent = formatMoney(lineAmt);
        }
    });

    calcChange();
}

function renderCart() {
    const container = document.getElementById('cartItemsContainer');
    const itemCountSpan = document.getElementById('cartItemCount');
    const checkoutBtn = document.getElementById('checkoutBtn');

    const totalItems = cart.length;
    itemCountSpan.textContent = totalItems;

    if (cart.length === 0) {
        container.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-muted" style="padding: 35px 12px; font-size: 12.5px;">
                    Cart is empty. Select category & click products on the right.
                </td>
            </tr>
        `;
        checkoutBtn.disabled = true;
    } else {
        container.innerHTML = cart.map(item => {
            const isKg = (item.unit === 'kg');

            if (isKg) {
                return `
                <tr style="background: #f0f9ff;">
                    <td>
                        <strong style="font-size:12.5px; color:#0369a1;">${item.name}</strong> 
                        <span class="badge" style="background:#0284c7; color:#fff; font-size:9px;">KG</span>
                    </td>
                    <td>
                        <div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
                            <input type="number" id="kgGrams_${item.id}" value="${item.grams}" title="Grams" onkeydown="preventInputSubmit(event)" oninput="onKgGramsChange(${item.id}, this.value)" style="width:52px; padding:2px; font-size:11.5px; font-weight:700; border:1px solid #93c5fd; border-radius:4px; text-align:center;">
                            <span style="font-size:10px;">g</span>
                            <input type="number" id="kgAmount_${item.id}" value="${Number(item.amount || 0).toFixed(2)}" title="Amount Rs" onkeydown="preventInputSubmit(event)" oninput="onKgAmountChange(${item.id}, this.value)" style="width:60px; padding:2px; font-size:11.5px; font-weight:700; border:1px solid #93c5fd; border-radius:4px; text-align:right;">
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <input type="number" value="${item.price}" onkeydown="preventInputSubmit(event)" onchange="updateItemPrice(${item.id}, this.value)" style="width:54px; padding:2px; font-size:11.5px; font-weight:700; border:1px solid #93c5fd; border-radius:4px; text-align:right;">
                    </td>
                    <td style="text-align: right;">
                        <strong id="itemLineTotal_${item.id}" style="font-size:12.5px; color:#0284c7;">${formatMoney(item.amount)}</strong>
                    </td>
                    <td style="text-align: center;">
                        <button type="button" onclick="removeFromCart(${item.id})" style="color:#ef4444; background:none; border:none; cursor:pointer; font-size:17px; font-weight:bold;">&times;</button>
                    </td>
                </tr>
                `;
            } else {
                return `
                <tr>
                    <td>
                        <strong style="font-size:12.5px; color:#0f172a;">${item.name}</strong>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:3px;">
                            <button type="button" class="cart-qty-btn" onclick="updateQty(${item.id}, -1)">&minus;</button>
                            <input type="number" value="${item.qty}" min="1" max="${item.stock}" onkeydown="preventInputSubmit(event)" onchange="setQty(${item.id}, this.value)" style="width:38px; padding:2px; text-align:center; font-size:11.5px; font-weight:700; border:1px solid #cbd5e1; border-radius:4px;">
                            <button type="button" class="cart-qty-btn" onclick="updateQty(${item.id}, 1)">&#43;</button>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <input type="number" value="${item.price}" onkeydown="preventInputSubmit(event)" onchange="updateItemPrice(${item.id}, this.value)" style="width:54px; padding:2px; font-size:11.5px; font-weight:700; border:1px solid #cbd5e1; border-radius:4px; text-align:right;">
                    </td>
                    <td style="text-align: right;">
                        <strong id="itemLineTotal_${item.id}" style="font-size:12.5px; color:#0f172a;">${formatMoney(item.price * item.qty)}</strong>
                    </td>
                    <td style="text-align: center;">
                        <button type="button" onclick="removeFromCart(${item.id})" style="color:#ef4444; background:none; border:none; cursor:pointer; font-size:17px; font-weight:bold;">&times;</button>
                    </td>
                </tr>
                `;
            }
        }).join('');
        checkoutBtn.disabled = false;
    }

    updateCartTotalsOnly();
}

function filterSearchDropdown() {
    const query = (document.getElementById('posProductSearch').value || '').trim().toLowerCase();
    const dropdown = document.getElementById('searchResultsDropdown');

    if (!query) {
        dropdown.style.display = 'none';
        filterProducts();
        return;
    }

    const matches = productsList.filter(p => {
        const name = (p.name || '').toLowerCase();
        const barcode = (p.barcode || '').toLowerCase();
        return name.includes(query) || barcode.includes(query);
    });

    if (matches.length > 0) {
        dropdown.innerHTML = matches.map(p => `
            <div class="search-dropdown-item" onclick='addToCart(${JSON.stringify(p)})'>
                <div>
                    <strong style="font-size:13px; color:#0f172a;">${p.name}</strong>
                    <div style="font-size:11px; color:#64748b;">${p.category_name || 'General'}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:13px; font-weight:700; color:var(--primary);">Rs ${p.market_sale_price}</div>
                    <small style="font-size:10px; color:${p.stock_quantity > 0 ? '#10b981' : '#ef4444'};">${p.stock_quantity > 0 ? p.stock_quantity + ' left' : 'Out of Stock'}</small>
                </div>
            </div>
        `).join('');
        dropdown.style.display = 'block';
    } else {
        dropdown.innerHTML = '<div style="padding:12px; font-size:12px; color:#94a3b8; text-align:center;">No product found</div>';
        dropdown.style.display = 'block';
    }

    filterProducts();
}

function handleSearchKey(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const query = (document.getElementById('posProductSearch').value || '').trim().toLowerCase();

        if (query !== '') {
            const exactBarcodeMatch = productsList.find(p => (p.barcode || '').trim().toLowerCase() === query);

            if (exactBarcodeMatch) {
                addToCart(exactBarcodeMatch);
            } else {
                const dropdown = document.getElementById('searchResultsDropdown');
                const firstItem = dropdown.querySelector('.search-dropdown-item');
                if (firstItem) {
                    firstItem.click();
                }
            }
        }
        return false;
    }
}

function selectPaymentMode(mode) {
    document.getElementById('selectedPaymentMode').value = mode;
    document.querySelectorAll('.payment-mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });

    const cashBox = document.getElementById('cashCalculatorBox');
    const udharSection = document.getElementById('udharCustomerSection');

    if (mode === 'Cash') {
        cashBox.style.display = 'flex';
        udharSection.style.display = 'none';
    } else if (mode === 'Udhar') {
        cashBox.style.display = 'none';
        udharSection.style.display = 'block';
    } else {
        cashBox.style.display = 'none';
        udharSection.style.display = 'none';
    }
}

function onUdharCustomerSelected(custId) {
    const cust = udharCustomers.find(c => String(c.id) === String(custId));
    const nameInput = document.getElementById('buyerNameInput');
    const phoneInput = document.getElementById('buyerPhoneInput');

    if (cust) {
        nameInput.value = cust.name;
        if (cust.phone) phoneInput.value = cust.phone;
    }
}

function getNetPayable() {
    const subtotal = cart.reduce((acc, i) => {
        const itemVal = (i.unit === 'kg') ? (Number(i.amount) || 0) : ((Number(i.price) || 0) * (Number(i.qty) || 0));
        return acc + itemVal;
    }, 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    return Math.max(0, subtotal - discount);
}

function calcChange() {
    const net = getNetPayable();
    const tenderedInput = document.getElementById('cashTendered');
    const tendered = parseFloat(tenderedInput.value) || 0;
    const change = Math.max(0, tendered - net);

    document.getElementById('changeDisplay').textContent = change.toLocaleString('en-US', {maximumFractionDigits: 2});
}

function clearSearch() {
    const searchInput = document.getElementById('posProductSearch');
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
    document.getElementById('searchResultsDropdown').style.display = 'none';
    activeCategory = '';
    document.querySelectorAll('.category-chip').forEach(btn => btn.classList.remove('active'));
    const allBtn = document.querySelector('.category-chip[data-cat=""]');
    if (allBtn) allBtn.classList.add('active');
    filterProducts();
}

function filterCategory(btnElement, catName) {
    activeCategory = (catName || '').trim().toLowerCase();
    document.querySelectorAll('.category-chip').forEach(chip => chip.classList.remove('active'));
    btnElement.classList.add('active');
    filterProducts();
}

function filterProducts() {
    const query = (document.getElementById('posProductSearch').value || '').trim().toLowerCase();
    const cards = document.querySelectorAll('.pos-product-card');

    cards.forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        const barcode = (card.dataset.barcode || '').toLowerCase();
        const cat = (card.dataset.category || '').toLowerCase();

        const matchesSearch = !query || name.includes(query) || barcode.includes(query);
        const matchesCategory = !activeCategory || cat === activeCategory;

        if (matchesSearch && matchesCategory) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

function validateCheckout() {
    if (cart.length === 0) {
        alert('Cart is empty.');
        return false;
    }
    return true;
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('searchResultsDropdown');
    const searchInput = document.getElementById('posProductSearch');
    if (dropdown && !dropdown.contains(e.target) && e.target !== searchInput) {
        dropdown.style.display = 'none';
    }
});

<?php if ($completedSaleInfo && !empty($completedSaleInfo['autoprint'])): ?>
window.addEventListener('load', () => {
    printReceipt('<?= e($completedSaleInfo['invoice']) ?>');
});
<?php endif; ?>

renderCart();
filterProducts();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>