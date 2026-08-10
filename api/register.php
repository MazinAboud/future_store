<?php
/**
 * POST /api/register.php
 *
 * Body: full_name, email, phone, address, password, password_confirm?, device_label?
 * 201 -> { ok, token, user }
 *
 * Every validation rule below is copied from register.php in the project root,
 * value for value. They have to agree: if the app accepted a 6-character
 * password the website rejects, the same person would be able to create an
 * account on the phone and then be unable to explain why the website refuses
 * the same details. Any change to one file has to be mirrored in the other.
 */

require_once __DIR__ . '/_bootstrap.php';

only('POST');

$fullName = body_str('full_name');
$email    = body_str('email');
$phone    = body_str('phone');
$address  = body_str('address');
$password = (string)(body()['password'] ?? '');
$confirm  = (string)(body()['password_confirm'] ?? $password);

$errors = [];
if (mb_strlen($fullName) < 3)                       $errors['full_name'] = 'أدخل الاسم الكامل.';
if (mb_strlen($fullName) > 120)                     $errors['full_name'] = 'الاسم طويل جدًا (120 حرفًا كحد أقصى).';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors['email']     = 'بريد إلكتروني غير صحيح.';
if (mb_strlen($email) > 150)                        $errors['email']     = 'البريد الإلكتروني طويل جدًا.';
if (!validate_phone($phone))                        $errors['phone']     = 'رقم موبايل غير صحيح.';
if (mb_strlen($address) < 5)                        $errors['address']   = 'أدخل عنوان توصيل واضح.';
if (mb_strlen($address) > 255)                      $errors['address']   = 'العنوان طويل جدًا (255 حرفًا كحد أقصى).';
if (mb_strlen($password) < 8)                       $errors['password']  = 'كلمة السر 8 أحرف على الأقل.';
if ($password !== $confirm)                         $errors['password_confirm'] = 'كلمتا السر غير متطابقتين.';

if ($errors) {
    json_error('من فضلك صحّح البيانات المميّزة.', 422, $errors);
}

// email is UNIQUE in the schema. Checking first turns a raw duplicate-key
// exception into a message the app can paint under the email field.
$chk = db()->prepare('SELECT 1 FROM users WHERE email = ?');
$chk->execute([$email]);
if ($chk->fetchColumn()) {
    json_error('هذا البريد الإلكتروني مسجل بالفعل.', 422, ['email' => 'هذا البريد الإلكتروني مسجل بالفعل.']);
}

// Role is hard-coded, never read from the request. Accepting a role field here
// would let anyone with the endpoint URL mint themselves an admin account.
db()->prepare("
    INSERT INTO users (role, full_name, email, phone, password_hash, address)
    VALUES ('customer', ?, ?, ?, ?, ?)
")->execute([$fullName, $email, $phone, password_hash($password, PASSWORD_BCRYPT), $address]);

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([db()->lastInsertId()]);
$user = $stmt->fetch();

$token = issue_token($user, body_str('device_label'));

json_ok(['token' => $token, 'user' => shape_user($user)], 201);
