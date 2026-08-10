<?php
/**
 * نموذج الإعداد — انسخه إلى includes/config.php ثم عدّل القيم.
 *
 *     copy includes\config.example.php includes\config.php     (Windows)
 *     cp   includes/config.example.php includes/config.php     (Linux/macOS)
 *
 * هذا الملف وحده هو المرفوع إلى Git؛ config.php مستثنى في .gitignore لأنه
 * يحمل كلمة سر حقيقية. القيم أدناه وهمية عمدًا: لن يعمل المشروع بها حتى
 * تستبدلها، وهذا مقصود — فملف يعمل بقيم افتراضية هو ملف يُنشر بها.
 */

/* ── قاعدة البيانات ────────────────────────────────────────────────────────
 * أنشئ مستخدمًا مخصّصًا للتطبيق، ولا تستخدم root أبدًا. التطبيق لا ينفّذ أي
 * أوامر بنية (CREATE/ALTER/DROP) وقت التشغيل، فهذه الصلاحيات الأربع تكفيه —
 * وتعني أن أي ثغرة مستقبلية لا تستطيع حذف جدول ولا قراءة قواعد بيانات أخرى:
 *
 *     CREATE USER 'fstore_user'@'localhost' IDENTIFIED BY 'ضع-كلمة-سر-قوية';
 *     GRANT SELECT, INSERT, UPDATE, DELETE ON future_store.* TO 'fstore_user'@'localhost';
 *     FLUSH PRIVILEGES;
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'future_store');
define('DB_USER', 'CHANGE_ME_db_user');
define('DB_PASS', 'CHANGE_ME_strong_password');

/* ── إعدادات المتجر ───────────────────────────────────────────────────────*/
define('SITE_NAME', 'Future Store');
define('SHIPPING_FEE', 8.00);
define('CURRENCY', '$');

/* ── بيانات التواصل الظاهرة في تذييل الموقع ───────────────────────────────*/
define('SITE_EMAIL', 'support@example.com');
define('SITE_PHONE', '+000 00 000 0000');

/* ── مسار الموقع — يُكتشف تلقائيًا، لا تعدّله ──────────────────────────────
 * يُشتق من موقع المشروع الفعلي تحت جذر الخادم، فتعمل الروابط والصور سواء
 * فُتح المشروع على http://localhost/ أو http://localhost/future_store/ دون
 * أن يضطر أحد لتعديل هذا الملف بعد نقل المجلد أو إعادة تسميته.
 */
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot     = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$basePath    = ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0)
    ? substr($projectRoot, strlen($docRoot))
    : '';
define('BASE_URL', $basePath);
unset($projectRoot, $docRoot, $basePath);

/* ── الأخطاء ──────────────────────────────────────────────────────────────
 * تُسجَّل ولا تُعرض. عرض الأخطاء للزائر يكشف مسارات الملفات وأسماء الجداول،
 * وهي أول ما يبحث عنه مهاجم. أبقِ display_errors على '0' في الإنتاج.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
