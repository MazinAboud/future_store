<?php
/**
 * آلة حالات الطلب — مصدر الحقيقة الوحيد لما يجوز من انتقالات.
 * ---------------------------------------------------------------------------
 * قبل هذا الملف كان `set_status` في admin/ و staff/ يقبل أي قيمة من قائمة
 * الحالات السبع دون النظر إلى الحالة الحالية. اختبار حي أثبت أن ذلك يسمح بـ:
 *
 *   delivered -> pending      (وهي الأخطر: العودة إلى pending ثم "قبول" الطلب
 *                              مرة أخرى تخصم المخزون مرة ثانية عن بيعة واحدة)
 *   delivered -> confirmed
 *   cancelled -> delivered
 *   rejected  -> shipped
 *
 * أي أن تاريخ الطلب كان قابلًا لإعادة الكتابة، والمخزون قابلًا للخصم مرتين.
 * الانتقالات هنا تُفرض على الخادم في كل مسار، لا في الواجهة.
 */

/** الانتقالات المسموحة: من => [إلى، ...]. الحالات غير المذكورة نهائية. */
function order_transitions(): array
{
    return [
        'pending'    => ['confirmed', 'rejected', 'cancelled'],
        'confirmed'  => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['delivered'],
        // نهائية: لا خروج منها. تصحيح خطأ بعد التسليم عملية تجارية منفصلة
        // (مرتجع/استرداد) لا مجرد تغيير حالة.
        'delivered'  => [],
        'rejected'   => [],
        'cancelled'  => [],
    ];
}

/** هل يجوز الانتقال من $from إلى $to؟ الانتقال إلى نفس الحالة مسموح (لا عملية). */
function order_can_transition(string $from, string $to): bool
{
    if ($from === $to) return true;
    return in_array($to, order_transitions()[$from] ?? [], true);
}

/** الحالات التي يمكن الانتقال إليها من الحالة الحالية — لبناء القوائم في الواجهة. */
function order_next_states(string $from): array
{
    return order_transitions()[$from] ?? [];
}

/**
 * الدفع عند الاستلام لا يُحصَّل إلا عند التسليم فعلًا.
 *
 * وسم طلب لم يُسلَّم بعد بأنه "مدفوع" يجعل تقارير الإيراد تعلن نقدًا لم يدخل
 * الصندوق، وهو أسوأ أنواع الخطأ لأنه يبدو صحيحًا في الشاشة.
 */
function order_can_mark_paid(string $status, string $paymentMethod = 'cod'): bool
{
    return $paymentMethod !== 'cod' || $status === 'delivered';
}

/**
 * يسجّل حدثًا في تاريخ الطلب (سجل إلحاقي للمراجعة).
 *
 * الجدول order_events لا يُحدَّث ولا يُحذف منه في أي مسار — فأي تغيير حالة يترك
 * أثرًا يمكن مراجعته لاحقًا: من فعل، متى، ومن أي حالة إلى أي حالة.
 * يفشل بصمت متعمَّد: تسجيل الحدث يجب ألا يُسقط العملية التجارية نفسها.
 */
function order_log_event(PDO $pdo, int $orderId, ?string $from, string $to, ?int $actorId, ?string $actorRole, ?string $reason = null): void
{
    try {
        $pdo->prepare("INSERT INTO order_events (order_id, from_status, to_status, actor_id, actor_role, reason)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$orderId, $from, $to, $actorId, $actorRole, $reason]);
    } catch (Throwable $e) {
        error_log('order_log_event failed: ' . $e->getMessage());
    }
}
