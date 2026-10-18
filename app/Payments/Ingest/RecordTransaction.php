<?php

namespace App\Payments\Ingest;

use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Enums\WebhookEventType;
use App\Models\BankAccount;
use App\Models\InboundEvent;
use App\Models\ProviderConnection;
use App\Models\Transaction;
use App\Payments\Matching\RuleMatcher;
use App\Payments\Memo\MemoNormalizer;
use App\Payments\Providers\NormalizedTransaction;
use App\Telegram\TelegramNotifier;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ghi một giao dịch đã chuẩn hóa và khớp đơn, trong một DB transaction.
 */
final class RecordTransaction
{
    private const DEDUP_INDEX = 'transactions_shop_id_provider_external_id_unique';

    /** Hai giao dịch đến cùng lúc qua hai nhà cung cấp có thể deadlock ở gap lock; chạy lại là thấy bản gốc. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly RuleMatcher $matcher,
        private readonly WebhookDispatcher $webhooks,
        private readonly TelegramNotifier $telegram,
    ) {}

    /**
     * Trả về giao dịch đã ghi. Nếu nhà cung cấp gửi lại giao dịch đã có, trả bản cũ
     * và không khớp lần hai.
     */
    public function record(NormalizedTransaction $normalized, ProviderConnection $connection, ?InboundEvent $event = null): Transaction
    {
        return DB::transaction(function () use ($normalized, $connection, $event): Transaction {
            $existing = $this->findExisting($normalized, $connection, lock: false);

            if ($existing !== null) {
                return $existing;
            }

            $bankAccount = $this->resolveBankAccount($normalized, $connection);
            $fingerprint = $bankAccount === null ? null : self::fingerprint($bankAccount->getKey(), $normalized);

            $transaction = new Transaction([
                'provider_connection_id' => $connection->getKey(),
                'inbound_event_id' => $event?->getKey(),
                'provider' => $normalized->provider,
                'external_id' => $normalized->externalId,
                'bank_account_id' => $bankAccount?->getKey(),
                'direction' => $normalized->direction,
                'amount' => $normalized->amount,
                'content' => Str::limit($normalized->content, 497),
                'reference_code' => $normalized->referenceCode,
                'provider_code' => $normalized->providerCode,
                'transacted_at' => $normalized->transactedAt,
                'payer_name' => $normalized->payerName,
                'payer_account' => $normalized->payerAccount,
                'raw' => $normalized->raw,
                'fingerprint' => $fingerprint,
                'status' => TransactionStatus::Unmatched,
            ]);
            $transaction->shop_id = $connection->shop_id;

            if ($normalized->direction === TransferDirection::Out) {
                $transaction->status = TransactionStatus::Ignored;
            } elseif (($original = $this->findCrossProviderOriginal($fingerprint, $normalized)) !== null) {
                $transaction->status = TransactionStatus::Duplicate;
                $transaction->duplicate_of = $original->getKey();
            }

            try {
                $transaction->save();
            } catch (UniqueConstraintViolationException $e) {
                // Một request gửi lại chạy song song đã ghi trước.
                if ($e->index !== self::DEDUP_INDEX) {
                    throw $e;
                }

                return $this->findExisting($normalized, $connection, lock: true)
                    ?? throw $e;
            }

            if ($transaction->status === TransactionStatus::Unmatched) {
                $order = $this->matcher->match($transaction, $connection->shop);

                // Ghi webhook và tin Telegram trong cùng transaction: deadlock chạy lại thì bản ghi của lượt hỏng cũng mất.
                $type = $order === null ? null : WebhookEventType::forOrderStatus($order->status);

                if ($order === null) {
                    $this->webhooks->transaction(WebhookEventType::TransactionUnmatched, $transaction);
                } elseif ($type !== null) {
                    $this->webhooks->order($type, $order, $transaction);
                }

                $this->telegram->transaction($connection->shop, $transaction, $order);
            }

            return $transaction;
        }, self::ATTEMPTS);
    }

    /**
     * Khóa chống trùng chéo nhà cung cấp: cùng tài khoản, cùng số tiền, cùng nội dung, cùng phút.
     * Không dùng mã tham chiếu vì SePay (referenceCode) và Pay2S (transactionNumber)
     * chưa chắc gửi cùng một mã.
     */
    public static function fingerprint(int $bankAccountId, NormalizedTransaction $normalized): string
    {
        return hash('sha256', implode('|', [
            $bankAccountId,
            $normalized->amount,
            MemoNormalizer::normalize($normalized->content),
            $normalized->transactedAt->format('Y-m-d H:i'),
        ]));
    }

    private function findExisting(NormalizedTransaction $normalized, ProviderConnection $connection, bool $lock): ?Transaction
    {
        $query = Transaction::query()
            ->where('shop_id', $connection->shop_id)
            ->where('provider', $normalized->provider)
            ->where('external_id', $normalized->externalId);

        // Đọc có khóa để thấy bản ghi vừa được transaction khác commit (REPEATABLE READ).
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function resolveBankAccount(NormalizedTransaction $normalized, ProviderConnection $connection): ?BankAccount
    {
        $query = BankAccount::query()->where('shop_id', $connection->shop_id);

        if ($connection->bank_account_id !== null) {
            return $query->whereKey($connection->bank_account_id)->first();
        }

        return $query->where('account_number', $normalized->accountNumber)->first();
    }

    private function findCrossProviderOriginal(?string $fingerprint, NormalizedTransaction $normalized): ?Transaction
    {
        if ($fingerprint === null) {
            return null;
        }

        // Chỉ coi là trùng khi bản kia đến từ nhà cung cấp khác: hai lần chuyển giống hệt nhau
        // qua cùng một nhà cung cấp là hai giao dịch thật, có id khác nhau.
        return Transaction::query()
            ->where('fingerprint', $fingerprint)
            ->where('provider', '!=', $normalized->provider)
            ->whereNull('duplicate_of')
            ->lockForUpdate()
            ->first();
    }
}
