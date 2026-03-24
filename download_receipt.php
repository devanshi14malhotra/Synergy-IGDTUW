<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$uid = isset($_GET['uid']) ? trim($_GET['uid']) : '';
if (!preg_match('/^[A-Z0-9]{7}$/', $uid)) {
    http_response_code(400);
    die('Invalid UID.');
}

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

$safeUID         = htmlspecialchars($row['UID'],                  ENT_QUOTES, 'UTF-8');
$safeEnrollment  = htmlspecialchars($row['EnrollmentNo'],         ENT_QUOTES, 'UTF-8');
$safeName        = htmlspecialchars($row['Name'],                 ENT_QUOTES, 'UTF-8');
$safeAffiliation = htmlspecialchars($row['Affiliation'],          ENT_QUOTES, 'UTF-8');
$safeCourse      = htmlspecialchars($row['Course'],               ENT_QUOTES, 'UTF-8');
$safeMobile      = htmlspecialchars($row['MobileNo'],             ENT_QUOTES, 'UTF-8');
$safeEmail       = htmlspecialchars($row['EmailID'],              ENT_QUOTES, 'UTF-8');
$safeRole        = htmlspecialchars($row['TeamRole']    ?? '',    ENT_QUOTES, 'UTF-8');
$safeCaptainUID  = htmlspecialchars($row['CaptainUID']  ?? '',    ENT_QUOTES, 'UTF-8');
$safeAmount      = htmlspecialchars((string)$row['TotalAmount'],  ENT_QUOTES, 'UTF-8');
$safeTxn         = htmlspecialchars($row['TransactionID'] ?? '',  ENT_QUOTES, 'UTF-8');
$receiptNo       = htmlspecialchars('SSF-2026-' . $row['UID'],    ENT_QUOTES, 'UTF-8');

$createdTs     = strtotime((string)($row['CreatedAt'] ?? ''));
$formattedDate = $createdTs ? date('d M Y, h:i A', $createdTs) : htmlspecialchars($row['CreatedAt'], ENT_QUOTES, 'UTF-8');
$safeDate      = htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8');

$sportsArray = json_decode($row['Sports'], true);
$sportsRows  = '';
if (is_array($sportsArray)) {
    foreach ($sportsArray as $sport) {
        $sportsRows .= '<li style="margin: 5px 0;">' . htmlspecialchars($sport, ENT_QUOTES, 'UTF-8') . '</li>';
    }
}
if ($sportsRows === '') $sportsRows = '<li style="margin: 5px 0;">No sport selected</li>';

$optionalRows = '';
if ($safeRole       !== '') $optionalRows .= '<tr><td class="lbl">Team Role</td><td class="val">' . $safeRole . '</td></tr>';
if ($safeCaptainUID !== '') $optionalRows .= '<tr><td class="lbl">Captain UID</td><td class="val">' . $safeCaptainUID . '</td></tr>';

