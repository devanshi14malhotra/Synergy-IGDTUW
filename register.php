<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

mysqli_report(MYSQLI_REPORT_OFF);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method.');
}

// ====== Database connection ======
require_once __DIR__ . '/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ====== Styled error page helper ======
function renderErrorPage($message)
{
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Error | Synergy Sports Fest</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Montserrat, Arial, sans-serif; background: radial-gradient(circle at top right, #ffe083, #ffc333 55%); color: #0f172a; min-height: 100vh; display: grid; place-items: center; padding: 1.2rem; }
        .card { width: min(680px, 100%); background: #fff; border-radius: 20px; box-shadow: 0 24px 48px rgba(15,23,42,0.15); overflow: hidden; }
        .head { background: linear-gradient(125deg, #fee2e2, #fecaca); border-bottom: 1px solid #fca5a5; padding: 1.4rem 1.5rem; }
        .tag { margin: 0; font-size: 0.74rem; letter-spacing: 0.12em; text-transform: uppercase; color: #991b1b; font-weight: 800; }
        h1 { margin: 0.35rem 0 0; font-size: clamp(1.2rem, 2.6vw, 1.75rem); color: #7f1d1d; }
        .body { padding: 1.3rem 1.5rem 1.6rem; }
        .msg { margin: 0; color: #475569; line-height: 1.7; }
        .err-box { margin-top: 0.9rem; padding: 0.85rem 1rem; background: #fef2f2; border: 1px solid #fca5a5; border-left: 4px solid #ef4444; border-radius: 12px; font-weight: 700; color: #b91c1c; }
        .btn { display: inline-block; margin-top: 1.2rem; border: none; border-radius: 11px; padding: 0.72rem 1.1rem; text-decoration: none; font-weight: 700; background: linear-gradient(120deg, #f59e0b, #fde047); color: #111827; cursor: pointer; }
    </style>
</head>
<body>
    <section class="card">
        <header class="head">
            <p class="tag">Synergy Sports Fest 2026</p>
            <h1>Registration Error</h1>
        </header>
        <div class="body">
            <p class="msg">There was a problem with your submission. Please review the error below and try again.</p>
            <div class="err-box">' . $safe . '</div>
            <a class="btn" href="javascript:history.back()">Go Back & Fix</a>
        </div>
    </section>
</body>
</html>';
    exit;
}

// ====== Generate a unique 7-char UID, guaranteed not already in DB ======
function generateUID($conn, $length = 7)
{
    $chars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $maxIndex = strlen($chars) - 1;

    do {
        $uid = '';
        for ($i = 0; $i < $length; $i++) {
            $uid .= $chars[random_int(0, $maxIndex)];
        }
        $check = $conn->prepare("SELECT 1 FROM `2026_Participants` WHERE UID = ?");
        $check->bind_param("s", $uid);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
    } while ($exists);

    return $uid;
}

// ====== File upload helper with MIME type verification ======
function handleFileUpload($inputName, $relativeDir, $baseName, $isRequired = true)
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($isRequired) {
            renderErrorPage("No file uploaded for: {$inputName}. Please go back and attach the required file.");
        }
        return '';
    }

    if ($_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        renderErrorPage("File upload failed for {$inputName} (error code: {$_FILES[$inputName]['error']}). Please try again.");
    }

    // Validate file size
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($_FILES[$inputName]['size'] > $maxSize) {
        renderErrorPage("The file uploaded for {$inputName} exceeds the 5MB size limit. Please compress or resize it and try again.");
    }

    // Validate by actual MIME type (not just extension)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($_FILES[$inputName]['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        renderErrorPage("Invalid file type for {$inputName}. Only JPG, PNG, and PDF files are accepted.");
    }

    // Map MIME to safe extension
    $mimeToExt = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    $fileExt = $mimeToExt[$mimeType];

    $uploadDir = __DIR__ . '/' . trim($relativeDir, '/\\') . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName);
    if (!$safeBase) $safeBase = 'participant';
    $safeBase = substr($safeBase, 0, 50);

    // Use random bytes for uniqueness instead of time() alone
    $uniquePart  = bin2hex(random_bytes(8));
    $filename     = $safeBase . '_' . $inputName . '_' . $uniquePart . '.' . $fileExt;
    $relativePath = trim($relativeDir, '/\\') . '/' . $filename;

    if (strlen($relativePath) > 255) {
        renderErrorPage("Internal path error for {$inputName}. Please contact support.");
    }

    if (!move_uploaded_file($_FILES[$inputName]['tmp_name'], $uploadDir . $filename)) {
        renderErrorPage("Failed to save the uploaded file for {$inputName}. Please try again.");
    }

    return $relativePath;
}

// ====== Receipt page ======
function renderReceiptPage($uid, $participantName, $affiliation, $mobileNo, $emailId, $finalSports, $role, $totalAmount, $transactionID)
{
    $safeUid         = htmlspecialchars($uid,             ENT_QUOTES, 'UTF-8');
    $safeName        = htmlspecialchars($participantName, ENT_QUOTES, 'UTF-8');
    $safeAffiliation = htmlspecialchars($affiliation,     ENT_QUOTES, 'UTF-8');
    $safeMobile      = htmlspecialchars($mobileNo,        ENT_QUOTES, 'UTF-8');
    $safeEmail       = htmlspecialchars($emailId,         ENT_QUOTES, 'UTF-8');
    $safeRole        = htmlspecialchars($role,            ENT_QUOTES, 'UTF-8');
    $safeAmount      = htmlspecialchars((string)$totalAmount, ENT_QUOTES, 'UTF-8');
    $safeTxn         = htmlspecialchars($transactionID ?? '', ENT_QUOTES, 'UTF-8');

    $sportsListItems = '';
    foreach ($finalSports as $sport) {
        $sportsListItems .= '<li>' . htmlspecialchars($sport, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    if ($sportsListItems === '') $sportsListItems = '<li>No sport selected</li>';

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Receipt | Synergy Sports Fest</title>
    <style>
        :root { --line: #e2e8f0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Montserrat, Arial, sans-serif; background: radial-gradient(circle at top right, #ffe083, #ffc333 55%); color: #0f172a; min-height: 100vh; display: grid; place-items: center; padding: 1.4rem; }
        .bill { width: min(680px, 100%); background: #fff; border: 1px solid var(--line); border-radius: 20px; box-shadow: 0 24px 48px rgba(15,23,42,0.15); overflow: hidden; }
        .head { background: linear-gradient(125deg, #fff4cc, #ffd86b); border-bottom: 1px solid var(--line); padding: 1.4rem 1.5rem; }
        .tag { margin: 0; font-size: 0.74rem; letter-spacing: 0.12em; text-transform: uppercase; color: #ea580c; font-weight: 800; }
        h1 { margin: 0.35rem 0 0; font-size: clamp(1.2rem, 2.6vw, 1.75rem); }
        .body { padding: 1.3rem 1.5rem 1.6rem; }
        .uid-badge { display: inline-block; margin-bottom: 1rem; padding: 0.5rem 1rem; background: #fef3c7; border: 1.5px solid #fbbf24; border-radius: 10px; font-size: 1.1rem; font-weight: 800; letter-spacing: 0.1em; color: #92400e; }
        .uid-note { font-size: 0.8rem; font-weight: 500; color: #b45309; margin-left: 0.5rem; }
        .section { margin-top: 0.85rem; padding: 0.85rem 0.9rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
        .section-title { margin: 0 0 0.5rem; font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: #94a3b8; font-weight: 700; }
        .row { margin: 0.3rem 0; color: #1e293b; line-height: 1.6; }
        .row b { color: #0f172a; }
        ul { margin: 0.3rem 0 0 1.1rem; padding: 0; }
        li { margin: 0.2rem 0; }
        .amount-row { margin-top: 1rem; padding-top: 0.85rem; border-top: 2px dashed #fbbf24; font-size: 1.1rem; font-weight: 800; color: #92400e; }
        .actions { display: flex; gap: 0.7rem; flex-wrap: wrap; margin-top: 1.2rem; }
        .btn { border: none; border-radius: 11px; padding: 0.72rem 1.1rem; text-decoration: none; font-weight: 700; display: inline-block; cursor: pointer; font-size: 0.95rem; }
        .btn-primary { background: linear-gradient(120deg, #f59e0b, #fde047); color: #111827; }
        .btn-secondary { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
        @media print { .actions { display: none; } body { background: #fff; } .bill { box-shadow: none; border: none; } }
    </style>
</head>
<body>
    <section class="bill">
        <header class="head">
            <p class="tag">Synergy Sports Fest 2026</p>
            <h1>Registration Receipt</h1>
        </header>
        <div class="body">
            <div>
                <span class="uid-badge">UID: ' . $safeUid . '</span>
                <span class="uid-note">Save this for future reference</span>
            </div>
            <div class="section">
                <p class="section-title">Participant Details</p>
                <p class="row"><b>Name:</b> ' . $safeName . '</p>
                <p class="row"><b>College / Institute:</b> ' . $safeAffiliation . '</p>
                <p class="row"><b>Mobile:</b> +91 ' . $safeMobile . '</p>
                <p class="row"><b>Email:</b> ' . $safeEmail . '</p>'
                . ($safeRole !== '' ? '<p class="row"><b>Team Role:</b> ' . $safeRole . '</p>' : '') . '
            </div>
            <div class="section">
                <p class="section-title">Sports Registered</p>
                <ul>' . $sportsListItems . '</ul>
            </div>'
            . ($safeTxn !== '' ? '
            <div class="section">
                <p class="section-title">Payment</p>
                <p class="row"><b>Transaction ID:</b> ' . $safeTxn . '</p>
            </div>' : '') . '
            <p class="amount-row">Total Amount Paid: &#8377; ' . $safeAmount . '</p>
            <div class="actions">
                <a class="btn btn-primary" href="download_receipt.php?uid=' . $safeUid . '" target="_blank">Download Receipt (PDF)</a>
                <button class="btn btn-secondary" onclick="window.print()">Print</button>
                <a class="btn btn-secondary" href="ignite26.html">Go To Ignite 2026</a>
            </div>
        </div>
    </section>
</body>
</html>';
}

// ====== Collect and validate form fields ======
$EnrollmentNo = isset($_POST['EnrollmentNo']) ? trim($_POST['EnrollmentNo']) : '';
$Name         = isset($_POST['Name'])         ? trim($_POST['Name'])         : '';
$Affiliation  = isset($_POST['Affiliation'])  ? trim($_POST['Affiliation'])  : '';
$Course       = isset($_POST['Course'])       ? trim($_POST['Course'])       : '';
$MobileNo     = isset($_POST['MobileNo'])     ? trim($_POST['MobileNo'])     : '';
$EmailID      = isset($_POST['EmailID'])      ? trim($_POST['EmailID'])      : '';

// Required field presence
if ($EnrollmentNo === '') renderErrorPage('Enrollment number is required.');
if ($Name         === '') renderErrorPage('Full name is required.');
if ($Affiliation  === '') renderErrorPage('Affiliation / college name is required.');

// Course: must be one of the allowed values
$allowedCourses = ['B.Tech', 'BCA', 'BBA', 'M.Tech', 'MCA', 'MBA', 'Other'];
if (!in_array($Course, $allowedCourses, true)) {
    renderErrorPage('Invalid course selected. Please go back and choose a valid option.');
}

// Mobile: exactly 10 digits
$MobileNo = preg_replace('/\D/', '', $MobileNo);
if (strlen($MobileNo) !== 10) {
    renderErrorPage('Mobile number must be exactly 10 digits.');
}

// Email: server-side format validation
if (!filter_var($EmailID, FILTER_VALIDATE_EMAIL)) {
    renderErrorPage('The email address entered does not appear to be valid. Please check and try again.');
}

// ====== Coach ======
$CoachComingRaw = isset($_POST['CoachComing']) ? trim($_POST['CoachComing']) : '';
$CoachComing    = ($CoachComingRaw === 'Yes') ? 'Yes' : (($CoachComingRaw === 'No') ? 'No' : '');
$CoachFullName  = isset($_POST['CoachFullName']) ? trim($_POST['CoachFullName']) : '';
$CoachMobileNo  = preg_replace('/\D/', '', isset($_POST['CoachMobileNo']) ? trim($_POST['CoachMobileNo']) : '');

if ($CoachComing === '') {
    renderErrorPage('Please select whether a coach is accompanying you.');
}
if ($CoachComing === 'Yes') {
    if ($CoachFullName === '') {
        renderErrorPage('Coach full name is required when a coach is accompanying you.');
    }
    if (strlen($CoachMobileNo) !== 10) {
        renderErrorPage('Coach mobile number must be exactly 10 digits.');
    }
} else {
    $CoachFullName = '';
    $CoachMobileNo = '';
}

// ====== Sports selection ======
$individualSports   = [];
if (isset($_POST['A100']))    $individualSports[] = '100m Sprint';
if (isset($_POST['A200']))    $individualSports[] = '200m Sprint';
if (isset($_POST['A400']))    $individualSports[] = '400m Sprint';
if (isset($_POST['ShotPut'])) $individualSports[] = 'Shot Put';
if (isset($_POST['Chess']))   $individualSports[] = 'Chess';

$allowedRelayValues = ['4 x 100m'];
if (isset($_POST['Relay[]']) && is_array($_POST['Relay[]'])) {
    $validRelay = array_intersect($_POST['Relay[]'], $allowedRelayValues);
    if (count($validRelay) > 0) $individualSports[] = 'Relay 4 x 100m';
}

$teamSportNames = [];
if (isset($_POST['BBOption'])) $teamSportNames[] = 'Basketball';
if (isset($_POST['VBOption'])) $teamSportNames[] = 'Volleyball';
if (isset($_POST['KKOption'])) $teamSportNames[] = 'Kho Kho';
if (isset($_POST['FBOption'])) $teamSportNames[] = 'Football';

// Server-side check: at least one sport must be selected
if (count($individualSports) === 0 && count($teamSportNames) === 0) {
    renderErrorPage('Please select at least one sport to register.');
}

$hasTeamSport = count($teamSportNames) > 0;

// ====== Team role ======
$TeamRoleRaw = isset($_POST['TeamRole']) ? trim($_POST['TeamRole']) : '';
$TeamRole    = ($TeamRoleRaw === 'Captain') ? 'Captain' : (($TeamRoleRaw === 'Member') ? 'Member' : '');

if ($hasTeamSport && $TeamRole === '') {
    renderErrorPage('Please select Team Captain or Team Member for your team sport registration.');
}

$CaptainUID = '';
if ($TeamRole === 'Member') {
    $CaptainUID = isset($_POST['CaptainUID']) ? trim($_POST['CaptainUID']) : '';

    if ($CaptainUID === '') {
        renderErrorPage("Captain's UID is required for team members.");
    }

    // Validate Captain UID exists and is actually a Captain
    $capStmt = $conn->prepare("
        SELECT UID, Sports, TeamRole
        FROM `2026_Participants`
        WHERE UID = ? AND TeamRole = 'Captain'
        LIMIT 1
    ");
    if (!$capStmt) {
        renderErrorPage('Database error during captain verification. Please try again.');
    }
    $capStmt->bind_param('s', $CaptainUID);
    $capStmt->execute();
    $capResult = $capStmt->get_result();
    $captainRow = $capResult->fetch_assoc();
    $capStmt->close();

    if (!$captainRow) {
        renderErrorPage(
            'The Captain UID you entered (' . htmlspecialchars($CaptainUID, ENT_QUOTES, 'UTF-8') . ') was not found or does not belong to a registered captain. ' .
            'Please check the UID with your captain and try again.'
        );
    }

    // Check that the captain is registered for at least one of the same team sports
    $captainSports = json_decode((string) $captainRow['Sports'], true) ?? [];

    // Strip role labels from captain sports for comparison, e.g. "Basketball (Captain)" -> "Basketball"
    $captainSportsClean = array_map(function ($sport) {
        return trim((string) preg_replace('/\s*\(.*?\)/', '', (string) $sport));
    }, $captainSports);

    // Check overlap with member's selected team sports
    $memberTeamSportsClean = $teamSportNames;
    $overlap = array_intersect($memberTeamSportsClean, $captainSportsClean);

    if (count($overlap) === 0) {
        renderErrorPage(
            'The captain (UID: ' . htmlspecialchars($CaptainUID, ENT_QUOTES, 'UTF-8') . ') is not registered for the same sport(s) you selected. ' .
            'Please make sure you and your captain are registering for the same team event.'
        );
    }
}

// ====== Build sports list ======
$finalSports = $individualSports;
foreach ($teamSportNames as $sport) {
    $finalSports[] = $sport . ($TeamRole !== '' ? ' (' . $TeamRole . ')' : '');
}

$sportsJSON = json_encode($finalSports);

// ====== Calculate total amount ======
$totalAmount = count($individualSports) * 120;
if ($TeamRole === 'Captain') {
    $totalAmount += count($teamSportNames) * 1700;
}

$isPaymentRequired = $totalAmount > 0;

// ====== Transaction ID ======
$TransactionID = isset($_POST['TransactionID']) ? trim($_POST['TransactionID']) : '';
if ($isPaymentRequired && $TransactionID === '') {
    renderErrorPage('Transaction ID is required. Please complete the payment and enter your transaction ID.');
}
if (!$isPaymentRequired) $TransactionID = null;

// ====== File uploads ======
$IDCardPath            = handleFileUpload('IDCard',            'uploads/idcards',             $EnrollmentNo, true);
$paymentUploadDir      = 'uploads/payments';
$PaymentScreenshotPath = handleFileUpload('PaymentScreenshot', $paymentUploadDir, $EnrollmentNo, $isPaymentRequired);
if (!$isPaymentRequired) $PaymentScreenshotPath = null;

// ====== Generate unique UID ======
$uid = generateUID($conn);

// ====== Insert into DB ======
$stmt = $conn->prepare("
    INSERT INTO `2026_Participants`
        (UID, EnrollmentNo, Name, Affiliation, Course, MobileNo, EmailID,
         CoachComing, CoachFullName, CoachMobileNo,
         Sports, TeamRole, CaptainUID,
         TotalAmount, TransactionID,
         IDCardPath, PaymentScreenshotPath)
    VALUES
        (?, ?, ?, ?, ?, ?, ?,
         ?, ?, ?,
         ?, ?, ?,
         ?, ?,
         ?, ?)
");

if (!$stmt) {
    renderErrorPage('A database error occurred. Please try again. (Prepare failed)');
}

$teamRoleParam = $TeamRole   !== '' ? $TeamRole   : null;
$captainParam  = $CaptainUID !== '' ? $CaptainUID : null;

$stmt->bind_param(
    "sssssssssssssisss",
    $uid, $EnrollmentNo, $Name, $Affiliation, $Course, $MobileNo, $EmailID,
    $CoachComing, $CoachFullName, $CoachMobileNo,
    $sportsJSON, $teamRoleParam, $captainParam,
    $totalAmount, $TransactionID,
    $IDCardPath, $PaymentScreenshotPath
);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    renderReceiptPage($uid, $Name, $Affiliation, $MobileNo, $EmailID, $finalSports, $TeamRole, $totalAmount, $TransactionID ?? '');
    exit;
} else {
    $errno = $stmt->errno;
    $stmt->close();
    $conn->close();
    if ($errno === 1062) {
        // UID collision race condition (extremely rare)
        renderErrorPage('A registration conflict occurred. Please try submitting again.');
    }
    renderErrorPage('A database error occurred while saving your registration. Please try again.');
}
?>