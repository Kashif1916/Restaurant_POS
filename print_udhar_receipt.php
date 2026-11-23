<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if (!$id) {
    die('Invalid receipt ID');
}

// Fetch current payment receipt along with ledger info
$stmt = $pdo->prepare("SELECT up.*, 
    ul.customer_id, 
    ul.buyer_name, 
    ul.buyer_phone, 
    ul.balance_amount as current_bill_balance, 
    ul.total_amount as current_bill_total,
    COALESCE(s.invoice_number, 'OPENING-BAL') as invoice_number, 
    u.username as received_by, 
    u.full_name as received_by_fullname 
    FROM udhar_payments up 
    JOIN udhar_ledger ul ON ul.id = up.udhar_id 
    LEFT JOIN sales s ON s.id = up.sale_id 
    LEFT JOIN users u ON u.id = up.created_by 
    WHERE up.id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    die('Udhar payment voucher not found.');
}

// Fetch all OTHER pending bills for this customer (to show detailed breakdown)
$custName = $payment['buyer_name'];
$custPhone = $payment['buyer_phone'];
$customerId = $payment['customer_id'];

$pendingBillsStmt = $pdo->prepare("SELECT ul.*, COALESCE(s.invoice_number, 'OPENING-BAL') as invoice_num, COALESCE(s.created_at, uc.created_at, ul.updated_at) as bill_date 
    FROM udhar_ledger ul 
    LEFT JOIN sales s ON s.id = ul.sale_id 
    LEFT JOIN udhar_customers uc ON uc.id = ul.customer_id
    WHERE ul.status = 'Pending' 
      AND (
          (? IS NOT NULL AND ul.customer_id = ?) 
          OR (ul.buyer_name = ?) 
          OR (? != '' AND ul.buyer_phone = ?)
      )
    ORDER BY ul.id ASC");
$pendingBillsStmt->execute([$customerId, $customerId, $custName, $custPhone, $custPhone]);
$pendingBills = $pendingBillsStmt->fetchAll();

// Calculate Total Pending Udhar Across ALL Bills for this customer
$totalPendingUdhar = 0.0;
foreach ($pendingBills as $pb) {
    $totalPendingUdhar += (float) $pb['balance_amount'];
}

$settings = get_store_settings($pdo);
$autoprint = isset($_GET['autoprint']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wasooli Receipt #<?= e($payment['receipt_number']) ?></title>
    <style>
        /* Sharp & Dark Thermal Formatting - Matched with print_invoice.php */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f8fafc;
            color: #000000;
            width: 80mm;
        }
        .thermal-receipt {
            width: 76mm;
            margin: 10px auto;
            background: #fff;
            padding: 2mm;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
            font-size: 13px;
            color: #000000;
            line-height: 1.3;
        }
        .thermal-header {
            text-align: center;
            margin-bottom: 2mm;
        }
        .thermal-header h2 {
            font-size: 18px;
            font-weight: 900;
            margin: 0 0 2px 0;
            text-transform: uppercase;
            color: #000000;
        }
        .thermal-header p {
            margin: 1mm 0;
            font-size: 12px;
            font-weight: bold;
            color: #000000;
        }
        .receipt-tag {
            display: inline-block;
            font-weight: 900;
            font-size: 13px;
            padding: 2px 8px;
            border: 1.5px solid #000000;
            margin-top: 4px;
            text-transform: uppercase;
        }
        .thermal-line {
            border-top: 1.5px solid #000000;
            margin: 2mm 0;
        }
        .thermal-line-dashed {
            border-top: 1px dashed #000000;
            margin: 2mm 0;
        }
        .thermal-meta {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 2mm;
            color: #000000;
        }
        .thermal-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5mm;
            font-size: 12.5px;
            font-weight: bold;
            color: #000000;
        }
        .thermal-grand {
            font-size: 15px;
            font-weight: 900;
            border-top: 2px solid #000000;
            border-bottom: 2px solid #000000;
            padding: 2mm 0;
            margin: 2mm 0;
            color: #000000;
        }
        .pending-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            font-weight: bold;
            margin-top: 2mm;
        }
        .pending-table th {
            border-bottom: 1px solid #000;
            text-align: left;
            padding-bottom: 2px;
        }
        .pending-table td {
            padding: 2px 0;
        }
        .sig-container {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
            padding-top: 5px;
        }
        .sig-box {
            width: 120px;
            border-top: 1.5px dashed #000000;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            color: #000000;
            padding-top: 4px;
        }
        .thermal-footer {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin-top: 3mm;
            color: #000000;
        }

        /* Offline Urdu Font Style */
        .urdu-text {
            font-family: 'Urdu Typesetting', 'Traditional Arabic', 'Jameel Noori Nastaliq', 'Noto Nastaliq Urdu', 'Segoe UI', Arial, sans-serif !important;
            font-size: 17.5px !important;
            font-weight: bold !important;
            line-height: 1.5 !important;
            direction: rtl;
            text-align: center;
            color: #000000 !important;
        }

        /* Print Specific Styles */
        @media print {
            @page {
                margin: 0;
                size: 80mm auto;
            }
            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 80mm !important;
            }
            .no-print {
                display: none !important;
            }
            .thermal-receipt {
                width: 76mm !important;
                margin: 0 !important;
                padding: 2mm !important;
                border: none !important;
                box-shadow: none !important;
            }
            * {
                color: #000000 !important;
                text-shadow: none !important;
            }
        }
    </style>
