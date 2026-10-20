<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Enrollment\CompletionNotEligibleException;
use App\Exceptions\Enrollment\EnrollmentNotLearningException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use App\Services\CompletionEligibilityService;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Certificate\IssueAction as IssueCertificateAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 受講生本人による「修了証を受け取る」自己発火 Action。
 *
 * Controller の `$this->authorize('receiveCertificate', $enrollment)` で本人 + status==Learning を判定済の前提。
 * 本 Action はデータ整合性(`CompletionEligibilityService` の合格判定)のみ実施し、認可は Policy 側に委譲する。
 *
 * 処理フロー:
 * 1. トランザクション外で CompletionEligibilityService::isEligible を確認
 * 2. DB::transaction() 内で Enrollment を status=passed / passed_at=now() に更新
 * 3. 同じトランザクション内で EnrollmentStatusLog を記録
 * 4. 同じトランザクション内で IssueCertificateAction を呼び Certificate を発行
 * 5. 同じトランザクション内で修了証 PDF を生成・保存
 * 6. PDF生成・保存失敗時はDB変更をロールバック
 * 7. コールバック完了後の例外では、DB状態が不明なためPDFを誤削除しない
 *
 * 修了通知は送らない: 本人の操作直後のリダイレクト先画面で修了証 PDF DL リンクを提示するため、別途通知は冗長。
 */
final class ReceiveCertificateAction
{
    public function __construct(
        private readonly CompletionEligibilityService $eligibility,
        private readonly EnrollmentStatusChangeService $statusChanger,
        private readonly IssueCertificateAction $issueCertificate,
        private readonly CertificatePdfService $certificatePdf,
    ) {}

    /**
     * @throws CompletionNotEligibleException 公開模試すべてに合格していない
     * @throws EnrollmentNotLearningException Policy の事前判定漏れに対する整合性ガード
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Learning) {
            throw new EnrollmentNotLearningException;
        }

        if (! $this->eligibility->isEligible($enrollment)) {
            throw new CompletionNotEligibleException;
        }

        $transactionCallbackCompleted = false;

        try {
            return DB::transaction(function () use ($enrollment, &$transactionCallbackCompleted) {
                $enrollment->update([
                    'status' => EnrollmentStatus::Passed->value,
                    'passed_at' => now(),
                ]);

                $this->statusChanger->recordStatusChange(
                    $enrollment,
                    fromStatus: EnrollmentStatus::Learning,
                    toStatus: EnrollmentStatus::Passed,
                    changedBy: $enrollment->user,
                    reason: '受講生による修了証受領',
                );

                $certificate = ($this->issueCertificate)($enrollment->refresh());
                $this->certificatePdf->generateAndStore($certificate);

                // コールバック完了後のコミット例外では、DBの永続化結果が不明なためPDFを削除しない。
                $transactionCallbackCompleted = true;

                return $certificate;
            });
        } catch (Throwable $exception) {
            if ($transactionCallbackCompleted) {
                // コミット結果不明時に、永続化済みCertificateのPDFを誤削除しない。
                try {
                    Log::warning('修了証PDFの補償削除を保留しました。', [
                        'enrollment_id' => $enrollment->id,
                        'exception_class' => $exception::class,
                        'exception_message' => $exception->getMessage(),
                    ]);
                } catch (Throwable) {
                    // ログ失敗でも元のDB例外を隠さない。
                }
            }

            throw $exception;
        }
    }
}
