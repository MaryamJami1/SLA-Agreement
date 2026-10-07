<?php
/**
 * Every vendor write that starts from a booking or a vendor invoice (admin only). Each action is one
 * transaction in app/vendors.php; this page only routes, reports and redirects back.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/payments.php';
require_once APP_ROOT . '/app/vendors.php';

require_post();
$admin = require_admin();
$pdo = db();
$int = static fn(string $k): int => ctype_digit((string) ($_POST[$k] ?? '')) ? (int) $_POST[$k] : 0;
$action = (string) ($_POST['action'] ?? '');
$bookingId = $int('booking_id');
$invoiceId = $int('invoice_id');

if (in_array($action, ['add_line', 'remove_line', 'generate_invoice'], true)) {
    $back = 'booking/form.php?id=' . $bookingId . '#vendors';
    try {
        switch ($action) {
            case 'add_line':
                flash('ok', 'Assigned “' . add_vendor_line($pdo, $admin, $bookingId, $_POST) . '” to this event.');
                break;
            case 'remove_line':
                flash('ok', 'Removed “' . remove_vendor_line($pdo, $admin, $bookingId, $int('line_id')) . '” from this event.');
                break;
            case 'generate_invoice':
                $r = generate_vendor_invoice($pdo, $admin, $bookingId, $int('vendor_id'),
                    $_POST['discount'] ?? '', $_POST['tax_pct'] ?? '', $_POST['notes'] ?? '');
                flash('ok', "Invoice {$r['invoice_no']} issued. Print it or save it as PDF to send to the vendor.");
                $back = 'vendors/invoice.php?id=' . $r['id'];
                break;
        }
    } catch (AdminRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$back = 'vendors/invoice.php?id=' . $invoiceId;
switch ($action) {
    case 'void_invoice':
        try {
            flash('ok', 'Invoice ' . void_vendor_invoice($pdo, $admin, $invoiceId, $_POST['reason'] ?? '')
                . ' voided. Its services can be invoiced again from the booking page.');
        } catch (AdminRefused | TryAgainException $e) {
            flash('error', $e->getMessage());
        }
        break;

    case 'add_payment':
        if (!consume_payment_form_token($_POST['form_token'] ?? null)) {
            flash('error', 'That form was already submitted (or has expired), so nothing new was recorded. Check the payments list.');
            break;
        }
        [$data, $errors] = parse_payment_input($_POST, 'payment');
        if (!$errors) {
            try {
                $t = record_vendor_payment($pdo, $admin, $invoiceId, $data);
                flash('ok', 'Payment of ' . format_rs(decimal_to_paisa($data['amount'])) . ' recorded. '
                    . ($t['balance'] > 0 ? 'Remaining: ' . format_rs($t['balance']) . '.'
                        : ($t['balance'] < 0 ? 'The vendor is now overpaid by ' . format_rs(-$t['balance']) . '.' : 'The invoice is fully paid.')));
                break;
            } catch (AdminRefused | TryAgainException $e) {
                $errors = ['amount' => $e->getMessage()];
            }
        }
        // Show the form again with what was typed (one-time, cleared once displayed).
        $fields = array_intersect_key($_POST, array_flip(['amount', 'paid_on', 'method', 'bank_name', 'reference_no', 'notes']));
        $_SESSION['vendor_payment_form'] = ['invoice_id' => $invoiceId, 'errors' => $errors,
            'input' => array_map(static fn($v) => is_string($v) ? $v : '', $fields)];
        flash('error', 'Payment not recorded: ' . implode(' ', $errors));
        break;

    case 'void_payment':
        try {
            void_vendor_payment($pdo, $admin, $invoiceId, $int('payment_id'), $_POST['reason'] ?? '');
            flash('ok', 'Payment voided.');
        } catch (AdminRefused | TryAgainException $e) {
            flash('error', $e->getMessage());
        }
        break;

    default:
        flash('error', 'Unknown action.');
        redirect('admin/vendors.php');
}
redirect($back);
