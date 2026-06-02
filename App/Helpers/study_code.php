<?php
// App/Helpers/study_code.php

function generate_study_code(PDO $pdo): string
{
    $year = date("Y");

    $stmt = $pdo->prepare("
        SELECT study_code
        FROM studies
        WHERE study_code LIKE ?
        ORDER BY study_code DESC
        LIMIT 1
    ");

    $stmt->execute(["STUDY-" . $year . "-%"]);
    $lastCode = $stmt->fetchColumn();

    if (!$lastCode) {
        return "STUDY-" . $year . "-001";
    }

    $parts = explode("-", $lastCode);
    $lastNumber = (int) end($parts);
    $nextNumber = $lastNumber + 1;

    return "STUDY-" . $year . "-" . str_pad((string)$nextNumber, 3, "0", STR_PAD_LEFT);
}