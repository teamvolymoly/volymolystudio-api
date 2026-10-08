<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class SendAuthMail implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    public array $backoff = [10, 60];

    private array $context = [];

    public function __construct(
        private readonly string $email,
        private readonly string $subject,
        private readonly string $body,
        array $context = [],
    ) {
        $this->context = $context;
    }

    public function handle(): void
    {
        // Queued/retried messages may have been replaced or consumed meanwhile.
        if (isset($this->context['verification_id'])) {
            $verification = EmailVerificationCode::find($this->context['verification_id']);
            if (! $verification || $verification->email !== $this->email || $verification->consumed_at
                || ! $verification->expires_at || $verification->expires_at->lte(now())
                || $verification->attempts >= $verification->max_attempts
                || ! hash_equals($verification->code_hash, $this->context['code_hash'])) {
                return;
            }
            $latestId = EmailVerificationCode::where('email', $this->email)
                ->where('purpose', $verification->purpose)->max('id');
            if ((int) $latestId !== $verification->id) {
                return;
            }

            // Older queued jobs without the code field retain their plain-text delivery.
            if ($verification->purpose === 'login' && isset($this->context['code'])) {
                $secondsRemaining = max(1, $verification->expires_at->timestamp - now()->timestamp);
                $minutesRemaining = (int) ceil($secondsRemaining / 60);
                $expiryText = $secondsRemaining < 60
                    ? 'less than a minute'
                    : $minutesRemaining.' '.($minutesRemaining === 1 ? 'minute' : 'minutes');

                Mail::send(
                    ['html' => 'emails.login-verification', 'text' => 'emails.login-verification-text'],
                    [
                        'code' => $this->context['code'],
                        'expiryText' => $expiryText,
                        'frontendUrl' => config('auth.frontend_url'),
                    ],
                    function ($message): void {
                        $message->to($this->email)->subject($this->subject);
                    },
                );

                return;
            }
        }

        if (isset($this->context['reset_token'])) {
            $user = User::where('email', $this->email)->first();
            if (! $user || ! Password::broker()->tokenExists($user, $this->context['reset_token'])) {
                return;
            }

            Mail::send(
                ['html' => 'emails.password-reset', 'text' => 'emails.password-reset-text'],
                [
                    'email' => $this->email,
                    // Jobs queued before the HTML template do not have a saved URL.
                    'resetUrl' => $this->context['reset_url'] ?? $user->passwordResetUrl($this->context['reset_token']),
                    'frontendUrl' => config('auth.frontend_url'),
                    'expiresInMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                ],
                function ($message): void {
                    $message->to($this->email)->subject($this->subject);
                },
            );

            return;
        }

        Mail::raw($this->body, function ($message): void {
            $message->to($this->email)->subject($this->subject);
        });
    }
}
