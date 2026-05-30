<?php
$pageTitle = 'خرید اعتبار';
require_once __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$plans = [];
try {
    $db = Database::getInstance();
    $plans = $db->query("SELECT * FROM payment_plans WHERE is_active = 1")->fetchAll();
} catch (\Throwable $e) {}
?>
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-white mb-2">خرید اعتبار</h1>
    <p class="text-gray-400 text-sm mb-6">اعتبار فعلی: <span class="text-emerald-400 font-bold"><?= number_format($credits) ?></span></p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <?php foreach ($plans as $plan): ?>
        <div class="glass rounded-2xl p-5 text-center hover:border-emerald-500/30 transition border border-transparent">
            <h3 class="text-white font-bold text-lg mb-2"><?= htmlspecialchars($plan['name']) ?></h3>
            <p class="text-emerald-400 text-3xl font-bold mb-1"><?= number_format($plan['credits']) ?></p>
            <p class="text-gray-500 text-sm mb-4">اعتبار</p>
            <p class="text-gray-300 text-lg font-bold mb-4"><?= number_format($plan['price_rial'] / 10) ?> تومان</p>
            <button onclick="payWithZibal(<?= $plan['id'] ?>)" class="btn-primary w-full rounded-xl py-2.5 text-white font-bold text-sm">
                <i data-lucide="shopping-cart" class="w-4 h-4 inline"></i> خرید
            </button>
        </div>
        <?php endforeach; ?>
        <?php if (empty($plans)): ?>
        <div class="col-span-3 text-center text-gray-500 py-10">هیچ پلنی فعال نیست</div>
        <?php endif; ?>
    </div>
</div>

<script>
function payWithZibal(planId) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'در حال اتصال به درگاه...';
    fetch('/web/ajax/start_payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({plan_id: planId, gateway: 'zibal'})
    }).then(r => r.json()).then(d => {
        if (d.url) {
            window.location.href = d.url;
        } else {
            alert(d.error || 'خطا');
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="shopping-cart" class="w-4 h-4 inline"></i> خرید';
            lucide.createIcons();
        }
    }).catch(() => {
        alert('خطا در ارتباط');
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="shopping-cart" class="w-4 h-4 inline"></i> خرید';
    });
}
</script>
<?php require __DIR__ . '/footer.php'; ?>
</main></div></body></html>