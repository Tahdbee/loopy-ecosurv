<?php

/**
 * Server-side input validation helpers.
 * HTML attributes such as required / minlength / min are only a convenience for honest users;
 * every rule below is enforced again on the server.
 */

function product_categories(): array
{
    return ["Books", "Electronics", "Dorm Items", "Fashion", "Study Supplies", "Other"];
}

/**
 * Returns an error message, or null when the product fields are valid.
 */
function validate_product_fields(string $name, float $price, string $description, string $condition, string $category): ?string
{
    $nameLength = mb_strlen($name);
    if ($nameLength < 3 || $nameLength > 150) {
        return "Product name must be 3 to 150 characters.";
    }
    if (!in_array($category, product_categories(), true)) {
        return "Choose a valid category.";
    }
    if (!is_finite($price) || $price < 500 || $price > 100000000) {
        return "Price must be between Rp 500 and Rp 100.000.000.";
    }
    $conditionLength = mb_strlen($condition);
    if ($conditionLength < 2 || $conditionLength > 60) {
        return "Condition must be 2 to 60 characters.";
    }
    if (mb_strlen($description) > 2000) {
        return "Description must be 2000 characters or fewer.";
    }
    return null;
}

/**
 * Returns an error message, or null when the registration fields are valid.
 * (Faculty/major and the university e-mail domain are checked separately in register.php.)
 */
function validate_registration_fields(string $fullName, string $email, string $password): ?string
{
    $nameLength = mb_strlen($fullName);
    if ($nameLength < 2 || $nameLength > 120) {
        return "Full name must be 2 to 120 characters.";
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/', $fullName)) {
        return "Full name contains invalid characters.";
    }
    if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Enter a valid email address.";
    }
    $passwordLength = strlen($password);
    if ($passwordLength < 8 || $passwordLength > 72) {
        return "Password must be 8 to 72 characters.";
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return "Password must include at least one letter and one number.";
    }
    return null;
}
