<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Context\ContextPiiException;
use App\Modules\Ai\Context\ContextTooLargeException;
use App\Modules\Ai\Exceptions\AiAuthenticationException;
use App\Modules\Ai\Exceptions\AiConfigurationException;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use App\Modules\Ai\Exceptions\AiInvalidResponseException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\AiResponseInvalidException;
use App\Modules\Ai\Exceptions\AiTimeoutException;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\BudgetExceededException;
use App\Modules\Ai\Exceptions\ManualRequestThrottledException;
use App\Modules\Ai\Exceptions\OrdersSyncBehindException;
use App\Modules\Ai\Exceptions\StaleDataException;
use App\Modules\Ai\Exceptions\UnpricedModelException;
use Throwable;

/**
 * What a person sees when the analyst cannot run: a stable CODE (the UI branches on it) and one plain Persian sentence. The sentences are
 * fixed text — an exception's own message is never forwarded, so a key, a prompt, a response body or SQL can never reach the screen.
 */
final class AnalystMessages
{
    /** @return array{code: string, message: string} */
    public static function forException(Throwable $e): array
    {
        return match (true) {
            $e instanceof AnalystNotReadyException => ['code' => $e->reason === 'ai_disabled' ? 'ai_disabled' : 'not_ready', 'message' => self::forReadiness($e->reason)],
            $e instanceof StaleDataException => ['code' => 'stale_data', 'message' => 'داده‌ها قدیمی‌اند؛ تا همگام‌سازی و محاسبه‌ی شبانه انجام نشود تحلیل ساخته نمی‌شود.'],
            $e instanceof OrdersSyncBehindException => ['code' => 'orders_sync_behind', 'message' => 'همگام‌سازی سفارش‌ها از پایان روز مورد تحلیل عقب است و سفارش‌های آن روز ناقص‌اند؛ پس از همگام‌سازی دوباره تلاش کنید.'],
            $e instanceof ManualRequestThrottledException => ['code' => 'throttled', 'message' => 'سقف درخواست دستی پر شده است؛ بعداً دوباره تلاش کنید.'],
            $e instanceof BudgetExceededException => ['code' => 'budget_exceeded', 'message' => 'بودجه‌ی ماهانه‌ی هوش مصنوعی تمام شده است.'],
            $e instanceof UnpricedModelException => ['code' => 'not_ready', 'message' => self::forReadiness('unpriced_model')],
            $e instanceof AiAuthenticationException, $e instanceof AiConfigurationException => ['code' => 'provider_config', 'message' => 'کلید یا تنظیمات ارائه‌دهنده‌ی هوش مصنوعی درست نیست؛ به مدیر سیستم اطلاع دهید.'],
            $e instanceof AiRateLimitException => ['code' => 'provider_busy', 'message' => 'ارائه‌دهنده موقتاً درخواست‌ها را محدود کرده است؛ چند دقیقه بعد دوباره تلاش کنید.'],
            $e instanceof AiTimeoutException => ['code' => 'provider_unreachable', 'message' => 'ارتباط با ارائه‌دهنده برقرار نشد یا زیادی طول کشید؛ بعداً دوباره تلاش کنید.'],
            $e instanceof AiContentBlockedException => ['code' => 'blocked', 'message' => 'ارائه‌دهنده پاسخ را مسدود کرد؛ چیزی ذخیره نشد.'],
            $e instanceof AiInvalidResponseException, $e instanceof AiResponseInvalidException => ['code' => 'invalid_response', 'message' => 'پاسخ مدل معتبر یا کامل نبود؛ چیزی ذخیره نشد و می‌توانید دوباره تلاش کنید.'],
            $e instanceof ContextPiiException, $e instanceof ContextTooLargeException => ['code' => 'context_error', 'message' => 'ساخت داده‌ی ورودی تحلیل به‌صورت امن ممکن نشد؛ به مدیر سیستم اطلاع دهید.'],
            default => ['code' => 'unexpected', 'message' => 'خطای غیرمنتظره رخ داد؛ به مدیر سیستم اطلاع دهید.'],
        };
    }

    /** The HTTP status a refusal code is sent with: throttled is 429, every other known refusal is 422. */
    public static function httpStatus(string $code): int
    {
        return $code === 'throttled' ? 429 : 422;
    }

    public static function forReadiness(string $code): string
    {
        return match ($code) {
            'ai_disabled' => 'تحلیلگر هوشمند غیرفعال است.',
            'missing_api_key', 'unknown_provider' => 'کلید یا ارائه‌دهنده‌ی هوش مصنوعی تنظیم نشده است.',
            'missing_model' => 'مدل هوش مصنوعی تنظیم نشده است.',
            'unpriced_model' => 'قیمت مدل هوش مصنوعی تنظیم نشده است، پس بودجه قابل کنترل نیست.',
            default => 'تحلیلگر هوشمند آماده نیست.',
        };
    }

    /** A recent alert's reason code (AiGenerationFailed::$reason) as a sentence. */
    public static function forAlertReason(string $reason): string
    {
        return match ($reason) {
            'stale_data' => 'تحلیل به‌خاطر قدیمی بودن داده انجام نشد.',
            'orders_sync_behind' => 'تحلیل به‌خاطر عقب‌ماندن همگام‌سازی سفارش‌ها (روز ناقص) انجام نشد.',
            'invalid_response' => 'پاسخ مدل دوبار نامعتبر بود و ذخیره نشد.',
            'provider_error' => 'ارائه‌دهنده خطا داد.',
            'context_error' => 'ساخت داده‌ی ورودی ناموفق بود.',
            default => 'تولید تحلیل ناموفق بود.',
        };
    }
}
