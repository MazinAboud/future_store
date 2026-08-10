<?php
/**
 * GET  /api/me.php   -> { ok, user }
 * PUT  /api/me.php   -> { ok, user }   Body: full_name, phone, address
 *
 * The PUT rules match account/profile.php's "update_info" branch exactly.
 * Note what is absent: email and role. The website's own profile form does not
 * let a customer change either, so neither does this. Accepting a role field
 * would be a privilege-escalation hole; accepting an email would need the
 * uniqueness handling the website deliberately keeps on the admin side.
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET', 'PUT', 'POST'); // POST accepted so clients without PUT support still work

$me = require_customer();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_ok(['user' => shape_user($me)]);
}

$fullName = body_str('full_name');
$phone    = body_str('phone');
$address  = body_str('address');

$errors = [];
if (mb_strlen($fullName) < 3)   $errors['full_name'] = 'أدخل الاسم الكامل.';
if (mb_strlen($fullName) > 120) $errors['full_name'] = 'الاسم طويل جدًا (120 حرفًا كحد أقصى).';
if (!validate_phone($phone))    $errors['phone']     = 'رقم موبايل غير صحيح.';
if (mb_strlen($address) < 5)    $errors['address']   = 'أدخل عنوانًا واضحًا.';
if (mb_strlen($address) > 255)  $errors['address']   = 'العنوان طويل جدًا (255 حرفًا كحد أقصى).';

if ($errors) {
    json_error('من فضلك صحّح البيانات المميّزة.', 422, $errors);
}

// WHERE id = the id from the token, never from the request body. This is the
// one line that stops a customer editing someone else's profile by guessing
// an id, and it is why no endpoint in this folder reads an id for "who am I".
db()->prepare('UPDATE users SET full_name = ?, phone = ?, address = ? WHERE id = ?')
    ->execute([$fullName, $phone, $address, $me['id']]);

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$me['id']]);

json_ok(['user' => shape_user($stmt->fetch()), 'message' => 'تم تحديث بياناتك بنجاح.']);