</head>
<body>
    <div style="text-align: center; margin: 20px 0;" class="no-print">
        <button onclick="window.print()" style="padding: 8px 16px; font-weight: bold; cursor: pointer;">
            🖨️ Print Thermal Slip (80mm)
        </button>
        <button onclick="window.close()" style="padding: 8px 16px; font-weight: bold; cursor: pointer;">Close</button>
    </div>

    <div class="thermal-receipt">
        <div class="thermal-header">
            <h2><?= e($settings['store_name']) ?></h2>
            <p><?= e($settings['address']) ?></p>
            <p>Phone: <?= e($settings['phone']) ?></p>
            <div class="receipt-tag">UDHAR WASOOLI SLIP</div>
        </div>

        <div class="thermal-line"></div>

        <div class="thermal-meta">
            <div class="thermal-row">
                <span>Receipt #:</span>
                <strong><?= e($payment['receipt_number']) ?></strong>
            </div>
            <div class="thermal-row">
                <span>Date:</span>
                <span><?= date('d/m/Y h:i A', strtotime($payment['created_at'])) ?></span>
            </div>
            <div class="thermal-row">
                <span>Cashier:</span>
                <span><?= e($payment['received_by_fullname'] ?: $payment['received_by']) ?></span>
            </div>
            <div class="thermal-row">
                <span>Invoice Ref:</span>
                <span><?= e($payment['invoice_number']) ?></span>
            </div>
        </div>

        <div class="thermal-line"></div>

        <div style="font-size: 12px; margin-bottom: 2mm;">
            <div class="thermal-row">
                <span>Customer:</span>
                <strong><?= e($payment['buyer_name']) ?></strong>
            </div>
            <?php if ($payment['buyer_phone']): ?>
                <div class="thermal-row">
                    <span>Phone:</span>
                    <span><?= e($payment['buyer_phone']) ?></span>
                </div>
            <?php endif; ?>
            <div class="thermal-row">
                <span>Payment Mode:</span>
                <strong><?= e($payment['payment_mode']) ?></strong>
            </div>
            <?php if ($payment['notes']): ?>
                <div class="thermal-row">
                    <span>Notes:</span>
                    <span><?= e($payment['notes']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- WASOOLI AMOUNT & THIS BILL REMAINING -->
        <div class="thermal-row thermal-grand">
            <span>AMOUNT RECEIVED:</span>
            <span>Rs <?= money($payment['amount_paid']) ?></span>
        </div>

        <div class="thermal-row" style="font-size: 12.5px; margin-top: 4px;">
            <span>This Bill Remaining:</span>
            <strong>Rs <?= money($payment['current_bill_balance']) ?></strong>
        </div>

        <!-- PENDING BILLS BREAKDOWN SECTION -->
        <div class="thermal-line-dashed"></div>

        <div style="font-size: 12px; font-weight: 900; text-transform: uppercase; text-align: center; margin-bottom: 4px;">
            📋 Customer Pending Udhar Details
        </div>

        <?php if (empty($pendingBills)): ?>
            <div style="text-align: center; font-size: 11.5px; font-weight: bold; margin: 6px 0; color: #000;">
                ✓ ALL UDHAR CLEARED! (Koi udhar baki nahi)
            </div>
        <?php else: ?>
            <table class="pending-table">
                <thead>
                    <tr>
                        <th>Bill #</th>
                        <th>Date</th>
                        <th style="text-align: right;">Pending</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingBills as $pb): ?>
                        <tr style="<?= (int)$pb['id'] === (int)$payment['udhar_id'] ? 'background: #f1f5f9;' : '' ?>">
                            <td><?= e($pb['invoice_num']) ?></td>
                            <td><?= date('d/m/y', strtotime($pb['bill_date'])) ?></td>
                            <td style="text-align: right;">Rs <?= money($pb['balance_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="thermal-line-dashed"></div>

            <div class="thermal-row" style="font-size: 14px; font-weight: 900; margin-top: 4px;">
                <span>TOTAL PENDING UDHAR:</span>
                <span>Rs <?= money($totalPendingUdhar) ?></span>
            </div>
        <?php endif; ?>

        <div class="sig-container">
            <div class="sig-box">Customer Sign</div>
            <div class="sig-box">Store Stamp / Sign</div>
        </div>

        <div class="thermal-footer">
            <p class="urdu-text" style="margin: 0 0 4px 0;">اپنے کاروبار کے لیے 2000 روپے ماہانہ پر سافٹ ویئر حاصل کرنے کے لیے رابطہ کریں</p>
            <p style="font-size: 12px; color: #000; margin: 0;">KJ Software House Khushab<br>Phone: 03180711280</p>
        </div>
    </div>

    <?php if ($autoprint): ?>
        <script>
            window.addEventListener('load', () => {
                window.print();
            });
        </script>
    <?php endif; ?>
</body>
</html>