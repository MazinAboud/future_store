<?php
/**
 * سجل العمليات الإدارية (admin_events).
 * ---------------------------------------------------------------------------
 * لماذا هذا الملف موجود:
 *
 * `order_events` يوثّق دورة حياة الطلب، وهو ممتاز — لكنه يغطي الطلبات وحدها.
 * كل شيء آخر كان يحدث بلا أثر إطلاقًا:
 *
 *   - حذف مستخدم يحذف معه **كل طلباته وتقييماته وطلبات صيانته**، ولا يبقى
 *     في النظام ما يقول من فعل ذلك ولا متى. لو اختفى عميل وطلباته، لا توجد
 *     وسيلة للتمييز بين خطأ إداري وتخريب متعمّد.
 *   - تغيير المخزون يدويًا يصحّح فرقًا أو يخفيه، والنتيجة واحدة على الشاشة.
 *   - إخفاء منتج أو حذفه يغيّر الكتالوج بلا سبب مسجَّل.
 *   - إعادة تعيين كلمة سر حساب آخر: أخطر عملية في اللوحة، وكانت صامتة.
 *
 * سجل التدقيق ليس ترفًا تنظيميًا — هو الدليل الوحيد عند أي نزاع، ولذلك فهو
 * **إضافة فقط**: لا تُحدَّث صفوفه ولا تُحذف من أي مسار في التطبيق.
 *
 * قاعدة صارمة: التسجيل لا يُسقط العملية أبدًا. لو فشلت الكتابة في السجل
 * لسبب ما، لا يجوز أن يتحوّل ذلك إلى فشل في حذف مستخدم كان الأدمن ينتظره؛
 * الفشل يُسجَّل في سجل PHP ويمضي الطلب. عكس ذلك — إسقاط العملية — يحوّل
 * ضابطًا وقائيًا إلى عطل.
 */

/**
 * يسجّل عملية إدارية واحدة.
 *
 * @param string $action      اسم قصير ثابت: delete_user, update_stock, ...
 * @param string $entityType  الكيان المتأثر: user, product, variant, brand
 * @param int|null $entityId  معرّفه إن وُجد (يبقى مسجَّلًا بعد الحذف)
 * @param string|null $summary وصف مقروء — يبقى مفهومًا بعد اختفاء الصف الأصلي
 */
function admin_log(string $action, string $entityType, ?int $entityId = null, ?string $summary = null): void
{
    try {
        $me = function_exists('current_user') ? current_user() : null;

        db()->prepare("
            INSERT INTO admin_events (actor_id, actor_role, action, entity_type, entity_id, summary, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $me['id']   ?? null,
            $me['role'] ?? null,
            $action,
            $entityType,
            $entityId,
            $summary !== null ? mb_substr($summary, 0, 255) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // لا تُسقط العملية الأصلية بسبب فشل التسجيل — انظر التعليق أعلاه.
        error_log('admin_log failed: ' . $e->getMessage());
    }
}

/** آخر العمليات المسجَّلة، للعرض في لوحة التقارير. */
function admin_events_recent(int $limit = 50): array
{
    $limit = max(1, min(200, $limit)); // مقيَّد: يُدمج في SQL لأن MySQL لا يقبل ربط LIMIT
    return db()->query("
        SELECT e.*, u.full_name AS actor_name
        FROM admin_events e
        LEFT JOIN users u ON u.id = e.actor_id
        ORDER BY e.id DESC
        LIMIT $limit
    ")->fetchAll();
}

/** تسمية عربية لكل نوع عملية. */
function admin_action_label(string $action): string
{
    return [
        'create_employee' => 'إنشاء موظف',
        'toggle_user'     => 'تفعيل/تعطيل حساب',
        'reset_password'  => 'إعادة تعيين كلمة سر',
        'edit_user'       => 'تعديل بيانات حساب',
        'delete_user'     => 'حذف حساب',
        'create_product'  => 'إضافة منتج',
        'update_product'  => 'تعديل منتج',
        'toggle_product'  => 'إظهار/إخفاء منتج',
        'delete_product'  => 'حذف منتج',
        'hide_product'    => 'إخفاء منتج (له مبيعات)',
        'update_stock'    => 'تعديل مخزون',
        'add_brand'       => 'إضافة ماركة',
        'delete_brand'    => 'حذف ماركة',
    ][$action] ?? $action;
}
