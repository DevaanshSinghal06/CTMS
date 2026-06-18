<?php

function generate_subject_initials(string $firstName, string $lastName): string
{
    $firstName = trim($firstName);
    $lastName = trim($lastName);

    $firstInitial = $firstName !== "" ? strtoupper(substr($firstName, 0, 1)) : "";
    $lastInitial = $lastName !== "" ? strtoupper(substr($lastName, 0, 1)) : "";

    return $firstInitial . $lastInitial;
}