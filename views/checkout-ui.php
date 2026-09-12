<?php
    $transaction_details = pp_get_transation($payment_id);
    $setting = pp_get_settings();
    $faq_list = pp_get_faq();
    $support_links = pp_get_support_links();

    $plugin_slug = 'bangla-qr';
    $plugin_info = pp_get_plugin_info($plugin_slug);
    $settings = pp_get_plugin_setting($plugin_slug);
    
    $currency = $settings['currency'] ?? 'BDT';
    $raw_amount = $transaction_details['response'][0]['transaction_amount'] ?? 0;
    $raw_currency = $transaction_details['response'][0]['transaction_currency'] ?? 'BDT';
    
    $transaction_amount = convertToDefault($raw_amount, $raw_currency, $currency);
    $fixed_charge = safeNumber($settings['fixed_charge'] ?? 0);
    $percent_charge = safeNumber($settings['percent_charge'] ?? 0);
    $transaction_fee = $fixed_charge + ($transaction_amount * ($percent_charge / 100));
    $total_payable = $transaction_amount + $transaction_fee;
    
    $configured_provider = !empty($settings['sender_key']) ? trim($settings['sender_key']) : 'Rocket';
    $timer_minutes = safeNumber($settings['timer_duration'] ?? 15);
    if($timer_minutes <= 0) $timer_minutes = 15;
    $timer_seconds = $timer_minutes * 60;
    
    // Assets Directory & URLs
    $plugin_dir_name = !empty($plugin_info['plugin_dir']) ? $plugin_info['plugin_dir'] : 'payment-gateway';
    $assets_base = pp_get_site_url() . '/pp-content/plugins/' . $plugin_dir_name . '/' . $plugin_slug . '/assets/';
    $local_assets = __DIR__ . '/../assets/';

    if (file_exists($local_assets . 'qr.png')) {
        $qr_image_url = $assets_base . 'qr.png?v=' . filemtime($local_assets . 'qr.png');
    } elseif (file_exists($local_assets . 'bangla-qr-default.jpg')) {
        $qr_image_url = $assets_base . 'bangla-qr-default.jpg?v=' . filemtime($local_assets . 'bangla-qr-default.jpg');
    } else {
        $qr_image_url = $assets_base . 'icon.png';
    }

    $download_image_url = $qr_image_url;

    // Check if the transaction is already completed on initial page load
    $initial_tx_status = strtolower($transaction_details['response'][0]['transaction_status'] ?? '');
    if ($initial_tx_status === 'completed' || $initial_tx_status === 'success' || $initial_tx_status === 'paid') {
        header('Location: ' . pp_get_paymentlink($payment_id));
        echo "<script>window.location.href='" . pp_get_paymentlink($payment_id) . "';</script>";
        exit();
    }

    // Auto-detect customer mobile from transaction details if passed by merchant
    $prefill_mobile_raw = $transaction_details['response'][0]['customer_mobile'] ?? ($transaction_details['response'][0]['customer_phone'] ?? ($transaction_details['response'][0]['phone'] ?? ''));
    $prefill_clean = preg_replace('/[^0-9]/', '', (string)$prefill_mobile_raw);
    if (strpos($prefill_clean, '8801') === 0 && strlen($prefill_clean) >= 13) {
        $prefill_clean = substr($prefill_clean, 2);
    }
    if (strlen($prefill_clean) < 11 || strlen($prefill_clean) > 12) {
        $prefill_clean = '';
    }

    // ──────────────────────────────────────────────────────────
    // SECURE AUTOMATED SMS VERIFICATION WITH MOBILE NUMBER MATCHING
    // ──────────────────────────────────────────────────────────
    if (isset($_POST['bangla-qr']) || isset($_POST['bangla-qr-poll'])) {
        global $db_prefix;

        // 1. Check if transaction is already completed
        $current_tx = pp_get_transation($payment_id);
        $tx_status = strtolower($current_tx['response'][0]['transaction_status'] ?? '');
        
        if ($tx_status === 'completed' || $tx_status === 'success' || $tx_status === 'paid') {
            echo json_encode([
                "status" => "true",
                "completed" => true,
                "message" => "Payment successfully verified!",
                "redirect" => pp_get_paymentlink($payment_id)
            ]);
            exit();
        }

        // 2. Determine payment type: 'mobile' (default) or 'card'
        $payment_type = trim($_POST['payment_type'] ?? 'mobile');
        if ($payment_type !== 'card') {
            $payment_type = 'mobile';
        }

        if ($payment_type === 'card') {
            // Card / Bank Account verification (no phone validation required)
            $customer_account = trim($_POST['customer_account'] ?? ($_POST['customer_mobile'] ?? ''));
            $clean_account = preg_replace('/[^0-9]/', '', $customer_account);

            if (empty($clean_account) || strlen($clean_account) < 4) {
                echo json_encode([
                    "status" => "false",
                    "completed" => false,
                    "message" => "Valid Card or Account number (minimum 4 digits) required for verification."
                ]);
                exit();
            }

            $user_acc_suffix_4 = substr($clean_account, -4);
            $user_acc_len = strlen($clean_account);
        } else {
            // Mobile Banking verification
            $customer_mobile = trim($_POST['customer_mobile'] ?? '');
            $clean_mobile = preg_replace('/[^0-9]/', '', $customer_mobile);
            if (strpos($clean_mobile, '8801') === 0 && strlen($clean_mobile) >= 13) {
                $clean_mobile = substr($clean_mobile, 2);
            }

            if (empty($clean_mobile) || strlen($clean_mobile) < 11) {
                echo json_encode([
                    "status" => "false",
                    "completed" => false,
                    "message" => "Valid mobile number required for verification."
                ]);
                exit();
            }

            // Primary 11-digit mobile components
            $user_mobile_11 = substr($clean_mobile, 0, 11);
            $user_prefix_3  = substr($user_mobile_11, 0, 3);
            $user_suffix_4  = substr($user_mobile_11, -4);
            $user_suffix_3  = substr($user_mobile_11, -3);
        }

        // 4. Amount matching bounds (tolerance ±0.5 BDT)
        $min_val = (float)$total_payable - 0.5;
        if ($min_val < 0) $min_val = 0;
        $max_val = (float)$total_payable + 0.5;

        $min_str = number_format($total_payable, 2, '.', '');
        $comma_str = number_format($total_payable, 2);

        // 5. Query recent unused SMS (No strict time limit to prevent missing delayed SMS or timezone discrepancies)
        // Orders by id DESC so newest incoming payments are evaluated first
        $all_recent_sms = json_decode(getData($db_prefix . 'sms_data', "WHERE LOWER(status) != 'used' ORDER BY id DESC LIMIT 50"), true);

        $matched_sms = null;
        if ($all_recent_sms['status'] == true && !empty($all_recent_sms['response'])) {
            foreach ($all_recent_sms['response'] as $row) {
                $sms_amt = (float)($row['amount'] ?? 0);
                $sms_status = strtolower($row['status'] ?? '');

                // Check amount match
                $amount_match = ($sms_status !== 'used' && (
                    ($sms_amt >= $min_val && $sms_amt <= $max_val) ||
                    $row['amount'] == $min_str ||
                    $row['amount'] == $comma_str ||
                    (string)$sms_amt == (string)(float)$total_payable
                ));

                if (!$amount_match) continue;

                // Check payment matching based on payment_type
                $search_texts = [];
                if (!empty($row['mobile_number'])) $search_texts[] = (string)$row['mobile_number'];
                if (!empty($row['sender']))        $search_texts[] = (string)$row['sender'];
                if (!empty($row['sms_text']))      $search_texts[] = (string)$row['sms_text'];
                if (!empty($row['message']))       $search_texts[] = (string)$row['message'];
                if (!empty($row['raw_sms']))       $search_texts[] = (string)$row['raw_sms'];

                $is_matched = false;

                if ($payment_type === 'card') {
                    // ── CARD / BANK ACCOUNT MATCHING ──
                    // Supports e.g.:
                    // Automatic Rocket SIM 2 10771****9394 6925286912 400.00 8889.20
                    // Bangla QR 01300000****4337 6912624747
                    foreach ($search_texts as $text) {
                        $text = trim($text);
                        if ($text === '') continue;

                        // Check 1: Direct TrxID match if user entered their Transaction ID
                        if (!empty($row['transaction_id']) && $row['transaction_id'] === $clean_account) {
                            $is_matched = true;
                            break;
                        }

                        // Check 2: Masked card / account pattern in text: e.g. 10771****9394 or 01300000****4337
                        if (preg_match_all('/(\d{3,10})[*xX]{3,8}(\d{4})/', $text, $card_matches, PREG_SET_ORDER)) {
                            foreach ($card_matches as $cm) {
                                $card_prefix = $cm[1];
                                $card_suffix = $cm[2];

                                // User entered last 4 digits
                                if ($user_acc_len === 4 && $clean_account === $card_suffix) {
                                    $is_matched = true;
                                    break 2;
                                }

                                // User entered longer or full card/account number
                                if ($user_acc_suffix_4 === $card_suffix) {
                                    if ($user_acc_len >= 8) {
                                        if (strpos($clean_account, $card_prefix) === 0 || strpos($card_prefix, substr($clean_account, 0, min(strlen($clean_account), strlen($card_prefix)))) === 0) {
                                            $is_matched = true;
                                            break 2;
                                        }
                                    }
                                    $is_matched = true;
                                    break 2;
                                }
                            }
                        }

                        // Check 3: Clean unmasked digits match
                        $clean_digits = preg_replace('/[^0-9]/', '', $text);
                        if ($user_acc_len >= 8 && strpos($clean_digits, $clean_account) !== false) {
                            $is_matched = true;
                            break;
                        }
                    }

                } else {
                    // ── MOBILE BANKING MATCHING ──
                    // Supports 11-digit & 12-digit unmasked and strictly 11-12 digit masked mobile numbers
                    foreach ($search_texts as $text) {
                        $text = trim($text);
                        if ($text === '') continue;

                        // A. Regex check for BD mobile sequence (11 or 12 digits) in text
                        if (preg_match_all('/(?:(?:\+?88)?)(01[3-9]\d{8})(\d)?\b/', $text, $matches, PREG_SET_ORDER)) {
                            foreach ($matches as $m) {
                                if ($m[1] === $user_mobile_11) {
                                    $is_matched = true;
                                    break 2;
                                }
                            }
                        }

                        // B. Clean digits unmasked comparison
                        $clean_digits = preg_replace('/[^0-9]/', '', $text);
                        if (strpos($clean_digits, '8801') === 0 && strlen($clean_digits) >= 13) {
                            $clean_digits = substr($clean_digits, 2);
                        }

                        // Exact 11 or 12 digit match
                        if ($clean_digits === $clean_mobile || $clean_digits === $user_mobile_11) {
                            $is_matched = true;
                            break;
                        }

                        // Rocket 12-digit unmasked where first 11 digits match user input
                        if (strlen($clean_digits) === 12 && substr($clean_digits, 0, 11) === $user_mobile_11) {
                            $is_matched = true;
                            break;
                        }

                        if (strlen($clean_digits) >= 11 && strlen($clean_digits) <= 13 && strpos($clean_digits, $user_mobile_11) === 0) {
                            $is_matched = true;
                            break;
                        }

                        // C. Masked number comparison (strictly 11 or 12 chars like 017****2645 or 017*****6451)
                        if (strpos($text, '*') !== false || stripos($text, 'x') !== false) {
                            $clean_masked = preg_replace('/[^0-9*xX]/', '', $text);
                            if (strpos($clean_masked, '8801') === 0) {
                                $clean_masked = substr($clean_masked, 2);
                            }

                            // Strict phone pattern: requires 013-019 followed immediately by 3-5 stars, then 4-5 digits
                            // Total length must be 11 or 12 chars. Rejects 16-digit cards like 01300000****4337!
                            if (preg_match('/^01[3-9][*xX]{3,5}\d{4,5}$/', $clean_masked)) {
                                $m_prefix = substr($clean_masked, 0, 3);
                                if ($m_prefix === $user_prefix_3) {
                                    $m_suffix_4 = substr($clean_masked, -4);
                                    if ($m_suffix_4 === $user_suffix_4) {
                                        $is_matched = true;
                                        break;
                                    }

                                    $rocket_sub_3 = substr($clean_masked, -4, 3);
                                    if ($rocket_sub_3 === $user_suffix_3) {
                                        $is_matched = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($is_matched) {
                    $matched_sms = $row;
                    break;
                }
            }
        }

        if ($matched_sms !== null) {
            $sms_id = $matched_sms['id'];
            $sms_trxid = !empty($matched_sms['transaction_id']) ? $matched_sms['transaction_id'] : '';

            if (empty($sms_trxid)) {
                // If transaction_id column was empty, attempt extraction from SMS text
                $sms_full_text = ($matched_sms['sms_text'] ?? '') . ' ' . ($matched_sms['message'] ?? '') . ' ' . ($matched_sms['raw_sms'] ?? '');
                if (preg_match('/(?:TxnId|TrxID|Trx\s*Id|TrxID:|TxnID:)\s*[:#]?\s*([A-Za-z0-9]+)/i', $sms_full_text, $trx_match)) {
                    $sms_trxid = $trx_match[1];
                } elseif (preg_match('/\t([0-9]{8,15})\t/', $sms_full_text, $tab_match)) {
                    // e.g. Rocket tab format: MD MONIR ISLAM\tRocket merchant\t10.00 BDT\t017672626451\t6910317462\t...
                    $sms_trxid = $tab_match[1];
                } elseif (preg_match('/(?:[0-9]{11,12}|[*0-9]{10,20})\s+([0-9]{8,15})\b/', $sms_full_text, $seq_match)) {
                    $sms_trxid = $seq_match[1];
                } else {
                    $sms_trxid = 'BQR' . time();
                }
            }

            $payer_identity = ($payment_type === 'card') ? $clean_account : $customer_mobile;
            $sms_sender = !empty($matched_sms['mobile_number']) ? $matched_sms['mobile_number'] : (!empty($matched_sms['sender']) ? $matched_sms['sender'] : $payer_identity);

            if (pp_set_transaction_byid($payment_id, $plugin_slug, $plugin_info['plugin_name'] ?? 'Bangla QR', $sms_sender, $sms_trxid, 'completed', $sms_id)) {
                echo json_encode([
                    "status" => "true",
                    "completed" => true,
                    "message" => "Payment completed successfully",
                    "redirect" => pp_get_paymentlink($payment_id)
                ]);
                exit();
            }
        }

        echo json_encode([
            "status" => "false",
            "completed" => false,
            "message" => "Awaiting payment..."
        ]);
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['display_name'] ?? 'Bangla QR Payment') ?> - <?php echo htmlspecialchars($setting['response'][0]['site_name'] ?? 'PipraPay')?></title>
    <link rel="icon" type="image/x-icon" href="<?php if(isset($setting['response'][0]['favicon']) && $setting['response'][0]['favicon'] !== "--"){echo htmlspecialchars($setting['response'][0]['favicon']);}else{echo 'https://cdn.piprapay.com/media/favicon.png';}?>">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons & Font Awesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $setting['response'][0]['global_text_color'] ?? '#5f38f9' ?>;
            --primary-btn: <?php echo $setting['response'][0]['primary_button_color'] ?? '#5f38f9' ?>;
            --primary-btn-hover: <?php echo $setting['response'][0]['button_hover_color'] ?? '#4926cc' ?>;
            --btn-text: <?php echo $setting['response'][0]['button_text_color'] ?? '#ffffff' ?>;
            --bg-light: #f8fafc;
            --card-border: #e2e8f0;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; }

        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            color: var(--text-dark);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 1.5rem 1rem;
        }

        .payment-container {
            max-width: 540px;
            width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.07), 0 5px 15px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            border: 1px solid var(--card-border);
        }

        .payment-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
        }

        .back-btn {
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #f1f5f9; color: var(--text-dark);
            text-decoration: none; cursor: pointer; transition: all 0.2s; border: none;
            flex-shrink: 0;
        }
        .back-btn:hover { background: #e2e8f0; transform: translateX(-2px); }

        .timer-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(244, 42, 65, 0.08); color: #d63031;
            padding: 7px 14px; border-radius: 30px;
            font-weight: 700; font-size: 0.92rem;
            border: 1px solid rgba(244, 42, 65, 0.2);
            white-space: nowrap;
        }

        .timer-pulse {
            width: 10px; height: 10px;
            background-color: #d63031; border-radius: 50%;
            display: inline-block; animation: pulse-animation 1.5s infinite;
            flex-shrink: 0;
        }

        @keyframes pulse-animation {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(214, 48, 49, 0.7); }
            70% { transform: scale(1.15); box-shadow: 0 0 0 8px rgba(214, 48, 49, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(214, 48, 49, 0); }
        }

        .payment-body { padding: 1.5rem; }

        .summary-card {
            background: #f8fafc; border: 1px solid var(--card-border);
            border-radius: 14px; padding: 1.1rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.25rem;
            gap: 12px;
            flex-wrap: nowrap;
        }

        .merchant-brand { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }

        .merchant-logo {
            width: 46px; height: 46px; border-radius: 12px;
            object-fit: cover; background: #fff; padding: 4px;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .merchant-name {
            font-weight: 700; font-size: 1rem; color: var(--text-dark);
            margin-bottom: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        
        .summary-amount-box {
            text-align: right;
            flex-shrink: 0;
        }

        .payable-amount {
            font-size: 1.45rem; font-weight: 800; color: var(--primary-color);
            text-align: right; white-space: nowrap;
        }
        .amount-subtext { font-size: 0.75rem; color: var(--text-muted); text-align: right; font-weight: 500; white-space: nowrap; }

        /* ── STEP 1: Mobile Number Input Screen ── */
        .mobile-input-screen {
            text-align: center;
            padding: 0.5rem 0;
        }

        .mobile-icon-circle {
            width: 80px; height: 80px; border-radius: 50%;
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem auto;
            color: var(--primary-color); font-size: 2rem;
            box-shadow: 0 8px 25px rgba(95, 56, 249, 0.15);
        }

        .mobile-input-title {
            font-size: 1.15rem; font-weight: 700; color: var(--text-dark);
            margin-bottom: 0.35rem;
        }

        .mobile-input-desc {
            font-size: 0.88rem; color: var(--text-muted);
            margin-bottom: 1.5rem; line-height: 1.5;
        }

        /* ── Payment Type Tabs (Segmented Switcher) ── */
        .payment-type-tabs {
            display: flex;
            background: #f1f5f9;
            border-radius: 14px;
            padding: 4px;
            margin-bottom: 1.25rem;
            gap: 4px;
            border: 1px solid #e2e8f0;
        }

        .type-tab-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            font-size: 0.92rem;
            font-weight: 700;
            color: #64748b;
            border: none;
            background: transparent;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .type-tab-btn:hover {
            color: #1e293b;
            background: rgba(255, 255, 255, 0.6);
        }

        .type-tab-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 3px 8px rgba(15, 23, 42, 0.08);
        }

        .type-tab-btn i {
            font-size: 1.1rem;
        }

        .type-tab-btn.active i {
            color: var(--primary-color);
        }

        .mobile-field-wrapper {
            position: relative; margin-bottom: 1rem; width: 100%;
        }

        .mobile-field-wrapper .country-prefix {
            position: absolute; left: 0.9rem; top: 50%; transform: translateY(-50%);
            font-size: 1rem; font-weight: 700; color: #334155;
            display: flex; align-items: center; gap: 5px;
            pointer-events: none;
            z-index: 2;
        }

        .mobile-field-wrapper .country-prefix img {
            width: 22px; height: 15px; object-fit: cover; border-radius: 2px;
        }

        .mobile-field-wrapper .card-prefix {
            position: absolute; left: 0.9rem; top: 50%; transform: translateY(-50%);
            font-size: 1.25rem; color: #64748b;
            display: flex; align-items: center;
            pointer-events: none;
            z-index: 2;
        }

        .mobile-input-field {
            width: 100%;
            padding: 0.95rem 0.85rem 0.95rem 4.6rem;
            font-size: 1.15rem; font-weight: 600; letter-spacing: 0.5px;
            border: 2px solid var(--card-border);
            border-radius: 14px;
            background: #f8fafc;
            color: var(--text-dark);
            transition: all 0.25s;
            outline: none;
        }

        .card-input-field {
            width: 100%;
            padding: 0.95rem 0.85rem 0.95rem 2.9rem;
            font-size: 1.05rem; font-weight: 600; letter-spacing: 0.5px;
            border: 2px solid var(--card-border);
            border-radius: 14px;
            background: #f8fafc;
            color: var(--text-dark);
            transition: all 0.25s;
            outline: none;
        }

        .mobile-input-field:focus,
        .card-input-field:focus {
            border-color: var(--primary-color);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(95, 56, 249, 0.1);
        }

        .mobile-input-field::placeholder,
        .card-input-field::placeholder { color: #94a3b8; font-weight: 400; letter-spacing: 0; }

        .mobile-input-field.is-invalid,
        .card-input-field.is-invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .mobile-error {
            color: #ef4444; font-size: 0.82rem; font-weight: 500;
            margin-top: 0.5rem; display: none;
            text-align: left;
        }

        .btn-continue-payment {
            width: 100%;
            padding: 0.95rem 1.5rem;
            font-size: 1rem; font-weight: 700;
            background: var(--primary-btn); color: var(--btn-text);
            border: none; border-radius: 14px;
            cursor: pointer; transition: all 0.25s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        .btn-continue-payment:hover {
            background: var(--primary-btn-hover);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(95, 56, 249, 0.25);
        }

        .btn-continue-payment:disabled {
            opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none;
        }

        .mobile-security-note {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            margin-top: 1rem;
            font-size: 0.78rem; color: var(--text-muted);
        }

        /* ── STEP 2: QR Payment Screen (hidden initially) ── */
        .qr-payment-screen { display: none; }

        /* Paying From Banner */
        .paying-from-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #93c5fd;
            border-radius: 14px;
            padding: 0.85rem 1.15rem;
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 1.25rem;
        }

        .paying-from-icon {
            width: 38px; height: 38px; border-radius: 50%;
            background: #fff; display: flex; align-items: center; justify-content: center;
            color: #2563eb; font-size: 1.15rem; flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.12);
        }

        .paying-from-text {
            font-size: 0.85rem; color: #1e40af; line-height: 1.4;
            min-width: 0; flex: 1;
        }
        .paying-from-text strong { display: block; color: #1e3a5f; font-size: 0.95rem; }
        .paying-from-number {
            font-family: 'Inter', monospace; font-weight: 800;
            font-size: 1.05rem; color: #1d4ed8; letter-spacing: 0.5px;
            word-break: break-all;
        }

        .auto-status-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #86efac; border-radius: 14px;
            padding: 0.75rem 1.15rem; display: flex; align-items: center;
            gap: 12px; margin-bottom: 1.25rem;
        }

        .auto-status-radar {
            width: 36px; height: 36px; border-radius: 50%;
            background: #ffffff; display: flex; align-items: center; justify-content: center;
            color: #16a34a; font-size: 1.15rem;
            box-shadow: 0 2px 8px rgba(22, 163, 74, 0.15);
            position: relative; flex-shrink: 0;
        }

        .radar-wave {
            position: absolute; width: 100%; height: 100%;
            border-radius: 50%; border: 2px solid #22c55e;
            animation: radar-pulse 2s infinite ease-out;
        }

        @keyframes radar-pulse {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(1.8); opacity: 0; }
        }

        .auto-status-text { font-size: 0.78rem; color: #166534; line-height: 1.35; }
        .auto-status-text strong { display: block; color: #14532d; font-size: 0.86rem; margin-bottom: 1px; }

        .qr-card-container {
            background: #ffffff; border: 2px dashed #cbd5e1;
            border-radius: 18px; padding: 1.4rem;
            text-align: center; margin-bottom: 1.25rem; position: relative;
        }

        .qr-badge-header {
            display: flex; align-items: center; justify-content: center;
            gap: 8px; margin-bottom: 0.75rem;
        }

        .bangla-qr-logo { height: 30px; object-fit: contain; }

        .qr-image-wrapper {
            position: relative; display: inline-block; background: #fff;
            padding: 12px; border-radius: 16px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.07);
            margin: 0.4rem 0; border: 1px solid #e2e8f0;
            max-width: 100%;
        }

        .qr-image-display {
            width: 220px;
            max-width: 100%;
            height: auto;
            aspect-ratio: 1 / 1;
            object-fit: contain;
            border-radius: 10px; display: block;
            margin: 0 auto;
        }

        .qr-scan-guide {
            font-size: 0.88rem; color: var(--text-muted);
            margin-top: 0.5rem; font-weight: 600;
            word-break: break-word;
        }

        .supported-apps-strip {
            background: #f8fafc; border-radius: 12px;
            padding: 0.65rem 0.85rem; margin-top: 0.75rem;
            display: flex; align-items: center; justify-content: center;
            flex-wrap: wrap; gap: 6px; font-size: 0.78rem; color: var(--text-muted);
        }

        .app-pill {
            background: #ffffff; border: 1px solid #e2e8f0;
            padding: 3px 9px; border-radius: 14px; font-weight: 600; color: #334155;
        }

        .screenshot-tip-badge {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
            margin: 0.75rem 0;
            font-size: 0.8rem;
            color: #0369a1;
            line-height: 1.45;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
        }
        .screenshot-tip-badge strong {
            color: #0c4a6e;
        }

        .instruction-list {
            list-style: none; padding: 0; margin: 0 0 1.25rem 0;
            border: 1px solid var(--card-border); border-radius: 14px;
            background: #fff; overflow: hidden;
        }

        .instruction-item {
            display: flex; align-items: center;
            padding: 0.8rem 1rem; border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem; color: #334155;
        }
        .instruction-item:last-child { border-bottom: none; }

        .step-number {
            width: 24px; height: 24px; border-radius: 50%;
            background: rgba(95, 56, 249, 0.1); color: var(--primary-color);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.78rem; margin-right: 12px; flex-shrink: 0;
        }

        .step-content {
            flex: 1; display: flex; align-items: center;
            justify-content: space-between; flex-wrap: wrap; gap: 6px;
            min-width: 0;
        }

        .copy-pill-btn {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #475569;
            font-size: 0.78rem; font-weight: 600; padding: 3px 10px;
            border-radius: 8px; cursor: pointer; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 4px;
            flex-shrink: 0;
        }
        .copy-pill-btn:hover { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }

        .payment-footer {
            padding: 1.1rem 1.5rem; background: #f8fafc;
            border-top: 1px solid var(--card-border);
            text-align: center; font-size: 0.8rem; color: var(--text-muted);
        }

        .success-overlay {
            display: none; position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255, 255, 255, 0.96); z-index: 9999;
            align-items: center; justify-content: center;
            flex-direction: column; text-align: center; padding: 2rem;
        }

        .success-checkmark {
            width: 90px; height: 90px; border-radius: 50%;
            background: #dcfce7; color: #16a34a;
            display: flex; align-items: center; justify-content: center;
            font-size: 3rem; margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px rgba(22, 163, 74, 0.2);
            animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes popIn {
            0% { transform: scale(0); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Slide transition */
        .slide-out { animation: slideOut 0.35s ease forwards; }
        .slide-in  { animation: slideIn  0.35s ease forwards; }

        @keyframes slideOut {
            0% { opacity: 1; transform: translateX(0); }
            100% { opacity: 0; transform: translateX(-30px); }
        }
        @keyframes slideIn {
            0% { opacity: 0; transform: translateX(30px); }
            100% { opacity: 1; transform: translateX(0); }
        }

        /* ── COMPLETE MOBILE RESPONSIVE MEDIA QUERIES ── */
        @media (max-width: 576px) {
            body {
                padding: 0.75rem 0.5rem;
                justify-content: flex-start;
            }
            .payment-container {
                border-radius: 16px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            }
            .payment-header {
                padding: 0.85rem 1rem;
            }
            .back-btn {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }
            .timer-badge {
                padding: 6px 11px;
                font-size: 0.82rem;
            }
            .payment-body {
                padding: 1rem 0.85rem;
            }
            .summary-card {
                padding: 0.85rem 1rem;
                border-radius: 12px;
                margin-bottom: 1rem;
                flex-direction: row !important;
                align-items: center;
                justify-content: space-between;
                flex-wrap: nowrap !important;
                gap: 10px;
            }
            .merchant-logo {
                width: 38px;
                height: 38px;
                border-radius: 10px;
            }
            .merchant-name {
                font-size: 0.9rem;
                max-width: 140px;
            }
            .payable-amount {
                font-size: 1.15rem;
            }
            .mobile-icon-circle {
                width: 65px;
                height: 65px;
                font-size: 1.6rem;
                margin-bottom: 1rem;
            }
            .mobile-input-title {
                font-size: 1.05rem;
            }
            .mobile-input-desc {
                font-size: 0.82rem;
                margin-bottom: 1.25rem;
            }
            .mobile-field-wrapper .country-prefix {
                left: 0.75rem;
                font-size: 0.95rem;
            }
            .mobile-field-wrapper .country-prefix img {
                width: 20px;
                height: 14px;
            }
            .mobile-input-field {
                padding: 0.85rem 0.75rem 0.85rem 4.3rem;
                font-size: 1.05rem;
                border-radius: 12px;
                letter-spacing: 0.5px;
            }
            .btn-continue-payment {
                padding: 0.85rem 1rem;
                font-size: 0.95rem;
                border-radius: 12px;
            }
            .paying-from-banner {
                padding: 0.75rem 0.85rem;
                gap: 10px;
                border-radius: 12px;
                margin-bottom: 1rem;
            }
            .paying-from-icon {
                width: 34px;
                height: 34px;
                font-size: 1rem;
            }
            .paying-from-text strong {
                font-size: 0.88rem;
            }
            .paying-from-number {
                font-size: 0.95rem;
            }
            .auto-status-card {
                padding: 0.65rem 0.8rem;
                gap: 10px;
                border-radius: 12px;
                margin-bottom: 1rem;
            }
            .auto-status-radar {
                width: 32px;
                height: 32px;
                font-size: 1rem;
            }
            .auto-status-text {
                font-size: 0.75rem;
                line-height: 1.3;
            }
            .auto-status-text strong {
                font-size: 0.82rem;
            }
            .qr-card-container {
                padding: 1rem 0.75rem;
                border-radius: 14px;
                margin-bottom: 1rem;
            }
            .qr-image-wrapper {
                padding: 8px;
                border-radius: 12px;
            }
            .qr-image-display {
                width: 190px;
                max-width: 100%;
                height: auto;
            }
            .qr-scan-guide {
                font-size: 0.82rem;
            .supported-apps-strip {
                padding: 0.5rem 0.6rem;
                gap: 4px;
            }
            .app-pill {
                padding: 2px 7px;
                font-size: 0.72rem;
            }
            .instruction-list {
                border-radius: 12px;
                margin-bottom: 1rem;
            }
            .instruction-item {
                padding: 0.7rem 0.75rem;
                font-size: 0.82rem;
            }
            .step-number {
                width: 22px;
                height: 22px;
                font-size: 0.72rem;
                margin-right: 8px;
            }
            .payment-footer {
                padding: 0.9rem 1rem;
                font-size: 0.75rem;
            }
        }

        @media (max-width: 380px) {
            body {
                padding: 0.5rem 0.25rem;
            }
            .payment-header {
                padding: 0.75rem 0.85rem;
            }
            .timer-badge {
                padding: 5px 9px;
                font-size: 0.78rem;
            }
            .payment-body {
                padding: 0.85rem 0.65rem;
            }
            .summary-card {
                padding: 0.65rem 0.7rem;
                flex-direction: row !important;
                align-items: center;
                justify-content: space-between;
                flex-wrap: nowrap !important;
                gap: 6px;
            }
            .merchant-logo {
                width: 32px;
                height: 32px;
            }
            .merchant-name {
                font-size: 0.8rem;
                max-width: 95px;
            }
            .merchant-brand small {
                font-size: 0.68rem;
            }
            .payable-amount {
                font-size: 0.98rem;
                text-align: right;
            }
            .amount-subtext {
                font-size: 0.65rem;
                text-align: right;
            }
            .mobile-field-wrapper .country-prefix {
                left: 0.6rem;
                font-size: 0.9rem;
            }
            .mobile-input-field {
                padding: 0.8rem 0.5rem 0.8rem 4rem;
                font-size: 0.98rem;
            }
            .auto-status-card {
                padding: 0.55rem 0.75rem;
                gap: 8px;
            }
            .auto-status-radar {
                width: 28px;
                height: 28px;
                font-size: 0.9rem;
            }
            .auto-status-text {
                font-size: 0.72rem;
                line-height: 1.25;
            }
            .auto-status-text strong {
                font-size: 0.78rem;
            }
            .qr-image-display {
                width: 160px;
            }
        }
    </style>
</head>
<body>

    <div class="payment-container">
        <!-- Header -->
        <div class="payment-header">
            <button class="back-btn" onclick="location.href='<?php echo pp_get_paymentlink($payment_id)?>'" title="Back to payment methods">
                <i class="fas fa-arrow-left"></i>
            </button>
            
            <div class="d-flex align-items-center gap-2">
                <div class="timer-badge" id="timerBadge">
                    <span class="timer-pulse"></span>
                    <i class="bi bi-clock me-1"></i>
                    <span id="countdownDisplay">15:00</span>
                </div>
            </div>
        </div>

        <div class="payment-body">
            <!-- Merchant & Amount Summary (always visible) -->
            <div class="summary-card">
                <div class="merchant-brand">
                    <img src="<?php if(isset($setting['response'][0]['favicon']) && $setting['response'][0]['favicon'] !== "--"){echo htmlspecialchars($setting['response'][0]['favicon']);}else{echo 'https://cdn.piprapay.com/media/favicon.png';}?>" alt="Merchant Logo" class="merchant-logo">
                    <div style="min-width: 0; flex: 1;">
                        <div class="merchant-name"><?php echo htmlspecialchars($settings['merchant_name'] ?? $setting['response'][0]['site_name'] ?? 'Merchant') ?></div>
                        <small class="text-muted" style="white-space: nowrap;"><i class="bi bi-shield-check text-success me-1"></i>Bangla QR Verified</small>
                    </div>
                </div>
                <div class="summary-amount-box">
                    <div class="payable-amount"><?php echo number_format($total_payable, 2).' '.$currency ?></div>
                    <div class="amount-subtext">Total Payable</div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- STEP 1: Identification Screen (Tab Switcher)       -->
            <!-- ═══════════════════════════════════════════════════ -->
            <div class="mobile-input-screen" id="mobileInputScreen">
                
                <!-- Payment Method Tab Bar -->
                <div class="payment-type-tabs">
                    <button type="button" class="type-tab-btn active" id="tabMobile">
                        <i class="bi bi-phone"></i>
                        <span>Mobile Banking</span>
                    </button>
                    <button type="button" class="type-tab-btn" id="tabCard">
                        <i class="bi bi-credit-card-2-front"></i>
                        <span>Card / Bank</span>
                    </button>
                </div>

                <!-- SECTION 1: Mobile Banking (Default) -->
                <div id="sectionMobileBanking">
                    <div class="mobile-icon-circle">
                        <i class="bi bi-phone"></i>
                    </div>
                    <div class="mobile-input-title">Enter Your Mobile Number</div>
                    <div class="mobile-input-desc">
                        Enter the mobile number you will use to make the payment.<br>
                        <strong style="color: #334155;">You must pay from this number only.</strong>
                    </div>

                    <div class="mobile-field-wrapper">
                        <div class="country-prefix">
                            <img src="https://flagcdn.com/w40/bd.png" alt="BD">
                            +88
                        </div>
                        <input type="tel" class="mobile-input-field" id="customerMobile" placeholder="01XXXXXXXXX" maxlength="12" value="<?php echo htmlspecialchars($prefill_clean); ?>" autocomplete="tel" inputmode="numeric">
                        <div class="mobile-error" id="mobileError">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <span id="mobileErrorText">Please enter a valid 11 or 12 digit mobile number</span>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Card / Bank Account (Without Phone Validation) -->
                <div id="sectionCardBank" style="display: none;">
                    <div class="mobile-icon-circle" style="background: rgba(14, 165, 233, 0.1); color: #0284c7;">
                        <i class="bi bi-credit-card-2-front"></i>
                    </div>
                    <div class="mobile-input-title">Enter Card or Account Number</div>
                    <div class="mobile-input-desc">
                        Enter the card or bank account number used for payment.<br>
                        <strong style="color: #334155;">e.g. 10771****9394, last 4 digits, or card number.</strong>
                    </div>

                    <div class="mobile-field-wrapper">
                        <div class="card-prefix">
                            <i class="bi bi-credit-card"></i>
                        </div>
                        <input type="text" class="card-input-field" id="customerAccount" placeholder="e.g. 10771****9394 or 9394" maxlength="24" autocomplete="off" inputmode="numeric">
                        <div class="mobile-error" id="accountError">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <span id="accountErrorText">Please enter at least 4 digits of your card or account number</span>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn-continue-payment" id="btnContinue" disabled>
                    <i class="bi bi-qr-code-scan"></i>
                    Continue to Payment
                </button>

                <div class="mobile-security-note">
                    <i class="bi bi-lock-fill text-success"></i>
                    Your details are only used for payment verification
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- STEP 2: QR Payment Screen (shown after details)   -->
            <!-- ═══════════════════════════════════════════════════ -->
            <div class="qr-payment-screen" id="qrPaymentScreen">

                <!-- Paying From Banner -->
                <div class="paying-from-banner" id="payingFromBanner">
                    <div class="paying-from-icon" id="payingFromIcon">
                        <i class="bi bi-phone-vibrate"></i>
                    </div>
                    <div class="paying-from-text">
                        <strong id="payingFromTitle">Pay from this number only</strong>
                        <span class="paying-from-number" id="payingFromNumber"></span>
                    </div>
                </div>

                <!-- Auto-Verification Active -->
                <div class="auto-status-card" id="autoStatusBanner">
                    <div class="auto-status-radar">
                        <div class="radar-wave"></div>
                        <i class="bi bi-broadcast"></i>
                    </div>
                    <div class="auto-status-text">
                        <strong>Auto-Verification Active</strong>
                        Payment will be automatically verified upon complete.
                    </div>
                </div>

                <!-- Bangla QR Display Card -->
                <div class="qr-card-container">
                    <div class="qr-badge-header">
                        <img src="<?php echo pp_get_site_url().'/pp-content/plugins/'.$plugin_info['plugin_dir'].'/'.$plugin_slug.'/assets/icon.png'; ?>" alt="Bangla QR" class="bangla-qr-logo" onerror="this.style.display='none'">
                        <span class="fw-bold text-dark fs-6">Scan with Any Banking / MFS App</span>
                    </div>

                    <div class="qr-image-wrapper">
                        <img src="<?php echo htmlspecialchars($qr_image_url) ?>" alt="Bangla QR Code" class="qr-image-display" id="mainQrCode">
                    </div>

                    <div class="qr-scan-guide">
                        Open bKash, Nagad, Rocket, Cellfin, Astha or Bank App &amp; Scan
                    </div>

                    <!-- Screenshot / Gallery Scan Guidance Badge -->
                    <div class="screenshot-tip-badge">
                        <span style="font-size: 1.25rem; line-height: 1; flex-shrink: 0;">📸</span>
                        <div>
                            <strong>Paying on this phone?</strong> Take a screenshot of this QR and select it from your MFS/Bank app's Gallery Scan.
                        </div>
                    </div>

                    <div class="supported-apps-strip">
                        <span class="app-pill">Rocket</span>
                        <span class="app-pill">bKash</span>
                        <span class="app-pill">Nagad</span>
                        <span class="app-pill">Cellfin</span>
                        <span class="app-pill">Astha</span>
                        <span class="app-pill">NexusPay</span>
                        <span class="app-pill">+30 Banks</span>
                    </div>
                </div>

                <!-- Step Instructions -->
                <ul class="instruction-list">
                    <li class="instruction-item">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <span>Open <strong>Rocket, bKash, Nagad, Cellfin, Astha</strong> or Bank App</span>
                        </div>
                    </li>
                    <li class="instruction-item">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <span>Tap <strong>Scan QR</strong> (or upload screenshot from gallery)</span>
                        </div>
                    </li>
                    <li class="instruction-item">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <span>Enter exact Amount: <strong><?php echo number_format($total_payable, 2).' '.$currency ?></strong></span>
                            <button type="button" class="copy-pill-btn btn-copy-amount" onclick="copyValue('<?php echo $total_payable ?>', 'btn-copy-amount')">
                                <i class="bi bi-copy"></i> Copy
                            </button>
                        </div>
                    </li>
                    <li class="instruction-item">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <span>Enter PIN & confirm payment in your app</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div class="payment-footer">
            <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                <i class="bi bi-shield-lock-fill text-success"></i>
                <span>256-bit Encrypted Automated Bangla QR Payment</span>
            </div>
            <div>Powered by <a href="https://piprapay.com/" target="_blank" style="color: var(--primary-color); text-decoration: none; font-weight: 600;">PipraPay</a></div>
        </div>
    </div>

    <!-- Success Overlay -->
    <div class="success-overlay" id="successOverlay">
        <div class="success-checkmark">
            <i class="bi bi-check-lg"></i>
        </div>
        <h3 class="fw-bold text-dark mb-2">Payment Confirmed!</h3>
        <p class="text-muted mb-4" id="successMessage">Your payment has been verified successfully.</p>
        <div class="spinner-border text-primary spinner-border-sm" role="status"></div>
        <span class="text-muted small mt-2">Redirecting to receipt...</span>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ── Utility Functions ──
        function copyValue(text, btnClass) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    showCopiedState(btnClass);
                }).catch(function() { fallbackCopy(text, btnClass); });
            } else {
                fallbackCopy(text, btnClass);
            }
        }

        function fallbackCopy(text, btnClass) {
            var temp = document.createElement("textarea");
            temp.value = text;
            temp.style.position = "fixed";
            temp.style.left = "-9999px";
            document.body.appendChild(temp);
            temp.focus();
            temp.select();
            try { document.execCommand("copy"); showCopiedState(btnClass); } catch (e) {}
            document.body.removeChild(temp);
        }

        function showCopiedState(btnClass) {
            var btn = document.querySelector("." + btnClass);
            if (btn) {
                var original = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                btn.style.backgroundColor = '#16a34a';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#16a34a';
                setTimeout(function() {
                    btn.innerHTML = original;
                    btn.style.backgroundColor = '';
                    btn.style.color = '';
                    btn.style.borderColor = '';
                }, 2000);
            }
        }

        // ── Tab Bar Switching: Mobile Banking vs Card / Bank ──
        var currentPaymentType = 'mobile';
        var tabMobile = document.getElementById('tabMobile');
        var tabCard = document.getElementById('tabCard');
        var sectionMobileBanking = document.getElementById('sectionMobileBanking');
        var sectionCardBank = document.getElementById('sectionCardBank');

        var mobileInput = document.getElementById('customerMobile');
        var cardInput = document.getElementById('customerAccount');
        var btnContinue = document.getElementById('btnContinue');
        var mobileError = document.getElementById('mobileError');
        var accountError = document.getElementById('accountError');

        var customerMobileNumber = '';
        var customerAccountNumber = '';

        function switchPaymentType(type) {
            currentPaymentType = type;
            if (type === 'card') {
                tabCard.classList.add('active');
                tabMobile.classList.remove('active');
                sectionMobileBanking.style.display = 'none';
                sectionCardBank.style.display = 'block';
                if (mobileError) mobileError.style.display = 'none';
                cardInput.focus();
                validateCardInput();
            } else {
                tabMobile.classList.add('active');
                tabCard.classList.remove('active');
                sectionCardBank.style.display = 'none';
                sectionMobileBanking.style.display = 'block';
                if (accountError) accountError.style.display = 'none';
                mobileInput.focus();
                validateMobileInput();
            }
        }

        tabMobile.addEventListener('click', function() { switchPaymentType('mobile'); });
        tabCard.addEventListener('click', function() { switchPaymentType('card'); });

        function validateMobileInput() {
            var val = mobileInput.value.replace(/[^0-9]/g, '');
            if ((val.length === 11 || val.length === 12) && val.startsWith('01')) {
                btnContinue.disabled = false;
                mobileInput.classList.remove('is-invalid');
                if (mobileError) mobileError.style.display = 'none';
                return true;
            } else {
                btnContinue.disabled = true;
                return false;
            }
        }

        function validateCardInput() {
            var cleanDigits = cardInput.value.replace(/[^0-9]/g, '');
            if (cleanDigits.length >= 4) {
                btnContinue.disabled = false;
                cardInput.classList.remove('is-invalid');
                if (accountError) accountError.style.display = 'none';
                return true;
            } else {
                btnContinue.disabled = true;
                return false;
            }
        }

        mobileInput.addEventListener('input', function() {
            var val = this.value.replace(/[^0-9]/g, '');
            this.value = val;
            validateMobileInput();
        });

        cardInput.addEventListener('input', function() {
            validateCardInput();
        });

        // Enable continue button if pre-filled with valid number
        if (mobileInput.value) {
            validateMobileInput();
        }

        mobileInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !btnContinue.disabled) {
                btnContinue.click();
            }
        });

        cardInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !btnContinue.disabled) {
                btnContinue.click();
            }
        });

        btnContinue.addEventListener('click', function() {
            if (currentPaymentType === 'mobile') {
                var val = mobileInput.value.replace(/[^0-9]/g, '');
                if ((val.length !== 11 && val.length !== 12) || !val.startsWith('01')) {
                    mobileInput.classList.add('is-invalid');
                    document.getElementById('mobileErrorText').textContent = 'Please enter a valid 11 or 12 digit mobile number starting with 01';
                    if (mobileError) mobileError.style.display = 'block';
                    return;
                }

                customerMobileNumber = val;

                // Show paying-from number
                document.getElementById('payingFromIcon').innerHTML = '<i class="bi bi-phone-vibrate"></i>';
                document.getElementById('payingFromTitle').textContent = 'Pay from this number only';
                document.getElementById('payingFromNumber').textContent = val;

            } else {
                var rawCard = cardInput.value.trim();
                var cleanCard = rawCard.replace(/[^0-9]/g, '');
                if (cleanCard.length < 4) {
                    cardInput.classList.add('is-invalid');
                    document.getElementById('accountErrorText').textContent = 'Please enter at least 4 digits of your card or account number';
                    if (accountError) accountError.style.display = 'block';
                    return;
                }

                customerAccountNumber = rawCard;

                // Show paying-from card/account
                document.getElementById('payingFromIcon').innerHTML = '<i class="bi bi-credit-card-2-front"></i>';
                document.getElementById('payingFromTitle').textContent = 'Pay from this card / account only';
                document.getElementById('payingFromNumber').textContent = rawCard;
            }

            // Animate transition: hide mobile screen → show QR screen
            var inputScreen = document.getElementById('mobileInputScreen');
            var qrScreen = document.getElementById('qrPaymentScreen');

            inputScreen.classList.add('slide-out');
            setTimeout(function() {
                inputScreen.style.display = 'none';
                qrScreen.style.display = 'block';
                qrScreen.classList.add('slide-in');

                // Start polling ONLY after details are entered
                startPaymentPolling();
            }, 350);
        });

        // ── Payment Verification Engine ──
        var paymentId = "<?php echo htmlspecialchars($payment_id) ?>";
        var paymentUrl = "<?php echo pp_get_paymentlink($payment_id) ?>";
        var isCompleted = false;

        function triggerSuccess(redirectUrl, msg) {
            if (isCompleted) return;
            isCompleted = true;

            var overlay = document.getElementById('successOverlay');
            if (overlay) overlay.style.display = 'flex';
            
            var successMsg = document.getElementById('successMessage');
            if (successMsg && msg) successMsg.innerText = msg;

            setTimeout(function() {
                window.location.href = redirectUrl || paymentUrl;
            }, 1500);
        }

        function startPaymentPolling() {
            async function pollVerification() {
                if (isCompleted) return;

                try {
                    var formData = new FormData();
                    formData.append("bangla-qr", paymentId);
                    formData.append("payment_id", paymentId);
                    formData.append("payment_type", currentPaymentType);

                    if (currentPaymentType === 'card') {
                        formData.append("customer_account", customerAccountNumber);
                        formData.append("customer_mobile", customerAccountNumber);
                    } else {
                        formData.append("customer_mobile", customerMobileNumber);
                    }

                    var res = await fetch(paymentUrl + "?method=bangla-qr", {
                        method: "POST",
                        body: formData
                    });

                    var text = await res.text();
                    var data = null;
                    try { data = JSON.parse(text); } catch(e) {}

                    if (data && (data.status === "true" || data.status === true)) {
                        triggerSuccess(data.redirect || paymentUrl, data.message || "Payment verified successfully!");
                        return;
                    }
                } catch (err) {}

                if (!isCompleted) {
                    setTimeout(pollVerification, 3000);
                }
            }

            pollVerification();
        }

        // ── 15-Minute Countdown Timer ──
        var secondsLeft = <?php echo (int)$timer_seconds ?>;
        var countEl = document.getElementById('countdownDisplay');

        function tickTimer() {
            if (isCompleted) return;
            if (secondsLeft <= 0) {
                if (countEl) countEl.innerText = "00:00 (Expired)";
                var banner = document.getElementById('autoStatusBanner');
                if (banner) {
                    banner.className = 'alert alert-danger mb-0 py-3';
                    banner.innerHTML = '<div class="d-flex align-items-center gap-2"><i class="bi bi-exclamation-octagon-fill fs-5"></i> <div><strong>Payment Session Expired</strong><br>The 15-minute verification window has expired. Please restart the transaction.</div></div>';
                }
                return;
            }

            var mins = Math.floor(secondsLeft / 60);
            var secs = secondsLeft % 60;
            if (countEl) {
                countEl.innerText = (mins < 10 ? "0" : "") + mins + ":" + (secs < 10 ? "0" : "") + secs;
            }
            secondsLeft--;
            setTimeout(tickTimer, 1000);
        }

        tickTimer();

        // Auto-focus the mobile input
        mobileInput.focus();
    </script>
</body>
</html>
