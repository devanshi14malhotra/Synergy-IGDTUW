<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Validate UID from URL
$uid = isset($_GET['uid']) ? trim($_GET['uid']) : '';
if (!preg_match('/^[A-Z0-9]{7}$/', $uid)) {
    http_response_code(400);
    die('Invalid UID.');
}

// Fetch from DB
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) { http_response_code(500); die('DB connection failed.'); }

$stmt = $conn->prepare("
    SELECT UID, EnrollmentNo, Name, Affiliation, Course, MobileNo, EmailID,
           Sports, TeamRole, CaptainUID, TotalAmount, TransactionID, CreatedAt
    FROM `2026_Participants` WHERE UID = ? LIMIT 1
");
$stmt->bind_param("s", $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) { http_response_code(404); die('Registration not found.'); }

// Prepare values
$safeUID         = htmlspecialchars($row['UID'],           ENT_QUOTES, 'UTF-8');
$safeEnrollment  = htmlspecialchars($row['EnrollmentNo'],  ENT_QUOTES, 'UTF-8');
$safeName        = htmlspecialchars($row['Name'],          ENT_QUOTES, 'UTF-8');
$safeAffiliation = htmlspecialchars($row['Affiliation'],   ENT_QUOTES, 'UTF-8');
$safeCourse      = htmlspecialchars($row['Course'],        ENT_QUOTES, 'UTF-8');
$safeMobile      = htmlspecialchars($row['MobileNo'],      ENT_QUOTES, 'UTF-8');
$safeEmail       = htmlspecialchars($row['EmailID'],       ENT_QUOTES, 'UTF-8');
$safeRole        = htmlspecialchars($row['TeamRole'] ?? '',      ENT_QUOTES, 'UTF-8');
$safeCaptainUID  = htmlspecialchars($row['CaptainUID'] ?? '',    ENT_QUOTES, 'UTF-8');
$safeAmount      = htmlspecialchars((string)$row['TotalAmount'], ENT_QUOTES, 'UTF-8');
$safeTxn         = htmlspecialchars($row['TransactionID'] ?? '', ENT_QUOTES, 'UTF-8');
$safeDate        = htmlspecialchars($row['CreatedAt'],     ENT_QUOTES, 'UTF-8');

$sportsArray = json_decode($row['Sports'], true);
$sportsItems = '';
if (is_array($sportsArray)) {
    foreach ($sportsArray as $sport) {
        $sportsItems .= '<li>' . htmlspecialchars($sport, ENT_QUOTES, 'UTF-8') . '</li>';
    }
}
if ($sportsItems === '') $sportsItems = '<li>No sport selected</li>';

// Build PDF HTML
// Note: DOMPDF works best with inline styles, no flexbox/grid
$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; color: #0f172a; padding: 30px; }
    .header { background: #ffd86b; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
    .header .tag { font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #ea580c; font-weight: bold; margin-bottom: 6px; }
    .header h1 { font-size: 20px; color: #0f172a; margin: 0; }
    .uid-box { background: #fef3c7; border: 1.5px solid #fbbf24; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
    .uid-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #b45309; margin-bottom: 4px; }
    .uid-value { font-size: 22px; font-weight: bold; letter-spacing: 4px; color: #92400e; }
    .uid-note { font-size: 10px; color: #b45309; margin-top: 4px; }
    .section { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; }
    .section-title { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; font-weight: bold; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid #f1f5f9; }
    .row { margin: 4px 0; line-height: 1.7; }
    .label { font-weight: bold; color: #0f172a; }
    ul { margin: 4px 0 0 16px; padding: 0; }
    li { margin: 3px 0; }
    .amount-box { border-top: 2px dashed #fbbf24; padding-top: 12px; margin-top: 4px; font-size: 15px; font-weight: bold; color: #92400e; }
    .footer { margin-top: 24px; font-size: 10px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 10px; }
</style>
</head>
<body>
    <div class="header">
        <div class="tag">Synergy Sports Fest 2026</div>
        <h1>Registration Receipt</h1>
    </div>
    <div class="uid-box">
        <div class="uid-label">Registration UID</div>
        <div class="uid-value">' . $safeUID . '</div>
        <div class="uid-note">Keep this UID safe — you may need it for verification at the event.</div>
    </div>
    <div class="section">
        <div class="section-title">Participant Details</div>
        <div class="row"><span class="label">Name:</span> ' . $safeName . '</div>
        <div class="row"><span class="label">Enrollment No:</span> ' . $safeEnrollment . '</div>
        <div class="row"><span class="label">College / Institute:</span> ' . $safeAffiliation . '</div>
        <div class="row"><span class="label">Course:</span> ' . $safeCourse . '</div>
        <div class="row"><span class="label">Mobile:</span> +91 ' . $safeMobile . '</div>
        <div class="row"><span class="label">Email:</span> ' . $safeEmail . '</div>'
        . ($safeRole        !== '' ? '<div class="row"><span class="label">Team Role:</span> ' . $safeRole . '</div>' : '')
        . ($safeCaptainUID  !== '' ? '<div class="row"><span class="label">Captain UID:</span> ' . $safeCaptainUID . '</div>' : '') . '
    </div>
    <div class="section">
        <div class="section-title">Sports Registered</div>
        <ul>' . $sportsItems . '</ul>
    </div>'
    . ($safeTxn !== '' ? '
    <div class="section">
        <div class="section-title">Payment</div>
        <div class="row"><span class="label">Transaction ID:</span> ' . $safeTxn . '</div>
    </div>' : '') . '
    <div class="section">
        <div class="amount-box">Total Amount Paid: Rs ' . $safeAmount . '</div>
    </div>
    <div class="footer">Registered on: ' . $safeDate . ' &nbsp;|&nbsp; System-generated receipt — Synergy Sports Fest 2026</div>
</body>
</html>';

// Generate and stream PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream('SynergyReceipt_' . $safeUID . '.pdf', ['Attachment' => true]);