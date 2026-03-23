<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

$uidInput        = '';
$enrollmentInput = '';
$mobileInput     = '';
$errorMessage    = '';
$record          = null;
$sportsList      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uidInput        = isset($_POST['uid'])        ? strtoupper(trim($_POST['uid'])) : '';
    $enrollmentInput = isset($_POST['enrollment']) ? trim($_POST['enrollment'])      : '';
    $mobileInput     = isset($_POST['mobile'])     ? trim($_POST['mobile'])          : '';

    // Strip leading +91 or 0 from mobile if entered that way
    $mobileInput = preg_replace('/^(\+91|0)/', '', $mobileInput);

    if ($uidInput === '' && $enrollmentInput === '') {
        $errorMessage = 'Please enter either a UID or an Enrollment Number.';
    } elseif ($enrollmentInput !== '' && $mobileInput === '') {
        // Mobile is mandatory when searching by enrollment number
        $errorMessage = 'Please enter your Mobile Number to verify your identity when searching by Enrollment Number.';
    } else {

        // --- Search by UID (no extra verification needed — UIDs are unique) ---
        if ($uidInput !== '') {
            $stmt = $conn->prepare(
                'SELECT UID, EnrollmentNo, Name, Affiliation, Course, MobileNo, EmailID,
                        Sports, TeamRole, CaptainUID, TotalAmount, TransactionID
                 FROM `2026_Participants`
                 WHERE UID = ?
                 LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('s', $uidInput);
                $stmt->execute();
                $result = $stmt->get_result();
                $record = $result ? $result->fetch_assoc() : null;
                $stmt->close();
            }
        }

        // --- Search by Enrollment Number + Mobile (disambiguation) ---
        if (!$record && $enrollmentInput !== '' && $mobileInput !== '') {
            $stmt = $conn->prepare(
                'SELECT UID, EnrollmentNo, Name, Affiliation, Course, MobileNo, EmailID,
                        Sports, TeamRole, CaptainUID, TotalAmount, TransactionID
                 FROM `2026_Participants`
                 WHERE EnrollmentNo = ? AND MobileNo = ?
                 LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ss', $enrollmentInput, $mobileInput);
                $stmt->execute();
                $result = $stmt->get_result();
                $record = $result ? $result->fetch_assoc() : null;
                $stmt->close();
            }

            // If no match, check whether the enrollment number exists at all
            // so we can give a more helpful error message
            if (!$record) {
                $stmtCheck = $conn->prepare(
                    'SELECT COUNT(*) AS cnt FROM `2026_Participants` WHERE EnrollmentNo = ?'
                );
                if ($stmtCheck) {
                    $stmtCheck->bind_param('s', $enrollmentInput);
                    $stmtCheck->execute();
                    $resultCheck = $stmtCheck->get_result();
                    $rowCheck    = $resultCheck ? $resultCheck->fetch_assoc() : null;
                    $stmtCheck->close();

                    if ($rowCheck && (int) $rowCheck['cnt'] > 0) {
                        // Enrollment exists but mobile didn't match
                        $errorMessage = 'The mobile number entered does not match our records for this Enrollment Number. Please check and try again.';
                    } else {
                        // Enrollment doesn't exist at all
                        $errorMessage = 'No registration found for the provided Enrollment Number.';
                    }
                }
            }
        }

        // --- UID search returned nothing ---
        if (!$record && $errorMessage === '' && $uidInput !== '') {
            $errorMessage = 'No registration found for the provided UID.';
        }

        // --- Decode sports JSON ---
        if ($record) {
            $decodedSports = json_decode((string) $record['Sports'], true);
            if (is_array($decodedSports)) {
                $sportsList = $decodedSports;
            }
        }
    }
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Lookup | Synergy Sports Fest</title>
    <style>
        :root {
            --bg: #fff9e5;
            --card: #ffffff;
            --ink: #0f172a;
            --muted: #475569;
            --line: #e2e8f0;
            --accent: #f59e0b;
            --danger-bg: #fef2f2;
            --danger-line: #fca5a5;
            --danger-ink: #b91c1c;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Montserrat, Arial, sans-serif;
            background: radial-gradient(circle at top right, #ffe083, #ffc333 55%);
            color: var(--ink);
            min-height: 100vh;
            padding: 1.2rem;
        }

        .page {
            width: min(760px, 100%);
            margin: 0 auto;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }

        .head {
            padding: 1.25rem 1.35rem;
            background: linear-gradient(125deg, #fff4cc, #ffd86b);
            border-bottom: 1px solid var(--line);
        }

        .tag {
            margin: 0;
            font-size: 0.74rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #ea580c;
            font-weight: 800;
        }

        h1 {
            margin: 0.35rem 0 0;
            font-size: clamp(1.2rem, 2.8vw, 1.75rem);
        }

        .body {
            padding: 1.2rem 1.35rem 1.35rem;
        }

        .lookup-form {
            display: grid;
            gap: 0.75rem;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        label {
            font-size: 0.9rem;
            font-weight: 700;
        }

        input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 0.72rem 0.82rem;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.15s;
        }

        input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .hint {
            margin: 0;
            color: var(--muted);
            font-size: 0.84rem;
        }

        /* Mobile field visibility toggle */
        .mobile-field {
            display: none; /* hidden by default */
        }

        .mobile-field.visible {
            display: flex;
        }

        .mobile-note {
            margin: 0;
            font-size: 0.8rem;
            color: var(--muted);
            font-style: italic;
        }

        .btn {
            margin-top: 0.25rem;
            border: none;
            border-radius: 12px;
            padding: 0.78rem 1rem;
            background: linear-gradient(120deg, #f59e0b, #fde047);
            color: #111827;
            font-size: 0.96rem;
            font-weight: 800;
            cursor: pointer;
            transition: opacity 0.15s;
        }

        .btn:hover { opacity: 0.88; }

        .error {
            margin-top: 0.9rem;
            padding: 0.75rem 0.9rem;
            background: var(--danger-bg);
            border: 1px solid var(--danger-line);
            color: var(--danger-ink);
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .receipt {
            margin-top: 1rem;
            padding: 0.95rem;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
        }

        .receipt h2 {
            margin: 0 0 0.75rem;
            color: #92400e;
            font-size: 1.1rem;
        }

        .row {
            margin: 0.22rem 0;
            line-height: 1.5;
        }

        .sports {
            margin: 0.35rem 0 0 1.1rem;
            padding: 0;
        }

        .sports li {
            margin: 0.2rem 0;
        }

        .amount {
            margin-top: 0.85rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #f59e0b;
            font-size: 1.02rem;
            font-weight: 800;
            color: #92400e;
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="card">
            <header class="head">
                <p class="tag">Synergy Sports Fest 2026</p>
                <h1>Receipt Lookup</h1>
            </header>
            <div class="body">
                <form class="lookup-form" method="POST" action="lookup.php" autocomplete="on">

                    <div class="field">
                        <label for="uid">UID</label>
                        <input id="uid" type="text" name="uid" maxlength="7"
                               placeholder="e.g. A9K2P7Q"
                               value="<?php echo h($uidInput); ?>">
                    </div>

                    <p class="hint">OR</p>

                    <div class="field">
                        <label for="enrollment">Enrollment Number</label>
                        <input id="enrollment" type="text" name="enrollment"
                               placeholder="e.g. SYN2026-145"
                               value="<?php echo h($enrollmentInput); ?>">
                    </div>

                    <!-- Mobile field — shown via JS when enrollment is filled, always visible on re-render if enrollment was submitted -->
                    <div class="field mobile-field<?php echo ($enrollmentInput !== '') ? ' visible' : ''; ?>" id="mobileField">
                        <label for="mobile">Mobile Number <span style="color:#ea580c;">*</span></label>
                        <input id="mobile" type="tel" name="mobile" maxlength="15"
                               placeholder="10-digit number, without +91"
                               value="<?php echo h($mobileInput); ?>">
                        <p class="mobile-note">Required to verify your identity when searching by Enrollment Number.</p>
                    </div>

                    <button class="btn" type="submit">Find Receipt</button>
                </form>

                <?php if ($errorMessage !== ''): ?>
                    <div class="error"><?php echo h($errorMessage); ?></div>
                <?php endif; ?>

                <?php if ($record): ?>
                    <section class="receipt">
                        <h2>Registration Receipt</h2>
                        <p class="row"><strong>UID:</strong> <?php echo h($record['UID']); ?></p>
                        <p class="row"><strong>Enrollment Number:</strong> <?php echo h($record['EnrollmentNo']); ?></p>
                        <p class="row"><strong>Name:</strong> <?php echo h($record['Name']); ?></p>
                        <p class="row"><strong>College / Institute:</strong> <?php echo h($record['Affiliation']); ?></p>
                        <p class="row"><strong>Mobile:</strong> +91 <?php echo h($record['MobileNo']); ?></p>
                        <p class="row"><strong>Email:</strong> <?php echo h($record['EmailID']); ?></p>
                        <?php if (!empty($record['TeamRole'])): ?>
                            <p class="row"><strong>Team Role:</strong> <?php echo h($record['TeamRole']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($record['CaptainUID'])): ?>
                            <p class="row"><strong>Captain UID:</strong> <?php echo h($record['CaptainUID']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($record['TransactionID'])): ?>
                            <p class="row"><strong>Transaction ID:</strong> <?php echo h($record['TransactionID']); ?></p>
                        <?php endif; ?>
                        <p class="row"><strong>Sports Selected:</strong></p>
                        <ul class="sports">
                            <?php if (!empty($sportsList)): ?>
                                <?php foreach ($sportsList as $sport): ?>
                                    <li><?php echo h($sport); ?></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li>Sports data not available for this registration.</li>
                            <?php endif; ?>
                        </ul>
                        <p class="amount">Total Amount: &#8377; <?php echo h($record['TotalAmount']); ?></p>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        // Show the mobile field as soon as the user starts typing in the enrollment field
        const enrollmentInput = document.getElementById('enrollment');
        const mobileField     = document.getElementById('mobileField');

        function toggleMobile() {
            if (enrollmentInput.value.trim() !== '') {
                mobileField.classList.add('visible');
            } else {
                mobileField.classList.remove('visible');
                // Clear mobile value when enrollment is cleared so it isn't submitted silently
                document.getElementById('mobile').value = '';
            }
        }

        enrollmentInput.addEventListener('input', toggleMobile);
    </script>
</body>
</html>
<?php
mysqli_close($conn);
?>