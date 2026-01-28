<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushController extends Controller
{
    public function publicKey()
    {
        return response()->json([
            'publicKey' => env('VAPID_PUBLIC_KEY'),
        ]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = $request->user();

        PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint' => $data['endpoint'],
            ],
            [
                'tenant_id' => $user->tenant_id,
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                // IMPORTANT: usiamo aes128gcm (più compatibile su Windows)
                'content_encoding' => 'aes128gcm',
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function test(Request $request)
    {
        try {
            // forza OPENSSL_CONF nel processo (utile su Windows)
            $conf = env('OPENSSL_CONF');
            if (!empty($conf)) {
                putenv("OPENSSL_CONF={$conf}");
            }

            $user = $request->user();

            $auth = [
                'VAPID' => [
                    'subject' => env('VAPID_SUBJECT', 'mailto:info@smartera.com'),
                    'publicKey' => env('VAPID_PUBLIC_KEY'),
                    'privateKey' => env('VAPID_PRIVATE_KEY'),
                ],
            ];

            $webPush = new WebPush($auth);

            $subs = PushSubscription::where('user_id', $user->id)->get();

            foreach ($subs as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth,
                    // IMPORTANT: usa quello salvato (aes128gcm)
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]);

                $payload = json_encode([
                    'title' => 'Smart Area Social AI',
                    'body'  => 'Notifica di test ✅',
                    'url'   => '/notifications',
                ]);

                $webPush->queueNotification($subscription, $payload);
            }

            $sent = 0;
            $failed = 0;
            $reasons = [];

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } else {
                    $failed++;
                    $reasons[] = (string) $report->getReason();
                }
            }

            return response()->json([
                'subscriptions' => $subs->count(),
                'sent' => $sent,
                'failed' => $failed,
                'reasons' => $reasons,
            ]);
       } catch (\Throwable $e) {
    $opensslErrors = [];
    while ($msg = openssl_error_string()) {
        $opensslErrors[] = $msg;
    }

    return response()->json([
        'error' => true,
        'message' => $e->getMessage(),
        'openssl' => $opensslErrors,
        'env' => [
            'OPENSSL_CONF' => env('OPENSSL_CONF'),
            'OPENSSL_MODULES' => getenv('OPENSSL_MODULES') ?: null,
        ],
    ], 500);
}

    }
}
