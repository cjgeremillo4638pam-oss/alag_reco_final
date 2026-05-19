<?php
/** @var array $prescription */
$p = $prescription;
$medications = is_string($p['medications']) ? json_decode($p['medications'], true) : $p['medications'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Prescription <?= htmlspecialchars($p['prescription_number']) ?></title>
    <style>
        /* 3R photo print size: 3.5in x 5in (≈ 89mm x 127mm). Margins kept
           tight so prescription content fits the small page. */
        @page { size: 3.5in 5in; margin: 0.2in; }
        * { font-family: 'Georgia', serif; box-sizing: border-box; }
        html, body { width: 3.5in; }
        body { padding: 6px 8px; margin: 0; font-size: 9px; line-height: 1.25; color: #222; }
        .header { border-bottom: 2px double #FF6B9A; padding-bottom: 5px; margin-bottom: 6px; }
        .header h1 { color: #FF6B9A; margin: 0; font-size: 13px; }
        .header p { margin: 1px 0; color: #666; font-size: 8px; }
        .rx-symbol { font-size: 22px; color: #FF6B9A; font-weight: bold; font-family: serif; line-height: 1; }
        .patient-info { background: #f9f9f9; padding: 5px 6px; border-radius: 4px; margin-bottom: 6px; }
        .patient-info p { margin: 1px 0; font-size: 8px; }
        .medications { margin: 6px 0; }
        .medications h3 { font-size: 10px; margin: 4px 0; }
        .medications table { width: 100%; border-collapse: collapse; }
        .medications th { background: #FF6B9A; color: white; padding: 2px 4px; text-align: left; font-size: 8px; }
        .medications td { padding: 2px 4px; border-bottom: 1px solid #eee; font-size: 8px; vertical-align: top; }
        .footer { border-top: 1px solid #ddd; padding-top: 6px; margin-top: 10px; font-size: 8px; }
        .signature-line { border-top: 1px solid #333; width: 110px; text-align: center; padding-top: 2px; font-size: 7px; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:center;margin-bottom:20px;">
        <button onclick="window.print()" style="background:#FF6B9A;color:white;border:none;padding:10px 30px;border-radius:8px;font-size:16px;cursor:pointer;">
            Print Prescription
        </button>
    </div>

    <div class="header">
        <div style="display:flex;justify-content:space-between;align-items:start;">
            <div>
                <h1>PediCare Clinic</h1>
                <p>123 Health St, Medical City, Metro Manila</p>
                <p>Tel: +63 917 123 4567 | info@pedicare.com</p>
            </div>
            <div style="text-align:right;">
                <div class="rx-symbol">Rx</div>
                <p><strong><?= htmlspecialchars($p['prescription_number']) ?></strong></p>
            </div>
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
        <div>
            <strong>Prescribing Doctor:</strong> Dr. <?= htmlspecialchars($p['doctor_first_name'] . ' ' . $p['doctor_last_name']) ?><br>
            <small><?= htmlspecialchars($p['specialization'] ?? '') ?> | License: <?= htmlspecialchars($p['license_number'] ?? '') ?></small>
        </div>
        <div style="text-align:right;">
            <strong>Date:</strong> <?= date('F j, Y', strtotime($p['prescription_date'])) ?>
        </div>
    </div>

    <div class="patient-info">
        <p><strong>Patient:</strong> <?= htmlspecialchars($p['patient_first_name'] . ' ' . $p['patient_last_name']) ?></p>
        <p><strong>Date of Birth:</strong> <?= date('F j, Y', strtotime($p['patient_dob'])) ?> (Age: <?= (int)((time() - strtotime($p['patient_dob'])) / 31557600) ?> years)</p>
        <p><strong>Gender:</strong> <?= $p['patient_gender'] ?> | <strong>Weight:</strong> <?= $p['patient_weight'] ? $p['patient_weight'] . ' kg' : 'N/A' ?></p>
        <p><strong>Allergies:</strong> <?= htmlspecialchars($p['patient_allergies'] ?: 'None reported') ?></p>
        <p><strong>Parent/Guardian:</strong> <?= htmlspecialchars($p['parent_first_name'] . ' ' . $p['parent_last_name']) ?> | <?= htmlspecialchars($p['parent_phone'] ?? '') ?></p>
    </div>

    <?php if ($p['diagnosis']): ?>
    <p><strong>Diagnosis:</strong> <?= htmlspecialchars($p['diagnosis']) ?></p>
    <?php endif; ?>

    <div class="medications">
        <h3>Medications</h3>
        <?php if (is_array($medications)): ?>
        <table>
            <thead><tr><th>#</th><th>Medication</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Instructions</th></tr></thead>
            <tbody>
            <?php foreach ($medications as $i => $med): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($med['name'] ?? '') ?></strong></td>
                <td><?= htmlspecialchars($med['dosage'] ?? '') ?></td>
                <td><?= htmlspecialchars($med['frequency'] ?? '') ?></td>
                <td><?= htmlspecialchars($med['duration'] ?? '') ?></td>
                <td><?= htmlspecialchars($med['instructions'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><?= htmlspecialchars((string) $medications) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($p['notes']): ?>
    <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($p['notes'])) ?></p>
    <?php endif; ?>

    <div class="footer">
        <div style="display:flex;justify-content:space-between;">
            <div>
                <p style="font-size:12px;color:#999;">This prescription is valid for 30 days from the date of issue.</p>
            </div>
            <div class="signature-line">
                Dr. <?= htmlspecialchars($p['doctor_first_name'] . ' ' . $p['doctor_last_name']) ?><br>
                <small>License: <?= htmlspecialchars($p['license_number'] ?? '') ?></small>
            </div>
        </div>
    </div>
</body>
</html>
