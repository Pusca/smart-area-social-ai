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
        $publicKey = trim((string) env('VAPID_PUBLIC_KEY', ''));
        if ($publicKey === '') {
            return response()->json([
                'error' => true,
                'message' => 'VAPID_PUBLIC_KEY non configurata',
            ], 500);
        }

        return response()->json([
            'publicKey' => $publicKey,
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
                'content_encoding' => 'aes128gcm',
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function test(Request $request)
    {
        try {
            $opensslConf = env('OPENSSL_CONF');
            if (!empty($opensslConf)) {
                putenv("OPENSSL_CONF={$opensslConf}");
            }
            $opensslModules = env('OPENSSL_MODULES');
            if (!empty($opensslModules)) {
                putenv("OPENSSL_MODULES={$opensslModules}");
            }

            $public = trim((string) env('VAPID_PUBLIC_KEY', ''));
            $private = trim((string) env('VAPID_PRIVATE_KEY', ''));

            if ($public === '' || $private === '') {
                return response()->json([
                    'error' => true,
                    'message' => 'VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY mancanti in .env',
                ], 422);
            }

            $auth = [
                'VAPID' => [
                    'subject' => env('VAPID_SUBJECT', 'mailto:info@smartera.com'),
                    'publicKey' => $public,
                    'privateKey' => $private,
                ],
            ];

            $user = $request->user();
            $subs = PushSubscription::where('user_id', $user->id)->get();
            if ($subs->isEmpty()) {
                return response()->json([
                    'subscriptions' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'reasons' => ['Nessuna subscription salvata per questo utente.'],
                ], 422);
            }

            $webPush = new WebPush($auth);
            $webPush->setReuseVAPIDHeaders(true);

            foreach ($subs as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth,
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]);

                // Test push senza payload:
                // evita la cifratura locale EC che su alcuni ambienti Windows/OpenSSL
                // può generare "Unable to create the local key".
                $webPush->queueNotification($subscription, null);
            }

            $sent = 0;
            $failed = 0;
            $reasons = [];

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }

                $failed++;
                $reason = (string) $report->getReason();
                $reasons[] = $reason;

                $reasonLower = strtolower($reason);
                if (str_contains($reasonLower, '410') || str_contains($reasonLower, '404')) {
                    PushSubscription::where('endpoint', $report->getEndpoint())->delete();
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
