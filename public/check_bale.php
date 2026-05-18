<?php
/**
 * Diagnostic: Check Webhook Accessibility & Redirection
 */
$fullUrl = "https://mobixai.ir/public/webhook.php";
$ch = curl_init($fullUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false, // Do NOT follow redirects
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_SSL_VERIFYPEER => false
]);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');
echo "🔍 Webhook URL Diagnostic\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
echo "Target URL: {$fullUrl}\n";
echo "HTTP Status Code: {$info['http_code']}\n";

if ($info['http_code'] == 301 || $info['http_code'] == 302) {
    echo "⚠️ WARNING: URL is REDIRECTING to: " . ($info['redirect_url'] ?? 'unknown') . "\n";
    echo "❌ CRITICAL: Bale POST body will be LOST during redirection.\n";
    echo "💡 FIX: Set your webhook to the FINAL URL shown above.\n";
} else {
    echo "✅ No redirect detected. Status 200 OK.\n";
}

echo "\n--- Server Info ---\n";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "PHP Interface: " . php_sapi_name() . "\n";