$txnCell = $safeTxn !== '' ? $safeTxn : 'Not applicable';

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #111827;
    background: #fff;
    padding: 28px;
}
.card {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    overflow: hidden;
}
.topbar { height: 5px; background: #1f2937; }
.header { padding: 18px 22px 14px; border-bottom: 1px solid #e5e7eb; }
.hdr-top { width: 100%; border-collapse: collapse; }
.hdr-top td { vertical-align: top; }
.org-name { font-size: 17px; font-weight: bold; color: #111827; }
.org-sub  { font-size: 10px; color: #6b7280; margin-top: 3px; }
.receipt-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.07em; color: #6b7280; text-align: right; }
.receipt-no    { font-size: 12px; font-weight: bold; color: #111827; text-align: right; margin-top: 3px; }
.meta-row { width: 100%; border-collapse: collapse; margin-top: 14px; }
.meta-row td { width: 33%; vertical-align: top; padding: 0 4px 0 0; }
.meta-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.07em; color: #6b7280; }
.meta-value { font-size: 12px; font-weight: bold; color: #111827; margin-top: 3px; }
.uid-value  { font-size: 14px; letter-spacing: 2px; }
.section { padding: 14px 22px; border-bottom: 1px solid #e5e7eb; }
.section:last-child { border-bottom: none; }
.section-title {
    font-size: 9px; font-weight: bold; text-transform: uppercase;
    letter-spacing: 0.1em; color: #6b7280;
    border-bottom: 1px solid #f3f4f6; padding-bottom: 6px; margin-bottom: 10px;
}
.details { width: 100%; border-collapse: collapse; font-size: 12px; }
.details tr { border-bottom: 1px solid #f9fafb; }
.lbl { width: 36%; color: #4b5563; padding: 5px 0; vertical-align: top; }
.val { color: #111827; font-weight: bold; padding: 5px 0; }
.pay-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 4px; }
.pay-table th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 7px 10px; text-align: left; font-weight: bold; color: #374151; }
.pay-table td { border: 1px solid #e5e7eb; padding: 7px 10px; color: #111827; }
.pay-table .amt { text-align: right; }
.total-row { margin-top: 10px; text-align: right; font-size: 14px; font-weight: bold; color: #111827; }
.note {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px;
    padding: 10px 12px; font-size: 10px; line-height: 1.7; color: #374151; margin-top: 10px;
}
.footer {
    border-top: 1px solid #e5e7eb; padding: 9px 22px;
    font-size: 10px; color: #6b7280; text-align: center;
    background: #f9fafb;
}
</style>
</head>
<body>
<div class="card">
  <div class="topbar"></div>
  <div class="header">
    <table class="hdr-top">
      <tr>
        <td>
          <div class="org-name">Synergy Sports Fest 2026</div>
          <div class="org-sub">Official Registration Acknowledgement Receipt</div>
        </td>
        <td style="text-align:right; width:40%;">
          <div class="receipt-label">Receipt Number</div>
          <div class="receipt-no">' . $receiptNo . '</div>
        </td>
      </tr>
    </table>
    <table class="meta-row">
      <tr>
        <td>
          <div class="meta-label">Participant UID</div>
          <div class="meta-value uid-value">' . $safeUID . '</div>
        </td>
        <td>
          <div class="meta-label">Issue Date</div>
          <div class="meta-value">' . $safeDate . '</div>
        </td>
        <td>
          <div class="meta-label">Enrollment No.</div>
          <div class="meta-value">' . $safeEnrollment . '</div>
        </td>
      </tr>
    </table>
  </div>
  <div class="section">
    <div class="section-title">Participant Information</div>
    <table class="details">
      <tr><td class="lbl">Full Name</td><td class="val">' . $safeName . '</td></tr>
      <tr><td class="lbl">Affiliation</td><td class="val">' . $safeAffiliation . '</td></tr>
      <tr><td class="lbl">Course</td><td class="val">' . $safeCourse . '</td></tr>
      <tr><td class="lbl">Mobile Number</td><td class="val">+91 ' . $safeMobile . '</td></tr>
      <tr><td class="lbl">Email Address</td><td class="val">' . $safeEmail . '</td></tr>
      ' . $optionalRows . '
    </table>
  </div>
  <div class="section">
    <div class="section-title">Sports Registered</div>
    <ul style="margin-left: 16px; padding: 0;">' . $sportsRows . '</ul>
  </div>
  <div class="section">
    <div class="section-title">Payment Summary</div>
    <table class="pay-table">
      <thead><tr><th>Description</th><th>Transaction ID</th><th class="amt">Amount</th></tr></thead>
      <tbody>
        <tr>
          <td>Registration Charges — Synergy Sports Fest 2026</td>
          <td>' . $txnCell . '</td>
          <td class="amt">INR ' . $safeAmount . '</td>
        </tr>
      </tbody>
    </table>
    <div class="total-row">Total Amount Paid: INR ' . $safeAmount . '</div>
    <div class="note">
      This receipt is system-generated and serves as official confirmation of your registration for Synergy Sports Fest 2026.
      Participants are advised to retain a copy and present it during event verification.
    </div>
  </div>
  <div class="footer">
    Generated on ' . $safeDate . ' &nbsp;&middot;&nbsp; Synergy Sports Fest 2026 &nbsp;&middot;&nbsp; System-generated document
  </div>
</div>
</body>
</html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isFontSubsettingEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('SynergyReceipt_' . $safeUID . '.pdf', ['Attachment' => true]);
