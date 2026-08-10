<?php
/**
 * GET /api/  -> the endpoint index.
 *
 * Exists so that opening the API folder in a browser answers "is this thing
 * alive and what does it serve" instead of returning a 403 from Options
 * -Indexes. Lists routes only — no data, no configuration, nothing that needs
 * a token to see.
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET');

json_ok([
    'name'    => SITE_NAME . ' API',
    'version' => '1.0',
    'auth'    => 'Authorization: Bearer <token>  (تُصدر من login أو register)',
    'endpoints' => [
        ['POST',       'register.php',        'إنشاء حساب عميل ويعيد توكنًا'],
        ['POST',       'login.php',           'تسجيل دخول عميل ويعيد توكنًا'],
        ['POST',       'logout.php',          'إبطال التوكن الحالي'],
        ['GET|PUT',    'me.php',              'عرض وتعديل بيانات الحساب'],
        ['POST',       'change-password.php', 'تغيير كلمة السر ويعيد توكنًا جديدًا'],
        ['GET',        'home.php',            'الماركات والأكثر مبيعًا'],
        ['GET',        'brands.php',          'كل الماركات'],
        ['GET',        'products.php',        'قائمة المنتجات: q, brand, min, max, storage, sort, page'],
        ['GET',        'product.php',         'تفاصيل منتج: slug أو id'],
        ['POST',       'order-create.php',    'إنشاء طلب'],
        ['GET',        'orders.php',          'طلبات صاحب التوكن'],
        ['GET',        'order.php',           'تفاصيل طلب: id'],
        // _selftest.php is intentionally NOT listed. It is a local diagnostic
        // that must be deleted before deployment, and advertising it from a
        // public endpoint would point an attacker straight at the one file
        // that reports the PHP version, the database name and the table list.
    ],
]);
